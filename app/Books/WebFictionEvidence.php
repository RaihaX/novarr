<?php

namespace App\Books;

use App\Novel;
use App\Sources\BookSource;
use App\Sources\MangaDexSource;
use App\Sources\SuwayomiSource;

/**
 * Is a book web fiction, by its own ePub? No network: the package metadata
 * (Epub::metadata(): source_urls, identifiers, producers, publisher) of the
 * original file at BookLibrary::path().
 *
 * Web fiction when any of (first hit wins, in this order):
 *
 *   source     a dc:source or URL identifier on a web-fiction host
 *              (hosts(): config novarr.books.web_fiction_hosts plus the
 *              hosts of Novarr's own novel sources; subdomains count, so
 *              forums.spacebattles.com is spacebattles.com);
 *   uid        a FanFicFare / FFDL uid naming such a host
 *              ("fanficfare-uid:storiesonline.net-u123-s68384");
 *   sol        a World Literature Company storiesonline.net id (an
 *              identifier whose scheme, id or value carries "SOL-ID" /
 *              "WLC-SOL"): WLC's mirror of storiesonline.net;
 *   publisher  the publisher is such a host ("storiesonline.net",
 *              "www.royalroad.com") or its name ("Royal Road",
 *              "Archive of Our Own": the host's first label, spaces
 *              removed);
 *   producer   made by a web-fiction downloader (FanFicFare, FFDL,
 *              FicHub, WebToEpub) even without a source URL.
 *
 * Calibre alone (a calibre "bkp" contributor, a urn:uuid) says nothing:
 * every Calibre book has one.
 */
class WebFictionEvidence
{
    /** Producers (dc:contributor role bkp, generator meta) that only download web fiction. */
    public const PRODUCERS = '/\b(?:fanficfare|fanficdownloader|ffdl|fichub|webtoepub|web\s*to\s*epub)\b/i';

    /**
     * The web-fiction hosts, lowercase without "www.".
     *
     * @return string[]
     */
    public static function hosts(): array
    {
        $hosts = (array) config('novarr.books.web_fiction_hosts', []);
        foreach ((array) config('novarr.sources', []) as $class) {
            if (!is_string($class) || !class_exists($class)
                || in_array($class, [BookSource::class, MangaDexSource::class, SuwayomiSource::class], true)
                || is_subclass_of($class, BookSource::class)) {
                continue;
            }
            try {
                $hosts = array_merge($hosts, (array) app($class)->hosts());
            } catch (\Throwable) {
                // A source that cannot be built adds nothing.
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn($h) => self::bareHost((string) $h),
            $hosts
        ), 'strlen')));
    }

    /** Is $host (a web-fiction host from hosts()) one whose stories are translations? */
    public static function isTranslatedHost(string $host): bool
    {
        $translated = array_map(fn($h) => self::bareHost((string) $h), (array) config('novarr.books.web_fiction_translated_hosts', []));

        return in_array(self::bareHost($host), $translated, true);
    }

    /**
     * The evidence in a book's original ePub, or null when there is none.
     * Throws nothing: a missing file is ['missing' => true] (the caller
     * tries again later), an unreadable one is null.
     *
     * @return ?array{rule: string, host: ?string, url: ?string, translated: bool, reason: string, missing?: bool}
     */
    public static function forBook(Novel $book): ?array
    {
        $path = BookLibrary::path($book);
        if (!is_file($path)) {
            return ['missing' => true, 'rule' => 'missing', 'host' => null, 'url' => null, 'translated' => false, 'reason' => 'the ePub file is missing'];
        }
        try {
            $epub = Epub::open($path);
            try {
                $metadata = $epub->metadata();
            } finally {
                $epub->close();
            }
        } catch (\Throwable) {
            return null;
        }

        return self::fromMetadata($metadata);
    }

    /**
     * The evidence in an ePub's metadata (Epub::metadata()), or null. Pure.
     *
     * @return ?array{rule: string, host: ?string, url: ?string, translated: bool, reason: string}
     */
    public static function fromMetadata(array $metadata): ?array
    {
        $hosts = self::hosts();
        $found = fn(string $rule, ?string $host, ?string $url, string $reason) => [
            'rule' => $rule,
            'host' => $host,
            'url' => $url,
            'translated' => $host !== null && self::isTranslatedHost($host),
            'reason' => $reason,
        ];

        // 1. A source URL (dc:source, URL identifiers).
        foreach ((array) ($metadata['source_urls'] ?? []) as $source) {
            $host = self::webHost((string) $source, $hosts);
            if ($host !== null) {
                $url = preg_match('#^https?://#i', trim((string) $source)) ? trim((string) $source) : null;

                return $found('source', $host, $url, "web fiction from {$host}");
            }
        }

        $identifiers = (array) ($metadata['identifiers'] ?? []);
        $publisher = trim((string) ($metadata['publisher'] ?? ''));

        // 2. A FanFicFare / FFDL uid: "fanficfare-uid:storiesonline.net-u123-s68384".
        foreach ($identifiers as $identifier) {
            $value = trim((string) ($identifier['value'] ?? ''));
            if (preg_match('/^(?:fanficfare|ffdl|fanficdownloader)-uid:([a-z0-9.-]+?\.[a-z]{2,})(?:-|$)/i', $value, $m)) {
                $host = self::webHost($m[1], $hosts);
                if ($host !== null) {
                    return $found('uid', $host, null, "web fiction from {$host}");
                }
            }
        }

        // 3. World Literature Company's storiesonline.net ids.
        foreach ($identifiers as $identifier) {
            $haystack = implode(' ', [(string) ($identifier['scheme'] ?? ''), (string) ($identifier['id'] ?? ''), (string) ($identifier['value'] ?? '')]);
            if (preg_match('/\bWLC-SOL\b|\bSOL-ID\b/i', $haystack)) {
                return $found('sol', 'storiesonline.net', null, 'web fiction from storiesonline.net (World Literature Company)');
            }
        }

        // 4. The publisher is a web-fiction host, or its name.
        if ($publisher !== '') {
            $host = self::webHost($publisher, $hosts) ?? self::hostByName($publisher, $hosts);
            if ($host !== null) {
                return $found('publisher', $host, null, "web fiction from {$host}");
            }
        }

        // 5. A web-fiction downloader made it.
        foreach ((array) ($metadata['producers'] ?? []) as $producer) {
            if (preg_match(self::PRODUCERS, (string) $producer, $m)) {
                $tool = trim(preg_replace('/\s*[\[(].*$/', '', (string) $producer) ?? '') ?: $m[0];

                return $found('producer', null, null, "web fiction ({$tool} download)");
            }
        }

        return null;
    }

    /** The web-fiction host of a URL or a bare host ("storiesonline.net"), or null. */
    public static function webHost(string $value, ?array $hosts = null): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $host = preg_match('#^[a-z][a-z0-9+.-]*://#i', $value)
            ? (string) parse_url($value, PHP_URL_HOST)
            : (preg_match('#^([a-z0-9-]+(?:\.[a-z0-9-]+)+)(?:[/:?\#]|$)#i', $value, $m) ? $m[1] : '');
        $host = self::bareHost($host);
        if ($host === '') {
            return null;
        }
        foreach ($hosts ?? self::hosts() as $web) {
            if ($host === $web || str_ends_with($host, '.' . $web)) {
                return $web;
            }
        }

        return null;
    }

    /** "Royal Road", "Archive of Our Own" → the host whose first label is that name, spaces removed. */
    private static function hostByName(string $name, array $hosts): ?string
    {
        $key = preg_replace('/[^a-z0-9]+/', '', strtolower(\Illuminate\Support\Str::ascii($name))) ?? '';
        if (strlen($key) < 5) {
            return null;
        }
        foreach ($hosts as $host) {
            if (explode('.', $host)[0] === $key) {
                return $host;
            }
        }

        return null;
    }

    private static function bareHost(string $host): string
    {
        $host = strtolower(trim($host));

        return (string) preg_replace('/^www\./', '', $host);
    }
}
