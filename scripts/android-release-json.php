<?php

/**
 * Writes the "<apk>.json" sidecar for `make release-android`:
 * {"version": "1.17", "build": 18, "notes": ["…", …]}, the notes being the
 * "## <version> (<build>)" section of android/CHANGELOG.md
 * (App\Support\AndroidChangelog). Refuses (exit 1, the reason on stderr)
 * when that section is missing, names another build, is empty or still
 * says "Notes to come".
 *
 *   php scripts/android-release-json.php <changelog> <version> <build> [<out>]
 *
 * Without <out> the JSON goes to stdout (the Makefile's dry run). Runs
 * without Laravel (no vendor/, no .env needed).
 */

require_once __DIR__ . '/../app/Support/AndroidChangelog.php';

use App\Support\AndroidChangelog;

if ($argc < 4 || $argc > 5) {
    fwrite(STDERR, "Usage: php scripts/android-release-json.php <changelog> <version> <build> [<out>]\n");
    exit(2);
}

[, $changelog, $version, $build] = $argv;
$out = $argv[4] ?? null;

if (!ctype_digit($build)) {
    fwrite(STDERR, "The build must be a whole number, got \"{$build}\".\n");
    exit(2);
}
$markdown = is_file($changelog) ? @file_get_contents($changelog) : false;
if ($markdown === false) {
    fwrite(STDERR, "Can't read the changelog at {$changelog}.\n");
    exit(1);
}

try {
    $notes = AndroidChangelog::notesFor($markdown, $version, (int) $build);
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$json = json_encode(
    ['version' => $version, 'build' => (int) $build, 'notes' => $notes],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
) . "\n";

if ($out === null) {
    echo $json;
    exit(0);
}
if (@file_put_contents($out, $json) === false) {
    fwrite(STDERR, "Can't write {$out}.\n");
    exit(1);
}
exit(0);
