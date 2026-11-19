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
 */
abstract class FakeRequestSource extends AbstractSource implements ProvidesChapterStats
{
    /** @var array<class-string, array|null|\Closure> */
    public static array $search = [];

    /** @var array<string, array|null|\Closure> */
    public static array $stats = [];

    /** @var array<int, array{source: string, kind: string, arg: string}> */
    public static array $calls = [];

    public const KEYS = [
        'fakemanga_a' => FakeMangaA::class,
        'fakemanga_b' => FakeMangaB::class,
        'fakemanga_c' => FakeMangaC::class,
        'fakenovel_a' => FakeNovelA::class,
        'fakenovel_b' => FakeNovelB::class,
    ];

    abstract public function host(): string;

    public static function register(): void
    {
        static::$search = [];
        static::$stats = [];
        static::$calls = [];
        config(['novarr.sources' => self::KEYS]);
    }

    public static function reset(): void
    {
        static::$search = [];
        static::$stats = [];
        static::$calls = [];
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
        return ['search'];
    }

    public function discover(string $type, ?string $query = null): ?array
    {
        static::$calls[] = ['source' => $this->name(), 'kind' => 'search', 'arg' => (string) $query];
        $answer = array_key_exists(static::class, static::$search) ? static::$search[static::class] : [];

        return $answer instanceof \Closure ? $answer($query) : $answer;
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
