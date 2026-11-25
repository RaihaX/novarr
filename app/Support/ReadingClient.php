<?php

namespace App\Support;

use App\ApiToken;
use App\Http\Middleware\IdentifyReader;
use App\Services\ReadingPositions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where a read came from: the visitor's address, the device's name, a short
 * description of the client and whether it came in on the public address.
 * Stamped on a chapter_reads row when the chapter is marked read
 * (App\Services\ReadState) and on the series' reading position
 * (App\Services\ReadingPositions), for the admin's Readers pages.
 *
 *   ip      $request->ip(): the visitor's address through TRUSTED_PROXIES,
 *           as App\AuthEvent::record() resolves it
 *   device  the name the client declared (X-Novarr-Device-Name header,
 *           `device_name` field or cookie; ReadingPositions::device()), else
 *           the name of the bearer token the request signed in with
 *   client  DeviceDescription of the User-Agent ("KOReader", "Firefox on
 *           macOS"); null when nothing matched
 *   public  PublicAddress::isPublicRequest()
 *
 * Built from the request in the controller action (never kept on a
 * service: Octane). Immutable. Describing the client never fails a read
 * write: from() returns null on any error.
 */
final class ReadingClient
{
    public const MAX_DEVICE = 120;

    public const MAX_CLIENT = 60;

    public function __construct(
        public readonly ?string $ip,
        public readonly ?string $device,
        public readonly ?string $client,
        public readonly bool $public = false,
    ) {
    }

    public static function from(?Request $request): ?self
    {
        if ($request === null) {
            return null;
        }

        try {
            $device = app(ReadingPositions::class)->device($request)['name'] ?? null;
            if ($device === null) {
                $token = $request->attributes->get(IdentifyReader::TOKEN_ATTRIBUTE);
                $device = $token instanceof ApiToken ? $token->name : null;
            }
            $client = DeviceDescription::from($request->userAgent());

            return new self(
                ip: self::clip($request->ip(), 45),
                device: self::clip($device, self::MAX_DEVICE),
                client: $client === DeviceDescription::UNKNOWN ? null : self::clip($client, self::MAX_CLIENT),
                public: PublicAddress::isPublicRequest($request),
            );
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /** The four chapter_reads columns. */
    public function columns(): array
    {
        return ['ip' => $this->ip, 'device' => $this->device, 'client' => $this->client, 'public' => $this->public];
    }

    /** columns() of $client, or the empty stamp (nulls, not public) without one. */
    public static function columnsOf(?self $client): array
    {
        return $client?->columns() ?? ['ip' => null, 'device' => null, 'client' => null, 'public' => false];
    }

    private static function clip(?string $value, int $max): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : Str::limit($value, $max, '');
    }
}
