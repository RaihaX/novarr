<?php

namespace Tests\Support;

use App\Novel;
use App\Sources\AbstractSource;
use App\Sources\ProvidesChapterStats;

/**
 * Network-free sources for the reader-request tests, registered through
 * config novarr.sources (FakeRequestSource::register()). Each subclass is
 * one source (key, host, medium); what its Discover search answers and what
 * its chapter stats are is set per class / per URL in the static maps.
 *
 *   FakeRequestSource::$search[FakeMangaA::class] = [['name' => 'Solo', 'url' => 'https://manga-a.test/solo']];
 *   FakeRequestSource::$search[FakeMangaB::class] = null;   // the source fails
 *   FakeRequestSource::$stats['https://manga-a.test/solo'] = ['numbers' => ['1', '2'], ...];
 *
 * Browse lists (the Requests page's Discover shelf): a class offers the
 * lists in $lists (besides 'search'), answering $browse[class][list][page]
 * (an item list, null = the source fails, or a Closure($page)). A paged
 * class (FakePagedManga, PagedDiscover) answers {items, has_more}-shaped
 * entries; a plain one answers page 1 only, through discover().
 *
 *   FakeRequestSource::$lists[FakeMangaA::class] = ['popular', 'top'];
 *   FakeRequestSource::$browse[FakeMangaA::class]['popular'][1] = [['name' => 'Solo', 'url' => '…']];
 */
abstract class FakeRequestSource extends AbstractSource implements ProvidesChapterStats
{
    /** @var array<class-string, array|null|\Closure> */
    public static array $search = [];

    /** @var array<string, array|null|\Closure> */
    public static array $stats = [];

    /** @var array<class-string, string[]> lists offered besides 'search' */
    public static array $lists = [];

    /** @var array<class-string, array<string, array<int, array|null|\Closure>>> */
    public static array $browse = [];

    /** @var array<int, array{source: string, kind: string, arg: string}> kind: search | stats | browse (arg "list:page") */
    public static array $calls = [];

    public const KEYS = [
        'fakemanga_a' => FakeMangaA::class,
        'fakemanga_b' => FakeMangaB::class,
        'fakemanga_c' => FakeMangaC::class,
        'fakenovel_a' => FakeNovelA::class,
        'fakenovel_b' => FakeNovelB::class,
        'fakepaged_manga' => FakePagedManga::class,
    ];

    abstract public function host(): string;

    public static function register(): void
    {
        static::reset();
        config(['novarr.sources' => self::KEYS]);
    }

    public static function reset(): void
    {
        static::$search = [];
        static::$stats = [];
        static::$calls = [];
        static::$lists = [];
        static::$browse = [];
    }

    public function hosts(): array
    {
        return [$this->host()];
    }

    public function name(): string
    {
        return (string) array_search(static::class, self::KEYS, true);
    }

    public function discoverLabel(): string
    {
        return 'Fake ' . strtoupper(substr($this->name(), -1)) . ' (' . $this->medium() . ')';
    }

    public function discoverLists(): array
    {
        return array_values(array_unique(array_merge(['search'], static::$lists[static::class] ?? [])));
    }

    public function discover(string $type, ?string $query = null): ?array
    {
        if ($type !== 'search') {
            return $this->browseAnswer($type, 1);
        }
        static::$calls[] = ['source' => $this->name(), 'kind' => 'search', 'arg' => (string) $query];
        $answer = array_key_exists(static::class, static::$search) ? static::$search[static::class] : [];

        return $answer instanceof \Closure ? $answer($query) : $answer;
    }

    /** The $browse answer for one page of a list (recorded in $calls); [] when none is set. */
    protected function browseAnswer(string $list, int $page): mixed
    {
        static::$calls[] = ['source' => $this->name(), 'kind' => 'browse', 'arg' => "{$list}:{$page}"];
        $answers = static::$browse[static::class][$list] ?? [];
        $answer = array_key_exists($page, $answers) ? $answers[$page] : [];

        return $answer instanceof \Closure ? $answer($page) : $answer;
    }

    public function chapterStats(string $url, array $languages): ?array
    {
        static::$calls[] = ['source' => $this->name(), 'kind' => 'stats', 'arg' => $url];
        $answer = static::$stats[$url] ?? null;
        if ($answer instanceof \Closure) {
            return $answer($languages);
        }

        return $answer === null ? null : $answer + ['languages' => ['en'], 'in_preferred_language' => true, 'updated_at' => null, 'requests' => 1];
    }

    public function tableOfContents(Novel $novel): array
    {
        return [];
    }

    public function metadata(Novel $novel): array
    {
        return [];
    }
}

class FakeMangaA extends FakeRequestSource
{
    public function host(): string { return 'manga-a.test'; }
    public function medium(): string { return Novel::MEDIUM_MANGA; }
}

class FakeMangaB extends FakeRequestSource
{
    public function host(): string { return 'manga-b.test'; }
    public function medium(): string { return Novel::MEDIUM_MANGA; }
}

class FakeMangaC extends FakeRequestSource
{
    public function host(): string { return 'manga-c.test'; }
    public function medium(): string { return Novel::MEDIUM_MANGA; }
}

class FakeNovelA extends FakeRequestSource
{
    public function host(): string { return 'novel-a.test'; }
    public function medium(): string { return Novel::MEDIUM_NOVEL; }
}

class FakeNovelB extends FakeRequestSource
{
    public function host(): string { return 'novel-b.test'; }
    public function medium(): string { return Novel::MEDIUM_NOVEL; }
}

/**
 * A manga source that pages its lists (PagedDiscover): $browse entries are
 * {items, has_more} (or a plain item list: no more pages). Off Discover
 * (no lists, so never searched) until a test sets its $lists.
 */
class FakePagedManga extends FakeRequestSource implements \App\Sources\PagedDiscover
{
    public function host(): string { return 'paged-manga.test'; }
    public function medium(): string { return Novel::MEDIUM_MANGA; }

    public function discoverLists(): array
    {
        return array_key_exists(static::class, static::$lists) ? parent::discoverLists() : [];
    }

    public function discoverPage(string $type, ?string $query, int $page): ?array
    {
        if ($type === 'search') {
            $items = $this->discover($type, $query);

            return $items === null ? null : ['items' => $items, 'has_more' => false];
        }
        $answer = $this->browseAnswer($type, $page);
        if ($answer === null) {
            return null;
        }

        return isset($answer['items']) ? $answer : ['items' => $answer, 'has_more' => false];
    }
}
