<?php

namespace App\Sources;

use App\Novel;
use App\Scraping\FailureSnapshot;
use App\Scraping\Fetcher;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Novel Arrow (formerly Novel Bin) — and the default source for anything not
 * matched elsewhere. TOC comes from the site's JSON API (the page only embeds
 * ~30 chapters), with a generic page-parse fallback for unrecognised sites.
 * Metadata is NovelUpdates first, Novel Arrow as fallback.
 */
class NovelArrowSource extends AbstractSource
{
    public function name(): string
    {
        return 'novelarrow';
    }

    public function matches(Novel $novel): bool
    {
        // Default source — handles novelarrow and anything unrecognised.
        return true;
    }

    public function contentSelectors(): array
    {
        return ['#chr-content', '.chr-c', ...self::GENERIC_CONTENT_SELECTORS];
    }

    public function removeSelectors(): array
    {
        return [
            ...self::GENERIC_REMOVE_SELECTORS,
            '.chr-nav', '#chr-nav-top', '#chr-nav-bottom', '.chr-nav-top', '.chr-nav-bottom',
            '.chapter-nav', '.report-chapter', '.ads', '.ad-container', '.share-buttons',
        ];
    }

    public function tableOfContents(Novel $novel): array
    {
        // The complete list lives behind a JSON API keyed by the slug.
        if ($novel->group_id == 1 && preg_match('/novelarrow|novelbin/i', $novel->translator_url ?? '')) {
            $result = novelArrowChapterArchive($novel->translator_url);
            if (!empty($result)) {
                return $result;
            }
            \Log::warning("NovelArrowSource: chapter list empty for {$novel->translator_url}; falling back to page parse");
            // The page only embeds the newest ~30 chapters: whatever the
            // fallback finds is a partial list for TOC health purposes.
            markTocRunPartial();
        }

        // Generic page parse (the novel page's embedded chapter list).
        $result = [];
        $reason = null;
        $html = app(Fetcher::class)->html($novel->translator_url, $reason, '.list-chapter');
        if ($html !== null) {
            (new Crawler($html))->filter('.list-chapter > li > a')->each(function ($node) use (&$result) {
                $result[] = generateTocChapterInfo($node->text(), trim($node->attr('href')));
            });
            if (array_filter($result) === []) {
                FailureSnapshot::noteTocHtml($html);
            }
        }

        return $result;
    }

    public function metadata(Novel $novel): array
    {
        $metadata = getMetadata($novel); // NovelUpdates
        $metadata['cover_candidates'] = array_values(array_filter([$metadata['image'] ?? null]));

        $needsFallback = empty($metadata['image']) || empty($metadata['description'])
            || empty($metadata['author']) || empty($metadata['no_of_chapters']);

        if ($needsFallback) {
            $fallback = getMetadataFromNovelArrow($novel);
            if (!empty($fallback['image'])) {
                $metadata['cover_candidates'][] = $fallback['image'];
            }
            foreach (['description', 'author', 'no_of_chapters', 'image', 'genres'] as $key) {
                if (empty($metadata[$key]) && !empty($fallback[$key])) {
                    $metadata[$key] = $fallback[$key];
                }
            }
        }

        // NovelUpdates carries no status (or wasn't matched): use the
        // source's own novel_status. Fetched separately when the fallback
        // above didn't run, but only then — a cheap single API call.
        if (empty($metadata['status_text'])) {
            $fallback ??= getMetadataFromNovelArrow($novel);
            if (!empty($fallback['status_text'])) {
                $metadata['status_text'] = $fallback['status_text'];
                $metadata['completed'] = (bool) ($fallback['completed'] ?? false);
            }
        }

        // Always keep the source site's own cover as a last-resort candidate —
        // it's deterministic by slug, and NovelUpdates' CDN can 403 hotlinked
        // downloads even when its metadata is otherwise complete (so the
        // fallback above never runs).
        if ($slug = $this->coverSlug($novel)) {
            $metadata['cover_candidates'][] = "https://images.novelarrow.com/novel/{$slug}.jpg";
        }
        $metadata['cover_candidates'] = array_values(array_unique($metadata['cover_candidates']));

        return $metadata;
    }

    /**
     * Best Novel Arrow slug for this novel: the novel URL when it's already a
     * Novel Arrow one, else the slug embedded in its own chapters' URLs (the
     * definitive source identity), else a guess from the name.
     */
    private function coverSlug(Novel $novel): ?string
    {
        if (!empty($novel->translator_url) && preg_match('/novelarrow|novelbin/i', $novel->translator_url)) {
            $slug = novelArrowSlug($novel->translator_url);
            if ($slug !== '') {
                return $slug;
            }
        }

        $chapterUrl = $novel->chapters()
            ->where('url', 'like', '%novelarrow%')
            ->value('url');
        if ($chapterUrl) {
            $slug = novelArrowSlug($chapterUrl);
            if ($slug !== '') {
                return $slug;
            }
        }

        return !empty($novel->name) ? novelSlug($novel->name) : null;
    }
}
