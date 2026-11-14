<?php

namespace Tests\Unit;

use App\Novel;
use App\Sources\AbstractSource;
use App\Sources\EmpireNovelSource;
use App\Sources\NovelArrowSource;
use App\Sources\NovelFullSource;
use App\Sources\SourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EmpireNovel / NovelFull claim a novel or URL by its parsed host (equal to,
 * or a subdomain of, one of hosts()), not by the site name appearing
 * anywhere in the URL. NovelArrow stays the catch-all.
 */
class SourceHostMatchingTest extends TestCase
{
    public static function urls(): array
    {
        return [
            // url => [expected source key]
            'novelfull plain'            => ['https://novelfull.com/some-novel.html', 'novelfull'],
            'novelfull www'              => ['https://www.novelfull.com/some-novel.html', 'novelfull'],
            'novelfull subdomain'        => ['https://m.novelfull.com/some-novel/chapter-1.html', 'novelfull'],
            'novelfull upper case'       => ['HTTPS://WWW.NovelFull.COM/x.html', 'novelfull'],
            'novelfull port'             => ['https://novelfull.com:443/x.html', 'novelfull'],
            'novelfull no scheme'        => ['novelfull.com/some-novel.html', 'novelfull'],
            'novelfull no scheme www'    => ['www.novelfull.com/some-novel.html', 'novelfull'],
            'novelfull protocol-relative'=> ['//novelfull.com/some-novel.html', 'novelfull'],
            'novelfull padded'           => ['  https://novelfull.com/x.html  ', 'novelfull'],
            'empire plain'               => ['https://empirenovel.com/novel/some-novel', 'empirenovel'],
            'empire www'                 => ['https://www.empirenovel.com/novel/some-novel/12', 'empirenovel'],
            'empire no scheme'           => ['empirenovel.com/novel/some-novel', 'empirenovel'],

            // Substring elsewhere in the URL no longer claims it.
            'novelfull in query'         => ['https://example.com/?ref=novelfull.com', 'novelarrow'],
            'novelfull in path'          => ['https://example.com/novelfull.com/x', 'novelarrow'],
            'novelfull in fragment'      => ['https://example.com/x#novelfull.com', 'novelarrow'],
            'novelfull in slug'          => ['https://novelping.com/novel/novelfull.com-story', 'novelarrow'],
            'empire in query'            => ['https://example.com/?u=https://empirenovel.com/novel/x', 'novelarrow'],
            'empire in path'             => ['https://novelping.com/novel/empirenovel.com', 'novelarrow'],
            'empire in userinfo'         => ['https://empirenovel.com@evil.test/novel/x', 'novelarrow'],
            'lookalike prefix'           => ['https://notnovelfull.com/x.html', 'novelarrow'],
            'lookalike suffix'           => ['https://novelfull.com.evil.test/x.html', 'novelarrow'],
            'relative path'              => ['/novelfull.com/x.html', 'novelarrow'],
            'empty'                      => ['', 'novelarrow'],
        ];
    }

    #[DataProvider('urls')]
    public function testForUrlResolvesByHost(string $url, string $expected): void
    {
        $this->assertSame($expected, SourceResolver::forUrl($url)->name());
    }

    #[DataProvider('urls')]
    public function testForNovelResolvesByTranslatorUrlHost(string $url, string $expected): void
    {
        $novel = new Novel();
        $novel->forceFill(['name' => 'Some Novel', 'translator_url' => $url]);

        $this->assertSame($expected, SourceResolver::for($novel)->name());
    }

    public function testAdapterMatchersAgreeWithTheResolver(): void
    {
        $full = new NovelFullSource();
        $empire = new EmpireNovelSource();

        $this->assertTrue($full->matchesUrl('https://www.novelfull.com/a.html'));
        $this->assertFalse($full->matchesUrl('https://example.com/?ref=novelfull.com'));
        $this->assertFalse($full->matchesUrl('https://empirenovel.com/novel/a'));
        $this->assertTrue($empire->matchesUrl('https://www.empirenovel.com/novel/a'));
        $this->assertFalse($empire->matchesUrl('https://example.com/empirenovel/novel/a'));

        $novel = new Novel();
        $novel->forceFill(['translator_url' => null]);
        $this->assertFalse($full->matches($novel));
        $this->assertFalse($empire->matches($novel));
    }

    public function testNovelArrowStaysTheCatchAll(): void
    {
        $arrow = new NovelArrowSource();
        $this->assertTrue($arrow->matchesUrl('https://example.com/anything'));
        $this->assertTrue($arrow->matchesUrl(''));
        $this->assertTrue($arrow->matches(new Novel()));
    }

    public function testUrlHost(): void
    {
        $this->assertSame('www.novelfull.com', AbstractSource::urlHost('HTTPS://WWW.NovelFull.com/x'));
        $this->assertSame('novelfull.com', AbstractSource::urlHost('novelfull.com'));
        $this->assertSame('novelfull.com', AbstractSource::urlHost('novelfull.com./x'));
        $this->assertSame('novelfull.com', AbstractSource::urlHost('//novelfull.com/x'));
        $this->assertNull(AbstractSource::urlHost(''));
        $this->assertNull(AbstractSource::urlHost('   '));
        $this->assertNull(AbstractSource::urlHost('/novel/x'));
        $this->assertNull(AbstractSource::urlHost('http://'));
    }

    public function testHostMatches(): void
    {
        $this->assertTrue(AbstractSource::hostMatches('novelfull.com', ['novelfull.com']));
        $this->assertTrue(AbstractSource::hostMatches('www.novelfull.com', ['NovelFull.com']));
        $this->assertTrue(AbstractSource::hostMatches('a.b.novelfull.com', ['novelfull.com']));
        $this->assertFalse(AbstractSource::hostMatches('notnovelfull.com', ['novelfull.com']));
        $this->assertFalse(AbstractSource::hostMatches('novelfull.com.evil.test', ['novelfull.com']));
        $this->assertFalse(AbstractSource::hostMatches('novelfull.com', ['', ' ']));
        $this->assertFalse(AbstractSource::hostMatches(null, ['novelfull.com']));
        $this->assertFalse(AbstractSource::hostMatches('novelfull.com', []));
    }
}
