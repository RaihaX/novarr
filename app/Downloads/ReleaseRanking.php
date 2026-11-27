<?php

namespace App\Downloads;

use App\DownloadGrab;
use App\Services\DuplicateSeries;
use App\Support\Bytes;

/**
 * Orders Prowlarr results (App\Downloads\Prowlarr::normalise()) for the
 * book wanted, best first, each with a score and a short "why"
 * ("epub · 1.2 MB · NZBgeek", plus what is wrong with it).
 *
 * The score adds up:
 *
 *   title     the share of the wanted title's words found in the release
 *             name (× 50), +10 when the whole title appears in it as
 *             DuplicateSeries::normalize() writes it; less than half of the
 *             words: −40 ("title differs"), so the wrong book sinks.
 *   author    a word of the author's name (3+ letters, as BookLibrary
 *             compares authors) in the release name: +15.
 *   format    ebook: epub +20, azw3 +8, mobi +6, pdf −5, a comic archive
 *             −15, an audiobook −40; audiobook: m4b +15, m4a +8, mp3 +6,
 *             flac +3, an ebook −40.
 *   junk      "sample", "summary", "workbook", "study guide",
 *             "CliffsNotes"…: −40; an abridged audiobook: −20, an
 *             "unabridged" one: +5.
 *   language  a language marker ("German", "FRENCH", "Hörbuch", "ITA"…)
 *             other than the book's language (English unless it says
 *             otherwise): −30; its own language's marker: +5.
 *   size      plausible (ebook 0.1–100 MB, audiobook 20 MB–5 GB): +5;
 *             outside: −25 ("unusual size"); unknown: 0.
 *   torrent   no seeders: −30; else up to +10 (more seeders, more).
 *   usenet    newer posts slightly preferred: up to +5 (0 after 5 years).
 *
 * Ties keep Prowlarr's order. Pure: no network, no database.
 */
final class ReleaseRanking
{
    public const EBOOK_FORMATS = ['epub' => 20, 'azw3' => 8, 'mobi' => 6, 'pdf' => -5, 'cbz' => -15, 'cbr' => -15];

    public const AUDIO_FORMATS = ['m4b' => 15, 'm4a' => 8, 'mp3' => 6, 'flac' => 3];

    /** Release-name words that say which format it is. */
    private const FORMAT_WORDS = [
        'epub' => 'epub', 'azw3' => 'azw3', 'azw' => 'azw3', 'kf8' => 'azw3', 'mobi' => 'mobi', 'pdf' => 'pdf',
        'cbz' => 'cbz', 'cbr' => 'cbr', 'm4b' => 'm4b', 'm4a' => 'm4a', 'aac' => 'm4a', 'mp3' => 'mp3', 'flac' => 'flac',
    ];

    /** Release-name words that say "not the book itself". */
    private const JUNK_WORDS = ['sample', 'samples', 'summary', 'summaries', 'workbook', 'cliffsnotes', 'sparknotes', 'excerpt', 'preview'];

    private const JUNK_PHRASES = [['study', 'guide'], ['book', 'summary'], ['reading', 'guide']];

    /** Release-name words that mark a language (ISO 639-1). */
    public const LANGUAGE_WORDS = [
        'german' => 'de', 'deutsch' => 'de', 'ger' => 'de', 'hörbuch' => 'de', 'horbuch' => 'de', 'hoerbuch' => 'de',
        'french' => 'fr', 'francais' => 'fr', 'français' => 'fr', 'fre' => 'fr', 'vf' => 'fr',
        'spanish' => 'es', 'espanol' => 'es', 'español' => 'es', 'castellano' => 'es', 'audiolibro' => 'es', 'spa' => 'es',
        'italian' => 'it', 'italiano' => 'it', 'ita' => 'it',
        'dutch' => 'nl', 'nederlands' => 'nl', 'nl' => 'nl',
        'portuguese' => 'pt', 'portugues' => 'pt', 'russian' => 'ru', 'rus' => 'ru', 'polish' => 'pl', 'polski' => 'pl',
        'swedish' => 'sv', 'svenska' => 'sv', 'danish' => 'da', 'norwegian' => 'no', 'finnish' => 'fi',
        'japanese' => 'ja', 'chinese' => 'zh', 'korean' => 'ko', 'hungarian' => 'hu', 'czech' => 'cs', 'turkish' => 'tr',
        'english' => 'en', 'eng' => 'en',
    ];

    private const STOP_WORDS = ['the', 'a', 'an', 'of', 'and', 'or', 'to', 'in', 'on', 'for'];

    /** Plausible sizes in bytes: [min, max]. */
    public const SIZES = [
        DownloadGrab::KIND_EBOOK => [100 * 1024, 100 * 1024 * 1024],
        DownloadGrab::KIND_AUDIOBOOK => [20 * 1024 * 1024, 5 * 1024 * 1024 * 1024],
    ];

    /**
     * @param  list<array>  $results  normalised Prowlarr results
     * @param  array{kind: string, title: string, author?: ?string, language?: ?string}  $wanted
     * @return list<array>  each result with score, format, why, warnings; best first
     */
    public static function rank(array $results, array $wanted): array
    {
        $scored = [];
        foreach (array_values($results) as $i => $result) {
            $scored[] = self::score($result, $wanted) + ['_order' => $i];
        }
        usort($scored, fn($a, $b) => [$b['score'], $a['_order']] <=> [$a['score'], $b['_order']]);

        return array_map(function ($r) {
            unset($r['_order']);

            return $r;
        }, $scored);
    }

    /** One result with its score, format, why and warnings. */
    public static function score(array $result, array $wanted): array
    {
        $kind = $wanted['kind'] === DownloadGrab::KIND_AUDIOBOOK ? DownloadGrab::KIND_AUDIOBOOK : DownloadGrab::KIND_EBOOK;
        $language = strtolower((string) ($wanted['language'] ?? '')) ?: 'en';
        $words = self::words((string) ($result['title'] ?? ''));
        $wordSet = array_flip($words);
        $score = 0.0;
        $warnings = [];

        // Title.
        $titleWords = self::words((string) ($wanted['title'] ?? ''));
        $significant = array_values(array_diff($titleWords, self::STOP_WORDS)) ?: $titleWords;
        $coverage = 1.0;
        if ($significant !== []) {
            $found = count(array_filter(array_unique($significant), fn($w) => isset($wordSet[$w])));
            $coverage = $found / count(array_unique($significant));
            $score += $coverage * 50;
            $compact = DuplicateSeries::normalize((string) $wanted['title']);
            if ($compact !== '' && str_contains(implode('', $words), $compact)) {
                $score += 10;
            }
            if ($coverage < 0.5) {
                $score -= 40;
                $warnings[] = 'title differs';
            }
        }

        // Author.
        $authorWords = array_values(array_filter(self::words((string) ($wanted['author'] ?? '')), fn($w) => mb_strlen($w) >= 3 && !in_array($w, self::STOP_WORDS, true)));
        $authorMatch = $authorWords !== [] && array_filter($authorWords, fn($w) => isset($wordSet[$w])) !== [];
        if ($authorMatch) {
            $score += 15;
        }

        // Format.
        $format = self::format($words, $kind);
        if ($kind === DownloadGrab::KIND_EBOOK) {
            if (($format !== null && isset(self::AUDIO_FORMATS[$format])) || isset($wordSet["audiobook"]) || isset($wordSet["audiobooks"])) {
                $score -= 40;
                $warnings[] = 'an audiobook';
            } elseif ($format !== null) {
                $score += self::EBOOK_FORMATS[$format] ?? 0;
            }
        } else {
            if ($format !== null && isset(self::EBOOK_FORMATS[$format])) {
                $score -= 40;
                $warnings[] = 'an ebook, not audio';
            } elseif ($format !== null) {
                $score += self::AUDIO_FORMATS[$format] ?? 0;
            }
            if (isset($wordSet['abridged']) && !isset($wordSet['unabridged'])) {
                $score -= 20;
                $warnings[] = 'abridged';
            } elseif (isset($wordSet['unabridged'])) {
                $score += 5;
            }
        }

        // Junk.
        $junk = array_values(array_filter(self::JUNK_WORDS, fn($w) => isset($wordSet[$w])));
        foreach (self::JUNK_PHRASES as $phrase) {
            if (self::hasPhrase($words, $phrase)) {
                $junk[] = implode(' ', $phrase);
            }
        }
        // "Sample" or "Summary" in the wanted title itself is not junk.
        $junk = array_values(array_diff($junk, $titleWords));
        if ($junk !== []) {
            $score -= 40;
            $warnings[] = $junk[0];
        }

        // Language.
        $languages = [];
        foreach ($words as $w) {
            if (isset(self::LANGUAGE_WORDS[$w]) && !in_array($w, $titleWords, true)) {
                $languages[self::LANGUAGE_WORDS[$w]] = $w;
            }
        }
        $other = array_diff_key($languages, [$language => true]);
        if ($other !== []) {
            $score -= 30;
            $warnings[] = 'in ' . ucfirst(self::languageName(array_key_first($other)));
        } elseif (isset($languages[$language])) {
            $score += 5;
        }

        // Size.
        $size = (int) ($result['size'] ?? 0);
        if ($size > 0) {
            [$min, $max] = self::SIZES[$kind];
            if ($size >= $min && $size <= $max) {
                $score += 5;
            } else {
                $score -= 25;
                $warnings[] = 'unusual size';
            }
        }

        // Seeders / age.
        if (($result['protocol'] ?? null) === DownloadGrab::TORRENT) {
            $seeders = $result['seeders'] ?? null;
            if ($seeders !== null && (int) $seeders <= 0) {
                $score -= 30;
                $warnings[] = 'no seeders';
            } elseif ($seeders !== null) {
                $score += min(10, log((int) $seeders + 1, 2) * 2);
            }
        } elseif (($result['age_days'] ?? null) !== null) {
            $score += max(0, 5 - ((int) $result['age_days']) / 365);
        }

        $why = array_values(array_filter([
            $format,
            $size > 0 ? Bytes::human($size) : null,
            $result['indexer'] ?? null,
        ]));

        return $result + [
            'score' => round($score, 2),
            'format' => $format,
            'title_match' => round($coverage, 2),
            'author_match' => $authorMatch,
            'warnings' => $warnings,
            'why' => implode(' · ', array_merge($why, $warnings)),
        ];
    }

    /**
     * The words of a release name or title: lower case, accents dropped,
     * split on anything that is not a letter or digit ("The.Final.Empire-
     * EPUB" → [the, final, empire, epub]). Apostrophes join ("Ender's").
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $text = mb_strtolower(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_KD);
            if (is_string($decomposed)) {
                $text = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $text;
            }
        }
        $text = str_replace(["'", "\u{2019}"], '', $text);

        return preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** The format a release name says, preferring the kind's own formats; null when it says none. */
    public static function format(array $words, string $kind): ?string
    {
        $found = [];
        foreach ($words as $w) {
            if (isset(self::FORMAT_WORDS[$w])) {
                $found[] = self::FORMAT_WORDS[$w];
            }
        }
        if ($found === []) {
            return null;
        }
        $own = $kind === DownloadGrab::KIND_AUDIOBOOK ? self::AUDIO_FORMATS : self::EBOOK_FORMATS;
        $mine = array_values(array_filter($found, fn($f) => isset($own[$f])));
        if ($mine !== []) {
            // Several ("EPUB MOBI PDF" bundles): the best of them.
            usort($mine, fn($a, $b) => $own[$b] <=> $own[$a]);

            return $mine[0];
        }

        return $found[0];
    }

    private static function hasPhrase(array $words, array $phrase): bool
    {
        $n = count($phrase);
        for ($i = 0; $i + $n <= count($words); $i++) {
            if (array_slice($words, $i, $n) === $phrase) {
                return true;
            }
        }

        return false;
    }

    private static function languageName(string $code): string
    {
        return [
            'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian', 'nl' => 'Dutch', 'pt' => 'Portuguese',
            'ru' => 'Russian', 'pl' => 'Polish', 'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian', 'fi' => 'Finnish',
            'ja' => 'Japanese', 'zh' => 'Chinese', 'ko' => 'Korean', 'hu' => 'Hungarian', 'cs' => 'Czech', 'tr' => 'Turkish',
            'en' => 'English',
        ][$code] ?? $code;
    }
}
