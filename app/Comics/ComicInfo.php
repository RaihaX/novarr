<?php

namespace App\Comics;

/**
 * A comic archive's own ComicInfo.xml (the ComicRack / Anansi schema):
 * Series, Number, Volume, Title, Writer, Penciller, Publisher, Year,
 * Summary, Manga, LanguageISO, Count, Genre, Web.
 *
 * Volume is the series' volume number ("Saga" volume 1) — or, by the
 * ComicRack habit, the series' start year ("Batman" Volume 2016): a Volume
 * of 1900 or more is read as that year, not a number.
 *
 * Manga=YesAndRightToLeft reads right to left; Yes / No / Unknown left to
 * right (a "Yes" alone says manga, not the direction).
 *
 * Pure: parse() takes the XML text; unreadable XML gives null.
 */
final class ComicInfo
{
    /**
     * @return ?array{series: ?string, number: ?float, volume: ?float, year: ?int, start_year: ?int, title: ?string, writers: list<string>, artists: list<string>, publisher: ?string, summary: ?string, rtl: bool, language: ?string, count: ?int, genres: list<string>, web: ?string}
     */
    public static function parse(string $xml): ?array
    {
        $xml = trim($xml);
        if ($xml === '' || !str_contains($xml, '<')) {
            return null;
        }
        // No network, no entities: ComicInfo never needs either.
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } catch (\Throwable) {
            $doc = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if ($doc === false) {
            return null;
        }

        // Element names are matched case-insensitively (taggers differ).
        $fields = [];
        foreach ($doc->children() as $child) {
            $fields[strtolower($child->getName())] = trim((string) $child);
        }
        $get = fn(string $key) => isset($fields[$key]) && $fields[$key] !== '' ? $fields[$key] : null;
        $list = fn(?string $v) => $v === null ? [] : array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;]/', $v) ?: []), fn($s) => $s !== '')));
        $num = function (?string $v): ?float {
            if ($v === null) {
                return null;
            }
            // "1", "001", "1.5", "½" (taggers write it so).
            $v = str_replace(['½', '¼', '¾'], ['.5', '.25', '.75'], $v);
            return preg_match('/^-?\d+(?:\.\d+)?/', ltrim($v, '#'), $m) ? (float) $m[0] : null;
        };

        $volume = $num($get('volume'));
        $startYear = null;
        if ($volume !== null && $volume >= 1900) {
            $startYear = (int) $volume;
            $volume = null;
        }
        $year = $get('year') !== null && preg_match('/^\d{4}$/', (string) $get('year')) ? (int) $get('year') : null;

        $summary = $get('summary');
        if ($summary !== null) {
            $summary = ComicVine::plainText($summary);
        }

        return [
            'series' => $get('series'),
            'number' => $num($get('number')),
            'volume' => $volume,
            'year' => $year,
            'start_year' => $startYear,
            'title' => $get('title'),
            'writers' => $list($get('writer')),
            'artists' => array_values(array_unique(array_merge($list($get('penciller')), $list($get('artist'))))),
            'publisher' => $get('publisher'),
            'summary' => $summary !== '' ? $summary : null,
            'rtl' => strcasecmp((string) $get('manga'), 'YesAndRightToLeft') === 0,
            'language' => $get('languageiso') !== null ? strtolower(substr((string) $get('languageiso'), 0, 2)) : null,
            'count' => ($c = $num($get('count'))) !== null && $c > 0 ? (int) $c : null,
            'genres' => $list($get('genre')),
            'web' => ($w = $get('web')) !== null && preg_match('#^https?://#i', $w) ? $w : null,
        ];
    }
}
