<?php

namespace App\Services;

use App\Passkey;
use App\Support\PublicAddress;
use App\User;
use Cose\Algorithm\Manager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AttestationStatement\PackedAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Passkeys (WebAuthn, web-auth/webauthn-lib): making one on the Account
 * page, the second step with one, and "Sign in with a passkey" (a
 * discoverable credential: no name typed, the passkey says whose it is).
 *
 * The relying party is the request's host (the public host on the public
 * address): a passkey is made for, offered on and accepted on that host
 * only (passkeys.rp_id). The origin must be exactly this host's (https, or
 * http://localhost). User verification is always required. Challenges are
 * random 32 bytes kept in this browser's session for CHALLENGE_TTL seconds
 * and used once (pulled before the check). The signature counter must move
 * forward unless the authenticator never counts (always 0, as synced
 * passkeys do). Browsers offer WebAuthn only on https (and localhost), so on
 * a plain-http address there is no passkey at all (available()).
 *
 * No state on the object (Octane-safe): the options and challenges live
 * in the session, the credentials in the table.
 */
class Passkeys
{
    public const CHALLENGE_TTL = 300;

    /** Session key prefix of the pending challenges, one per purpose. */
    public const SESSION_KEY = 'novarr_passkey_challenge';

    public const REGISTER = 'register';

    public const SIGN_IN = 'sign_in';

    public const SECOND_STEP = 'second_step';

    public const CONFIRM = 'confirm';

    public const TIMEOUT_MS = 120000;

    public const MAX_PER_USER = 20;

    public const UNAVAILABLE_MESSAGE = 'Passkeys need a secure (https) address; this one is plain http.';

    public const FAILED_MESSAGE = 'That passkey could not be used here. Try again, or use another way.';

    /** Whether passkeys can work on this address (the browser offers WebAuthn only in a secure context). */
    public static function available(Request $request): bool
    {
        $host = static::rpId($request);
        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        return PublicAddress::isPublicRequest($request) || $request->isSecure() || $host === 'localhost';
    }

    /** The relying-party id: the host, without port (the public host on the public address). */
    public static function rpId(Request $request): string
    {
        return strtolower(PublicAddress::matchedHost($request) ?? $request->getHost());
    }

    /** The one origin accepted in clientDataJSON. */
    public static function origin(Request $request): string
    {
        $host = static::rpId($request);
        if (PublicAddress::isPublicRequest($request)) {
            return 'https://' . $host;
        }
        $scheme = $request->isSecure() ? 'https' : 'http';
        $port = (int) $request->getPort();
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme . '://' . $host . ($port > 0 && $port !== $default ? ':' . $port : '');
    }

    /** $user's passkeys made on this address. */
    public function forHere(User $user, Request $request)
    {
        return Passkey::where('user_id', $user->id)->where('rp_id', static::rpId($request))->orderBy('id')->get();
    }

    // ---------------------------------------------------------------- making one

    /** The options for navigator.credentials.create() (JSON, base64url fields); the challenge is kept. */
    public function creationOptions(Request $request, User $user): array
    {
        $challenge = $this->newChallenge($request, self::REGISTER, (int) $user->id);
        $rpId = static::rpId($request);

        return [
            'rp' => ['id' => $rpId, 'name' => 'Novarr'],
            'user' => [
                'id' => static::b64(static::handleOf($user)),
                'name' => (string) $user->username,
                'displayName' => (string) $user->name,
            ],
            'challenge' => static::b64($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => self::TIMEOUT_MS,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'residentKey' => 'required',
                'requireResidentKey' => true,
                'userVerification' => 'required',
            ],
            'excludeCredentials' => $this->forHere($user, $request)->map(fn(Passkey $p) => array_filter([
                'type' => 'public-key',
                'id' => $p->credential_id,
                'transports' => $p->transportList() ?: null,
            ]))->values()->all(),
        ];
    }

    /**
     * Check the browser's answer to creationOptions() and store the
     * passkey. Returns the passkey, or an error sentence.
     */
    public function register(Request $request, User $user, mixed $credential, string $name): Passkey|string
    {
        $pending = $this->pullChallenge($request, self::REGISTER);
        if ($pending === null || (int) $pending['user'] !== (int) $user->id) {
            return 'That took too long. Try adding the passkey again.';
        }
        if (Passkey::where('user_id', $user->id)->count() >= self::MAX_PER_USER) {
            return 'You have ' . self::MAX_PER_USER . ' passkeys already. Remove one first.';
        }

        $rpId = static::rpId($request);
        try {
            $publicKeyCredential = $this->load($credential);
            $response = $publicKeyCredential->response;
            if (!$response instanceof AuthenticatorAttestationResponse) {
                return self::FAILED_MESSAGE;
            }
            $options = PublicKeyCredentialCreationOptions::create(
                PublicKeyCredentialRpEntity::create('Novarr', $rpId),
                PublicKeyCredentialUserEntity::create((string) $user->username, static::handleOf($user), (string) $user->name),
                $pending['challenge'],
                [PublicKeyCredentialParameters::createPk(-7), PublicKeyCredentialParameters::createPk(-257)],
                AuthenticatorSelectionCriteria::create(null, AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED, AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED),
                PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            );
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies($request)->creationCeremony())
                ->check($response, $options, $rpId);
        } catch (\Throwable $e) {
            Log::info('A passkey was not accepted: ' . $e->getMessage());

            return self::FAILED_MESSAGE;
        }

        $hash = hash('sha256', $record->publicKeyCredentialId);
        if (Passkey::where('credential_hash', $hash)->exists()) {
            return 'That passkey is already added.';
        }

        return Passkey::create([
            'user_id' => (int) $user->id,
            'rp_id' => $rpId,
            'credential_hash' => $hash,
            'credential_id' => static::b64($record->publicKeyCredentialId),
            'public_key' => base64_encode($record->credentialPublicKey),
            'counter' => (int) $record->counter,
            'transports' => json_encode(array_values(array_filter($record->transports, 'is_string'))),
            'aaguid' => $record->aaguid->toRfc4122(),
            'backup_eligible' => $record->backupEligible,
            'backup_status' => $record->backupStatus,
            'name' => static::cleanName($name),
        ]);
    }

    // ---------------------------------------------------------------- using one

    /**
     * The options for navigator.credentials.get(). With $user (second step,
     * confirming): that account's passkeys on this address; without (sign
     * in with a passkey): none listed — the browser offers its own.
     */
    public function requestOptions(Request $request, string $purpose, ?User $user = null): array
    {
        $challenge = $this->newChallenge($request, $purpose, $user?->id);

        return array_filter([
            'challenge' => static::b64($challenge),
            'rpId' => static::rpId($request),
            'timeout' => self::TIMEOUT_MS,
            'userVerification' => 'required',
            'allowCredentials' => $user === null ? null : $this->forHere($user, $request)->map(fn(Passkey $p) => array_filter([
                'type' => 'public-key',
                'id' => $p->credential_id,
                'transports' => $p->transportList() ?: null,
            ]))->values()->all(),
        ], fn($v) => $v !== null);
    }

    /**
     * Check the browser's answer to requestOptions($purpose). Returns the
     * passkey (counter and last use updated) when it is valid for this
     * address and — with $user — belongs to that account; null otherwise.
     */
    public function verify(Request $request, string $purpose, mixed $credential, ?User $user = null): ?Passkey
    {
        $pending = $this->pullChallenge($request, $purpose);
        if ($pending === null || ($user !== null && (int) ($pending['user'] ?? 0) !== (int) $user->id)) {
            return null;
        }

        $rpId = static::rpId($request);
        try {
            $publicKeyCredential = $this->load($credential);
            $response = $publicKeyCredential->response;
            if (!$response instanceof AuthenticatorAssertionResponse) {
                return null;
            }
            $passkey = Passkey::where('credential_hash', hash('sha256', $publicKeyCredential->rawId))
                ->where('rp_id', $rpId)
                ->when($user !== null, fn($q) => $q->where('user_id', $user->id))
                ->first();
            if ($passkey === null || $passkey->user === null) {
                return null;
            }

            $handle = static::handleOf($passkey->user);
            $record = CredentialRecord::create(
                Base64UrlSafe::decodeNoPadding($passkey->credential_id),
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                $passkey->transportList(),
                'none',
                EmptyTrustPath::create(),
                $passkey->aaguid ? Uuid::fromString($passkey->aaguid) : Uuid::fromString('00000000-0000-0000-0000-000000000000'),
                base64_decode((string) $passkey->public_key, true) ?: '',
                $handle,
                (int) $passkey->counter,
                null,
                $passkey->backup_eligible,
                $passkey->backup_status,
                true,
            );
            $options = PublicKeyCredentialRequestOptions::create(
                $pending['challenge'],
                $rpId,
                [],
                PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            );
            $checked = AuthenticatorAssertionResponseValidator::create($this->ceremonies($request)->requestCeremony())
                ->check($record, $response, $options, $rpId, $handle);
        } catch (\Throwable $e) {
            Log::info('A passkey sign-in was not accepted: ' . $e->getMessage());

            return null;
        }

        $counter = (int) $checked->counter;
        $values = ['counter' => $counter, 'backup_status' => $checked->backupStatus, 'last_used_at' => now()];
        if ($counter === 0) {
            // An authenticator that never counts (synced passkeys) stays at
            // 0: the single-use challenge is what stops a replay then. This
            // update may change nothing (the same values in the same second),
            // and MySQL/MariaDB report changed rows, not matched ones: so the
            // stored counter is asked, never the affected rows.
            Passkey::whereKey($passkey->id)->where('counter', 0)->toBase()->update($values);
            if (!Passkey::whereKey($passkey->id)->where('counter', 0)->exists()) {
                return null;
            }
        } else {
            // A counter that moves forward always changes the row, so one
            // affected row means this request moved it: a replayed assertion
            // (same counter) racing this one cannot also pass.
            $moved = Passkey::whereKey($passkey->id)->where('counter', '<', $counter)->toBase()->update($values);
            if ($moved !== 1) {
                return null;
            }
        }

        return $passkey->refresh();
    }

    // ---------------------------------------------------------------- helpers

    /** The account's random WebAuthn user handle (made on first use; never the id). */
    public static function handleOf(User $user): string
    {
        if (!is_string($user->passkey_handle) || $user->passkey_handle === '') {
            $user->forceFill(['passkey_handle' => bin2hex(random_bytes(16))])->save();
        }

        return (string) $user->passkey_handle;
    }

    public static function cleanName(?string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $name));

        return $name === '' ? 'Passkey' : mb_substr($name, 0, 80);
    }

    public static function b64(string $raw): string
    {
        return Base64UrlSafe::encodeUnpadded($raw);
    }

    private function newChallenge(Request $request, string $purpose, ?int $userId): string
    {
        $challenge = random_bytes(32);
        $request->session()->put(self::SESSION_KEY . '.' . $purpose, [
            'challenge' => base64_encode($challenge),
            'rp' => static::rpId($request),
            'user' => $userId,
            'expires' => now()->getTimestamp() + self::CHALLENGE_TTL,
        ]);

        return $challenge;
    }

    /** The pending challenge of $purpose, used up now; null when none, expired or for another address. */
    private function pullChallenge(Request $request, string $purpose): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }
        $pending = $request->session()->pull(self::SESSION_KEY . '.' . $purpose);
        if (!is_array($pending) || !isset($pending['challenge'], $pending['rp'], $pending['expires'])
            || (int) $pending['expires'] < now()->getTimestamp() || $pending['rp'] !== static::rpId($request)) {
            return null;
        }
        $challenge = base64_decode((string) $pending['challenge'], true);
        if ($challenge === false || strlen($challenge) !== 32) {
            return null;
        }

        return ['challenge' => $challenge, 'user' => $pending['user'] ?? null];
    }

    /** The JSON of a PublicKeyCredential (as the browser sent it) as the library's object. */
    private function load(mixed $credential): PublicKeyCredential
    {
        $json = is_string($credential) ? $credential : json_encode($credential);
        if (!is_string($json) || strlen($json) > 64 * 1024) {
            throw new \InvalidArgumentException('No credential.');
        }

        return $this->serializer()->deserialize($json, PublicKeyCredential::class, 'json');
    }

    private function serializer(): SerializerInterface
    {
        return (new WebauthnSerializerFactory($this->attestationSupport()))->create();
    }

    private function attestationSupport(): AttestationStatementSupportManager
    {
        return new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
            new PackedAttestationStatementSupport($this->algorithms()),
        ]);
    }

    private function algorithms(): Manager
    {
        return Manager::create()->add(ES256::create(), RS256::create());
    }

    private function ceremonies(Request $request): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins([static::origin($request)], false);
        $factory->setAlgorithmManager($this->algorithms());
        $factory->setAttestationStatementSupportManager($this->attestationSupport());

        return $factory;
    }
}
