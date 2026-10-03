<?php

namespace App\Scraping;

use App\Novel;
use App\NovelChapter;
use Carbon\Carbon;

/**
 * Gzipped copies of pages the scraper fetched but could not use (empty or
 * short chapter, empty TOC), so a markup change can be diagnosed from what
 * the site actually served instead of re-fetching it.
 *
 * Files: {path}/{novel_id}/{chapter_id}-{reason}-{Ymd_His}.html.gz
 * (chapter_id 0 for table-of-contents snapshots). The newest
 * keep_per_novel files per novel are kept and anything older than `days`
 * is pruned on every store.
 */
class FailureSnapshot
{
    public const NAME_PATTERN = '/^[0-9]+-[a-z_]+-[0-9_]+\.html\.gz$/';

    /** HTML of the last TOC page that parsed to nothing (see noteTocHtml()). */
    private static ?string $pendingTocHtml = null;

    /**
     * Settings page (snapshots_enabled) first, then config. The setting is
     * stored as '0'/'1'; filter_var also copes with 'false'/'off' strings.
     */
    public static function enabled(): bool
    {
        $value = setting('snapshots_enabled', config('novarr.snapshots.enabled', true));

        return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /** Newest snapshots kept per novel (Settings page, then config). */
    public static function keepPerNovel(): int
    {
        return max(0, (int) setting('snapshots_keep_per_novel', config('novarr.snapshots.keep_per_novel', 5)));
    }

    /** Retention window in days (Settings page, then config). */
    public static function days(): int
    {
        return max(0, (int) setting('snapshots_days', config('novarr.snapshots.days', 14)));
    }

    public static function root(): string
    {
        return rtrim((string) (config('novarr.snapshots.path') ?: storage_path('app/snapshots')), '/');
    }

    public static function directory(int $novelId): string
    {
        return self::root() . '/' . $novelId;
    }

    /**
     * Store a snapshot. Returns the file path, or null when disabled, the
     * HTML is empty, the novel is unsaved or the write failed (never throws).
     */
    public static function store(Novel $novel, ?NovelChapter $chapter, string $html, string $reason): ?string
    {
        if (!self::enabled() || trim($html) === '' || empty($novel->id)) {
            return null;
        }

        try {
            $reason = trim(preg_replace('/[^a-z_]+/', '_', strtolower($reason)), '_') ?: 'unknown';
            $dir = self::directory((int) $novel->id);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("cannot create {$dir}");
            }

            $name = sprintf('%d-%s-%s.html.gz', (int) ($chapter?->id ?? 0), $reason, Carbon::now()->format('Ymd_His'));
            $path = $dir . '/' . $name;
            if (file_put_contents($path, gzencode($html, 6)) === false) {
                throw new \RuntimeException("cannot write {$path}");
            }

            self::prune((int) $novel->id);
            \Log::info("FailureSnapshot stored {$path} ({$reason})");

            return is_file($path) ? $path : null;
        } catch (\Throwable $e) {
            \Log::warning('FailureSnapshot store failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Delete snapshots captured before the retention window, then all but
     * the newest keep_per_novel.
     */
    public static function prune(int $novelId): void
    {
        $keep = self::keepPerNovel();
        $cutoff = Carbon::now()->subDays(self::days())->getTimestamp();

        $files = self::files($novelId);
        foreach ($files as $i => $file) {
            if ($i >= $keep || $file['time']->getTimestamp() < $cutoff) {
                @unlink($file['path']);
            }
        }
    }

    /**
     * Snapshots for a novel, newest first.
     *
     * @return array<int, array{file: string, path: string, chapter_id: int, reason: string, time: Carbon, mtime: int, size: int}>
     */
    public static function files(int $novelId): array
    {
        $dir = self::directory($novelId);
        if (!is_dir($dir)) {
            return [];
        }

        $out = [];
        foreach (scandir($dir) ?: [] as $file) {
            if (!self::validName($file)) {
                continue;
            }
            $path = $dir . '/' . $file;
            preg_match('/^(\d+)-([a-z_]+)-(\d{8}_\d{6})\.html\.gz$/', $file, $m);
            $time = isset($m[3]) ? Carbon::createFromFormat('Ymd_His', $m[3]) : Carbon::createFromTimestamp(filemtime($path));
            $out[] = [
                'file' => $file,
                'path' => $path,
                'chapter_id' => (int) ($m[1] ?? 0),
                'reason' => $m[2] ?? 'unknown',
                'time' => $time,
                'mtime' => (int) filemtime($path),
                'size' => (int) filesize($path),
            ];
        }

        // Newest first by capture time (from the name, which is written with
        // the app clock), then name.
        usort($out, fn($a, $b) => [$b['time']->getTimestamp(), $b['file']] <=> [$a['time']->getTimestamp(), $a['file']]);

        return $out;
    }

    public static function validName(string $file): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $file);
    }

    /**
     * Gunzipped HTML of one snapshot, or null when the name is invalid or
     * the file does not exist inside the novel's snapshot directory.
     */
    public static function read(int $novelId, string $file): ?string
    {
        if (!self::validName($file)) {
            return null;
        }

        $dir = realpath(self::directory($novelId));
        $path = $dir ? realpath($dir . '/' . $file) : false;
        if ($path === false || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return null;
        }

        $html = @gzdecode((string) file_get_contents($path));

        return $html === false ? null : $html;
    }

    /**
     * TOC helpers only see a URL, not the Novel; when they come back empty
     * with a page in hand they note it here and tableOfContentGenerator()
     * stores it against the novel.
     */
    public static function noteTocHtml(?string $html): void
    {
        self::$pendingTocHtml = ($html === null || trim($html) === '') ? null : $html;
    }

    public static function takeTocHtml(): ?string
    {
        $html = self::$pendingTocHtml;
        self::$pendingTocHtml = null;

        return $html;
    }
}
