<?php

namespace App\Sources;

use App\Novel;

/**
 * novelfull.com. Cloudflare-protected; full chapter list comes from its AJAX
 * chapter-option endpoint (keyed by the numeric data-novel-id). Covers are
 * fetchable with a plain client, and the novel page carries description,
 * author and genres, so it's mostly self-sufficient with NovelUpdates as a
 * fallback for anything missing.
 */
class NovelFullSource implements Source
{
    public function name(): string
    {
        return 'novelfull';
    }

    public function matches(Novel $novel): bool
    {
        return stripos($novel->translator_url ?? '', 'novelfull.com') !== false;
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
        $nf['status_text'] = $nu['status_text'] ?? '';
        $nf['completed'] = $nu['completed'] ?? false;
        $nf['fully_translated'] = $nu['fully_translated'] ?? null;

        // novelfull cover first (fetchable), NovelUpdates as fallback.
        $nf['cover_candidates'] = array_values(array_filter([$nf['image'] ?? null, $nu['image'] ?? null]));

        return $nf;
    }
}
