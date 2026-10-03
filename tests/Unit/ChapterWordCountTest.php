<?php

namespace Tests\Unit;

use App\Console\Commands\ChapterScraper;
use Tests\TestCase;

/**
 * countChapterWords(): word counts for fetched chapter HTML (audit F24).
 */
class ChapterWordCountTest extends TestCase
{
    public function testTagsAndEntitiesDoNotInflateTheCount()
    {
        $this->assertSame(
            5,
            ChapterScraper::countChapterWords('<p>Hello world.</p><p>It&#039;s fine &quot;ok&quot;</p>')
        );
    }

    public function testParagraphBoundariesSeparateWords()
    {
        $this->assertSame(2, ChapterScraper::countChapterWords('<p>end</p><p>start</p>'));
        $this->assertSame(2, ChapterScraper::countChapterWords('one<br>two'));
    }

    public function testCurlyApostrophesAndHyphensStayInsideAWord()
    {
        $this->assertSame(3, ChapterScraper::countChapterWords('Don’t well-known 42'));
    }

    public function testEmptyAndTagOnlyInputIsZero()
    {
        $this->assertSame(0, ChapterScraper::countChapterWords(''));
        $this->assertSame(0, ChapterScraper::countChapterWords('<p>&nbsp;</p><br/>'));
    }

    public function testChineseCountsHalfAWordPerCharacter()
    {
        // A 300-character paragraph; str_word_count() gave ~0 here.
        $chinese = '<p>' . mb_substr(str_repeat('他看着远方的山心中', 40), 0, 300) . '</p>';
        $this->assertSame(300, mb_strlen(strip_tags($chinese)));
        $this->assertSame(150, ChapterScraper::countChapterWords($chinese));
    }

    public function testJapaneseAndKoreanAreCounted()
    {
        $this->assertSame(4, ChapterScraper::countChapterWords('ひらがなカタカナ'));
        $this->assertSame(2, ChapterScraper::countChapterWords('안녕하세'));
    }

    public function testMixedCjkAndLatin()
    {
        // 4 Han characters → 2, plus "Chapter" and "12".
        $this->assertSame(4, ChapterScraper::countChapterWords('Chapter 12 第十二章'));
    }
}
