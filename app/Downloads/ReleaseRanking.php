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
 * Comics (kind comic): format cbz +20, cbr +16, cb7 +10, pdf +4, an
 * ebook format −15 ("not a comic archive"), audio −40; no author needed
 * (release names rarely carry one); the issue / volume the name says
 * (App\Comics\ComicName: "v03", "#012", "Vol 1-9") against the one wanted:
 * another number −50 (a warning); a pack covering it −10 and marked `pack`
 * (offered, grabbed alone only when nothing better passes); "(Digital)"
 * +5, "TPB" / "Omnibus" +3; the year in its name the wanted one's +5;
 * plausible size 5 MB–2 GB (a pack up to 40 GB).
 *
 * An ebook search for a graphic novel (wanted graphic_novel) takes comic
 * archives as the book itself: cbz / cbr are not penalised there.
 *
 * Ties keep Prowlarr's order. Pure: no network, no database.
 */
final class ReleaseRanking
{
    public const EBOOK_FORMATS = ['epub' => 20, 'azw3' => 8, 'mobi' => 6, 'pdf' => -5, 'cbz' => -15, 'cbr' => -15];

    public const AUDIO_FORMATS = ['m4b' => 15, 'm4a' => 8, 'mp3' => 6, 'flac' => 3];

    public const COMIC_FORMATS = ['cbz' => 20, 'cbr' => 16, 'cb7' => 10, 'pdf' => 4];

    /** A pack of issues / volumes ("Vol 1-9") may be this large. */
    public const PACK_MAX_BYTES = 40 * 1024 * 1024 * 1024;

    /** Release-name words that say which format it is. */
    private const FORMAT_WORDS = [
        'epub' => 'epub', 'azw3' => 'azw3', 'azw' => 'azw3', 'kf8' => 'azw3', 'mobi' => 'mobi', 'pdf' => 'pdf',
        'cbz' => 'cbz', 'cbr' => 'cbr', 'cb7' => 'cb7', 'cbt' => 'cbz', 'm4b' => 'm4b', 'm4a' => 'm4a', 'aac' => 'm4a', 'mp3' => 'mp3', 'flac' => 'flac',
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
        DownloadGrab::KIND_COMIC => [5 * 1024 * 1024, 2 * 1024 * 1024 * 1024],
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
        $kind = in_array($wanted['kind'] ?? null, DownloadGrab::KINDS, true) ? $wanted['kind'] : DownloadGrab::KIND_EBOOK;
        $graphicNovel = $kind === DownloadGrab::KIND_EBOOK && !empty($wanted['graphic_novel']);
        $pack = false;
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
        if ($kind === DownloadGrab::KIND_COMIC) {
            if (($format !== null && isset(self::AUDIO_FORMATS[$format])) || isset($wordSet['audiobook'])) {
                $score -= 40;
                $warnings[] = 'an audiobook';
            } elseif ($format !== null && isset(self::COMIC_FORMATS[$format])) {
                $score += self::COMIC_FORMATS[$format];
            } elseif ($format !== null) {
                $score -= 15;
                $warnings[] = 'not a comic archive';
            }
        } elseif ($kind === DownloadGrab::KIND_EBOOK) {
            if (($format !== null && isset(self::AUDIO_FORMATS[$format])) || isset($wordSet["audiobook"]) || isset($wordSet["audiobooks"])) {
                $score -= 40;
                $warnings[] = 'an audiobook';
            } elseif ($format !== null && $graphicNovel && isset(self::COMIC_FORMATS[$format])) {
                // A graphic novel: its comic archive is the book.
                $score += self::COMIC_FORMATS[$format];
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

        // Another book of the series: "… - A Court of Thorns and Roses Book 6"
        // names the series, not the book wanted (book 1). A number that is not
        // the wanted book's is penalised; with no known number, any "Book N"
        // past 1 is a warning (never grabbed by itself, still offered).
        $numbers = $kind === DownloadGrab::KIND_COMIC ? [] : self::bookNumbers((string) ($result['title'] ?? ''));
        if ($kind === DownloadGrab::KIND_COMIC) {
            $comic = self::comicNumbers((string) ($result['title'] ?? ''), $wanted);
            $score += $comic['score'];
            $warnings = array_merge($warnings, $comic['warnings']);
            $pack = $comic['pack'];
        }
        if ($numbers !== []) {
            $index = isset($wanted['series_index']) && $wanted['series_index'] !== null && $wanted['series_index'] !== '' ? (float) $wanted['series_index'] : null;
            if ($index !== null && !in_array($index, $numbers, true)) {
                $score -= 50;
                $warnings[] = 'says book ' . self::numberLabel($numbers[0]) . ' (this is book ' . self::numberLabel($index) . ')';
            } elseif ($index === null && max($numbers) > 1) {
                $score -= 15;
                $warnings[] = 'says book ' . self::numberLabel(max($numbers)) . ' of a series';
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
            if ($pack) {
                $max = self::PACK_MAX_BYTES;
            }
            if ($graphicNovel) {
                $max = self::SIZES[DownloadGrab::KIND_COMIC][1];
            }
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
            'pack' => $pack,
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
        $own = match ($kind) {
            DownloadGrab::KIND_AUDIOBOOK => self::AUDIO_FORMATS,
            DownloadGrab::KIND_COMIC => self::COMIC_FORMATS,
            default => self::EBOOK_FORMATS,
        };
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

    /**
     * Book numbers a release name states: "Book 6", "Bk. 2", "Vol 3",
     * "Volume 03", "#4", "Book Six" (one–twelve). Years and sizes are not
     * numbers here (only 1–99 after one of those words).
     *
     * @return list<float>
     */
    public static function bookNumbers(string $title): array
    {
        $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12];
        $out = [];
        if (preg_match_all('/(?:\bbook|\bbk|\bvol(?:ume)?|#)\.?\s*(\d{1,2}(?:\.\d)?|' . implode('|', array_keys($words)) . ')\b/i', $title, $m)) {
            foreach ($m[1] as $n) {
                $n = strtolower($n);
                $out[] = (float) ($words[$n] ?? $n);
            }
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }

    /**
     * A comic release's issue / volume against the wanted one (wanted
     * number + number_kind volume | issue; year): score, warnings, pack.
     *
     * @return array{score: float, warnings: list<string>, pack: bool}
     */
    public static function comicNumbers(string $title, array $wanted): array
    {
        $parsed = \App\Comics\ComicName::parse($title);
        $score = 0.0;
        $warnings = [];
        $number = isset($wanted['number']) && $wanted['number'] !== null && $wanted['number'] !== '' ? (float) $wanted['number'] : null;
        $kind = $wanted['number_kind'] ?? null;
        $pack = $parsed['pack'] !== null;

        if ($pack) {
            $score -= 10;
            if ($number !== null && ($number < $parsed['pack']['from'] || $number > $parsed['pack']['to'])) {
                $score -= 40;
                $warnings[] = 'a pack of ' . self::numberLabel($parsed['pack']['from']) . '–' . self::numberLabel($parsed['pack']['to']) . ' (wanted ' . self::numberLabel($number) . ')';
            }
        } elseif ($number !== null) {
            $says = $kind === 'issue' ? ($parsed['issue'] ?? $parsed['volume']) : ($parsed['volume'] ?? $parsed['issue']);
            if ($says !== null && abs($says - $number) > 0.001) {
                $score -= 50;
                $warnings[] = 'says ' . ($kind === 'issue' ? '#' : 'volume ') . self::numberLabel($says) . ' (wanted ' . self::numberLabel($number) . ')';
            } elseif ($says !== null) {
                $score += 10;
            }
        }
        $words = array_flip(self::words($title));
        if (isset($words['digital'])) {
            $score += 5;
        }
        if ($parsed['tpb'] || $parsed['omnibus']) {
            $score += 3;
        }
        if (!empty($wanted['year']) && $parsed['year'] !== null && (int) $wanted['year'] === (int) $parsed['year']) {
            $score += 5;
        }

        return ['score' => $score, 'warnings' => $warnings, 'pack' => $pack];
    }

    private static function numberLabel(float $n): string
    {
        return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
    }
}
