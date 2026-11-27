<?php

namespace App\Comics;

/**
 * What a comic's file or release name says, the way the scene names them:
 *
 *   "Saga v01 (2012) (Digital) (F) (Zone-Empire).cbr"  → Saga, volume 1, 2012, digital
 *   "Batman 001 (2016) (Digital).cbz"                  → Batman, issue 1, 2016
 *   "Batman (2016) 001.cbz"                            → Batman, issue 1, 2016
 *   "Watchmen (1987) TPB.pdf"                          → Watchmen, 1987, tpb
 *   "Saga #54"                                         → Saga, issue 54
 *   "The Walking Dead Vol. 3 - Safety Behind Bars"     → The Walking Dead, volume 3, title
 *   "Spider-Man 2099 001 (1992)"                       → Spider-Man 2099, issue 1
 *   "Saga Vol 1-9 (2012-2018)"                         → Saga, a pack of volumes 1–9
 *
 * The issue number is the last number of the name before its (…) groups:
 * after a "#", or 1–3 digits, or with a leading zero ("001"); a four-digit
 * number without one ("2099") is part of the title. Volumes: v01, Vol. 3,
 * Volume 2, Book 4, Tome 1. Years: the first (19xx|20xx) in brackets.
 * Pure.
 */
final class ComicName
{
    /**
     * @return array{series: string, volume: ?float, issue: ?float, year: ?int, title: ?string, tpb: bool, digital: bool, omnibus: bool, pack: ?array{kind: string, from: float, to: float}}
     */
    public static function parse(string $name): array
    {
        $base = $name;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== '' && (in_array($ext, ComicLibrary::EXTENSIONS, true) || in_array($ext, ['zip', 'rar', '7z', 'tar', 'nzb', 'torrent'], true))) {
            $base = substr($name, 0, -strlen($ext) - 1);
        }
        // "Saga.v01.2012.Digital" / "Saga_v01": dots and underscores are spaces when there are none.
        if (!str_contains($base, ' ')) {
            $base = str_replace(['_', '.'], ' ', $base);
        }
        $base = str_replace('_', ' ', $base);

        $out = ['series' => '', 'volume' => null, 'issue' => null, 'year' => null, 'title' => null,
            'tpb' => false, 'digital' => false, 'omnibus' => false, 'pack' => null];

        // Bracket groups: (2012), (Digital), [Group], {tags}.
        if (preg_match_all('/[\(\[\{]([^\)\]\}]*)[\)\]\}]/u', $base, $groups)) {
            foreach ($groups[1] as $group) {
                $g = trim($group);
                if ($out['year'] === null && preg_match('/^((?:19|20)\d{2})(?:\s*-\s*(?:19|20)?\d{2,4})?$/', $g, $m)) {
                    $out['year'] = (int) $m[1];
                }
                self::flags($g, $out);
            }
        }
        $main = trim(preg_replace('/[\(\[\{][^\)\]\}]*[\)\]\}]/u', ' ', $base) ?? $base);

        // Edition words in the name itself.
        if (preg_match('/\b(tpb|trade paperback)\b/i', $main)) {
            $out['tpb'] = true;
        }
        if (preg_match('/\bomnibus\b/i', $main)) {
            $out['omnibus'] = true;
        }
        $main = preg_replace('/\b(tpb|trade paperback|digital|hc|ogn|gn|c2c|webrip|scan|omnibus|complete|complete series)\b/i', ' ', $main) ?? $main;

        // A year outside brackets ("Saga.v01.2012.Digital"): not the first
        // word ("2000 AD") and not in the future ("Spider-Man 2099").
        if ($out['year'] === null && preg_match('/^(.+?\S)\s+((?:19|20)\d{2})(?=\s|$)/u', $main, $m) && (int) $m[2] <= (int) date('Y') + 1
            && !preg_match('/^\s*(?:v|vol\.?|volume)\s*$/i', $m[1])) {
            $out['year'] = (int) $m[2];
            $main = trim(substr($main, 0, strpos($main, $m[2], strlen($m[1]))) . ' ' . substr($main, strpos($main, $m[2], strlen($m[1])) + 4));
        }

        // Packs: "Vol 1-9", "v01-v09", "#1-50", "001-012".
        if (preg_match('/\b(?:v|vol\.?|volume|volumes|vols\.?)\s*0*(\d{1,3}(?:\.\d)?)\s*(?:-|–|to)\s*(?:v|vol\.?|volume)?\s*0*(\d{1,3}(?:\.\d)?)\b/iu', $main, $m)) {
            $out['pack'] = ['kind' => 'volume', 'from' => (float) $m[1], 'to' => (float) $m[2]];
            $main = str_replace($m[0], ' ', $main);
        } elseif (preg_match('/(?:#|\s)0*(\d{1,4})\s*(?:-|–)\s*#?0*(\d{1,4})(?=\s|$)/u', ' ' . $main, $m) && (int) $m[2] > (int) $m[1]
            && !preg_match('/^(19|20)\d{2}$/', $m[1])) {
            $out['pack'] = ['kind' => 'issue', 'from' => (float) $m[1], 'to' => (float) $m[2]];
            $main = trim(substr(' ' . $main, 0, strpos(' ' . $main, $m[0])));
        }

        // A subtitle after " - " (taken once the number is found before it).
        $title = null;
        $parts = preg_split('/\s+(?:-|–|—|:)\s+/u', $main, 2);
        $head = trim($parts[0] ?? $main);
        $tail = isset($parts[1]) ? trim($parts[1]) : null;

        // Volume.
        if ($out['pack'] === null && preg_match('/\b(?:v|vol\.?|volume|book|tome|bk\.?)\s*0*(\d{1,3}(?:\.\d)?)\b/iu', $head, $m)) {
            $out['volume'] = (float) $m[1];
            $after = trim(substr($head, strpos($head, $m[0]) + strlen($m[0])));
            $head = trim(substr($head, 0, strpos($head, $m[0])));
            $title = $tail ?? ($after !== '' ? $after : null);
            $tail = null;
        } elseif ($out['pack'] === null && $tail !== null && preg_match('/^(?:v|vol\.?|volume|book|tome)\s*0*(\d{1,3}(?:\.\d)?)\b\s*(.*)$/iu', $tail, $m)) {
            // "Saga - Volume 1 - …"
            $out['volume'] = (float) $m[1];
            $rest = trim(preg_replace('/^(?:-|–|—|:)\s*/u', '', $m[2]) ?? '');
            $title = $rest !== '' ? $rest : null;
            $tail = null;
        }

        // Issue.
        if ($out['pack'] === null && $out['volume'] === null) {
            if (preg_match('/#\s*0*(\d{1,4}(?:\.\d{1,2})?)\b(.*)$/u', $head, $m)) {
                $out['issue'] = (float) $m[1];
                $head = trim(substr($head, 0, strpos($head, '#')));
                $title = $tail ?? (trim($m[2]) !== '' ? trim($m[2]) : null);
                $tail = null;
            } elseif (preg_match('/\s(\d{1,4}(?:\.\d{1,2})?)$/u', ' ' . $head, $m) && self::looksLikeIssue($m[1], $head)) {
                $out['issue'] = (float) $m[1];
                $head = trim(substr(' ' . $head, 0, -strlen($m[0])));
                $title = $tail;
                $tail = null;
            } elseif ($tail !== null && preg_match('/^#?\s*(\d{1,4}(?:\.\d{1,2})?)\b\s*(.*)$/u', $tail, $m) && self::looksLikeIssue($m[1], $head)) {
                $out['issue'] = (float) $m[1];
                $rest = trim(preg_replace('/^(?:-|–|—|:)\s*/u', '', $m[2]) ?? '');
                $title = $rest !== '' ? $rest : null;
                $tail = null;
            }
        }
        if ($tail !== null) {
            // No number: "Batman - The Killing Joke" is one series' title.
            $head = $head . ' - ' . $tail;
        }

        $series = trim(preg_replace('/\s+/u', ' ', $head) ?? $head, " \t-–—:,.");
        $out['series'] = $series !== '' ? $series : trim(preg_replace('/\s+/u', ' ', $main) ?? $main);
        $out['title'] = $title !== null && trim($title, " -–—:") !== '' ? trim($title, " -–—:") : null;

        return $out;
    }

    /** "001", "54", "#7": an issue; "2099" (four digits, no leading zero) is part of a title, as is a lone year. */
    private static function looksLikeIssue(string $number, string $head): bool
    {
        $int = explode('.', $number)[0];
        if (trim(substr(' ' . $head, 0, -strlen($number) - 1)) === '') {
            return false; // the whole name is a number
        }
        if (strlen($int) <= 3) {
            return true;
        }

        return $int[0] === '0';
    }

    private static function flags(string $group, array &$out): void
    {
        $g = strtolower($group);
        if (preg_match('/\bdigital\b|\bwebrip\b/', $g)) {
            $out['digital'] = true;
        }
        if (preg_match('/\btpb\b|trade paperback/', $g)) {
            $out['tpb'] = true;
        }
        if (str_contains($g, 'omnibus')) {
            $out['omnibus'] = true;
        }
    }

    /** "Volume 3", "#54", "Volume 1 – Safety Behind Bars": a chapter label from parse()'s answer (or ComicInfo's). */
    public static function label(?float $volume, ?float $issue, ?string $title, string $fallback): string
    {
        $number = fn(float $n) => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
        $head = $volume !== null ? 'Volume ' . $number($volume) : ($issue !== null ? '#' . $number($issue) : null);
        if ($head === null) {
            return mb_substr($title ?: $fallback, 0, 250);
        }

        return mb_substr($title ? "{$head} – {$title}" : $head, 0, 250);
    }
}
