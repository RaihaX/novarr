<?php

namespace App\Services;

use App\Novel;
use App\Scraping\OriginInference;
use App\Sources\MangaDexSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * A manga's origin (Japanese manga / Korean manhwa / Chinese manhua),
 * stored through Novel::applyOrigin(…, 'source'):
 *
 *   1. MangaDex's `originalLanguage` (metadata `original_language`)  → high
 *   2. a deciding genre (Manga / Manhwa / Manhua, OriginInference::
 *      genreLanguage()) — Suwayomi has no language field            → medium
 *   3. a series not on MangaDex with neither: one MangaDex title search,
 *      accepted only on an exact normalised title / alt-title match and
 *      cached 30 days, "not found" included                         → medium
 *
 * Runs from the metadata refresh (novel:create, novel:metadata) and the
 * novel:origin command — never on a page load. With $network false (the
 * edit form's "Auto") only stored tags and an already cached lookup count.
 */
class MangaOrigin
{
    public const CACHE_DAYS = 30;
    private const CACHE_PREFIX = 'manga_origin_title_v1_';

    public function __construct(private MangaDexSource $mangadex)
    {
    }

    /** MangaDex language code → stored origin_language: zh-hk / zh-ro → zh, ja-ro → ja … */
    public static function languageCode(?string $code): ?string
    {
        $code = strtolower(trim((string) $code));
        if ($code === '') {
            return null;
        }
        $base = explode('-', $code)[0];

        return in_array($base, ['ja', 'ko', 'zh'], true) ? $base : $code;
    }

    /** Origin attributes for applyOrigin(), or null for no language. */
    public static function attributes(?string $language, string $confidence): ?array
    {
        $language = self::languageCode($language);
        if ($language === null) {
            return null;
        }

        return [
            'origin' => $language === 'en' ? OriginInference::ORIGINAL : OriginInference::TRANSLATED,
            'origin_language' => mb_substr($language, 0, 16),
            'confidence' => $confidence,
        ];
    }

    /**
     * From a fresh metadata() answer: original_language, else the genres,
     * else (not a MangaDex series) the title lookup. Returns the attributes
     * found (with a `reason`) or null; applies them unless $apply is false.
     */
    public function fromMetadata(Novel $novel, array $metadata, bool $network = true, bool $apply = true): ?array
    {
        $found = null;
        if (($attributes = self::attributes($metadata['original_language'] ?? null, OriginInference::HIGH)) !== null) {
            $found = $attributes + ['reason' => 'MangaDex original language'];
        } else {
            $found = $this->fromStored($novel, (array) ($metadata['genres'] ?? []), $network);
        }

        if ($found !== null && $apply) {
            $novel->applyOrigin($found, 'source');
        }

        return $found;
    }

    /**
     * From what is stored (the command, the edit form's "Auto"): a MangaDex
     * series asks MangaDex for its metadata (one request) when $network,
     * anything else uses its tags and the title lookup.
     */
    public function detect(Novel $novel, bool $network = false, bool $apply = true): ?array
    {
        if (!$novel->isManga()) {
            return null;
        }
        if ($network && MangaDexSource::isMangaDexHost($novel->translator_url)) {
            return $this->fromMetadata($novel, $this->mangadex->metadata($novel), $network, $apply);
        }

        $genres = $novel->relationLoaded('tags') ? $novel->tags->pluck('name')->all() : $novel->tags()->pluck('name')->all();
        $found = $this->fromStored($novel, $genres, $network);
        if ($found !== null && $apply) {
            $novel->applyOrigin($found, 'source');
        }

        return $found;
    }

    private function fromStored(Novel $novel, array $genres, bool $network): ?array
    {
        if (($attributes = self::attributes(OriginInference::genreLanguage($genres), OriginInference::MEDIUM)) !== null) {
            return $attributes + ['reason' => 'genre tag'];
        }
        // A MangaDex series is never looked up on MangaDex by title.
        if (MangaDexSource::isMangaDexHost($novel->translator_url)) {
            return null;
        }
        $language = $this->lookupTitle((string) $novel->name, $network);
        $attributes = self::attributes($language, OriginInference::MEDIUM);

        return $attributes !== null ? $attributes + ['reason' => 'MangaDex title match'] : null;
    }

    /**
     * The original language of the MangaDex series titled exactly $title
     * (normalised: case, punctuation and spacing ignored; main or alt
     * title), or null. Cached CACHE_DAYS days, a miss included; a failed
     * request is not cached. $network false answers from the cache only.
     */
    public function lookupTitle(string $title, bool $network = true): ?string
    {
        $wanted = self::normalise($title);
        if ($wanted === '') {
            return null;
        }
        $key = self::CACHE_PREFIX . md5($wanted);

        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('language', $cached)) {
            return $cached['language'];
        }
        if (!$network) {
            return null;
        }

        try {
            $entries = $this->mangadex->searchTitle($title);
        } catch (\Exception $e) {
            Log::warning('MangaOrigin: MangaDex title search failed: ' . $e->getMessage());
            $entries = null;
        }
        if ($entries === null) {
            return null;
        }

        $language = self::matchLanguage($entries, $wanted);
        Cache::put($key, ['language' => $language], now()->addDays(self::CACHE_DAYS));

        return $language;
    }

    /**
     * The originalLanguage of the entries whose title or alt title
     * normalises to $wanted; null when none match or the matches disagree.
     * Pure.
     */
    public static function matchLanguage(array $entries, string $wanted): ?string
    {
        $languages = [];
        foreach ($entries as $entry) {
            $attributes = (array) ($entry['attributes'] ?? []);
            $titles = array_values((array) ($attributes['title'] ?? []));
            foreach ((array) ($attributes['altTitles'] ?? []) as $alt) {
                $titles = array_merge($titles, array_values((array) $alt));
            }
            foreach ($titles as $candidate) {
                if (is_string($candidate) && self::normalise($candidate) === $wanted) {
                    $language = self::languageCode($attributes['originalLanguage'] ?? null);
                    if ($language !== null) {
                        $languages[$language] = true;
                    }
                    break;
                }
            }
        }

        return count($languages) === 1 ? (string) array_key_first($languages) : null;
    }

    /** Lowercase letters and digits of any script, single-spaced. Pure. */
    public static function normalise(string $title): string
    {
        $title = mb_strtolower(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? '');
    }
}
