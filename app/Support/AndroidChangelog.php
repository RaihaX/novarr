<?php

namespace App\Support;

/**
 * Reads android/CHANGELOG.md: sections headed "## <version> (<build>)",
 * newest first, each a bullet list ("- …" or "* …"; an indented line
 * continues the bullet above it). The release notes of one version are
 * that section's bullets.
 *
 * Plain PHP with no framework calls: scripts/android-release-json.php
 * (run by `make release-android` on the build machine) loads this file on
 * its own, without booting Laravel.
 */
final class AndroidChangelog
{
    /** The line the lead leaves in a section whose notes are not written yet. */
    public const PLACEHOLDER = 'Notes to come';

    /** At most this many notes, each at most MAX_NOTE characters (the email, the app's prompt). */
    public const MAX_NOTES = 20;

    public const MAX_NOTE = 300;

    /**
     * Every section of $markdown in file order.
     *
     * @return list<array{version: string, build: ?int, notes: list<string>}>
     */
    public static function parse(string $markdown): array
    {
        $sections = [];
        $current = null;
        $lines = preg_split('/\R/', $markdown) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(?:v(?=\d))?([0-9][0-9A-Za-z.\-]*)\s*(?:\((?:build\s*)?(\d+)\))?\s*$/', $line, $m)) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = ['version' => $m[1], 'build' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null, 'notes' => []];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^#{1,2}\s/', $line)) {
                // Any other heading ends the section.
                $sections[] = $current;
                $current = null;
                continue;
            }
            if (preg_match('/^\s{0,3}[-*]\s+(.*)$/', $line, $m)) {
                $current['notes'][] = trim($m[1]);
            } elseif (trim($line) !== '' && preg_match('/^\s+\S/', $line) && $current['notes'] !== []) {
                // An indented line continues the bullet above it.
                $last = count($current['notes']) - 1;
                $current['notes'][$last] = trim($current['notes'][$last] . ' ' . trim($line));
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }

        foreach ($sections as &$section) {
            $section['notes'] = self::clean($section['notes']);
        }

        return $sections;
    }

    /**
     * The notes for $version (and $build, when given) in $markdown.
     * Throws \RuntimeException, with a message saying what to fix, when the
     * section is missing, names another build, is empty or still says
     * "Notes to come".
     *
     * @return list<string>
     */
    public static function notesFor(string $markdown, string $version, ?int $build = null): array
    {
        $section = null;
        foreach (self::parse($markdown) as $candidate) {
            if ($candidate['version'] === $version) {
                $section = $candidate;
                break;
            }
        }

        $heading = '## ' . $version . ($build !== null ? " ({$build})" : '');
        if ($section === null) {
            throw new \RuntimeException("android/CHANGELOG.md has no section for {$version}: add \"{$heading}\" with the release notes.");
        }
        if ($build !== null && $section['build'] !== null && $section['build'] !== $build) {
            throw new \RuntimeException("android/CHANGELOG.md says {$version} is build {$section['build']}, but build.gradle.kts has build {$build}: fix the heading to \"{$heading}\".");
        }
        if ($section['notes'] === []) {
            throw new \RuntimeException("android/CHANGELOG.md has no notes under \"{$heading}\": add a bullet list of what's new.");
        }
        foreach ($section['notes'] as $note) {
            if (self::isPlaceholder($note)) {
                throw new \RuntimeException("android/CHANGELOG.md still says \"" . self::PLACEHOLDER . "\" under \"{$heading}\": write the release notes first.");
            }
        }

        return $section['notes'];
    }

    /** "Notes to come" (any case, with or without a full stop or more words after it). */
    public static function isPlaceholder(string $note): bool
    {
        return (bool) preg_match('/^\W*notes to come\b/i', trim($note));
    }

    /**
     * Notes as shown anywhere: strings only, trimmed, no blanks, Markdown
     * emphasis and code marks dropped, each and the list capped.
     *
     * @return list<string>
     */
    public static function clean(mixed $notes): array
    {
        if (!is_array($notes)) {
            return [];
        }
        $clean = [];
        foreach ($notes as $note) {
            if (!is_string($note)) {
                continue;
            }
            $note = trim(preg_replace('/\s+/u', ' ', str_replace(['**', '__', '`'], '', $note)) ?? '');
            if ($note === '') {
                continue;
            }
            if (mb_strlen($note) > self::MAX_NOTE) {
                $note = rtrim(mb_substr($note, 0, self::MAX_NOTE - 1)) . '…';
            }
            $clean[] = $note;
            if (count($clean) >= self::MAX_NOTES) {
                break;
            }
        }

        return $clean;
    }
}
