<?php

namespace App\Books;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * Reads an ePub (2 or 3) without unpacking it: the package document's
 * metadata, the cover image, and the book split into chapters along its
 * table of contents (EPUB 3 nav, else the EPUB 2 NCX, else one chapter per
 * spine document).
 *
 * Only named entries are read from the zip (nothing is extracted to disk),
 * each capped at MAX_ENTRY_BYTES, and XML is parsed without external
 * entities or network access.
 */
class Epub
{
    /** Largest single document, cover or contents file read from the zip. */
    public const MAX_ENTRY_BYTES = 32 * 1024 * 1024;

    /** A section with fewer words is merged into the section after it (a part title page). */
    public const MIN_SECTION_WORDS = 15;

    /** Unnamed text before the first contents entry is kept only from this many words. */
    public const MIN_FRONT_MATTER_WORDS = 150;

    /**
     * Sections the contents names that are no part of the text: a list of
     * the chapters, the copyright and publisher pages.
     */
    /** A section longer than this is cut at its own chapter headings, or into parts. */
    public const SPLIT_ABOVE_WORDS = 15000;

    /** Words per part when a long section has no headings to cut at. */
    public const PART_WORDS = 5000;

    /** A "boilerplate" section longer than this is real text with a misleading label. */
    public const MAX_BOILERPLATE_WORDS = 1500;

    private const BOILERPLATE = '/^(?:(?:table of )?contents|toc|imprint|colophon|uncopyright|copyright(?: page)?|title ?page|half ?title|cover)$/i';

    private ZipArchive $zip;

    private string $opfPath;

    private DOMXPath $opf;

    /** @var array<string, array{href: string, type: string, properties: string}> manifest id => item (href is the zip path) */
    private array $manifest = [];

    /** @var string[] zip paths of the linear spine documents, in reading order */
    private array $spine = [];

    private ?string $ncxId = null;

    private function __construct()
    {
    }

    /** @throws EpubException */
    public static function open(string $path): self
    {
        $zip = new ZipArchive();
        if (!is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new EpubException('This file is not an ePub (it could not be opened as one).');
        }

        $epub = new self();
        $epub->zip = $zip;
        $epub->readPackage();

        return $epub;
    }

    public function close(): void
    {
        $this->zip->close();
    }

    /**
     * The package metadata. Missing values are null / [].
     *
     * @return array{title: ?string, authors: string[], description: ?string, language: ?string,
     *               isbn: ?string, subjects: string[], publisher: ?string, year: ?int,
     *               series: ?string, series_index: ?float, source_urls: string[],
     *               producers: string[], identifiers: array<int, array{value: string, scheme: ?string, id: ?string}>}
     *
     * source_urls  where the text came from: every dc:source, and every
     *              dc:identifier that is a URL (opf:scheme "URL" or an
     *              http(s) value), as written ("http://storiesonline.net/s/68384").
     * producers    the tool that made the file: dc:contributor with role
     *              "bkp" (book producer: "FanFicFare [https://…]",
     *              "calibre (7.1.0) [https://calibre-ebook.com]") and
     *              <meta name="generator"> values.
     * identifiers  every dc:identifier with its opf:scheme and id attribute
     *              ("fanficfare-uid:storiesonline.net-u123-s68384", scheme
     *              "WLC-SOL-ID"…). App\Books\WebFictionEvidence reads these.
     */
    public function metadata(): array
    {
        $text = fn(string $name): array => array_values(array_filter(array_map(
            fn(DOMElement $node) => EpubHtml::squash($node->textContent),
            iterator_to_array($this->opf->query("//*[local-name()='metadata']/*[local-name()='{$name}']"))
        ), fn($value) => $value !== ''));

        $authors = [];
        foreach ($this->opf->query("//*[local-name()='metadata']/*[local-name()='creator']") as $creator) {
            /** @var DOMElement $creator */
            $name = EpubHtml::squash($creator->textContent);
            if ($name === '') {
                continue;
            }
            $roles = array_filter([$creator->getAttributeNS('http://www.idpf.org/2007/opf', 'role') ?: $creator->getAttribute('opf:role')]);
            if ($roles === [] && $creator->hasAttribute('id')) {
                // EPUB 3: one <meta property="role"> per role ("aut", "ann", …).
                $roles = $this->refinesAll($creator->getAttribute('id'), 'role');
            }
            if ($roles === [] || in_array('aut', array_map('strtolower', $roles), true)) {
                $authors[] = $name;
            }
        }

        $description = $text('description')[0] ?? null;
        if ($description !== null) {
            $description = self::plainDescription($description);
        }

        $year = null;
        foreach ($text('date') as $date) {
            if (preg_match('/\b(1[5-9]\d\d|20\d\d)\b/', $date, $m)) {
                $year = (int) $m[1];
                break;
            }
        }

        [$series, $seriesIndex] = $this->series();

        return [
            'title' => $text('title')[0] ?? null,
            'authors' => array_values(array_unique($authors)),
            'description' => $description !== '' ? $description : null,
            'language' => self::languageCode($text('language')[0] ?? null),
            'isbn' => $this->isbn(),
            'subjects' => array_values(array_unique($text('subject'))),
            'publisher' => $text('publisher')[0] ?? null,
            'year' => $year,
            'series' => $series,
            'series_index' => $seriesIndex,
        ] + $this->provenance();
    }

    /**
     * Where the file came from: source_urls, producers and identifiers
     * (see metadata()).
     *
     * @return array{source_urls: string[], producers: string[], identifiers: array<int, array{value: string, scheme: ?string, id: ?string}>}
     */
    private function provenance(): array
    {
        $opfNs = 'http://www.idpf.org/2007/opf';
        $attr = fn(DOMElement $node, string $name): ?string => (trim($node->getAttributeNS($opfNs, $name) ?: $node->getAttribute("opf:{$name}") ?: $node->getAttribute($name)) ?: null);
        $isUrl = fn(string $value): bool => (bool) preg_match('#^https?://[^\s/]+\.[^\s/]+#i', $value);

        $sources = [];
        foreach ($this->opf->query("//*[local-name()='metadata']/*[local-name()='source']") as $node) {
            /** @var DOMElement $node */
            $value = EpubHtml::squash($node->textContent);
            if ($value !== '') {
                $sources[] = $value;
            }
        }

        $identifiers = [];
        foreach ($this->opf->query("//*[local-name()='metadata']/*[local-name()='identifier']") as $node) {
            /** @var DOMElement $node */
            $value = EpubHtml::squash($node->textContent);
            if ($value === '') {
                continue;
            }
            $scheme = $attr($node, 'scheme');
            $id = $node->getAttribute('id') ?: null;
            $identifiers[] = ['value' => $value, 'scheme' => $scheme, 'id' => $id];
            $url = preg_replace('/^url:/i', '', $value) ?? $value;
            if (strcasecmp((string) $scheme, 'URL') === 0 || $isUrl($url)) {
                $sources[] = $url;
            }
        }

        $producers = [];
        foreach ($this->opf->query("//*[local-name()='metadata']/*[local-name()='contributor']") as $node) {
            /** @var DOMElement $node */
            $name = EpubHtml::squash($node->textContent);
            if ($name === '') {
                continue;
            }
            $roles = array_filter([$attr($node, 'role')]);
            if ($roles === [] && $node->hasAttribute('id')) {
                $roles = $this->refinesAll($node->getAttribute('id'), 'role');
            }
            if (in_array('bkp', array_map('strtolower', $roles), true)) {
                $producers[] = $name;
            }
        }
        foreach ($this->opf->query("//*[local-name()='metadata']/*[local-name()='meta'][@name='generator']") as $node) {
            /** @var DOMElement $node */
            $content = EpubHtml::squash($node->getAttribute('content'));
            if ($content !== '') {
                $producers[] = $content;
            }
        }

        return [
            'source_urls' => array_values(array_unique($sources)),
            'producers' => array_values(array_unique($producers)),
            'identifiers' => $identifiers,
        ];
    }

    /**
     * The cover image: the item the metadata names, else the EPUB 3
     * cover-image item, else the first image of the guide's cover page,
     * else an image item called "cover".
     *
     * @return ?array{bytes: string, type: string, name: string}
     */
    public function cover(): ?array
    {
        $candidates = [];

        foreach ($this->opf->query("//*[local-name()='metadata']/*[local-name()='meta'][@name='cover']") as $meta) {
            /** @var DOMElement $meta */
            $ref = $meta->getAttribute('content');
            if (isset($this->manifest[$ref])) {
                $candidates[] = $this->manifest[$ref]['href'];
            } else {
                // Some writers put the href, not the id, in content.
                $candidates[] = $this->resolve(dirname($this->opfPath), $ref)[0];
            }
        }
        foreach ($this->manifest as $item) {
            if (preg_match('/(^|\s)cover-image(\s|$)/', $item['properties'])) {
                $candidates[] = $item['href'];
            }
        }
        foreach ($this->opf->query("//*[local-name()='guide']/*[local-name()='reference'][@type='cover']") as $reference) {
            /** @var DOMElement $reference */
            [$page] = $this->resolve(dirname($this->opfPath), $reference->getAttribute('href'));
            if ($image = $this->firstImageIn($page)) {
                $candidates[] = $image;
            }
        }
        foreach ($this->manifest as $id => $item) {
            if (str_starts_with($item['type'], 'image/') && (stripos($id, 'cover') !== false || stripos(basename($item['href']), 'cover') !== false)) {
                $candidates[] = $item['href'];
            }
        }

        foreach (array_unique($candidates) as $path) {
            $bytes = $this->read($path);
            if ($bytes === null || $bytes === '') {
                continue;
            }
            $info = @getimagesizefromstring($bytes);
            if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
                return ['bytes' => $bytes, 'type' => $info['mime'], 'name' => basename($path)];
            }
        }

        return null;
    }

    /**
     * The book as chapters in reading order, each a label and its "<p>…</p>"
     * body.
     *
     * @return array<int, array{label: string, html: string, words: int}>
     */
    public function chapters(): array
    {
        $entries = $this->contents();
        $documents = [];
        foreach ($this->spine as $path) {
            $xhtml = $this->read($path);
            $documents[$path] = $xhtml === null ? [] : EpubHtml::blocks($xhtml);
        }

        $sections = $entries === []
            ? $this->sectionsPerDocument($documents)
            : $this->sectionsFromContents($entries, $documents);

        $sections = $this->splitLongSections($sections);

        // Title, copyright and similar pages are dropped while they are short:
        // a long one is the book itself under a misleading first label. A
        // list of the chapters is dropped whatever its length.
        $sections = array_values(array_filter(
            $sections,
            fn($section) => !preg_match(self::BOILERPLATE, trim($section['label']))
                || ($section['words'] > self::MAX_BOILERPLATE_WORDS && !preg_match('/^(?:(?:table of )?contents|toc)$/i', trim($section['label'])))
        ));

        return $this->mergeShortSections($sections);
    }

    // ---------------------------------------------------------------------
    // Package
    // ---------------------------------------------------------------------

    private function readPackage(): void
    {
        $container = $this->read('META-INF/container.xml');
        $opfPath = null;
        if ($container !== null && ($doc = self::xml($container))) {
            $rootfile = (new DOMXPath($doc))->query("//*[local-name()='rootfile']")->item(0);
            $opfPath = $rootfile instanceof DOMElement ? ltrim($rootfile->getAttribute('full-path'), '/') : null;
        }
        // No container.xml: the first .opf in the zip.
        if ($opfPath === null || $opfPath === '') {
            for ($i = 0; $i < $this->zip->numFiles; $i++) {
                $name = (string) $this->zip->getNameIndex($i);
                if (str_ends_with(strtolower($name), '.opf')) {
                    $opfPath = $name;
                    break;
                }
            }
        }

        $opf = $opfPath ? $this->read($opfPath) : null;
        $doc = $opf !== null ? self::xml($opf) : null;
        if ($doc === null) {
            throw new EpubException('This ePub has no readable package document (content.opf).');
        }

        $this->opfPath = $opfPath;
        $this->opf = new DOMXPath($doc);
        $base = dirname($opfPath);

        foreach ($this->opf->query("//*[local-name()='manifest']/*[local-name()='item']") as $item) {
            /** @var DOMElement $item */
            $id = $item->getAttribute('id');
            if ($id === '') {
                continue;
            }
            $this->manifest[$id] = [
                'href' => $this->resolve($base, $item->getAttribute('href'))[0],
                'type' => strtolower($item->getAttribute('media-type')),
                'properties' => $item->getAttribute('properties'),
            ];
        }

        $spine = $this->opf->query("//*[local-name()='spine']")->item(0);
        if ($spine instanceof DOMElement) {
            $this->ncxId = $spine->getAttribute('toc') ?: null;
            foreach ($this->opf->query("*[local-name()='itemref']", $spine) as $itemref) {
                /** @var DOMElement $itemref */
                if (strtolower($itemref->getAttribute('linear')) === 'no') {
                    continue;
                }
                $item = $this->manifest[$itemref->getAttribute('idref')] ?? null;
                if ($item !== null && !in_array($item['href'], $this->spine, true)) {
                    $this->spine[] = $item['href'];
                }
            }
        }

        if ($this->spine === []) {
            throw new EpubException('This ePub lists nothing to read (its spine is empty).');
        }
    }

    /** An EPUB 3 refinement of a metadata element ("role", "file-as", …). */
    private function refines(string $id, string $property): ?string
    {
        return $this->refinesAll($id, $property)[0] ?? null;
    }

    /** @return string[] every value of one refinement, in order */
    private function refinesAll(string $id, string $property): array
    {
        if (!preg_match('/^[\w.:-]+$/u', $id)) {
            return [];
        }
        $values = [];
        foreach ($this->opf->query("//*[local-name()='meta'][@refines='#{$id}'][@property='{$property}']") as $node) {
            $value = EpubHtml::squash($node->textContent);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @return array{0: ?string, 1: ?float} */
    private function series(): array
    {
        $calibre = $this->opf->query("//*[local-name()='meta'][@name='calibre:series']")->item(0);
        if ($calibre instanceof DOMElement && trim($calibre->getAttribute('content')) !== '') {
            $index = $this->opf->query("//*[local-name()='meta'][@name='calibre:series_index']")->item(0);
            $value = $index instanceof DOMElement ? $index->getAttribute('content') : '';

            return [trim($calibre->getAttribute('content')), is_numeric($value) ? (float) $value : null];
        }

        foreach ($this->opf->query("//*[local-name()='meta'][@property='belongs-to-collection']") as $collection) {
            /** @var DOMElement $collection */
            $name = EpubHtml::squash($collection->textContent);
            $id = $collection->getAttribute('id');
            // A "set" is a reading list the book is on, not its series.
            if ($name === '' || ($id !== '' && strtolower((string) $this->refines($id, 'collection-type')) === 'set')) {
                continue;
            }
            $position = $id !== '' ? $this->refines($id, 'group-position') : null;

            return [$name, is_numeric($position) ? (float) $position : null];
        }

        return [null, null];
    }

    /** The first identifier that is a valid ISBN-13 or ISBN-10, digits only. */
    private function isbn(): ?string
    {
        foreach ($this->opf->query("//*[local-name()='metadata']/*[local-name()='identifier']") as $identifier) {
            /** @var DOMElement $identifier */
            $value = strtoupper(preg_replace('/[^0-9Xx]/', '', preg_replace('/^urn:isbn:/i', '', trim($identifier->textContent))) ?? '');
            if (self::validIsbn($value)) {
                return $value;
            }
        }

        return null;
    }

    public static function validIsbn(string $isbn): bool
    {
        if (preg_match('/^\d{13}$/', $isbn)) {
            if (!str_starts_with($isbn, '978') && !str_starts_with($isbn, '979')) {
                return false;
            }
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $isbn[$i] * ($i % 2 ? 3 : 1);
            }

            return (10 - $sum % 10) % 10 === (int) $isbn[12];
        }
        if (preg_match('/^\d{9}[\dX]$/', $isbn)) {
            $sum = 0;
            for ($i = 0; $i < 10; $i++) {
                $sum += ($isbn[$i] === 'X' ? 10 : (int) $isbn[$i]) * (10 - $i);
            }

            return $sum % 11 === 0;
        }

        return false;
    }

    /** "en", "en-GB", "eng", "English" → "en"; other languages to their two letters when known. */
    public static function languageCode(?string $language): ?string
    {
        $value = strtolower(trim((string) $language));
        if ($value === '' || $value === 'und') {
            return null;
        }
        $three = ['eng' => 'en', 'fre' => 'fr', 'fra' => 'fr', 'ger' => 'de', 'deu' => 'de', 'spa' => 'es', 'ita' => 'it',
            'jpn' => 'ja', 'kor' => 'ko', 'chi' => 'zh', 'zho' => 'zh', 'por' => 'pt', 'rus' => 'ru', 'dut' => 'nl', 'nld' => 'nl'];
        $names = ['english' => 'en', 'french' => 'fr', 'german' => 'de', 'spanish' => 'es', 'japanese' => 'ja', 'korean' => 'ko', 'chinese' => 'zh'];
        $primary = preg_split('/[-_]/', $value)[0];

        return $three[$primary] ?? $names[$primary] ?? (strlen($primary) === 2 ? $primary : null);
    }

    /** A description that is often escaped HTML, as plain paragraphs. */
    public static function plainDescription(string $description): string
    {
        $text = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('#<\s*(br|/p|/div|/li|/h\d)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_filter(array_map(fn($line) => EpubHtml::squash($line), explode("\n", $text)), fn($line) => $line !== '');

        return implode("\n\n", $lines);
    }

    // ---------------------------------------------------------------------
    // Contents
    // ---------------------------------------------------------------------

    /**
     * The table of contents, flattened in order: label, zip path, fragment.
     * Entries pointing outside the spine are dropped; a repeat of the same
     * place keeps the first.
     *
     * @return array<int, array{label: string, path: string, fragment: ?string}>
     */
    private function contents(): array
    {
        $entries = $this->navContents();
        if ($entries === []) {
            $entries = $this->ncxContents();
        }

        $seen = [];
        $kept = [];
        foreach ($entries as $entry) {
            // A repeat of the same place is dropped; entries without an anchor
            // into one shared file are told apart by their label (each is
            // found by its heading text in sectionsFromContents()).
            $key = $entry['path'] . '#' . ($entry['fragment'] ?? ("\0" . mb_strtolower($entry['label'])));
            if ($entry['label'] === '' || isset($seen[$key]) || !in_array($entry['path'], $this->spine, true)) {
                continue;
            }
            $seen[$key] = true;
            $entry['label'] = self::label($entry['label'], $entry['parent'] ?? null);
            $kept[] = $entry;
        }

        return $kept;
    }

    /**
     * A bare number ("I", "12") says little in a chapter list: under a named
     * part it becomes "A Scandal in Bohemia — I", else "Chapter I".
     */
    public static function label(string $label, ?string $parent): string
    {
        if (!preg_match('/^(?:[IVXLCDM]+|\d+)\.?$/i', $label)) {
            return $label;
        }
        $label = rtrim($label, '.');

        return $parent !== null && $parent !== '' && !preg_match('/^(?:contents|table of contents)$/i', $parent)
            ? "{$parent} — {$label}"
            : "Chapter {$label}";
    }

    /** @return array<int, array{label: string, path: string, fragment: ?string}> */
    private function navContents(): array
    {
        $nav = null;
        foreach ($this->manifest as $item) {
            if (preg_match('/(^|\s)nav(\s|$)/', $item['properties'])) {
                $nav = $item['href'];
                break;
            }
        }
        $xhtml = $nav !== null ? $this->read($nav) : null;
        $doc = $xhtml !== null ? self::xml($xhtml) : null;
        if ($doc === null) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        // The <nav epub:type="toc">; the first <nav> when none is marked.
        $root = null;
        foreach ($xpath->query("//*[local-name()='nav']") as $candidate) {
            /** @var DOMElement $candidate */
            $type = $candidate->getAttributeNS('http://www.idpf.org/2007/ops', 'type') ?: $candidate->getAttribute('epub:type');
            if (preg_match('/(^|\s)toc(\s|$)/', $type)) {
                $root = $candidate;
                break;
            }
            $root ??= $candidate;
        }
        if ($root === null) {
            return [];
        }

        $entries = [];
        foreach ($xpath->query(".//*[local-name()='a'][@href]", $root) as $link) {
            /** @var DOMElement $link */
            [$path, $fragment] = $this->resolve(dirname($nav), $link->getAttribute('href'));
            // The entry this one is nested under: <li><a>Parent</a><ol><li><a>this</a>.
            $parent = $xpath->query("ancestor::*[local-name()='li'][2]/*[local-name()='a' or local-name()='span'][1]", $link)->item(0);
            $entries[] = [
                'label' => EpubHtml::squash($link->textContent),
                'path' => $path,
                'fragment' => $fragment,
                'parent' => $parent ? EpubHtml::squash($parent->textContent) : null,
            ];
        }

        return $entries;
    }

    /** @return array<int, array{label: string, path: string, fragment: ?string}> */
    private function ncxContents(): array
    {
        $ncx = $this->ncxId !== null ? ($this->manifest[$this->ncxId]['href'] ?? null) : null;
        if ($ncx === null) {
            foreach ($this->manifest as $item) {
                if ($item['type'] === 'application/x-dtbncx+xml') {
                    $ncx = $item['href'];
                    break;
                }
            }
        }
        $xml = $ncx !== null ? $this->read($ncx) : null;
        $doc = $xml !== null ? self::xml($xml) : null;
        if ($doc === null) {
            return [];
        }

        $xpath = new DOMXPath($doc);
        $points = iterator_to_array($xpath->query("//*[local-name()='navMap']//*[local-name()='navPoint']"));
        // Document order is reading order; playOrder breaks ties a writer got wrong.
        $entries = [];
        foreach ($points as $point) {
            /** @var DOMElement $point */
            $label = $xpath->query("*[local-name()='navLabel']/*[local-name()='text']", $point)->item(0);
            $content = $xpath->query("*[local-name()='content']", $point)->item(0);
            if (!$content instanceof DOMElement) {
                continue;
            }
            [$path, $fragment] = $this->resolve(dirname($ncx), $content->getAttribute('src'));
            $parent = $xpath->query("parent::*[local-name()='navPoint']/*[local-name()='navLabel']/*[local-name()='text']", $point)->item(0);
            $entries[] = [
                'label' => $label ? EpubHtml::squash($label->textContent) : '',
                'path' => $path,
                'fragment' => $fragment,
                'parent' => $parent ? EpubHtml::squash($parent->textContent) : null,
            ];
        }

        return $entries;
    }

    // ---------------------------------------------------------------------
    // Sections
    // ---------------------------------------------------------------------

    /**
     * Cut the spine's blocks where each contents entry starts. Blocks before
     * the first entry are front matter: kept as "Front matter" only when
     * long enough to be more than a title or copyright page.
     *
     * @param  array<string, array<int, array{html: string, ids: string[], words: int}>>  $documents
     * @return array<int, array{label: string, html: string, words: int}>
     */
    private function sectionsFromContents(array $entries, array $documents): array
    {
        // path => [block index => label]; a fragment not found starts at the top.
        $starts = [];
        // path => index of the last start placed, so text search moves forward.
        $cursor = [];
        // How many entries share each path without an anchor.
        $anchorless = [];
        foreach ($entries as $entry) {
            if ($entry['fragment'] === null) {
                $anchorless[$entry['path']] = ($anchorless[$entry['path']] ?? 0) + 1;
            }
        }
        foreach ($entries as $entry) {
            $path = $entry['path'];
            $blocks = $documents[$path] ?? [];
            $at = null;
            if ($entry['fragment'] !== null) {
                foreach ($blocks as $index => $block) {
                    if (in_array($entry['fragment'], $block['ids'], true)) {
                        $at = $index;
                        break;
                    }
                }
            }
            // Several entries into one file with no anchor (a single-file
            // conversion): each starts at the paragraph that reads like its
            // label, after the previous one. The first that cannot be found
            // starts at the top only when it is the first in the file.
            if ($at === null && ($anchorless[$path] ?? 0) > 1) {
                $at = self::findHeading($blocks, $entry['label'], ($cursor[$path] ?? -1) + 1);
                if ($at === null && isset($starts[$path])) {
                    continue; // not in the text: no chapter of its own
                }
            }
            $at ??= 0;
            // Two entries at one place ("Part One" and its first chapter): the later, deeper one names it.
            $starts[$path][$at] = $entry['label'];
            $cursor[$path] = max($cursor[$path] ?? -1, $at);
        }

        $sections = [];
        $current = ['label' => 'Front matter', 'html' => '', 'words' => 0, 'front' => true];
        foreach ($this->spine as $path) {
            foreach ($documents[$path] ?? [] as $index => $block) {
                if (isset($starts[$path][$index])) {
                    $sections[] = $current;
                    $current = ['label' => $starts[$path][$index], 'html' => '', 'words' => 0, 'front' => false];
                }
                $current['html'] .= $block['html'];
                $current['words'] += $block['words'];
            }
            // An entry for an empty document (or one whose blocks are all ids) still counts.
            if (($documents[$path] ?? []) === [] && isset($starts[$path])) {
                $sections[] = $current;
                $current = ['label' => reset($starts[$path]), 'html' => '', 'words' => 0, 'front' => false];
            }
        }
        $sections[] = $current;

        return array_values(array_map(
            fn($section) => ['label' => $section['label'], 'html' => $section['html'], 'words' => $section['words']],
            array_filter($sections, fn($section) => !$section['front'] || $section['words'] >= self::MIN_FRONT_MATTER_WORDS)
        ));
    }

    /**
     * A very long section (a whole book under one contents entry) is cut
     * where the text itself starts chapters: paragraphs that were headings
     * (now "<p><strong>…</strong></p>") or short lines such as "Chapter
     * Twelve", "Prologue", "12". With fewer than three of those it is cut into
     * parts of about PART_WORDS words at paragraph breaks instead.
     *
     * @param  array<int, array{label: string, html: string, words: int}>  $sections
     * @return array<int, array{label: string, html: string, words: int}>
     */
    private function splitLongSections(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            if ($section['words'] <= self::SPLIT_ABOVE_WORDS) {
                $out[] = $section;
                continue;
            }

            $paragraphs = self::paragraphs($section['html']);
            $headings = [];
            foreach ($paragraphs as $i => $paragraph) {
                if (($heading = self::headingText($paragraph)) !== null) {
                    $headings[$i] = $heading;
                }
            }

            if (count($headings) < 3) {
                array_push($out, ...self::parts($paragraphs, 'Part'));
                continue;
            }

            $pieces = [];
            $current = ['label' => $section['label'], 'html' => '', 'words' => 0, 'paragraphs' => []];
            foreach ($paragraphs as $i => $paragraph) {
                if (isset($headings[$i])) {
                    if ($current['words'] > 0) {
                        $pieces[] = $current;
                        $current = ['label' => $headings[$i], 'html' => '', 'words' => 0, 'paragraphs' => []];
                    } else {
                        $current['label'] = $headings[$i];
                    }
                }
                $current['html'] .= $paragraph;
                $current['words'] += EpubHtml::countWords($paragraph);
                $current['paragraphs'][] = $paragraph;
            }
            $pieces[] = $current;

            // A stretch between two headings that is still a book's worth of
            // text (few headings in a long file) is cut into parts as well.
            foreach ($pieces as $piece) {
                if ($piece['words'] > self::SPLIT_ABOVE_WORDS) {
                    // A boilerplate label ("Copyright") on a book's worth of text names nothing: plain parts.
                    $prefix = preg_match(self::BOILERPLATE, trim($piece['label'])) ? 'Part' : $piece['label'] . ' — part';
                    array_push($out, ...self::parts($piece['paragraphs'], $prefix));
                } else {
                    $out[] = ['label' => $piece['label'], 'html' => $piece['html'], 'words' => $piece['words']];
                }
            }
        }

        return $out;
    }

    /**
     * A section's paragraphs; one paragraph that is itself longer than a
     * part (a whole book set as lines with <br/> inside one block) is broken
     * at its line breaks into paragraphs of its own.
     *
     * @return string[]
     */
    private static function paragraphs(string $html): array
    {
        $out = [];
        foreach (preg_split('/(?=<p>|<hr\/>)/', $html, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $paragraph) {
            if (EpubHtml::countWords($paragraph) <= self::PART_WORDS || !str_contains($paragraph, '<br/>')) {
                $out[] = $paragraph;
                continue;
            }
            $inner = preg_replace('#^<p>|</p>$#', '', $paragraph) ?? $paragraph;
            foreach (preg_split('#(?:\s*<br/>\s*)+#', $inner, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
                if (trim(strip_tags($line)) !== '') {
                    $out[] = '<p>' . trim($line) . '</p>';
                }
            }
        }

        return $out;
    }

    /**
     * Paragraphs cut into parts of about PART_WORDS words, at paragraph
     * breaks: "{$prefix} 1", "{$prefix} 2", …
     *
     * @param  string[]  $paragraphs
     * @return array<int, array{label: string, html: string, words: int}>
     */
    private static function parts(array $paragraphs, string $prefix): array
    {
        $out = [];
        $current = ['label' => "{$prefix} 1", 'html' => '', 'words' => 0];
        foreach ($paragraphs as $paragraph) {
            if ($current['words'] >= self::PART_WORDS) {
                $out[] = $current;
                $current = ['label' => $prefix . ' ' . (count($out) + 1), 'html' => '', 'words' => 0];
            }
            $current['html'] .= $paragraph;
            $current['words'] += EpubHtml::countWords($paragraph);
        }
        $out[] = $current;

        return $out;
    }

    /** A paragraph's text when it reads as a chapter heading, else null. */
    private static function headingText(string $paragraph): ?string
    {
        $text = EpubHtml::squash(html_entity_decode(strip_tags($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '' || mb_strlen($text) > 80) {
            return null;
        }
        // A whole paragraph in bold: an h1–h6 in the ePub (EpubHtml), or set like one.
        if (preg_match('#^<p><strong>(?:(?!</strong>).)+</strong></p>$#su', trim($paragraph)) && mb_strlen($text) <= 60) {
            return $text;
        }
        if (preg_match('/^(?:chapter|prologue|epilogue|interlude|afterword|part|book)\b/iu', $text) && mb_strlen($text) <= 60) {
            return $text;
        }

        return preg_match('/^\d{1,3}\.?$/', $text) ? "Chapter {$text}" : null;
    }

    /**
     * The first block from $from on whose text begins like $label (case,
     * punctuation and spacing ignored), within its first few words, or null.
     * "CHAPTER 2 Rain of Fire" finds "Chapter 2: Rain of Fire" and "CHAPTER 2"
     * followed by the title in the next paragraph.
     */
    private static function findHeading(array $blocks, string $label, int $from): ?int
    {
        $flat = fn(string $text): string => trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
        $want = $flat($label);
        if ($want === '') {
            return null;
        }
        $count = count($blocks);
        for ($i = max(0, $from); $i < $count; $i++) {
            $text = $flat($blocks[$i]['html']);
            if ($text === '' || mb_strlen($text) > mb_strlen($want) + 80) {
                continue; // a heading is short; a long paragraph only mentions it
            }
            // The label split over two short paragraphs ("Chapter 2" / "Rain of Fire").
            $joined = $i + 1 < $count ? trim($text . ' ' . $flat($blocks[$i + 1]['html'])) : $text;
            if (str_starts_with($text, $want) || $text === $want || str_starts_with($joined, $want)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * No usable contents: one section per spine document, named by its first
     * heading, else its <title>, else "Section N".
     *
     * @return array<int, array{label: string, html: string, words: int}>
     */
    private function sectionsPerDocument(array $documents): array
    {
        $sections = [];
        foreach ($this->spine as $path) {
            $blocks = $documents[$path] ?? [];
            $words = array_sum(array_column($blocks, 'words'));
            if ($words === 0) {
                continue;
            }
            $xhtml = (string) $this->read($path);
            $label = EpubHtml::firstHeading($xhtml) ?? EpubHtml::title($xhtml) ?? ('Section ' . (count($sections) + 1));
            $sections[] = ['label' => mb_substr(self::label($label, null), 0, 180), 'html' => implode('', array_column($blocks, 'html')), 'words' => $words];
        }

        return $sections;
    }

    /**
     * A section with almost no text (a part title page, a lone heading) is
     * joined to the start of the next one; an empty last one is dropped.
     *
     * @param  array<int, array{label: string, html: string, words: int}>  $sections
     * @return array<int, array{label: string, html: string, words: int}>
     */
    private function mergeShortSections(array $sections): array
    {
        $out = [];
        $carry = null;
        foreach ($sections as $section) {
            if ($carry !== null) {
                $section['html'] = $carry['html'] . $section['html'];
                $section['words'] += $carry['words'];
                $carry = null;
            }
            if ($section['words'] < self::MIN_SECTION_WORDS) {
                $carry = $section;
                continue;
            }
            $section['label'] = mb_substr($section['label'], 0, 180);
            $out[] = $section;
        }
        // Short text at the very end (a closing line) joins the last chapter.
        if ($carry !== null && $carry['words'] > 0) {
            if ($out !== []) {
                $out[count($out) - 1]['html'] .= $carry['html'];
                $out[count($out) - 1]['words'] += $carry['words'];
            } else {
                $out[] = ['label' => mb_substr($carry['label'], 0, 180), 'html' => $carry['html'], 'words' => $carry['words']];
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Zip and XML
    // ---------------------------------------------------------------------

    /** An entry's bytes, or null when missing or larger than MAX_ENTRY_BYTES. */
    private function read(string $path): ?string
    {
        $stat = $this->zip->statName($path);
        if ($stat === false) {
            // Some writers differ in case from their own manifest.
            $index = $this->zip->locateName($path, ZipArchive::FL_NOCASE);
            $stat = $index === false ? false : $this->zip->statIndex($index);
        }
        if ($stat === false || $stat['size'] > self::MAX_ENTRY_BYTES) {
            return null;
        }

        // Read through a stream so a lying size header cannot inflate past the cap.
        $stream = $this->zip->getStream($stat['name']);
        if ($stream === false) {
            return null;
        }
        $bytes = stream_get_contents($stream, self::MAX_ENTRY_BYTES + 1);
        fclose($stream);

        return $bytes === false || strlen($bytes) > self::MAX_ENTRY_BYTES ? null : $bytes;
    }

    /**
     * An href relative to a document, as [zip path, fragment]. "..", "."
     * and %-escapes are resolved; a path that climbs out of the zip root
     * stays at the root.
     *
     * @return array{0: string, 1: ?string}
     */
    private function resolve(string $base, string $href): array
    {
        $fragment = null;
        if (($hash = strpos($href, '#')) !== false) {
            $fragment = rawurldecode(substr($href, $hash + 1)) ?: null;
            $href = substr($href, 0, $hash);
        }
        $href = rawurldecode($href);
        $base = $base === '.' ? '' : $base;

        $parts = [];
        foreach (explode('/', ($href === '' ? '' : ($base !== '' ? $base . '/' : '')) . $href) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return [implode('/', $parts), $fragment];
    }

    /** The first <img>/<image> of a document, as a zip path. */
    private function firstImageIn(string $path): ?string
    {
        $xhtml = $this->read($path);
        $doc = $xhtml !== null ? self::xml($xhtml) : null;
        if ($doc === null) {
            return null;
        }
        $xpath = new DOMXPath($doc);
        $node = $xpath->query("//*[local-name()='img'][@src] | //*[local-name()='image']")->item(0);
        if (!$node instanceof DOMElement) {
            return null;
        }
        $src = $node->getAttribute('src') ?: $node->getAttributeNS('http://www.w3.org/1999/xlink', 'href') ?: $node->getAttribute('href');

        return $src !== '' ? $this->resolve(dirname($path), $src)[0] : null;
    }

    private static function xml(string $xml): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();

            return @$doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE) ? $doc : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
