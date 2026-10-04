<?php

namespace App\Scraping;

/**
 * Translated web novel or original English one? Pure helpers, no I/O.
 *
 * Two routes in:
 *
 *   fromNovelUpdates()  NovelUpdates' series page names the original
 *                       language (#showlang) and type (#showtype) — the
 *                       authority when the novel has a confident match.
 *   infer()             Weak signals for everything else: the author's name,
 *                       translator voice in the first chapters, the TOC
 *                       labels, the source host. NovelUpdates only indexes
 *                       translations, so a Latin-script author with no
 *                       translator voice and no NovelUpdates match reads as
 *                       an English original.
 *
 * Results are ['origin' => translated|original|unknown, 'origin_language'
 * => ?code, ...]; precedence (manual > novelupdates > inferred) is applied by
 * Novel::applyOrigin().
 */
class OriginInference
{
    public const TRANSLATED = 'translated';
    public const ORIGINAL = 'original';
    public const UNKNOWN = 'unknown';

    /** NovelUpdates' language word => code. Anything else maps to null. */
    public const LANGUAGE_CODES = [
        'chinese' => 'zh',
        'korean' => 'ko',
        'japanese' => 'ja',
        'english' => 'en',
        'vietnamese' => 'vi',
        'thai' => 'th',
        'indonesian' => 'id',
        'filipino' => 'tl',
        'spanish' => 'es',
    ];

    /** NovelUpdates' "(XX)" type suffix => code, for a page with no #showlang. */
    private const TYPE_CODES = [
        'CN' => 'zh', 'KR' => 'ko', 'JP' => 'ja', 'EN' => 'en',
        'VN' => 'vi', 'TH' => 'th', 'ID' => 'id', 'FIL' => 'tl', 'ES' => 'es',
    ];

    /** Code => display name (originLabel). */
    public const LANGUAGE_NAMES = [
        'zh' => 'Chinese',
        'ko' => 'Korean',
        'ja' => 'Japanese',
        'en' => 'English',
        'vi' => 'Vietnamese',
        'th' => 'Thai',
        'id' => 'Indonesian',
        'tl' => 'Filipino',
        'es' => 'Spanish',
    ];

    /** Sites that only publish original English fiction. */
    private const ORIGINAL_HOSTS = ['royalroad.com', 'scribblehub.com'];

    /**
     * Translator voice: the author-note classifier's speaker prefix (the
     * translator subset of ChapterScraper::looksLikeAuthorMessage) plus
     * phrases only a translation carries.
     */
    private const TRANSLATOR_SPEAKER = '/^\s*(?:translator|tl|tn|t\/n)\s*[:：]/imu';
    private const TRANSLATOR_PHRASES = '/translator[\'’]?s\s+notes?|\btranslated\s+by\b|\braws\b|machine\s+translat|\bMTL\b/iu';

    public static function languageCode(?string $word): ?string
    {
        $word = mb_strtolower(trim((string) $word));

        return self::LANGUAGE_CODES[$word] ?? null;
    }

    public static function languageName(?string $code): ?string
    {
        return self::LANGUAGE_NAMES[$code ?? ''] ?? null;
    }

    /**
     * Origin attributes from a parsed NovelUpdates page (fetchNovelUpdatesMetadata's
     * 'type' and 'original_language'), or null when the page carried neither.
     *
     * @return array{origin: string, origin_language: ?string, origin_type: ?string}|null
     */
    public static function fromNovelUpdates(array $metadata): ?array
    {
        $type = self::squash($metadata['type'] ?? '');
        $word = self::squash($metadata['original_language'] ?? '');

        if ($type === '' && $word === '') {
            return null;
        }

        $code = self::languageCode($word);
        if ($code === null && $word === '' && preg_match('/\(([A-Z]{2,3})\)\s*$/', $type, $m)) {
            $code = self::TYPE_CODES[$m[1]] ?? null;
        }

        // An unmapped language word is kept with the type so it isn't lost.
        $originType = $type;
        if ($code === null && $word !== '') {
            $originType = $type !== '' ? "{$type} · {$word}" : $word;
        }

        return [
            'origin' => $code === 'en' ? self::ORIGINAL : self::TRANSLATED,
            'origin_language' => $code,
            'origin_type' => $originType !== '' ? mb_substr($originType, 0, 32) : null,
        ];
    }

    /**
     * Best guess from weak signals, in order:
     *
     *   1. author name in Han / Hangul / Kana     → translated, that language
     *   2. translator voice in chapters or TOC    → translated (language from
     *                                               any CJK in the samples)
     *   3. a pinyin-only author ("Er Gen")        → unknown (likely Chinese,
     *                                               but too weak to call)
     *   4. original-fiction host (Royal Road …)   → original, en
     *   5. Latin-only author, no NovelUpdates match → original, en
     *   6. anything else                          → unknown
     *
     * @param string[] $chapterTexts up to 3 chapter bodies (only the first 400 chars are read)
     * @param string[] $tocLabels
     * @param bool     $hasNovelUpdatesMatch a confident NovelUpdates match exists
     * @param ?float   $novelUpdatesScore    best NovelUpdates score seen; a near miss (≥ 0.6)
     *                                       means a translation probably exists under another name
     * @return array{origin: string, origin_language: ?string, reason: string}
     */
    public static function infer(
        ?string $author,
        ?string $host = null,
        array $chapterTexts = [],
        array $tocLabels = [],
        bool $hasNovelUpdatesMatch = false,
        ?float $novelUpdatesScore = null,
    ): array {
        $author = trim((string) $author);

        if ($lang = self::scriptLanguage($author)) {
            return self::result(self::TRANSLATED, $lang, 'author name is written in ' . self::languageName($lang) . ' script');
        }

        $samples = array_map(
            fn($text) => mb_substr(self::plain((string) $text), 0, 400),
            array_slice(array_values($chapterTexts), 0, 3)
        );
        $sampleText = implode("\n", $samples);
        $labelText = implode("\n", array_map(fn($l) => (string) $l, $tocLabels));

        if (preg_match(self::TRANSLATOR_SPEAKER, $sampleText) === 1
            || preg_match(self::TRANSLATOR_PHRASES, $sampleText . "\n" . $labelText) === 1) {
            return self::result(self::TRANSLATED, self::scriptLanguage($sampleText . "\n" . $labelText), 'translator notes in the chapters');
        }

        if ($author !== '' && self::looksLikePinyin($author)) {
            return self::result(self::UNKNOWN, null, 'author name reads as pinyin');
        }

        $host = strtolower(preg_replace('/^www\./i', '', trim((string) $host)));
        if ($host !== '' && in_array($host, self::ORIGINAL_HOSTS, true)) {
            return self::result(self::ORIGINAL, 'en', "{$host} only hosts original fiction");
        }

        $nearMiss = $novelUpdatesScore !== null && $novelUpdatesScore >= 0.6;
        if ($author !== '' && !$hasNovelUpdatesMatch && !$nearMiss && self::isLatin($author)) {
            return self::result(self::ORIGINAL, 'en', 'Latin-script author, no translator notes, not on NovelUpdates');
        }

        return self::result(self::UNKNOWN, null, 'no decisive signal');
    }

    /** ja when Kana appears (Japanese mixes in Han), ko for Hangul, zh for Han. */
    public static function scriptLanguage(string $text): ?string
    {
        if ($text === '') {
            return null;
        }
        if (preg_match('/[\p{Hiragana}\p{Katakana}]/u', $text)) {
            return 'ja';
        }
        if (preg_match('/\p{Hangul}/u', $text)) {
            return 'ko';
        }
        if (preg_match('/\p{Han}/u', $text)) {
            return 'zh';
        }

        return null;
    }

    /** Letters are all Latin script (accents allowed); digits/punctuation ignored. */
    private static function isLatin(string $text): bool
    {
        return preg_match('/\p{L}/u', $text) === 1 && preg_match('/[^\P{L}\p{Latin}]/u', $text) === 0;
    }

    /**
     * Two or more words, each one to three Mandarin syllables ("Er Gen",
     * "Feng Qingyang", "Tang Jia San Shao"). English names fail on their first
     * non-pinyin word ("Guiltythree", "Some Author"); the few that pass only
     * become "unknown", never "translated".
     */
    private static function looksLikePinyin(string $author): bool
    {
        $words = preg_split('/[\s\-]+/u', mb_strtolower(\Illuminate\Support\Str::ascii($author)), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2) {
            return false;
        }

        // A word is one to three syllables ("Feng Qingyang", "Mo Yan").
        $syllable = '/^(?:(?:zh|ch|sh|[bpmfdtnlgkhjqxrzcsyw])?'
            . '(?:iang|iong|uang|ueng|ang|eng|ing|ong|ian|iao|uai|uan|ai|ao|an|ei|en|er|ia|ie|in|iu|ou|ua|ui|un|uo|ue|ve|a|e|i|o|u|v)){1,3}$/';
        foreach ($words as $word) {
            if (preg_match($syllable, $word) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function plain(string $html): string
    {
        $text = preg_replace('~<(?:br|/p|/div|/h\d)\b[^>]*>~i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function squash(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private static function result(string $origin, ?string $language, string $reason): array
    {
        return ['origin' => $origin, 'origin_language' => $language, 'reason' => $reason];
    }
}
