<?php

namespace App\Sources;

use App\Novel;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Novel Arrow (formerly Novel Bin) — and the default source for anything not
 * matched elsewhere. TOC comes from the site's JSON API (the page only embeds
 * ~30 chapters), with a generic page-parse fallback for unrecognised sites.
 * Metadata is NovelUpdates first, Novel Arrow as fallback.
 */
class NovelArrowSource implements Source
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

    public function tableOfContents(Novel $novel): array
    {
        // The complete list lives behind a JSON API keyed by the slug.
        if ($novel->group_id == 1 && preg_match('/novelarrow|novelbin/i', $novel->translator_url ?? '')) {
            $result = novelArrowChapterArchive($novel->translator_url);
            if (!empty($result)) {
                return $result;
            }
            \Log::warning("NovelArrowSource: chapter list empty for {$novel->translator_url}; falling back to page parse");
        }

        // Generic page parse (the novel page's embedded chapter list).
        $result = [];
        $html = fetchWithBrowser($novel->translator_url, '.list-chapter');
        if ($html !== null) {
            (new Crawler($html))->filter('.list-chapter > li > a')->each(function ($node) use (&$result) {
                $result[] = generateTocChapterInfo($node->text(), trim($node->attr('href')));
            });
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

        return $metadata;
    }
}
