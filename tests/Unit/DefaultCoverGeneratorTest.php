<?php

namespace Tests\Unit;

use App\Services\DefaultCoverGenerator;
use Tests\TestCase;

/**
 * The fallback ePub cover (brand pack §7): a 1600 × 2400 PNG/JPEG rendered with
 * GD when a novel has no artwork of its own.
 */
class DefaultCoverGeneratorTest extends TestCase
{
    private DefaultCoverGenerator $generator;
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new DefaultCoverGenerator();

        if (!$this->generator->isAvailable()) {
            $this->markTestSkipped('GD with FreeType support and the bundled brand fonts are required.');
        }

        $this->workDir = sys_get_temp_dir() . '/novarr-cover-test-' . getmypid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            foreach (glob($this->workDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->workDir);
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Output
    // -----------------------------------------------------------------

    /** A PNG cover is written at exactly the spec's 1600 × 2400. */
    public function testWritesAPngAtTheSpecifiedDimensions()
    {
        $path = $this->generator->generate($this->workDir . '/cover.png', 'Ashen God', 'Just call the polar bear', 1093);

        $this->assertFileExists($path);

        $info = getimagesize($path);
        $this->assertSame(1600, $info[0]);
        $this->assertSame(2400, $info[1]);
        $this->assertSame('image/png', $info['mime']);
        $this->assertSame(DefaultCoverGenerator::WIDTH, $info[0]);
        $this->assertSame(DefaultCoverGenerator::HEIGHT, $info[1]);
    }

    /** The ePub pipeline asks for JPEG, because Send-to-Kindle only renders JPEG covers. */
    public function testWritesAJpegWhenAskedTo()
    {
        $path = $this->generator->generate($this->workDir . '/cover.jpg', 'Outside Of Time', 'Ergen', 742, 'jpg');

        $info = getimagesize($path);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame([1600, 2400], [$info[0], $info[1]]);
    }

    /** Missing intermediate directories are created rather than failing the run. */
    public function testCreatesTheDestinationDirectory()
    {
        $path = $this->generator->generate($this->workDir . '/nested/deeper/cover.png', 'Ashen God');

        $this->assertFileExists($path);
    }

    /** The dark ground is #12151B, not black — the cover must not be a blank canvas. */
    public function testUsesTheBrandGround()
    {
        $path = $this->generator->generate($this->workDir . '/ground.png', 'Ashen God', null, 12);

        $image = imagecreatefrompng($path);
        // Top-right corner: ground only, well clear of the rule and the type.
        $rgb = imagecolorsforindex($image, imagecolorat($image, 1580, 20));
        imagedestroy($image);

        $this->assertSame([0x12, 0x15, 0x1B], [$rgb['red'], $rgb['green'], $rgb['blue']]);
    }

    // -----------------------------------------------------------------
    // Footer mark ("Serial": four flat bars, last bar amber)
    // -----------------------------------------------------------------

    /**
     * The footer mark sits at the page margin (192px) in a 96px box whose
     * bottom edge is the bottom margin (2400 - 192), so one grid unit is 3px:
     * grid (gx, gy) lands at (192 + 3·gx, 2112 + 3·gy).
     */
    private function markPixel(\GdImage $image, float $gx, float $gy): array
    {
        $rgb = imagecolorsforindex($image, imagecolorat($image, (int) (192 + 3 * $gx), (int) (2112 + 3 * $gy)));

        return [$rgb['red'], $rgb['green'], $rgb['blue']];
    }

    /** Bar 1 is flat indigo, bar 4 is amber, and the old N's diagonal is gone. */
    public function testTheFooterMarkIsTheSerialBars()
    {
        $path = $this->generator->generate($this->workDir . '/mark.png', 'Ashen God', null, 12);
        $image = imagecreatefrompng($path);

        $bar1Left = $this->markPixel($image, 7, 7);    // inside bar 1 (x 5–27, y 5–9)
        $bar1Right = $this->markPixel($image, 25, 7);
        $bar4 = $this->markPixel($image, 9, 26.5);     // inside bar 4 (x 5–13, y 24.5–28.5)
        $gap = $this->markPixel($image, 18.5, 16.75);  // where the old diagonal spine ran
        $rightOfBar4 = $this->markPixel($image, 20, 26.5);
        imagedestroy($image);

        $this->assertSame([0x64, 0x70, 0xFF], $bar1Left);
        // Flat fill, no gradient: both ends of the bar are the same colour.
        $this->assertSame($bar1Left, $bar1Right);
        $this->assertSame([0xF0, 0xB4, 0x29], $bar4);
        $this->assertSame([0x12, 0x15, 0x1B], $gap);
        $this->assertSame([0x12, 0x15, 0x1B], $rightOfBar4);
    }

    /** On the light (e-ink) ground the amber bar drops to #C98A00 for contrast. */
    public function testTheLightCoverUsesTheDeeperAmber()
    {
        $path = $this->generator->generate($this->workDir . '/mark-light.png', 'Ashen God', null, 12, 'png', true);
        $image = imagecreatefrompng($path);

        $bar4 = $this->markPixel($image, 9, 26.5);
        $gap = $this->markPixel($image, 18.5, 16.75);
        imagedestroy($image);

        $this->assertSame([0xC9, 0x8A, 0x00], $bar4);
        $this->assertSame([0xF7, 0xF8, 0xFA], $gap);
    }

    // -----------------------------------------------------------------
    // Title size steps
    // -----------------------------------------------------------------

    /** ≤18 characters take the largest step. */
    public function testShortTitlesUseTheLargestSizeStep()
    {
        $layout = $this->generator->layoutTitle('Ashen God');

        $this->assertSame(181, $layout['size']);
    }

    /** 19–44 characters step down once. */
    public function testMediumTitlesStepDownOnce()
    {
        $title = 'The Legend of the Sword Immortal';   // 32 chars
        $this->assertSame(32, mb_strlen($title));

        $this->assertSame(128, $this->generator->layoutTitle($title)['size']);
    }

    /** 45–90 characters step down twice. */
    public function testLongTitlesStepDownTwice()
    {
        $title = "A Horror Novel's Supporting Character Wants to Live as a Human"; // 62 chars
        $this->assertSame(62, mb_strlen($title));

        $this->assertSame(101, $this->generator->layoutTitle($title)['size']);
    }

    /** The steps are boundaries, not ranges: 18 and 19 characters differ. */
    public function testTheStepBoundaryIsInclusive()
    {
        $eighteen = 'Ashen God Rising!!';
        $nineteen = $eighteen . '!';

        $this->assertSame(18, mb_strlen($eighteen));
        $this->assertSame(19, mb_strlen($nineteen));

        $this->assertSame(181, $this->generator->layoutTitle($eighteen)['size']);
        $this->assertSame(128, $this->generator->layoutTitle($nineteen)['size']);
    }

    /** Titles are wrapped, never centred or clipped mid-word. */
    public function testTitlesAreWrappedOnWordBoundaries()
    {
        $layout = $this->generator->layoutTitle("A Horror Novel's Supporting Character Wants to Live as a Human");

        $this->assertGreaterThan(1, count($layout['lines']));
        $this->assertSame(
            "A Horror Novel's Supporting Character Wants to Live as a Human",
            implode(' ', $layout['lines']),
        );
    }

    // -----------------------------------------------------------------
    // Truncation
    // -----------------------------------------------------------------

    /** Titles at or under the 90-character cap are untouched. */
    public function testTitlesWithinTheCapAreNotTruncated()
    {
        $title = str_repeat('ab ', 29) . 'cd';   // 89 chars
        $this->assertSame(89, mb_strlen($title));

        $this->assertSame($title, $this->generator->truncateTitle($title));
    }

    /** Over 90 characters: cut at a word boundary and closed with an ellipsis. */
    public function testOverlongTitlesAreTruncatedAtAWordBoundaryWithAnEllipsis()
    {
        $title = 'The Extremely Long Title Of A Web Novel That Simply Refuses To Stop Going On And On Forever And Ever Beyond All Reason';
        $this->assertGreaterThan(90, mb_strlen($title));

        $truncated = $this->generator->truncateTitle($title);

        $this->assertStringEndsWith('…', $truncated);
        $this->assertLessThanOrEqual(DefaultCoverGenerator::MAX_TITLE_CHARS, mb_strlen($truncated));

        // Word boundary: everything before the ellipsis is a whole-word prefix
        // of the original, so no half-words are left dangling.
        $kept = mb_substr($truncated, 0, -1);
        $this->assertStringStartsWith($kept, $title);
        $this->assertSame(' ', mb_substr($title, mb_strlen($kept), 1));
    }

    /** Whitespace is normalised so a ragged title doesn't wrap on stray newlines. */
    public function testWhitespaceIsCollapsed()
    {
        $this->assertSame('Ashen God', $this->generator->truncateTitle("  Ashen\n\t God  "));
    }

    /** An empty title still produces a renderable cover. */
    public function testEmptyTitlesFallBackToAPlaceholder()
    {
        $this->assertSame('Untitled', $this->generator->truncateTitle('   '));

        $path = $this->generator->generate($this->workDir . '/empty.png', '');
        $this->assertFileExists($path);
    }

    /** A single unbreakable word is shrunk rather than allowed to overrun the page. */
    public function testUnbreakableTitlesAreShrunkToFit()
    {
        $layout = $this->generator->layoutTitle(str_repeat('W', 40));

        $this->assertLessThan(128, $layout['size']);
        $this->assertCount(1, $layout['lines']);
    }
}
