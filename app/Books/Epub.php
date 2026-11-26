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
     *               series: ?string, series_index: ?float}
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

        $sections = array_values(array_filter(
            $sections,
            fn($section) => !preg_match(self::BOILERPLATE, trim($section['label']))
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
            $key = $entry['path'] . '#' . ($entry['fragment'] ?? '');
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
        foreach ($entries as $entry) {
            $blocks = $documents[$entry['path']] ?? [];
            $at = 0;
            if ($entry['fragment'] !== null) {
                foreach ($blocks as $index => $block) {
                    if (in_array($entry['fragment'], $block['ids'], true)) {
                        $at = $index;
                        break;
                    }
                }
            }
            // Two entries at one place ("Part One" and its first chapter): the later, deeper one names it.
            $starts[$entry['path']][$at] = $entry['label'];
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
