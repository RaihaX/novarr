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
     * @param string[] $chapterTexts the first chapter bodies (translator voice: 400 chars of 3; text signals: SAMPLE_CHARS of SAMPLE_CHAPTERS)
     * @param string[] $tocLabels
     * @param bool     $hasNovelUpdatesMatch a confident NovelUpdates match exists
     * @param ?float   $novelUpdatesScore    best NovelUpdates score seen; a near miss (≥ 0.6)
     *                                       means a translation probably exists under another name
     * @param string[] $genres the series' genres / tags
     * @return array{origin: string, origin_language: ?string, reason: string, confidence: string}
     */
    public static function infer(
        ?string $author,
        ?string $host = null,
        array $chapterTexts = [],
        array $tocLabels = [],
        bool $hasNovelUpdatesMatch = false,
        ?float $novelUpdatesScore = null,
        array $genres = [],
    ): array {
        $author = trim((string) $author);

        if ($lang = self::scriptLanguage($author)) {
            return self::result(self::TRANSLATED, $lang, 'author name is written in ' . self::languageName($lang) . ' script', self::HIGH);
        }

        $host = strtolower(preg_replace('/^www\./i', '', trim((string) $host)));
        if ($host !== '' && in_array($host, self::ORIGINAL_HOSTS, true)) {
            return self::result(self::ORIGINAL, 'en', "{$host} only hosts original fiction", self::HIGH);
        }

        // What the story itself reads like: names, forms of address, places,
        // money, genre. Stronger than anything the author's name says — a
        // Chinese author's pen-name is often published in English words.
        $signal = self::classifyText(array_map(fn($t) => self::plain((string) $t), array_values($chapterTexts)), $genres);
        if ($signal['language'] !== null && $signal['confidence'] !== self::LOW) {
            return self::result(self::TRANSLATED, $signal['language'], $signal['reason'], $signal['confidence']);
        }

        $samples = array_map(
            fn($text) => mb_substr(self::plain((string) $text), 0, 400),
            array_slice(array_values($chapterTexts), 0, 3)
        );
        $sampleText = implode("\n", $samples);
        $labelText = implode("\n", array_map(fn($l) => (string) $l, $tocLabels));

        if (preg_match(self::TRANSLATOR_SPEAKER, $sampleText) === 1
            || preg_match(self::TRANSLATOR_PHRASES, $sampleText . "\n" . $labelText) === 1) {
            // The language: any CJK left in the samples, else a weak text signal.
            $language = self::scriptLanguage($sampleText . "\n" . $labelText) ?? $signal['language'];

            return self::result(self::TRANSLATED, $language, 'translator notes in the chapters', $language !== null && $signal['language'] === null ? self::HIGH : self::MEDIUM);
        }

        if ($author !== '' && self::looksLikePinyin($author)) {
            return self::result(self::TRANSLATED, 'zh', 'author name reads as pinyin', $signal['language'] === 'zh' ? self::MEDIUM : self::LOW);
        }

        // A weak text signal is still a translation, of a language we will
        // not name on a badge (low confidence).
        if ($signal['language'] !== null) {
            return self::result(self::TRANSLATED, $signal['language'], $signal['reason'], self::LOW);
        }

        // Nothing foreign in a fair amount of text: an English original —
        // surely so only for a screen-name author. Translated novels set in
        // a Western world (wizards, Rome, a game) read the same, and their
        // authors' pen-names come out as English words, so a plain or
        // multi-word name stays a low guess (no badge). Without enough
        // text to judge, the old weak rule stands, as low.
        $nearMiss = $novelUpdatesScore !== null && $novelUpdatesScore >= 0.6;
        if ($author !== '' && !$hasNovelUpdatesMatch && !$nearMiss && self::isLatin($author)) {
            if ($signal['words'] >= self::MIN_WORDS) {
                return self::result(self::ORIGINAL, 'en', 'no names, forms of address or places of a translated story in ' . $signal['words'] . ' words', self::looksLikeHandle($author) ? self::HIGH : self::LOW);
            }

            return self::result(self::ORIGINAL, 'en', 'Latin-script author, no translator notes, not on NovelUpdates', self::LOW);
        }

        return self::result(self::UNKNOWN, null, 'no decisive signal', self::LOW);
    }

    // ---- What the text reads like ---------------------------------------------

    /** Confidence of a result (novels.origin_confidence). */
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';

    /** Words of story needed before "nothing foreign in it" means an English original. */
    public const MIN_WORDS = 1200;

    /** Characters read per chapter, and chapters read. */
    private const SAMPLE_CHARS = 9000;
    public const SAMPLE_CHAPTERS = 5;

    /** Signals per 1,000 words: below WEAK nothing, from CLEAR medium, from STRONG high. */
    private const WEAK = 0.5;
    private const CLEAR = 3.0;
    private const STRONG = 4.0;

    /** One or two Mandarin syllables: a romanised name part ("Zhou", "Qingshan"). */
    private const PINYIN_WORD = '/^(?:(?:zh|ch|sh|[bpmfdtnlgkhjqxrzcsyw])?'
        . '(?:iang|iong|uang|ang|eng|ing|ong|ian|iao|uai|uan|ai|ao|an|ei|en|er|ia|ie|in|iu|ou|ua|ui|un|uo|ue|a|e|i|o|u)){1,2}$/';

    /** English words (and names) that happen to be valid pinyin. */
    private const NOT_PINYIN = [
        'a', 'an', 'he', 'she', 'me', 'we', 'you', 'her', 'can', 'man', 'men', 'sun', 'long', 'song', 'ran', 'fan',
        'pan', 'ban', 'tan', 'bang', 'gang', 'hang', 'sang', 'rang', 'sing', 'ring', 'king', 'wing', 'bin', 'pin',
        'tin', 'win', 'sin', 'gun', 'run', 'fun', 'bun', 'nun', 'dan', 'ken', 'ben', 'jen', 'ren', 'lee', 'kai',
        'mai', 'lou', 'sue', 'jean', 'dean', 'sean', 'juan', 'luke', 'mike', 'june', 'duke', 'lane', 'kane', 'zane',
        'so', 'no', 'go', 'do', 'to', 'la', 'ma', 'pa', 'ha', 'yo', 'yeah', 'yes', 'woman', 'human', 'began', 'begin',
        'given', 'even', 'seven', 'heaven', 'queen', 'guide', 'china', 'chinese', 'nine', 'mine', 'line', 'fine',
        'wine', 'pine', 'dine', 'sure', 'pure', 'cure', 'here', 'were', 'there', 'wage', 'cage', 'page', 'sage',
    ];

    /** Family names romanised from Korean (short on purpose; several are English names too). */
    private const KOREAN_FAMILY = 'Kim|Lee|Park|Choi|Jung|Jeong|Kang|Yoon|Seo|Shin|Baek|Han';

    /**
     * Which language a translated story came from, read off its English
     * text: romanised names, forms of address, places, money, and the
     * genre words a translation keeps. Pure.
     *
     * @param  string[]  $texts   plain chapter texts (the first SAMPLE_CHAPTERS, SAMPLE_CHARS each)
     * @param  string[]  $genres  the series' genres / tags
     * @return array{language: ?string, confidence: string, reason: string, words: int, rates: array<string, float>}
     */
    public static function classifyText(array $texts, array $genres = []): array
    {
        $text = implode("\n", array_map(fn($t) => mb_substr((string) $t, 0, self::SAMPLE_CHARS), array_slice(array_values($texts), 0, self::SAMPLE_CHAPTERS)));
        $words = preg_match_all('/\p{L}+/u', $text);
        $counts = ['zh' => self::chineseSignals($text), 'ko' => self::koreanSignals($text), 'ja' => self::japaneseSignals($text)];
        $rates = array_map(fn($n) => $words > 0 ? round($n * 1000 / $words, 2) : 0.0, $counts);

        // The genre settles it when the text does not disagree.
        $byGenre = self::genreLanguage($genres);

        arsort($rates);
        $best = (string) array_key_first($rates);
        $bestRate = (float) reset($rates);
        $second = (float) (array_values($rates)[1] ?? 0.0);

        $language = null;
        $confidence = self::LOW;
        if ($words >= 200 && $bestRate >= self::WEAK && $bestRate >= 2 * $second) {
            $language = $best;
            $confidence = $bestRate >= self::STRONG ? self::HIGH : ($bestRate >= self::CLEAR ? self::MEDIUM : self::LOW);
        }
        if ($byGenre !== null && ($language === null || $language === $byGenre || $confidence === self::LOW)) {
            $confidence = $language === $byGenre && $confidence !== self::LOW ? self::HIGH : self::MEDIUM;
            $language = $byGenre;
        }

        $name = $language !== null ? (string) self::languageName($language) : '';
        $reason = $language === null
            ? 'no decisive signal'
            : ($byGenre === $language && $bestRate < self::WEAK
                ? "{$name} genre tags"
                : "{$name} names, forms of address or setting ({$bestRate} per 1,000 words)");

        return ['language' => $language, 'confidence' => $confidence, 'reason' => $reason, 'words' => (int) $words, 'rates' => $rates];
    }

    /** Romanised Chinese names ("Zhou Yuan", "Li Qingshan") and the words a Chinese web novel keeps. */
    private static function chineseSignals(string $text): float
    {
        $score = 0.0;
        if (preg_match_all('/\b([A-Z][a-z]{1,8}) ([A-Z][a-z]{1,10})\b/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $pair) {
                if (self::isPinyinWord($pair[1]) && self::isPinyinWord($pair[2])) {
                    $score += 1;
                }
            }
        }
        $score += 0.5 * preg_match_all(
            '/\b(?:cultivat(?:ion|ors?|ing|ed?)|daoists?|dantian|meridians?|spirit(?:ual)? stones?|young master|'
            . '(?:senior|junior) (?:brother|sister)|martial (?:uncle|arts?)|jianghu|taels?|nascent soul|'
            . 'foundation establishment|qi|sect master|inner sect|outer sect)\b/i',
            $text
        );

        return $score;
    }

    /** Korean forms of address, romanised names with a hyphenated given name, places and money. */
    private static function koreanSignals(string $text): float
    {
        $score = 2.0 * preg_match_all('/\b(?:hyung|hyungnim|noona|oppa|unnie|sunbae|hoobae|ahjussi|ahjumma)\b/i', $text);
        $score += 2.0 * preg_match_all('/\b[A-Z][a-z]+(?:-[a-z]+)?-(?:nim|ssi)\b/', $text);
        $score += 2.0 * preg_match_all('/\b(?:' . self::KOREAN_FAMILY . ') [A-Z][a-z]+-[A-Za-z][a-z]+\b/', $text);
        $score += 0.5 * preg_match_all('/\b(?:' . self::KOREAN_FAMILY . ') [A-Z][a-z]{2,}(?![a-z-])/', $text);
        $score += 1.0 * preg_match_all('/\b(?:Seoul|Busan|Incheon|Gangnam|Korean?|murim)\b|\b\d[\d,.]* (?:million |billion )?won\b/i', $text);

        return $score;
    }

    /** Japanese name suffixes, forms of address, places and money. */
    private static function japaneseSignals(string $text): float
    {
        $score = 2.0 * preg_match_all('/\b[A-Z][a-z]+-(?:san|kun|chan|sama|senpai|sensei|dono)\b/', $text);
        $score += 1.0 * preg_match_all('/\b(?:senpai|sensei|onii-chan|onee-chan|nii-san|nee-san)\b/i', $text);
        $score += 1.0 * preg_match_all('/\b(?:Tokyo|Osaka|Kyoto|Japan(?:ese)?)\b|\b\d[\d,.]* (?:million |billion )?yen\b/i', $text);

        return $score;
    }

    /** The language a genre / tag names outright, or null. */
    public static function genreLanguage(array $genres): ?string
    {
        $map = [
            'zh' => ['xianxia', 'wuxia', 'xuanhuan', 'chinese', 'manhua', 'cultivation'],
            'ko' => ['korean', 'manhwa', 'murim'],
            'ja' => ['japanese', 'manga', 'light novel'],
        ];
        $found = [];
        foreach ($genres as $genre) {
            $genre = mb_strtolower(trim((string) $genre));
            foreach ($map as $language => $names) {
                if (in_array($genre, $names, true)) {
                    $found[$language] = true;
                }
            }
        }

        return count($found) === 1 ? (string) array_key_first($found) : null;
    }

    private static function isPinyinWord(string $word): bool
    {
        $word = strtolower($word);

        return !in_array($word, self::NOT_PINYIN, true) && preg_match(self::PINYIN_WORD, $word) === 1;
    }

    /** A screen-name author ("joyce_4070", "SleepDeprivedSloth", "Exlor"): digits, underscores, inner capitals, or one invented word. */
    public static function looksLikeHandle(string $author): bool
    {
        $author = trim($author);

        return $author !== '' && (preg_match('/[\d_]|[a-z][A-Z]/', $author) === 1 || preg_match('/^\p{L}{3,}$/u', $author) === 1);
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

    private static function result(string $origin, ?string $language, string $reason, string $confidence = self::LOW): array
    {
        return ['origin' => $origin, 'origin_language' => $language, 'reason' => $reason, 'confidence' => $confidence];
    }
}
