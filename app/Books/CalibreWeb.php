<?php

namespace App\Books;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The owner's Calibre-Web, read through its OPDS feed (HTTP basic auth):
 * the newest books (/opds/new), a search (/opds/search?query=), each book's
 * ePub (/opds/download/{id}/epub/) and cover (/opds/cover/{id}).
 *
 * Off until config novarr.calibre_web.url is set. Only books in the
 * configured languages are listed (Calibre's three-letter codes, "eng"); a
 * book with no language in Calibre is listed too. Plain Calibre-Web and
 * Calibre-Web-Automated serve the same feed.
 */
class CalibreWeb
{
    public static function enabled(): bool
    {
        return self::base() !== '';
    }

    public static function base(): string
    {
        return rtrim(trim((string) config('novarr.calibre_web.url', '')), '/');
    }

    /**
     * One page of the newest books, newest first.
     *
     * @return array{entries: array<int, array>, next: ?int}  next: the offset of the next page, null at the end
     */
    public function recent(int $offset = 0): array
    {
        return $this->feed('/opds/new', ['offset' => max(0, $offset)]);
    }

    /** @return array{entries: array<int, array>, next: ?int} */
    public function search(string $query, int $offset = 0): array
    {
        return $this->feed('/opds/search', ['query' => trim($query), 'offset' => max(0, $offset)]);
    }

    /**
     * Download a book's ePub to a temporary file (the caller deletes it).
     *
     * @throws \RuntimeException
     */
    public function downloadEpub(array $entry): string
    {
        if (empty($entry['epub_href'])) {
            throw new \RuntimeException("“{$entry['title']}” has no ePub in Calibre.");
        }
        $target = tempnam(sys_get_temp_dir(), 'novarr-calibre-');
        $max = max(1, (int) config('novarr.books.max_upload_mb', 50)) * 1024 * 1024;

        try {
            $response = $this->client(300)->withOptions(['sink' => $target])->get($this->absolute($entry['epub_href']));
        } catch (\Throwable $e) {
            @unlink($target);
            throw new \RuntimeException('Calibre-Web did not answer: ' . $e->getMessage());
        }
        if (!$response->successful()) {
            @unlink($target);
            throw new \RuntimeException('Calibre-Web answered ' . $response->status() . ' for the ePub of “' . $entry['title'] . '”.');
        }
        clearstatcache(true, $target);
        if (filesize($target) > $max) {
            @unlink($target);
            throw new \RuntimeException("The ePub of “{$entry['title']}” is larger than the upload limit.");
        }

        return $target;
    }

    /** A book's cover image bytes from Calibre, or null. */
    public function cover(array $entry): ?string
    {
        if (empty($entry['cover_href'])) {
            return null;
        }
        try {
            $response = $this->client()->get($this->absolute($entry['cover_href']));
        } catch (\Throwable) {
            return null;
        }
        $bytes = $response->successful() ? $response->body() : '';

        return $bytes !== '' && @getimagesizefromstring($bytes) ? $bytes : null;
    }

    /** Can the feed be read with the configured account? Null when it can, else why not. */
    public function problem(): ?string
    {
        if (!self::enabled()) {
            return 'Calibre-Web is not set up (NOVARR_CALIBRE_WEB_URL).';
        }
        try {
            $response = $this->client()->get(self::base() . '/opds');
        } catch (\Throwable $e) {
            return 'Calibre-Web did not answer: ' . $e->getMessage();
        }

        return match (true) {
            $response->status() === 401 => 'Calibre-Web refused the username or password (NOVARR_CALIBRE_WEB_USERNAME / _PASSWORD).',
            !$response->successful() => 'Calibre-Web answered ' . $response->status() . '.',
            default => null,
        };
    }

    /** @return array{entries: array<int, array>, next: ?int} */
    private function feed(string $path, array $query): array
    {
        if (!self::enabled()) {
            return ['entries' => [], 'next' => null];
        }

        try {
            $response = $this->client()->get(self::base() . $path, $query);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Calibre-Web did not answer: ' . $e->getMessage());
        }
        if ($response->status() === 401) {
            throw new \RuntimeException('Calibre-Web refused the username or password.');
        }
        if (!$response->successful()) {
            throw new \RuntimeException('Calibre-Web answered ' . $response->status() . '.');
        }

        return self::parse($response->body());
    }

    /**
     * An OPDS acquisition feed as book entries (navigation entries are
     * skipped), keeping only the configured languages.
     *
     * @return array{entries: array<int, array>, next: ?int}
     */
    public static function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new \RuntimeException('Calibre-Web sent something that is not an OPDS feed.');
        }

        $x = new DOMXPath($doc);
        $text = fn(string $query, ?\DOMNode $context = null): ?string => ($node = $x->query($query, $context)->item(0)) ? (trim($node->textContent) ?: null) : null;
        $languages = array_map('strtolower', (array) config('novarr.calibre_web.languages', ['eng']));

        $entries = [];
        foreach ($x->query("/*[local-name()='feed']/*[local-name()='entry']") as $entry) {
            /** @var DOMElement $entry */
            $links = [];
            foreach ($x->query("*[local-name()='link']", $entry) as $link) {
                /** @var DOMElement $link */
                $links[] = ['rel' => $link->getAttribute('rel'), 'href' => $link->getAttribute('href'), 'type' => strtolower($link->getAttribute('type')), 'title' => strtolower($link->getAttribute('title'))];
            }
            $acquisitions = array_filter($links, fn($l) => str_starts_with($l['rel'], 'http://opds-spec.org/acquisition'));
            if ($acquisitions === []) {
                continue; // a navigation entry (a shelf, an author list)
            }
            $epub = null;
            foreach ($acquisitions as $link) {
                if ($link['type'] === 'application/epub+zip' || $link['title'] === 'epub' || preg_match('#/epub/?$#i', $link['href'])) {
                    $epub = $link['href'];
                    break;
                }
            }
            $cover = null;
            foreach ($links as $link) {
                if ($link['rel'] === 'http://opds-spec.org/image') {
                    $cover = $link['href'];
                    break;
                }
            }

            $langs = [];
            foreach ($x->query("*[local-name()='language']", $entry) as $node) {
                $langs[] = strtolower(trim($node->textContent));
            }
            if ($languages !== [] && $langs !== [] && array_intersect($langs, $languages) === []) {
                continue;
            }

            $authors = [];
            foreach ($x->query("*[local-name()='author']/*[local-name()='name']", $entry) as $node) {
                if (($name = trim($node->textContent)) !== '') {
                    $authors[] = $name;
                }
            }
            $tags = [];
            foreach ($x->query("*[local-name()='category']", $entry) as $node) {
                /** @var DOMElement $node */
                if (($tag = trim($node->getAttribute('label') ?: $node->getAttribute('term'))) !== '') {
                    $tags[] = $tag;
                }
            }

            // Calibre-Web's content: "RATING: …<br/>TAGS: …<br/>SERIES: name [1.00]<br/>…<p>comments</p>".
            $content = $x->query("*[local-name()='content']", $entry)->item(0);
            $description = null;
            $series = null;
            $seriesIndex = null;
            if ($content !== null) {
                $paragraphs = [];
                foreach ($x->query(".//*[local-name()='p']", $content) as $p) {
                    $paragraphs[] = $doc->saveXML($p);
                }
                $description = $paragraphs !== [] ? (Epub::plainDescription(implode('', $paragraphs)) ?: null) : null;
                if (preg_match('/SERIES:\s*(.+?)\s*\[([\d.]+)\]/u', $content->textContent, $m)) {
                    $series = trim($m[1]);
                    $seriesIndex = (float) $m[2];
                }
            }

            $id = null;
            foreach ([$epub, $cover, ...array_column($acquisitions, 'href')] as $href) {
                if ($href && preg_match('#/opds/(?:download|cover)/(\d+)#', $href, $m)) {
                    $id = (int) $m[1];
                    break;
                }
            }
            $uuid = preg_replace('/^urn:uuid:/i', '', (string) $text("*[local-name()='id']", $entry));
            $published = (string) $text("*[local-name()='published']", $entry);

            $entries[] = [
                'id' => $id,
                'uuid' => $uuid !== '' ? $uuid : null,
                'title' => (string) ($text("*[local-name()='title']", $entry) ?? 'Untitled'),
                'authors' => $authors,
                'publisher' => $text("*[local-name()='publisher']/*[local-name()='name']", $entry),
                'year' => preg_match('/^(\d{4})/', $published, $m) && (int) $m[1] > 101 ? (int) $m[1] : null,
                'languages' => $langs,
                'tags' => array_values(array_unique($tags)),
                'description' => $description,
                'series' => $series,
                'series_index' => $seriesIndex,
                'cover_href' => $cover,
                'epub_href' => $epub,
            ];
        }

        $next = null;
        $nextLink = $x->query("/*[local-name()='feed']/*[local-name()='link'][@rel='next']")->item(0);
        if ($nextLink instanceof DOMElement && preg_match('/[?&]offset=(\d+)/', $nextLink->getAttribute('href'), $m)) {
            $next = (int) $m[1];
        }

        return ['entries' => $entries, 'next' => $next];
    }

    /** A feed href ("/opds/download/5/epub/") as a URL on the configured server. */
    private function absolute(string $href): string
    {
        if (preg_match('#^https?://#i', $href)) {
            // Only ever the configured server: a feed cannot send the credentials elsewhere.
            $host = parse_url($href, PHP_URL_HOST);
            if ($host !== parse_url(self::base(), PHP_URL_HOST)) {
                throw new \RuntimeException('Calibre-Web linked to another server.');
            }

            return $href;
        }
        // Calibre-Web behind a path prefix links with that prefix already.
        $basePath = rtrim((string) parse_url(self::base(), PHP_URL_PATH), '/');
        $origin = substr(self::base(), 0, strlen(self::base()) - strlen($basePath));

        return $origin . '/' . ltrim($href, '/');
    }

    private function client(int $timeout = 0)
    {
        $client = Http::timeout($timeout ?: max(1, (int) config('novarr.calibre_web.timeout', 30)))
            ->withUserAgent('Novarr (self-hosted reading server)');
        $user = (string) config('novarr.calibre_web.username', '');
        if ($user !== '') {
            $client = $client->withBasicAuth($user, (string) config('novarr.calibre_web.password', ''));
        }

        return $client;
    }
}
