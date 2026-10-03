<?php

namespace Tests\Unit;

use App\NovelChapter;
use PHPUnit\Framework\TestCase;

/** The reader renders presentContent() raw, so it must leave no attributes. */
class PresentContentTest extends TestCase
{
    public function testAttributesAreStrippedFromAllowedTags(): void
    {
        $html = '<p onclick="alert(1)" style="color:red">Hi <b onmouseover="x()">there</b></p>';
        $this->assertSame('<p>Hi <b>there</b></p>', NovelChapter::presentContent($html));
    }

    public function testDisallowedTagsAndScriptsGo(): void
    {
        $html = '<p>Hi</p><script>alert(1)</script><img src=x onerror=alert(1)><em class="a">x</em>';
        $this->assertSame('<p>Hi</p>alert(1)<em>x</em>', NovelChapter::presentContent($html));
    }

    public function testVoidTagsAreSelfClosedAndNbspParagraphsDropped(): void
    {
        $this->assertSame('<p>a</p><br/><hr/>', NovelChapter::presentContent('<p>a</p><p>&nbsp;</p><br><hr>'));
    }

    /** A bare "<" is prose, not a tag: nothing after it may be lost. */
    public function testBareLessThanDoesNotSwallowText(): void
    {
        $this->assertSame('<p>I &lt;3 you, she said.</p><p>Next line.</p>', NovelChapter::presentContent('<p>I <3 you, she said.</p><p>Next line.</p>'));
        $this->assertSame('<p>a &lt; b and b > c</p>', NovelChapter::presentContent('<p>a < b and b > c</p>'));
    }
}
