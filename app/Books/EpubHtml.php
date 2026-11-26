<?php

namespace App\Books;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * One XHTML document of an ePub as a list of blocks, each the reader's
 * "<p>…</p>" markup (NovelChapter::presentContent()'s tags only: p, br, hr,
 * em, strong, i, b, u, s) plus the element ids inside it, so a contents
 * entry that points at "chapter.xhtml#ch3" can start a chapter mid-file.
 *
 * Headings become a bold paragraph; images, scripts and styles are left
 * out (the text is what the reader shows); links keep their text only.
 * A block with no text but an id (an empty anchor) is kept as a marker.
 */
class EpubHtml
{
    /** Containers walked into when they hold blocks; a leaf one is a paragraph. */
    private const CONTAINERS = [
        'body', 'div', 'section', 'article', 'aside', 'main', 'header', 'footer', 'nav',
        'blockquote', 'figure', 'ul', 'ol', 'dl', 'table', 'thead', 'tbody', 'tfoot', 'tr',
        'li', 'td', 'th', 'center', 'hgroup', 'details',
    ];

    /** Always a paragraph of its own. */
    private const LEAVES = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'dt', 'dd', 'figcaption', 'caption', 'address', 'summary'];

    private const HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /** Dropped with everything inside. */
    private const SKIP = ['script', 'style', 'head', 'title', 'svg', 'math', 'img', 'image', 'video', 'audio', 'object', 'iframe', 'noscript', 'template', 'button', 'input', 'select', 'textarea', 'form'];

    /** Inline tags kept as they are (b/i normalised to strong/em). */
    private const INLINE = ['em' => 'em', 'i' => 'em', 'cite' => 'em', 'dfn' => 'em', 'var' => 'em', 'strong' => 'strong', 'b' => 'strong', 'u' => 'u', 's' => 's', 'strike' => 's', 'del' => 's'];

    /** @var array<int, array{html: string, ids: string[], words: int}> */
    private array $blocks = [];

    /** Ids seen since the last block was emitted (an empty anchor, a section's id). */
    private array $pendingIds = [];

    /**
     * @return array<int, array{html: string, ids: string[], words: int}>
     */
    public static function blocks(string $xhtml): array
    {
        $doc = self::load($xhtml);
        if ($doc === null) {
            return [];
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            return [];
        }

        $walker = new self();
        $walker->container($body);
        $walker->flushPendingIds();

        return $walker->blocks;
    }

    /** The document's <title>, for a section the contents never names. */
    public static function title(string $xhtml): ?string
    {
        $doc = self::load($xhtml);
        $title = $doc?->getElementsByTagName('title')->item(0);
        $text = $title ? self::squash($title->textContent) : '';

        return $text !== '' ? $text : null;
    }

    /** First heading's text (h1–h3), for naming an unnamed section. */
    public static function firstHeading(string $xhtml): ?string
    {
        $doc = self::load($xhtml);
        if ($doc === null) {
            return null;
        }
        foreach (['h1', 'h2', 'h3'] as $tag) {
            $node = $doc->getElementsByTagName($tag)->item(0);
            $text = $node ? self::squash($node->textContent) : '';
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    public static function squash(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{a0}", ' ', $text)) ?? '');
    }

    public static function countWords(string $html): int
    {
        return str_word_count(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * XHTML first (what an ePub must hold); a document that is not well
     * formed is read as HTML instead. External entities are never loaded:
     * no LIBXML_NOENT, and LIBXML_NONET forbids network access.
     */
    private static function load(string $xhtml): ?DOMDocument
    {
        if (trim($xhtml) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            if (@$doc->loadXML($xhtml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
                return $doc;
            }

            $doc = new DOMDocument();
            // The XML declaration (if any) is replaced so libxml reads UTF-8.
            $html = preg_replace('/^\s*<\?xml[^>]*\?>/', '', $xhtml) ?? $xhtml;
            if (@$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE)) {
                return $doc;
            }

            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function tag(DOMNode $node): string
    {
        return strtolower($node->localName ?? $node->nodeName);
    }

    /** Walk a container: blocks become paragraphs, runs of inline content between them too. */
    private function container(DOMElement $element): void
    {
        $this->collectId($element);

        $inline = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = self::tag($child);
                if (in_array($tag, self::SKIP, true)) {
                    // A skipped element can still carry the id a contents entry points at.
                    $this->collectId($child);
                    continue;
                }
                if ($tag === 'hr') {
                    $this->emitInline($inline);
                    $inline = [];
                    $this->emit('<hr/>', 0);
                    continue;
                }
                if (in_array($tag, self::LEAVES, true)) {
                    $this->emitInline($inline);
                    $inline = [];
                    $this->leaf($child, in_array($tag, self::HEADINGS, true));
                    continue;
                }
                if (in_array($tag, self::CONTAINERS, true)) {
                    $this->emitInline($inline);
                    $inline = [];
                    if ($this->hasBlockChildren($child)) {
                        $this->container($child);
                    } else {
                        $this->leaf($child, false);
                    }
                    continue;
                }
            }
            $inline[] = $child;
        }
        $this->emitInline($inline);
    }

    private function hasBlockChildren(DOMElement $element): bool
    {
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $tag = self::tag($child);
                if ($tag === 'hr' || in_array($tag, self::LEAVES, true) || in_array($tag, self::CONTAINERS, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** One paragraph from an element's inline content. */
    private function leaf(DOMElement $element, bool $heading): void
    {
        $this->collectId($element);
        $html = $this->inlineHtml(iterator_to_array($element->childNodes));
        if ($heading && $html !== '') {
            $html = '<strong>' . $html . '</strong>';
        }
        $this->emitParagraph($html);
    }

    /** @param DOMNode[] $nodes */
    private function emitInline(array $nodes): void
    {
        if ($nodes === []) {
            return;
        }
        $this->emitParagraph($this->inlineHtml($nodes));
    }

    private function emitParagraph(string $html): void
    {
        // <br/> at either end of a paragraph is noise.
        $html = trim(preg_replace('/^(\s*<br\/>\s*)+|(\s*<br\/>\s*)+$/', '', $html) ?? $html);
        if (self::squash(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') {
            // No text: nothing to show, but its ids still mark a place.
            return;
        }
        $this->emit('<p>' . $html . '</p>', self::countWords($html));
    }

    private function emit(string $html, int $words): void
    {
        $this->blocks[] = ['html' => $html, 'ids' => $this->pendingIds, 'words' => $words];
        $this->pendingIds = [];
    }

    /** Ids left after the last block: an empty marker block keeps them. */
    private function flushPendingIds(): void
    {
        if ($this->pendingIds !== []) {
            $this->blocks[] = ['html' => '', 'ids' => $this->pendingIds, 'words' => 0];
            $this->pendingIds = [];
        }
    }

    private function collectId(DOMElement $element): void
    {
        foreach (['id', 'name'] as $attribute) {
            $id = trim($element->getAttribute($attribute));
            if ($id !== '' && !in_array($id, $this->pendingIds, true)) {
                $this->pendingIds[] = $id;
            }
        }
    }

    /** @param DOMNode[] $nodes */
    private function inlineHtml(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                $text = preg_replace('/\s+/u', ' ', $node->textContent) ?? '';
                $out .= htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
                continue;
            }
            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = self::tag($node);
            $this->collectId($node);
            if (in_array($tag, self::SKIP, true)) {
                continue;
            }
            if ($tag === 'br') {
                $out .= '<br/>';
                continue;
            }

            $inner = $this->inlineHtml(iterator_to_array($node->childNodes));
            $wrap = self::INLINE[$tag] ?? null;
            if ($wrap !== null && trim(strip_tags($inner)) !== '') {
                $out .= "<{$wrap}>{$inner}</{$wrap}>";
            } elseif (in_array($tag, self::LEAVES, true) || in_array($tag, self::CONTAINERS, true)) {
                // A block nested in inline content: keep it on its own line.
                $out .= ($out !== '' ? '<br/>' : '') . $inner;
            } else {
                $out .= $inner;
            }
        }

        return preg_replace('/ {2,}/', ' ', $out) ?? $out;
    }
}
