<?php

namespace Tests\Unit;

use App\Console\Commands\NormalizeChapterLabels as N;
use Tests\TestCase;

/**
 * Audit F1/F2/F3: novel:normalize_labels must never floor decimals, never let
 * a URL override a stored number, never read "season2" as a part, and never
 * mangle real title words while stripping promo spam.
 */
class NormalizeChapterLabelsTest extends TestCase
{
    // ---- F1: stored numbers (decimals) are kept --------------------------

    public function testDecimalChapterNumbersAreNeverFloored()
    {
        $this->assertSame(12.5, N::extractChapterNumber('Side Story', 12.5));
        $this->assertSame(164.2, N::extractChapterNumber('Chapter 164(2)', 164.2, 'https://x.test/novel/chapter-164-2'));
        $this->assertSame(7.25, N::extractChapterNumber('Chapter 7 - Interlude', '7.25'));
    }

    public function testStoredNumberIsKeptWhenLabelAndUrlDisagree()
    {
        $this->assertSame(42.0, N::extractChapterNumber('Chapter 40 - Title', 42, 'https://x.test/chapter-40'));
    }

    // ---- F2: URL never overrides; parts parsed narrowly -----------------

    public function testUrlDoesNotOverrideStoredNumberFarAway()
    {
        $this->assertSame(205.0, N::extractChapterNumber('Chapter 205 - Fight', 205, 'https://x.test/volume-3-chapter-5'));
    }

    public function testUrlOnlyFillsInMissingNumber()
    {
        $this->assertSame(5.0, N::extractChapterNumber('Some Title', 0, 'https://x.test/volume-3-chapter-5'));
        $this->assertSame(17.0, N::extractChapterNumber('Untitled', -1, 'https://x.test/chapter-17'));
        // Nothing to derive from -> stored value is kept.
        $this->assertSame(0.0, N::extractChapterNumber('Untitled', 0, null));
    }

    public function testLabelNumberPreferredOverUrlWhenMissing()
    {
        $this->assertSame(9.0, N::extractChapterNumber('Chapter 9 - X', 0, 'https://x.test/chapter-99'));
    }

    public function testUnderscorePartNoLongerChangesAStoredNumber()
    {
        // Old behaviour: 5 + 12/10 = 6.2.
        $this->assertSame(5.0, N::extractChapterNumber('Title_12', 5));
    }

    public function testRenumberDerivesFromLabelWithPartEncoding()
    {
        $this->assertSame(5.2, N::extractChapterNumber('Chapter 5 - Title_2', 99, null, true));
        $this->assertSame(5.93, N::extractChapterNumber('Chapter 5 - Title_12', 99, null, true));
        $this->assertSame(5.91, N::extractChapterNumber('Chapter 5 - Title_10', 0));
        $this->assertSame(164.2, N::extractChapterNumber('Chapter 164(2)', 0));
        $this->assertSame(8.3, N::extractChapterNumber('Chapter 8 - Title (Part 3)', 0));
        $this->assertSame(8.3, N::extractChapterNumber('Chapter 8 - Title Part 3', 0));
        $this->assertSame(12.5, N::extractChapterNumber('Chapter 12.5 - Side', 0));
    }

    public function testDigitGluedToWordIsNotAPart()
    {
        $this->assertSame(0.0, N::partSuffix('Wizard2'));
        $this->assertSame(0.0, N::partSuffix('Chapter 30 - The Return-season2'));
        // URL ending "season2" is not read as a part either.
        $this->assertSame(30.0, N::extractChapterNumber('Chapter 30 - X', 0, 'https://x.test/chapter-30-season2'));
    }

    public function testPartSuffixEncoding()
    {
        $this->assertSame(0.2, N::partSuffix('Title_2'));
        $this->assertSame(0.9, N::partSuffix('Title (Part 9)'));
        // Same encoding as Helpers' encodeChapterPart(): 10–18 -> .91–.99.
        $this->assertSame(0.91, N::partSuffix('Title_10'));
        $this->assertSame(0.93, N::partSuffix('Title (Part 12)'));
        $this->assertSame(0.99, N::partSuffix('Title_40'));
        $this->assertNotSame(N::partSuffix('Title_1'), N::partSuffix('Title_10'));
        $this->assertSame(0.2, N::partSuffix('Title (2)'));
        $this->assertSame(0.0, N::partSuffix('Plain Title'));
    }

    // ---- F3: label cleanup doesn't mangle titles ------------------------

    public function testRealTitleWordsAreNotTreatedAsSpam()
    {
        $this->assertSame('Chapter 3 - The Subscriber Returns', N::normalizeLabel('Chapter 3 - The Subscriber Returns', 3));
        $this->assertSame('Chapter 4 - Chase Reading Kings', N::normalizeLabel('Chapter 4 - Chase Reading Kings', 4));
        $this->assertSame('Chapter 1 - I Am the King', N::normalizeLabel('Chapter 1 - I Am the King', 1));
        $this->assertSame('Chapter 9 - 1000 Years Later', N::normalizeLabel('Chapter 9 - 1000 Years Later', 9));
        $this->assertSame('ll Be Back', N::normalizeLabel('ll Be Back', 2));
    }

    public function testPromoSuffixesAreRemoved()
    {
        $this->assertSame('Chapter 10 - The Duel', N::normalizeLabel('Chapter 10 - The Duel (Please Subscribe)', 10));
        $this->assertSame('Chapter 10 - The Duel', N::normalizeLabel('Chapter 10 - The Duel [Seeking Chase Reading]', 10));
        $this->assertSame('Chapter 10 - The Duel', N::normalizeLabel('Chapter 10 - The Duel (Please Continue Read', 10));
        $this->assertSame('Chapter 10 - The Duel', N::normalizeLabel('Chapter 10 - The Duel Please Chase Reading', 10));
        $this->assertSame('Chapter 10 - The Duel', N::normalizeLabel('Chapter 10 - The Duel (Subscribe)(Chase Reading)', 10));
        $this->assertSame('Chapter 10 - The Duel (Part 2)', N::normalizeLabel('Chapter 10 - The Duel (Please Subscribe)_2', 10));
    }

    public function testLeadingNumberOnlyStrippedWhenItIsTheChapterNumber()
    {
        $this->assertSame('Chapter 1 - Title', N::normalizeLabel('Chapter 1 - 1 1 Title', 1));
        $this->assertSame('Chapter 101 - 94 Title (Part 2)', N::normalizeLabel('Chapter 101 - 101 94 Title_2', 101.2));
        $this->assertSame('Chapter 321 - Level 8 Wizard', N::normalizeLabel('Chapter321 321 Level 8 Wizard', 321));
        $this->assertSame('Years Later', N::normalizeLabel('1000 Years Later', 1000));
        $this->assertSame('1000 Years Later', N::normalizeLabel('1000 Years Later', 12.5));
    }

    public function testDecimalChapterLabelsKeepTheirOwnNumber()
    {
        $this->assertSame('Side Story', N::normalizeLabel('Side Story', 12.5));
        $this->assertSame('Chapter 164(2)', N::normalizeLabel('Chapter 164(2)', 164.2));
        $this->assertSame('Chapter 12.5 - Extra', N::normalizeLabel('Chapter 12.5 - Extra', 12.5));
    }

    public function testNormalizationIsIdempotent()
    {
        $labels = [
            ['Chapter 10 - The Duel (Please Subscribe)_2', 10.2],
            ['Chapter 1 - 1 1 Title', 1],
            ['Chapter321 321 Level 8 Wizard', 321],
            ['Chapter 3 - The Subscriber Returns', 3],
            ['Side Story', 12.5],
            ['Chapter 0 - Prologue', 1],
        ];
        foreach ($labels as [$label, $num]) {
            $once = N::normalizeLabel($label, $num);
            $this->assertSame($once, N::normalizeLabel($once, $num), "not idempotent for: {$label}");
            $n1 = N::extractChapterNumber($once, N::extractChapterNumber($label, $num));
            $this->assertSame((float) N::extractChapterNumber($label, $num), $n1);
        }
    }

    // ---- F1: dedupe keeper selection ------------------------------------

    public function testDedupeKeepsDownloadedRowThenLowestId()
    {
        [$keep, $delete] = N::planDuplicateRemoval([
            ['id' => 3, 'status' => 0, 'url' => 'a'],
            ['id' => 7, 'status' => 1, 'url' => 'b'],
            ['id' => 5, 'status' => 1, 'url' => 'c'],
        ]);
        $this->assertSame(5, $keep);
        $this->assertSame([7, 3], $delete);

        [$keep, $delete] = N::planDuplicateRemoval([
            ['id' => 9, 'status' => false, 'url' => 'a'],
            ['id' => 2, 'status' => false, 'url' => 'a'],
        ]);
        $this->assertSame(2, $keep);
        $this->assertSame([9], $delete);

        $this->assertSame([null, []], N::planDuplicateRemoval([]));
    }
}
