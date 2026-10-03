<?php

namespace App\Sources;

/**
 * Defaults for the content-extraction hints in Source, so an adapter only
 * overrides what differs for its site.
 */
abstract class AbstractSource implements Source
{
    /**
     * Known chapter-body containers across supported (and look-alike)
     * sites, tried after the adapter's own selectors.
     */
    public const GENERIC_CONTENT_SELECTORS = [
        '#chr-content',
        '.chr-c',
        '.chapter-content',
        '#chapter-content',
        '.entry-content',
        '#read-novel',
        '.reader-page',
        'article',
        '.text',
        '#content',
    ];

    /** Noise removed on every site. */
    public const GENERIC_REMOVE_SELECTORS = [
        'script', 'style', 'noscript', 'iframe', 'template', 'button',
        'ins.adsbygoogle',
        '[data-format]',
    ];

    public function contentSelectors(): array
    {
        return self::GENERIC_CONTENT_SELECTORS;
    }

    public function removeSelectors(): array
    {
        return self::GENERIC_REMOVE_SELECTORS;
    }

    public function supportsMultiPage(): ?string
    {
        return null;
    }
}
