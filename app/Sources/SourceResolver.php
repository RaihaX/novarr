<?php

namespace App\Sources;

use App\Novel;

/**
 * The source registry: config novarr.sources, an ordered map of source key
 * => adapter class. The first adapter whose matches() / matchesUrl()
 * accepts a novel or URL wins; NovelArrowSource accepts everything, so it is
 * the default and must stay last. When nothing matches, the last registered
 * adapter is used.
 */
class SourceResolver
{
    /**
     * Registry override (key => class). Empty: config novarr.sources is
     * read. Reset to [] to go back to the config.
     *
     * @var array<string, class-string<Source>>
     */
    protected static array $sources = [];

    /** @var array<string, class-string<Source>> what the config map defaults to */
    public const DEFAULT_SOURCES = [
        'mangadex' => MangaDexSource::class,
        'suwayomi' => SuwayomiSource::class,
        'book' => BookSource::class,
        'comic' => ComicSource::class,
        'empirenovel' => EmpireNovelSource::class,
        'novelfull' => NovelFullSource::class,
        'novelarrow' => NovelArrowSource::class,
    ];

    public static function for(Novel $novel): Source
    {
        $sources = static::all();
        foreach ($sources as $source) {
            if ($source->matches($novel)) {
                return $source;
            }
        }

        return static::fallback($sources);
    }

    /** The source that claims a (novel or chapter) URL. */
    public static function forUrl(string $url): Source
    {
        $sources = static::all();
        foreach ($sources as $source) {
            if ($source->matchesUrl($url)) {
                return $source;
            }
        }

        return static::fallback($sources);
    }

    /**
     * Registered adapter classes in registry order.
     *
     * @return array<string, class-string<Source>>
     */
    public static function classes(): array
    {
        $map = static::$sources ?: (array) config('novarr.sources', self::DEFAULT_SOURCES);

        // A mistyped entry must not take the scheduled sweep down with it:
        // skip anything that is not a Source class, and never end up empty.
        $valid = [];
        foreach ($map as $key => $class) {
            if (is_string($class) && class_exists($class) && is_subclass_of($class, Source::class)) {
                $valid[$key] = $class;
            } else {
                \Illuminate\Support\Facades\Log::warning("novarr.sources: ignoring '{$key}', which is not a Source class.");
            }
        }

        return $valid ?: self::DEFAULT_SOURCES;
    }

    /**
     * Fresh adapter instances in registry order, keyed by source key.
     *
     * @return array<string, Source>
     */
    public static function all(): array
    {
        $sources = [];
        foreach (static::classes() as $key => $class) {
            $sources[$key] = new $class();
        }

        return $sources;
    }

    /**
     * The adapter registered under a key, or null. A Discover entry key of
     * an ExpandsDiscover source ("suwayomi:123") gives that entry's scoped
     * instance (null when the source no longer lists it).
     */
    public static function get(string $key): ?Source
    {
        $classes = static::classes();
        if (isset($classes[$key])) {
            return new $classes[$key]();
        }

        [$base, $entry] = array_pad(explode(':', $key, 2), 2, null);
        $class = $entry !== null && $entry !== '' ? ($classes[$base] ?? null) : null;
        if ($class === null || !is_subclass_of($class, ExpandsDiscover::class)) {
            return null;
        }

        return (new $class())->discoverSources()[$entry] ?? null;
    }

    /**
     * The sources to ask for a chapter's API fast path, in order: the one
     * that claims the URL first, then every other registered source in
     * registry order. The first non-null Source::chapterContent() answer
     * wins.
     *
     * @return Source[]
     */
    public static function fastPathCandidates(string $url): array
    {
        $sources = static::all();
        $claimer = null;
        foreach ($sources as $key => $source) {
            if ($source->matchesUrl($url)) {
                $claimer = $key;
                break;
            }
        }

        if ($claimer === null) {
            return array_values($sources);
        }

        return [$sources[$claimer], ...array_values(array_diff_key($sources, [$claimer => true]))];
    }

    /**
     * Sources shown on the Discover page, keyed by source key: text-novel
     * sources before manga ones (a manga source must never become the
     * page's default), and within each medium those with browse lists
     * (popular / completed) first, then search-only ones, each group in
     * registry order. The first one is the page's default.
     *
     * $medium 'novel' or 'manga' keeps only that medium; null (or 'all')
     * keeps every source.
     *
     * A source implementing ExpandsDiscover is replaced by its entries,
     * keyed "{key}:{entry id}", each tiered like any other source.
     *
     * @return array<string, Source>
     */
    public static function discoverable(?string $medium = null): array
    {
        $candidates = [];
        foreach (static::all() as $key => $source) {
            if ($source instanceof ExpandsDiscover) {
                foreach ($source->discoverSources() as $entry => $scoped) {
                    $candidates["{$key}:{$entry}"] = $scoped;
                }
                continue;
            }
            $candidates[$key] = $source;
        }

        $groups = [];
        foreach ($candidates as $key => $source) {
            $lists = $source->discoverLists();
            if ($lists === []) {
                continue;
            }
            $sourceMedium = $source->medium();
            if ($medium !== null && $medium !== 'all' && $sourceMedium !== $medium) {
                continue;
            }
            $tier = ($sourceMedium === \App\Novel::MEDIUM_NOVEL ? 0 : 2)
                + (array_intersect($lists, ['popular', 'completed']) !== [] ? 0 : 1);
            $groups[$tier][$key] = $source;
        }
        ksort($groups);

        $ordered = [];
        foreach ($groups as $group) {
            $ordered += $group;
        }

        return $ordered;
    }

    /** @param array<string, Source> $sources */
    protected static function fallback(array $sources): Source
    {
        // classes() is never empty, so neither is $sources.
        return end($sources);
    }
}
