<?php

namespace App\Sources;

/**
 * Merges a source site's own metadata with the NovelUpdates metadata
 * according to a per-source precedence definition (data, e.g. an adapter's
 * METADATA_PRECEDENCE constant), so the adapters don't each carry their own
 * merge code.
 *
 * Sides are named 'site' and 'novelupdates'; each may be given as an array
 * or as a callable that fetches it (called at most once). Precedence keys:
 *
 *   fetch      Order the two sides are fetched in, e.g. ['site', 'novelupdates'].
 *   lazy       true: only the BASE side is fetched up front; the other side
 *              is fetched the first time a step needs it (and not at all when
 *              none does). false: both are fetched up front, in `fetch` order.
 *   base       The side the result starts as a copy of: its keys (and their
 *              order) are kept, the other side's keys only arrive through the
 *              rules below.
 *   steps      Order the rules run in (it decides where new keys land):
 *              any of 'fields', 'status', 'copy', 'covers'.
 *   fields     field => preferred side. Preferred side is the base: the
 *              other side fills the field when the base value is empty() and
 *              the other's is not. Preferred side is the other one: its value
 *              replaces the base's when it is not empty().
 *   gate       Fields of the base; when given, the OTHER side contributes to
 *              `fields` and `covers` only if at least one of them is empty()
 *              on the base. Omitted: always.
 *   ungated    Fields of `fields` the gate does not apply to: the other side
 *              still fills them when the gate shuts it out of the rest. The
 *              other side is only fetched for one when the rule needs its
 *              value (for a base-preferred field: the base value is empty()),
 *              so with `lazy` a complete base still costs no second fetch.
 *   status     null: status_text / completed are the base's. Otherwise
 *              ['mode' => …, 'prefer' => side]:
 *                'fill'    (prefer = base) — when the base's status_text is
 *                          empty() and the other's is not, both status_text
 *                          and completed (cast to bool) come from the other.
 *                'resolve' — status_text = preferred's ?: other's (always
 *                          set, '' when neither has one); completed = the
 *                          preferred side's (bool) when its status_text is
 *                          not '' (null counts as ''), else the other's.
 *   copy       field => side: always set to that side's value (null when
 *              absent).
 *   covers     Sides in order; cover_candidates = their non-empty 'image'
 *              values (list, de-duplicated by trimmed URL, first seen kept;
 *              see uniqueCovers()).
 */
final class MetadataMerger
{
    public const SITE = 'site';
    public const NOVELUPDATES = 'novelupdates';

    /**
     * @param array|callable(): array $site
     * @param array|callable(): array $novelUpdates
     */
    public static function merge(array|callable $site, array|callable $novelUpdates, array $precedence): array
    {
        $sides = [self::SITE => $site, self::NOVELUPDATES => $novelUpdates];
        $resolved = [];
        $get = function (string $side) use (&$sides, &$resolved): array {
            if (!array_key_exists($side, $resolved)) {
                $value = $sides[$side];
                $resolved[$side] = is_array($value) ? $value : (array) $value();
            }

            return $resolved[$side];
        };

        $base = $precedence['base'];
        $other = $base === self::SITE ? self::NOVELUPDATES : self::SITE;

        if (!empty($precedence['lazy'])) {
            $get($base);
        } else {
            foreach ($precedence['fetch'] ?? [self::SITE, self::NOVELUPDATES] as $side) {
                $get($side);
            }
        }

        $result = $get($base);

        // Does the other side get a say in fields / covers?
        $gate = $precedence['gate'] ?? null;
        $otherContributes = $gate === null;
        foreach ((array) $gate as $field) {
            if (empty($result[$field])) {
                $otherContributes = true;
                break;
            }
        }

        foreach ($precedence['steps'] as $step) {
            switch ($step) {
                case 'fields':
                    $ungated = (array) ($precedence['ungated'] ?? []);
                    foreach ($precedence['fields'] ?? [] as $field => $preferred) {
                        if (!$otherContributes) {
                            // Gate shut: only ungated fields, and only when
                            // the rule can use the other side's value.
                            if (!in_array($field, $ungated, true)
                                || ($preferred === $base && !empty($result[$field]))) {
                                continue;
                            }
                        }
                        $theirs = $get($other);
                        if ($preferred === $base) {
                            if (empty($result[$field]) && !empty($theirs[$field])) {
                                $result[$field] = $theirs[$field];
                            }
                        } elseif (!empty($theirs[$field])) {
                            $result[$field] = $theirs[$field];
                        }
                    }
                    break;

                case 'status':
                    $rule = $precedence['status'] ?? null;
                    if ($rule === null) {
                        break;
                    }
                    if ($rule['mode'] === 'fill') {
                        if (empty($result['status_text'])) {
                            $theirs = $get($other);
                            if (!empty($theirs['status_text'])) {
                                $result['status_text'] = $theirs['status_text'];
                                $result['completed'] = (bool) ($theirs['completed'] ?? false);
                            }
                        }
                    } elseif ($rule['mode'] === 'resolve') {
                        $preferred = $get($rule['prefer']);
                        $second = $get($rule['prefer'] === self::SITE ? self::NOVELUPDATES : self::SITE);
                        $result['status_text'] = ($preferred['status_text'] ?? '') ?: ($second['status_text'] ?? '');
                        $result['completed'] = ($preferred['status_text'] ?? '') !== ''
                            ? (bool) ($preferred['completed'] ?? false)
                            : (bool) ($second['completed'] ?? false);
                    }
                    break;

                case 'copy':
                    foreach ($precedence['copy'] ?? [] as $field => $side) {
                        $result[$field] = $get($side)[$field] ?? null;
                    }
                    break;

                case 'covers':
                    $images = [];
                    foreach ($precedence['covers'] ?? [] as $side) {
                        if ($side !== $base && !$otherContributes) {
                            continue;
                        }
                        $images[] = $get($side)['image'] ?? null;
                    }
                    $result['cover_candidates'] = self::uniqueCovers(array_filter($images));
                    break;
            }
        }

        return $result;
    }

    /**
     * Cover URLs without duplicates, in first-seen order. URLs are compared
     * after trim() and otherwise as-is (no case or scheme folding); the
     * first occurrence is kept unchanged.
     *
     * @param iterable<mixed> $urls
     * @return list<mixed>
     */
    public static function uniqueCovers(iterable $urls): array
    {
        $seen = [];
        $unique = [];
        foreach ($urls as $url) {
            $key = is_string($url) ? trim($url) : $url;
            if (!is_scalar($key)) {
                $unique[] = $url; // not a URL string: leave it alone
                continue;
            }
            $key = (string) $key;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $url;
        }

        return $unique;
    }
}
