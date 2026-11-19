<?php

namespace App\Sources;

/**
 * A source whose chapter pages carry noise that no fixed selector can name:
 * the page itself says what to drop. Royal Road, for one, hides
 * anti-piracy sentences in the chapter text with a class whose name is
 * random per page load and defined in an inline <style> (display: none).
 *
 * ContentExtractor removes these nodes along with removeSelectors(), before
 * the chapter body is read (a node holding the body container is never
 * removed).
 */
interface PageNoiseSelectors
{
    /**
     * Extra CSS selectors to remove from this one fetched chapter page.
     * Pure: read from $html only, no requests.
     *
     * @return string[]
     */
    public function pageRemoveSelectors(string $html): array;
}
