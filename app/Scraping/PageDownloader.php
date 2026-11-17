<?php

namespace App\Scraping;

use App\ChapterPage;
use App\NovelChapter;
use App\Sources\ReportsPageFetches;
use App\Sources\Source;
use App\Sources\SourceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Downloads every page image of a manga chapter, all or nothing.
 *
 * The chapter's source lists the pages (Source::chapterPages()); each image
 * is fetched with Fetcher::download() into a scratch directory on the
 * `manga` disk, then checked: a real image (getimagesize), an allowed type
 * (jpeg / png / webp / gif / avif), at most novarr.manga.max_page_bytes. Only
 * when every page passed is the chapter's directory replaced (rename, same
 * filesystem) and its chapter_pages rows rewritten in one transaction; a
 * failure anywhere leaves the previous download — files and rows — exactly
 * as it was.
 *
 * Failure categories (ChapterScraper's, so the usual backoff applies):
 *   rate_limited  — the page list or an image answered 429/503
 *   not_found     — the source has no such chapter (404 on the page list)
 *   no_content    — the source lists no pages for it
 *   fetch_failed  — the page list or an image could not be fetched at all
 *   partial_pages — an image was missing, not an image, or too big
 *
 * After a failed image (other than a rate limit) the page list is asked for
 * once more — MangaDex's image servers rotate and an answer may have gone
 * stale — and the remaining pages come from the new list.
 */
class PageDownloader
{
    public const MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/avif' => 'avif',
    ];

    public function download(NovelChapter $chapter): PageDownloadResult
    {
        $novel = $chapter->novel;
        $url = (string) chapterSourceUrl($chapter);
        $source = $novel ? SourceResolver::for($novel) : SourceResolver::forUrl($url);
        $chapterHost = self::host($url);

        $failure = null;
        $pages = $source->chapterPages($url, $failure);
        if ($pages === null) {
            return PageDownloadResult::failure('no_content', "{$source->name()} serves no page images");
        }
        if ($pages === []) {
            return PageDownloadResult::failure($failure ?: 'no_content', 'the source gave no page list' . ($failure ? " ({$failure})" : ''));
        }

        $disk = ChapterPage::disk();
        $root = rtrim($disk->path(''), '/');
        $scratch = '.tmp/' . $chapter->id . '-' . bin2hex(random_bytes(6));
        if (!$this->makeDirectory("{$root}/{$scratch}")) {
            return PageDownloadResult::failure('fetch_failed', 'could not create a scratch directory on the manga disk');
        }

        try {
            $result = $this->fetchAll($chapter, $source, $url, $pages, "{$root}/{$scratch}", $chapterHost);
            if (!$result['ok']) {
                return PageDownloadResult::failure($result['category'], $result['message']);
            }

            $this->replace($chapter, $root, $scratch, $result['rows']);

            return PageDownloadResult::success(count($result['rows']), array_sum(array_column($result['rows'], 'bytes')));
        } catch (\Throwable $e) {
            Log::error("PageDownloader: chapter {$chapter->id} failed: " . $e->getMessage());

            return PageDownloadResult::failure('fetch_failed', $e->getMessage());
        } finally {
            self::removeTree("{$root}/{$scratch}");
        }
    }

    /**
     * Fetch and check every page into $dir.
     *
     * @return array{ok: bool, category: ?string, message: ?string, rows: array<int, array>}
     */
    private function fetchAll(NovelChapter $chapter, Source $source, string $url, array $pages, string $dir, ?string $chapterHost): array
    {
        $fetcher = app(Fetcher::class);
        $maxBytes = max(1, (int) config('novarr.manga.max_page_bytes', 30 * 1024 * 1024));
        $refreshed = false;
        $rows = [];

        for ($i = 0; $i < count($pages); $i++) {
            if ($i > 0) {
                $this->pause((int) config('novarr.manga.page_delay_ms', 200));
            }

            $number = $i + 1;
            $page = $pages[$i];
            $tmp = "{$dir}/page-{$number}.part";
            $status = null;
            $meta = null;
            $ok = $fetcher->download((string) $page['url'], $tmp, (array) ($page['headers'] ?? []), $maxBytes, $status, $meta);

            $check = $ok ? self::inspect($tmp, $maxBytes) : null;
            $good = $ok && $check['ok'];
            if ($source instanceof ReportsPageFetches) {
                $source->reportPageFetch($page, $good, (int) ($meta['bytes'] ?? 0), (int) ($meta['ms'] ?? 0), (bool) ($meta['cached'] ?? false));
            }

            if ($good) {
                $file = ChapterPage::fileName($number, $check['ext']);
                rename($tmp, "{$dir}/{$file}");
                $rows[] = [
                    'page' => $number,
                    'file' => $file,
                    'mime' => $check['mime'],
                    'width' => $check['width'],
                    'height' => $check['height'],
                    'bytes' => $check['bytes'],
                ];
                continue;
            }
            @unlink($tmp);

            $reason = $ok ? $check['reason'] : (string) ($meta['reason'] ?? 'fetch_failed');
            $category = match ($reason) {
                'rate_limited' => 'rate_limited',
                'exception', 'http_5xx', 'empty_response' => 'fetch_failed',
                default => 'partial_pages',
            };

            if ($category === 'rate_limited') {
                $imageHost = self::host((string) $page['url']);
                if ($imageHost !== null && $chapterHost !== null && $imageHost !== $chapterHost) {
                    Fetcher::moveRetryAfter($imageHost, $chapterHost);
                }
            } elseif (!$refreshed) {
                // Ask for the page list again (a fresh image server) and go
                // on from this page with it.
                $refreshed = true;
                $failure = null;
                $fresh = $source->chapterPages($url, $failure);
                if (is_array($fresh) && count($fresh) === count($pages)) {
                    Log::info("PageDownloader: page {$number} of chapter {$chapter->id} failed ({$reason}); retrying with a fresh page list");
                    $pages = $fresh;
                    $i--;
                    continue;
                }
            }

            return [
                'ok' => false,
                'category' => $category,
                'message' => "page {$number} of " . count($pages) . " failed ({$reason})",
                'rows' => [],
            ];
        }

        return ['ok' => true, 'category' => null, 'message' => null, 'rows' => $rows];
    }

    /**
     * Is the file a page image Novarr keeps?
     *
     * @return array{ok: bool, reason: ?string, mime?: string, ext?: string, width?: int, height?: int, bytes?: int}
     */
    public static function inspect(string $path, int $maxBytes): array
    {
        clearstatcache(true, $path);
        $bytes = (int) @filesize($path);
        if ($bytes <= 0) {
            return ['ok' => false, 'reason' => 'empty_response'];
        }
        if ($bytes > $maxBytes) {
            return ['ok' => false, 'reason' => 'too_large'];
        }

        $info = @getimagesize($path);
        if (!is_array($info) || empty($info['mime'])) {
            return ['ok' => false, 'reason' => 'not_an_image'];
        }
        $mime = strtolower((string) $info['mime']);
        if (!isset(self::MIMES[$mime])) {
            return ['ok' => false, 'reason' => 'unsupported_type'];
        }

        return [
            'ok' => true,
            'reason' => null,
            'mime' => $mime,
            'ext' => self::MIMES[$mime],
            'width' => (int) $info[0] ?: null,
            'height' => (int) $info[1] ?: null,
            'bytes' => $bytes,
        ];
    }

    /**
     * Swap the checked pages in: the old directory is moved aside, the
     * scratch directory takes its place, and the rows are rewritten in one
     * transaction. If the rows can't be written the old directory comes back.
     */
    private function replace(NovelChapter $chapter, string $root, string $scratch, array $rows): void
    {
        $relative = ChapterPage::chapterDirectory((int) $chapter->novel_id, (int) $chapter->id);
        $final = "{$root}/{$relative}";
        $aside = "{$root}/.tmp/old-{$chapter->id}-" . bin2hex(random_bytes(6));

        if (!$this->makeDirectory(dirname($final))) {
            throw new \RuntimeException("could not create {$relative}'s parent on the manga disk");
        }

        $hadOld = is_dir($final);
        if ($hadOld && !rename($final, $aside)) {
            throw new \RuntimeException("could not move the previous pages of chapter {$chapter->id} aside");
        }
        if (!rename("{$root}/{$scratch}", $final)) {
            if ($hadOld) {
                rename($aside, $final);
            }
            throw new \RuntimeException("could not move the new pages of chapter {$chapter->id} into place");
        }

        try {
            DB::transaction(function () use ($chapter, $rows, $relative) {
                ChapterPage::where('novel_chapter_id', $chapter->id)->delete();
                $now = now();
                foreach (array_chunk($rows, 200) as $batch) {
                    ChapterPage::insert(array_map(fn(array $row) => [
                        'novel_chapter_id' => $chapter->id,
                        'page' => $row['page'],
                        'path' => "{$relative}/{$row['file']}",
                        'mime' => $row['mime'],
                        'width' => $row['width'],
                        'height' => $row['height'],
                        'bytes' => $row['bytes'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $batch));
                }
            });
        } catch (\Throwable $e) {
            // Put the previous download back as it was.
            self::removeTree($final);
            if ($hadOld) {
                rename($aside, $final);
            }
            throw $e;
        }

        if ($hadOld) {
            self::removeTree($aside);
        }
    }

    /** Wait between two image requests. Overridable so tests don't wait. */
    protected function pause(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    /** Lower-cased host of a URL, or null. */
    private static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function makeDirectory(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0755, true) || is_dir($path);
    }

    /** rm -rf for a directory we own (never follows symlinks). */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree("{$path}/{$entry}");
            }
        }
        @rmdir($path);
    }
}

