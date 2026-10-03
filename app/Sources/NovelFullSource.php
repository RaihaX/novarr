<?php

namespace App\Sources;

use App\Novel;
use Symfony\Component\DomCrawler\Crawler;

/**
 * novelfull.com. Cloudflare-protected; full chapter list comes from its AJAX
 * chapter-option endpoint (keyed by the numeric data-novel-id). Covers are
 * fetchable with a plain client, and the novel page carries description,
 * author and genres, so it's mostly self-sufficient with NovelUpdates as a
 * fallback for anything missing.
 */
class NovelFullSource extends AbstractSource
{
    public function name(): string
    {
        return 'novelfull';
    }

    public function matches(Novel $novel): bool
    {
        return stripos($novel->translator_url ?? '', 'novelfull.com') !== false;
    }

    public function contentSelectors(): array
    {
        return ['#chapter-content', ...self::GENERIC_CONTENT_SELECTORS];
    }

    public function removeSelectors(): array
    {
        return [
            ...self::GENERIC_REMOVE_SELECTORS,
            '#chapter-content > div[align="center"]', // ad slot above the text
            '#chapter-content > div[align="left"]',   // "report chapter" notice
            '#chapter-content > div[style]',          // inline ad boxes
            '.chapter-nav', '#chapter-nav-top', '#chapter-nav-bottom',
            '#chapter_error', '.chapter-end',
        ];
    }

    /**
     * Search results page (/search?keyword=…) → discover cards with cover
     * and author. Pure. Rows are .list-truyen .row blocks with an
     * h3.truyen-title link, an img.cover and a span.author.
     *
     * @return array<int, array{name: string, url: string, cover: string, author: string}>
     */
    public static function parseSearchResults(string $html, string $origin = 'https://novelfull.com'): array
    {
        $items = [];
        $abs = fn(string $u) => $u === '' ? '' : (preg_match('#^https?://#i', $u) ? $u : (str_starts_with($u, '//') ? 'https:' . $u : $origin . '/' . ltrim($u, '/')));

        (new Crawler($html))->filter('.list-truyen .row')->each(function (Crawler $row) use (&$items, $abs) {
            $link = $row->filter('h3.truyen-title a');
            if ($link->count() === 0) {
                return;
            }
            $href = trim($link->attr('href') ?? '');
            $name = trim($link->attr('title') ?: $link->text());
            if ($href === '' || $name === '' || !str_ends_with($href, '.html')) {
                return;
            }

            $img = $row->filter('img.cover');
            $cover = $img->count() ? trim($img->attr('src') ?: ($img->attr('data-src') ?? '')) : '';
            $author = $row->filter('span.author');

            $items[] = [
                'name' => $name,
                'url' => $abs($href),
                'cover' => $abs($cover),
                'author' => $author->count() ? trim(preg_replace('/\s+/u', ' ', $author->text())) : '',
            ];
        });

        return $items;
    }

    public function tableOfContents(Novel $novel): array
    {
        return novelFullToc($novel->translator_url);
    }

    public function metadata(Novel $novel): array
    {
        $nf = getMetadataFromNovelFull($novel->translator_url);
        $nu = getMetadata($novel); // NovelUpdates — fills gaps / richer genres

        // Prefer novelfull's own fields, fall back to NovelUpdates.
        foreach (['description', 'author', 'genres', 'no_of_chapters'] as $key) {
            if (empty($nf[$key]) && !empty($nu[$key])) {
                $nf[$key] = $nu[$key];
            }
        }
        // NovelUpdates' status wins when it has one; otherwise keep the
        // "Status:" the novel page itself reports (e.g. "Ongoing").
        $nf['status_text'] = ($nu['status_text'] ?? '') ?: ($nf['status_text'] ?? '');
        $nf['completed'] = ($nu['status_text'] ?? '') !== ''
            ? (bool) ($nu['completed'] ?? false)
            : (bool) ($nf['completed'] ?? false);
        $nf['fully_translated'] = $nu['fully_translated'] ?? null;

        // novelfull cover first (fetchable), NovelUpdates as fallback.
        $nf['cover_candidates'] = array_values(array_filter([$nf['image'] ?? null, $nu['image'] ?? null]));

        return $nf;
    }
}
