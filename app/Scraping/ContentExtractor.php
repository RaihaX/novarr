<?php

namespace App\Scraping;

use App\Sources\AbstractSource;
use App\Sources\Source;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Pulls a chapter body out of a fetched page as a list of "<p>…</p>"
 * strings. Pure apart from extractMultiPage(), which fetches follow-up parts
 * through the injected Fetcher.
 *
 * 1. Noise removal: the source's removeSelectors() (ads, nav, "report
 *    chapter" notices…) plus the stripChapterNoise() widget signatures,
 *    applied to the DOM so a removed widget can't unbalance the tree.
 * 2. Known containers: the source's contentSelectors() then the generic
 *    list, in order. The first whose paragraphs look like a whole chapter
 *    (chapterParagraphsLookComplete) wins.
 * 3. Readability-style fallback, only when no known container exists on
 *    the page: every div/article/section/main/td is scored on its own text
 *    (text length × (1 − link density), bonus for ≥3 long <p>); blocks
 *    inside comment/nav/footer/sidebar/share/ad chrome score 0. The best
 *    is kept only if it looks complete. When a known container matched but
 *    is thin (locked/teaser stub) its paragraphs are returned unchanged.
 * 4. Every paragraph goes through finalizeChapterParagraph() (spam,
 *    watermark and Unicode clean-up).
 */
class ContentExtractor
{
    /**
     * Ad / recommendation widget signatures in id/class attributes — the
     * same families stripChapterNoise() strips from HTML strings.
     */
    public const WIDGET_PATTERN = '/(taboola|outbrain|trc[_-]?rbox|ulplugin|recommend|sponsored|ad-slot|ads-wrapper|adv-box)/i';

    /** id/class tokens that mark a block as page chrome rather than story text. */
    public const CHROME_PATTERN = '/(?:^|[^a-z])(comment\w*|disqus\w*|wpd\w*|wpdiscuz\w*|repl(?:y|ies)\w*|nav\w*|footer\w*|sidebar\w*|related\w*|recommend\w*|share\w*|sharing|social\w*|menu\w*|header\w*|breadcrumbs?|ads?|advert\w*|sponsor\w*)(?:[^a-z]|$)/i';

    public const MAX_PARTS = 5;

    /** @var array<string,string> CSS → XPath cache */
    private array $xpathCache = [];

    public static function make(): static
    {
        return new static();
    }

    /**
     * @return string[] "<p>…</p>" paragraphs, [] when nothing plausible was found
     */
    public function extract(string $html, ?Source $source = null, string $urlForLog = ''): array
    {
        if (trim($html) === '') {
            return [];
        }

        try {
            $doc = (new Crawler($html))->getNode(0)?->ownerDocument;
        } catch (\Throwable $e) {
            \Log::warning("ContentExtractor could not parse HTML for {$urlForLog}: " . $e->getMessage());
            return [];
        }
        if (!$doc instanceof \DOMDocument) {
            return [];
        }

        $xpath = new \DOMXPath($doc);
        $contentSelectors = $this->contentSelectors($source);

        // Nodes that hold a known chapter container are never removed as
        // noise, even if a broad remove selector / widget token matches them.
        $protected = [];
        foreach ($contentSelectors as $selector) {
            foreach ($this->query($xpath, $selector) as $node) {
                $protected[] = $node;
            }
        }

        $this->removeNoise($xpath, $source, $protected);

        // (2) known containers, in order.
        $best = [];
        $bestWords = 0;
        $knownMatched = false;
        foreach ($contentSelectors as $selector) {
            $paragraphs = [];
            foreach ($this->outermost($this->query($xpath, $selector)) as $node) {
                if ($this->text($node) !== '') {
                    $knownMatched = true;
                }
                array_push($paragraphs, ...$this->containerParagraphs($node));
            }

            if (chapterParagraphsLookComplete($paragraphs)) {
                \Log::debug("ContentExtractor matched {$selector} (paragraphs: " . count($paragraphs) . ") for {$urlForLog}");
                return $paragraphs;
            }

            $words = chapterParagraphWords($paragraphs);
            if ($words > $bestWords || ($best === [] && $paragraphs !== [])) {
                $best = $paragraphs;
                $bestWords = $words;
            }
        }

        // (3) readability-style scorer — only when no known container is on
        // the page at all. A known container that is merely thin (a locked /
        // teaser stub) is returned as-is so the word gate rejects it and the
        // chapter is retried; substituting another block (typically the
        // comments) would save junk as a downloaded chapter.
        $winner = $knownMatched ? null : $this->bestScoredNode($xpath);
        if ($winner !== null) {
            $paragraphs = $this->containerParagraphs($winner);
            if (chapterParagraphsLookComplete($paragraphs)) {
                \Log::info("ContentExtractor used the scorer fallback (<{$winner->nodeName}" . $this->describe($winner) . ">, paragraphs: " . count($paragraphs) . ") for {$urlForLog}");
                return $paragraphs;
            }
        }

        // The page fetched fine but nothing looked like a whole chapter —
        // the strongest signal that the site changed its markup.
        \Log::warning(
            "ChapterGenerator found insufficient content for URL: {$urlForLog} "
            . "(paragraphs: " . count($best) . ", words: {$bestWords}"
            . ", html length: " . strlen($html) . "). "
            . "Site markup may have changed. First 300 chars of body: "
            . substr(trim(strip_tags($html)), 0, 300)
        );

        return array_values($best);
    }

    /**
     * extract() plus multi-page stitching: when the source declares a
     * "next part" selector, follow it (up to MAX_PARTS pages in total) as
     * long as the link stays on the same chapter — same path with a -N
     * suffix, or the same path with ?page=N — and append each part's
     * paragraphs. A "part" URL that is itself a chapter in the novel's TOC
     * (sites that list "chapter-12-2" as its own chapter) is never
     * followed; $isTocEntry decides that (default: a NovelChapter row with
     * that URL exists).
     *
     * @param (callable(string): bool)|null $isTocEntry
     * @return string[]
     */
    public function extractMultiPage(string $html, ?Source $source, string $url, ?Fetcher $fetcher = null, int $maxParts = self::MAX_PARTS, ?callable $isTocEntry = null): array
    {
        $paragraphs = $this->extract($html, $source, $url);

        $selector = $source?->supportsMultiPage();
        if ($selector === null || $selector === '' || $url === '') {
            return $paragraphs;
        }

        $fetcher ??= app(Fetcher::class);
        $visited = [$url => true];
        $currentHtml = $html;

        for ($part = 2; $part <= $maxParts; $part++) {
            $next = $this->nextPartUrl($currentHtml, $selector, $url, $part);
            if ($next === null || isset($visited[$next])) {
                break;
            }
            if (($isTocEntry ?? [$this, 'isKnownChapterUrl'])($next)) {
                \Log::debug("ContentExtractor: {$next} is a separate TOC chapter, not part {$part} of {$url}");
                break;
            }
            $visited[$next] = true;

            $reason = null;
            $partHtml = $fetcher->html($next, $reason);
            if ($partHtml === null || $partHtml === '') {
                \Log::warning("ContentExtractor: chapter part {$part} failed for {$url} ({$next}: " . ($reason ?? 'unknown') . ")");
                break;
            }

            $more = $this->extract($partHtml, $source, $next);
            if ($more === []) {
                break;
            }
            \Log::debug("ContentExtractor stitched part {$part} of {$url} (" . count($more) . " paragraphs)");
            array_push($paragraphs, ...$more);
            $currentHtml = $partHtml;
        }

        return $paragraphs;
    }

    /**
     * The URL of part $part when the page links to it via $selector, else
     * null. Only links that continue the same chapter are accepted.
     */
    public function nextPartUrl(string $html, string $selector, string $chapterUrl, int $part): ?string
    {
        try {
            $links = (new Crawler($html, $chapterUrl))->filter($selector);
        } catch (\Throwable $e) {
            return null;
        }

        foreach ($links as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $href = trim($node->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
                continue;
            }
            $candidate = $this->absoluteUrl($href, $chapterUrl);
            if ($candidate !== null && $this->isPartOf($candidate, $chapterUrl, $part)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Is $url stored as a chapter of its own? (false when the DB is unavailable) */
    public function isKnownChapterUrl(string $url): bool
    {
        try {
            return \App\NovelChapter::where('url', $url)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Does $candidate continue $chapterUrl as part $part (slug-N or ?page=N)? */
    public function isPartOf(string $candidate, string $chapterUrl, int $part): bool
    {
        $c = parse_url($candidate);
        $b = parse_url($chapterUrl);
        if (!$c || !$b || strtolower($c['host'] ?? '') !== strtolower($b['host'] ?? '')) {
            return false;
        }

        $strip = fn(string $p) => preg_replace('/\.(html?|php)$/i', '', rtrim($p, '/'));
        $basePath = $strip($b['path'] ?? '');
        $candPath = $strip($c['path'] ?? '');
        if ($basePath === '' || basename($basePath) === '') {
            return false;
        }

        if ($candPath === $basePath . '-' . $part) {
            return true;
        }

        parse_str($c['query'] ?? '', $query);
        return $candPath === $basePath && (string) ($query['page'] ?? '') === (string) $part;
    }

    // ------------------------------------------------------------------

    /** @return string[] */
    private function contentSelectors(?Source $source): array
    {
        $selectors = $source ? $source->contentSelectors() : [];
        return array_values(array_unique([...$selectors, ...AbstractSource::GENERIC_CONTENT_SELECTORS]));
    }

    /** @param \DOMNode[] $protected */
    private function removeNoise(\DOMXPath $xpath, ?Source $source, array $protected): void
    {
        $selectors = $source ? $source->removeSelectors() : AbstractSource::GENERIC_REMOVE_SELECTORS;

        $doomed = [];
        foreach ($selectors as $selector) {
            foreach ($this->query($xpath, $selector) as $node) {
                $doomed[] = $node;
            }
        }

        // stripChapterNoise()'s widget families, matched on id/class.
        foreach ($xpath->query('//*[@id or @class]') as $node) {
            if (!$node instanceof \DOMElement || !in_array(strtolower($node->nodeName), ['div', 'section', 'aside', 'iframe', 'ul', 'span'], true)) {
                continue;
            }
            if (preg_match(self::WIDGET_PATTERN, $node->getAttribute('id') . ' ' . $node->getAttribute('class'))) {
                $doomed[] = $node;
            }
        }

        foreach ($doomed as $node) {
            if ($node->parentNode === null || $this->containsAny($node, $protected)) {
                continue;
            }
            $node->parentNode->removeChild($node);
        }
    }

    /** @param \DOMNode[] $nodes */
    private function containsAny(\DOMNode $node, array $nodes): bool
    {
        foreach ($nodes as $other) {
            for ($n = $other; $n !== null; $n = $n->parentNode) {
                if ($n->isSameNode($node)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return \DOMNode[] */
    private function query(\DOMXPath $xpath, string $selector): array
    {
        try {
            $this->xpathCache[$selector] ??= (new CssSelectorConverter(true))->toXPath($selector);
            $list = $xpath->query($this->xpathCache[$selector]);
        } catch (\Throwable $e) {
            return [];
        }

        return $list === false ? [] : iterator_to_array($list);
    }

    /**
     * Drop nodes nested inside another node of the same list, so a
     * selector matching both a container and its child isn't read twice.
     *
     * @param \DOMNode[] $nodes
     * @return \DOMNode[]
     */
    private function outermost(array $nodes): array
    {
        return array_values(array_filter($nodes, function (\DOMNode $node) use ($nodes) {
            for ($p = $node->parentNode; $p !== null; $p = $p->parentNode) {
                foreach ($nodes as $other) {
                    if ($other->isSameNode($p)) {
                        return false;
                    }
                }
            }
            return true;
        }));
    }

    /**
     * Paragraphs of one container. When most of its text sits in <p>
     * elements only those are read (headings, notices and stray widgets
     * between them are skipped, as the old "selector p" path did);
     * otherwise the whole container is walked with <br>/block breaks — the
     * br-separated layout.
     *
     * @return string[]
     */
    public function containerParagraphs(\DOMNode $container): array
    {
        $xpath = new \DOMXPath($container->ownerDocument ?? $container);
        $ps = $xpath->query('.//p[not(ancestor::p)]', $container);

        $pText = 0;
        $pCount = 0;
        foreach ($ps as $p) {
            $len = mb_strlen($this->text($p));
            if ($len > 0) {
                $pText += $len;
                $pCount++;
            }
        }
        $total = mb_strlen($this->text($container));

        $inners = [];
        if ($pCount >= 3 && $total > 0 && $pText >= 0.6 * $total) {
            foreach ($ps as $p) {
                array_push($inners, ...chapterNodeParagraphs($p));
            }
        } else {
            $html = '';
            foreach ($container->childNodes as $child) {
                $html .= $container->ownerDocument->saveHTML($child);
            }
            $html = stripChapterNoise($html);
            $fragment = (new Crawler('<html><body><div id="__novarr_root">' . $html . '</div></body></html>'))->filter('#__novarr_root');
            if ($fragment->count() > 0) {
                $inners = chapterNodeParagraphs($fragment->getNode(0));
            }
        }

        $result = [];
        foreach ($inners as $inner) {
            $paragraph = finalizeChapterParagraph($inner);
            if ($paragraph !== null) {
                $result[] = $paragraph;
            }
        }

        return $result;
    }

    /**
     * Highest-scoring block candidate, or null when nothing scores above 0.
     */
    public function bestScoredNode(\DOMXPath $xpath): ?\DOMElement
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($xpath->query('//div|//article|//section|//main|//td') as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $score = $this->score($node);
            if ($score > $bestScore) {
                $best = $node;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * Readability-style score over a block's *own* content: direct text and
     * inline / paragraph children (nested blocks are candidates of their
     * own, so the tightest container wins).
     */
    public function score(\DOMElement $node): float
    {
        static $own = ['p', 'span', 'em', 'i', 'strong', 'b', 'u', 'a', 'font', 'small', 'sup', 'sub', 'br',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ul', 'ol', 'li', 'label'];

        $text = '';
        $linkText = 0;
        $longParagraphs = 0;

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text .= ' ' . $child->nodeValue;
                continue;
            }
            if (!$child instanceof \DOMElement || !in_array(strtolower($child->nodeName), $own, true)) {
                continue;
            }
            $childText = $this->text($child);
            $text .= ' ' . $childText;

            if (strtolower($child->nodeName) === 'a') {
                $linkText += mb_strlen($childText);
            } else {
                foreach ($child->getElementsByTagName('a') as $a) {
                    $linkText += mb_strlen($this->text($a));
                }
            }
            if (strtolower($child->nodeName) === 'p' && mb_strlen($childText) > 80) {
                $longParagraphs++;
            }
        }

        $length = mb_strlen(trim(preg_replace('/\s+/u', ' ', $text)));
        if ($length === 0) {
            return 0.0;
        }

        $linkDensity = min(1.0, $linkText / $length);
        if ($linkDensity > 0.5) {
            return 0.0; // link lists (nav, TOC, tag clouds) never win
        }

        // Page chrome (comments, nav, footer, sidebar, share, ads…) on the
        // block or any ancestor up to <body> never wins — no bonus rescues it.
        if ($this->isChrome($node)) {
            return 0.0;
        }

        $score = $length * (1 - $linkDensity);

        if ($longParagraphs >= 3) {
            $score += 500;
        }

        return $score;
    }

    /** Does the block or an ancestor (up to <body>) look like page chrome? */
    public function isChrome(\DOMElement $node): bool
    {
        for ($n = $node; $n instanceof \DOMElement; $n = $n->parentNode) {
            $name = strtolower($n->nodeName);
            if ($name === 'body' || $name === 'html') {
                return false;
            }
            if (preg_match(self::CHROME_PATTERN, $n->getAttribute('id') . ' ' . $n->getAttribute('class'))) {
                return true;
            }
        }
        return false;
    }

    private function text(\DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent ?? ''));
    }

    private function describe(\DOMElement $node): string
    {
        $out = '';
        if ($node->getAttribute('id') !== '') {
            $out .= ' id="' . $node->getAttribute('id') . '"';
        }
        if ($node->getAttribute('class') !== '') {
            $out .= ' class="' . $node->getAttribute('class') . '"';
        }
        return $out;
    }

    private function absoluteUrl(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $b = parse_url($base);
        if (!$b || empty($b['host'])) {
            return null;
        }
        $origin = ($b['scheme'] ?? 'https') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) {
            return ($b['scheme'] ?? 'https') . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        if (str_starts_with($href, '?')) {
            return $origin . ($b['path'] ?? '/') . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
        return $origin . $dir . $href;
    }
}
