<?php

namespace App\Support;

/**
 * A short description of a device from its User-Agent ("Firefox on macOS",
 * "Safari on iPhone", "KOReader"), for the sign-in history, the browsers
 * list and the new-sign-in email. Deliberately simple: a few patterns, no
 * library, "Unknown device" when nothing matches. Pure function (no state).
 */
final class DeviceDescription
{
    public const UNKNOWN = 'Unknown device';

    public static function from(?string $userAgent): string
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return self::UNKNOWN;
        }

        // Novarr's own clients and e-readers first: they say who they are.
        foreach ([
            '/KOReader/i' => 'KOReader',
            '/^Novarr(-| )?iOS|^Novarr.*(iPhone|iPad|iOS|CFNetwork)/i' => 'Novarr app on iOS',
            '/^Novarr(-| )?Android|^Novarr.*Android|okhttp/i' => 'Novarr app on Android',
            '/^Novarr/i' => 'Novarr app',
            '/^curl\//i' => 'curl',
        ] as $pattern => $name) {
            if (preg_match($pattern, $ua)) {
                return $name;
            }
        }

        $browser = match (true) {
            (bool) preg_match('/Edg(e|A|iOS)?\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser\//', $ua) => 'Samsung Internet',
            (bool) preg_match('/Firefox\/|FxiOS\//', $ua) => 'Firefox',
            (bool) preg_match('/Chrome\/|CriOS\//', $ua) => 'Chrome',
            (bool) preg_match('/Safari\//', $ua) && (bool) preg_match('/Version\//', $ua) => 'Safari',
            (bool) preg_match('/Kindle|Silk\//', $ua) => 'Kindle browser',
            default => null,
        };

        $os = match (true) {
            (bool) preg_match('/iPad/', $ua) => 'iPad',
            (bool) preg_match('/iPhone|iPod/', $ua) => 'iPhone',
            (bool) preg_match('/Android/', $ua) => 'Android',
            (bool) preg_match('/CrOS/', $ua) => 'ChromeOS',
            (bool) preg_match('/Windows/', $ua) => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X/', $ua) => 'macOS',
            (bool) preg_match('/Kindle/', $ua) => 'Kindle',
            (bool) preg_match('/Linux/', $ua) => 'Linux',
            default => null,
        };

        if ($browser !== null && $os !== null) {
            return "{$browser} on {$os}";
        }
        if ($browser !== null || $os !== null) {
            return (string) ($browser ?? $os);
        }

        return self::UNKNOWN;
    }
}
