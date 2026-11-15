<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The KOReader plugin's "update over Wi-Fi": a manifest of the plugin folder
 * this server ships (config novarr.koreader_plugin_path) and its files.
 *
 * The build id is sha256 over "<path>\n<sha256>\n" for every file in sorted
 * order; the plugin computes the same value over its installed folder and
 * offers an update when the two differ. Only files listed in the manifest
 * are ever served: the requested path is looked up in that list, never
 * turned into a filesystem path first.
 */
class KoreaderPluginController extends Controller
{
    private const UNAVAILABLE = "The KOReader plugin isn't available on this server.";

    private const NOT_FOUND = "That file isn't part of the KOReader plugin.";

    /** GET /api/v1/koreader/plugin */
    public function manifest(): JsonResponse
    {
        $manifest = static::build((string) config('novarr.koreader_plugin_path'));
        if ($manifest === null) {
            return response()->json(['message' => self::UNAVAILABLE], 404);
        }

        return response()->json(['plugin' => [
            'version' => $manifest['version'],
            'build' => $manifest['build'],
            'size' => $manifest['size'],
            'files' => array_map(fn(array $f) => [
                'path' => $f['path'], 'size' => $f['size'], 'sha256' => $f['sha256'],
            ], $manifest['files']),
        ]]);
    }

    /** GET /api/v1/koreader/plugin/files/{path} */
    public function file(string $path): BinaryFileResponse|JsonResponse
    {
        $manifest = static::build((string) config('novarr.koreader_plugin_path'));
        if ($manifest === null) {
            return response()->json(['message' => self::UNAVAILABLE], 404);
        }

        // Cheap early refusals; the manifest lookup below is what actually
        // decides (a path not in the list is never served).
        if ($path === '' || str_contains($path, '\\') || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            return response()->json(['message' => self::NOT_FOUND], 404);
        }

        foreach ($manifest['files'] as $f) {
            if ($f['path'] === $path) {
                return response()->file($f['absolute'], ['Content-Type' => 'application/octet-stream']);
            }
        }

        return response()->json(['message' => self::NOT_FOUND], 404);
    }

    /**
     * The manifest of a plugin folder, or null when the folder is missing.
     *
     * @return array{version: ?string, build: string, size: int, files: list<array{path: string, size: int, sha256: string, absolute: string}>}|null
     */
    public static function build(string $root): ?array
    {
        $root = rtrim($root, '/');
        if ($root === '' || !is_dir($root)) {
            return null;
        }

        $files = [];
        static::collect($root, '', $files);
        usort($files, fn(array $a, array $b) => strcmp($a['path'], $b['path']));

        $size = 0;
        $joined = '';
        foreach ($files as $f) {
            $size += $f['size'];
            $joined .= $f['path'] . "\n" . $f['sha256'] . "\n";
        }

        $version = null;
        foreach ($files as $f) {
            if ($f['path'] === '_meta.lua'
                && preg_match('/\bversion\s*=\s*["\']([^"\']+)["\']/', (string) file_get_contents($f['absolute']), $m)) {
                $version = $m[1];
            }
        }

        return ['version' => $version, 'build' => hash('sha256', $joined), 'size' => $size, 'files' => $files];
    }

    /** Whether a file or folder name is left out of the plugin (OS and editor junk). */
    public static function skipped(string $name): bool
    {
        return str_starts_with($name, '.') || str_ends_with($name, '.tmp') || str_ends_with($name, '~');
    }

    /** Regular files under $dir (recursive, symlinks not followed), as relative paths with "/". */
    private static function collect(string $dir, string $prefix, array &$out): void
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..' || static::skipped($name)) {
                continue;
            }
            $absolute = $dir . '/' . $name;
            $relative = $prefix === '' ? $name : $prefix . '/' . $name;
            if (is_link($absolute)) {
                continue;
            }
            if (is_dir($absolute)) {
                static::collect($absolute, $relative, $out);
            } elseif (is_file($absolute)) {
                $out[] = [
                    'path' => $relative,
                    'size' => (int) filesize($absolute),
                    'sha256' => (string) hash_file('sha256', $absolute),
                    'absolute' => $absolute,
                ];
            }
        }
    }
}
