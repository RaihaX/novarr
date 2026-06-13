<?php

namespace App\Sources;

use App\Novel;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Novel Bin — and the default source for anything not matched elsewhere.
 * TOC comes from the AJAX chapter archive (the page only embeds ~30), with a
 * page-parse fallback. Metadata is NovelUpdates first, Novel Bin as fallback.
 */
class NovelBinSource implements Source
{
    public function name(): string
    {
        return 'novelbin';
    }

    public function matches(Novel $novel): bool
    {
        // Default source — handles novelbin and anything unrecognised.
        return true;
    }

    public function tableOfContents(Novel $novel): array
    {
        // The complete list lives behind an AJAX endpoint keyed by the slug.
        if ($novel->group_id == 1 && stripos($novel->translator_url ?? '', 'novelbin') !== false) {
            $result = novelBinChapterArchive($novel->translator_url);
            if (!empty($result)) {
                return $result;
            }
            \Log::warning("NovelBinSource: archive empty for {$novel->translator_url}; falling back to page parse");
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
            $fallback = getMetadataFromNovelBin($novel);
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
