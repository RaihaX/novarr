<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The Android app readers can install from this server (GET /app/android).
 * The APK is put at config novarr.android_apk_path out of band; an optional
 * sidecar "<apk>.json" holds {"version": "1.0", "build": 1, "notes": ["…"]}
 * (written by `make release-android` from android/CHANGELOG.md). Read from disk
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

    /**
     * The download link: on the public address when one is configured
     * (like invite links), else on the origin $request came in on, else the
     * app URL.
     */
    public static function url(?Request $request = null): string
    {
        $relative = route('app.android', [], false);
        $public = PublicAddress::url();
        if ($public !== null) {
            return $public . $relative;
        }

        return $request !== null
            ? rtrim($request->getSchemeAndHttpHost(), '/') . $relative
            : route('app.android');
    }

    /**
     * The app's page with "What's new" and the download button
     * (/app/android/whats-new), on the public address when one is
     * configured: the new version email's link (made in the console).
     */
    public static function pageUrl(): string
    {
        $relative = route('app.android.whats_new', [], false);
        $public = PublicAddress::url();

        return ($public ?? rtrim(url('/'), '/')) . $relative;
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

    /**
     * What an installed app needs to notice a newer APK (GET /api/v1/meta):
     * {version, build, url, size, sha256, notes}, or null when no APK is published.
     * `build` is the integer the app compares with its own version code; null
     * when the sidecar does not give one. `size` (bytes) and `sha256` (hex)
     * let the app check the file it downloads; sha256 is null when the file
     * can't be hashed. `notes` is the release notes of that version (a list
     * of strings, [] when the sidecar has none).
     */
    public static function release(?Request $request = null): ?array
    {
        if (!static::available()) {
            return null;
        }
        $meta = static::meta();
        $version = isset($meta['version']) && is_scalar($meta['version']) ? trim((string) $meta['version']) : '';
        $build = isset($meta['build']) && is_numeric($meta['build']) ? (int) $meta['build'] : null;

        return [
            'version' => $version !== '' ? $version : null,
            'build' => $build,
            'url' => static::url($request),
            'size' => (int) filesize(static::path()),
            'sha256' => static::sha256(),
            'notes' => static::notes(),
        ];
    }

    /** The published version's number ("1.17") from the sidecar, null when unknown. */
    public static function versionName(): ?string
    {
        $meta = static::meta();
        $version = isset($meta['version']) && is_scalar($meta['version']) ? trim((string) $meta['version']) : '';

        return $version !== '' ? $version : null;
    }

    /** The published build (version code) from the sidecar, null when unknown. */
    public static function build(): ?int
    {
        $meta = static::meta();

        return isset($meta['build']) && is_numeric($meta['build']) ? (int) $meta['build'] : null;
    }

    /**
     * The release notes of the published version (sidecar `notes`), cleaned
     * (App\Support\AndroidChangelog::clean): [] when there are none, as for
     * an APK published before release notes existed.
     *
     * @return list<string>
     */
    public static function notes(): array
    {
        return AndroidChangelog::clean(static::meta()['notes'] ?? null);
    }

    /**
     * The APK's SHA-256 (hex), null when it can't be read. Hashing a
     * multi-megabyte file on every meta call is too slow, so the hash is kept
     * in the cache under the file's path, mtime and size: replacing the APK
     * changes the key, and nothing lives in static state (Octane).
     */
    public static function sha256(): ?string
    {
        $path = static::path();
        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        $size = @filesize($path);
        if ($mtime === false || $size === false) {
            return null;
        }
        $key = 'android-apk-sha256:' . sha1($path . '|' . $mtime . '|' . $size);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $hash = @hash_file('sha256', $path);
        if (!is_string($hash) || $hash === '') {
            return null;
        }
        Cache::put($key, $hash, now()->addDays(30));

        return $hash;
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
