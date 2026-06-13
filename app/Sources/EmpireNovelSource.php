<?php

namespace App\Sources;

use App\Novel;

/**
 * empirenovel.com. Cloudflare-protected; the chapter list is paginated and
 * the site's own cover images can't be fetched server-side, so covers and
 * the richer description/genres come from NovelUpdates while the chapter
 * count comes from Empire Novel itself (the source we actually scrape).
 */
class EmpireNovelSource implements Source
{
    public function name(): string
    {
        return 'empirenovel';
    }

    public function matches(Novel $novel): bool
    {
        return stripos($novel->translator_url ?? '', 'empirenovel.com') !== false;
    }

    public function tableOfContents(Novel $novel): array
    {
        return empireNovelToc($novel->translator_url);
    }

    public function metadata(Novel $novel): array
    {
        $en = getMetadataFromEmpireNovel($novel->translator_url);
        $nu = getMetadata($novel); // NovelUpdates by name — richer desc/genres + a fetchable cover

        if (!empty($en['no_of_chapters'])) {
            $nu['no_of_chapters'] = $en['no_of_chapters'];
        }
        foreach (['description', 'author', 'genres'] as $key) {
            if (empty($nu[$key]) && !empty($en[$key])) {
                $nu[$key] = $en[$key];
            }
        }

        // NovelUpdates cover first (fetchable), Empire Novel's own as last resort.
        $nu['cover_candidates'] = array_values(array_filter([$nu['image'] ?? null, $en['image'] ?? null]));

        return $nu;
    }
}
