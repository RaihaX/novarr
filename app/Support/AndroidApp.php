<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * The Android app readers can install from this server (GET /app/android).
 * The APK is put at config novarr.android_apk_path out of band; an optional
 * sidecar "<apk>.json" holds {"version": "1.0", "build": 1}. Read from disk
 * on every call (no static state): Octane-safe, and a new APK shows up
 * without a restart.
 */
final class AndroidApp
{
    public const FILENAME = 'novarr.apk';

    public const MIME = 'application/vnd.android.package-archive';

    public static function path(): string
    {
        return (string) config('novarr.android_apk_path');
    }

    /** Whether an APK is there to download. */
    public static function available(): bool
    {
        $path = static::path();

        return $path !== '' && is_file($path) && is_readable($path) && filesize($path) > 0;
    }

    /** The download link on the origin $request came in on (like invite links), else the app URL. */
    public static function url(?Request $request = null): string
    {
        $relative = route('app.android', [], false);

        return $request !== null
            ? rtrim($request->getSchemeAndHttpHost(), '/') . $relative
            : route('app.android');
    }

    /** "Get the Android app (version 1.0, build 3)": the link text in the views. */
    public static function linkText(): string
    {
        $version = static::version();

        return 'Get the Android app' . ($version !== null ? " (version {$version})" : '');
    }

    /** "1.0" (or "1.0, build 3") from the sidecar, null when unknown. */
    public static function version(): ?string
    {
        $meta = static::meta();
        $version = isset($meta['version']) && is_scalar($meta['version']) ? trim((string) $meta['version']) : '';
        if ($version === '') {
            return null;
        }
        $build = isset($meta['build']) && is_scalar($meta['build']) ? trim((string) $meta['build']) : '';

        return $build !== '' ? "{$version}, build {$build}" : $version;
    }

    /** The sidecar's JSON object, [] when missing or unreadable. */
    private static function meta(): array
    {
        $file = static::path() . '.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }
}
