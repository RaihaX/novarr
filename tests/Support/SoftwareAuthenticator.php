<?php

namespace Tests\Support;

/**
 * A passkey authenticator in PHP for tests: a real P-256 key pair, real
 * CBOR, real authenticator data and ES256 signatures, so the server's
 * WebAuthn checks (web-auth/webauthn-lib) are exercised for real. Answers
 * the server's options JSON (App\Services\Passkeys) the way a browser's
 * navigator.credentials does, as the JSON the page's script posts.
 *
 * Every answer can be bent for a negative test: another origin, another
 * relying-party id, no user verification, a counter going back, another
 * challenge.
 */
final class SoftwareAuthenticator
{
    private \OpenSSLAsymmetricKey $key;

    public string $credentialId;

    public int $counter = 0;

    /** The user handle the server gave at registration (raw bytes). */
    public ?string $userHandle = null;

    public function __construct(?string $credentialId = null)
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new \RuntimeException('Could not make a P-256 key');
        }
        $this->key = $key;
        $this->credentialId = $credentialId ?? random_bytes(32);
    }

    /**
     * Answer navigator.credentials.create($options).
     *
     * @param array{origin?: string, rp_id?: string, flags?: int, challenge?: string, type?: string, counter?: int} $bend
     */
    public function register(array $options, string $origin, array $bend = []): array
    {
        $this->userHandle = self::b64d($options['user']['id']);
        $rpId = $bend['rp_id'] ?? $options['rp']['id'];
        $clientData = json_encode([
            'type' => $bend['type'] ?? 'webauthn.create',
            'challenge' => $bend['challenge'] ?? $options['challenge'],
            'origin' => $bend['origin'] ?? $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $counter = $bend['counter'] ?? $this->counter;
        $authData = hash('sha256', $rpId, true)
            . chr($bend['flags'] ?? 0x45) // UP | UV | AT
            . pack('N', $counter)
            . str_repeat("\0", 16) // aaguid
            . pack('n', strlen($this->credentialId)) . $this->credentialId
            . $this->coseKey();
        $attestation = self::cbor(['fmt' => 'none', 'attStmt' => [], 'authData' => new CborBytes($authData)]);

        return [
            'id' => self::b64($this->credentialId),
            'rawId' => self::b64($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'attestationObject' => self::b64($attestation),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * Answer navigator.credentials.get($options). The counter goes up by
     * one first (unless $bend says what it is).
     *
     * @param array{origin?: string, rp_id?: string, flags?: int, challenge?: string, counter?: int, user_handle?: ?string, credential_id?: string} $bend
     */
    public function assert(array $options, string $origin, array $bend = []): array
    {
        $rpId = $bend['rp_id'] ?? $options['rpId'];
        $clientData = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $bend['challenge'] ?? $options['challenge'],
            'origin' => $bend['origin'] ?? $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
        $counter = array_key_exists('counter', $bend) ? $bend['counter'] : ++$this->counter;
        $authData = hash('sha256', $rpId, true) . chr($bend['flags'] ?? 0x05) . pack('N', $counter);
        openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);
        $id = $bend['credential_id'] ?? $this->credentialId;
        $handle = array_key_exists('user_handle', $bend) ? $bend['user_handle'] : $this->userHandle;

        return [
            'id' => self::b64($id),
            'rawId' => self::b64($id),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64($clientData),
                'authenticatorData' => self::b64($authData),
                'signature' => self::b64($signature),
                'userHandle' => $handle === null ? null : self::b64($handle),
            ],
        ];
    }

    /** The public key as a COSE_Key (EC2, ES256). */
    private function coseKey(): string
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];

        return self::cbor(new CborMap([
            [1, 2],                       // kty: EC2
            [3, -7],                      // alg: ES256
            [-1, 1],                      // crv: P-256
            [-2, new CborBytes(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT))],
            [-3, new CborBytes(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT))],
        ]));
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function b64d(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4));
    }

    /** A small CBOR encoder: ints, text, bytes (CborBytes), arrays-as-maps with string keys, CborMap. */
    public static function cbor(mixed $value): string
    {
        if ($value instanceof CborBytes) {
            return self::head(2, strlen($value->bytes)) . $value->bytes;
        }
        if ($value instanceof CborMap) {
            $out = self::head(5, count($value->pairs));
            foreach ($value->pairs as [$k, $v]) {
                $out .= self::cbor($k) . self::cbor($v);
            }

            return $out;
        }
        if (is_int($value)) {
            return $value >= 0 ? self::head(0, $value) : self::head(1, -1 - $value);
        }
        if (is_string($value)) {
            return self::head(3, strlen($value)) . $value;
        }
        if (is_array($value)) {
            $out = self::head(5, count($value));
            foreach ($value as $k => $v) {
                $out .= self::cbor((string) $k) . self::cbor($v);
            }

            return $out;
        }
        throw new \InvalidArgumentException('Cannot CBOR-encode ' . get_debug_type($value));
    }

    private static function head(int $major, int $length): string
    {
        $m = $major << 5;
        if ($length < 24) {
            return chr($m | $length);
        }
        if ($length < 256) {
            return chr($m | 24) . chr($length);
        }
        if ($length < 65536) {
            return chr($m | 25) . pack('n', $length);
        }

        return chr($m | 26) . pack('N', $length);
    }
}

final class CborBytes
{
    public function __construct(public string $bytes)
    {
    }
}

final class CborMap
{
    /** @param list<array{0: mixed, 1: mixed}> $pairs */
    public function __construct(public array $pairs)
    {
    }
}
