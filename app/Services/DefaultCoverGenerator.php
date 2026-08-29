<?php

namespace App\Services;

use App\Novel;
use RuntimeException;

/**
 * Renders the Novarr fallback ePub cover (brand pack §7) with GD.
 *
 * 1600 × 2400, ground #12151B, a 3px (16px at full scale) vertical gradient
 * rule bleeding off the top edge inset by the page margin, the title flush
 * left in Literata 600 with size steps by length, the author under it, and a
 * footer holding the mark, the NOVARR. wordmark and the chapter count above a
 * hairline rule.
 *
 * Geometry is the brand mock (300 × 450) scaled by 1600/300 = 16/3. The mock
 * is the visual source of truth, so the title steps are the mock's 34/24/19px
 * scaled up (181/128/101) rather than README §7's literal "96/72/54px", which
 * cannot be reconciled with the mock at any single scale factor.
 *
 * Used as the last-resort cover in novel:epub; it is deliberately a plain
 * service so the web UI can render the same placeholder later.
 */
class DefaultCoverGenerator
{
    public const WIDTH = 1600;
    public const HEIGHT = 2400;

    /** Mock scale: the 300 × 450 artboard maps onto 1600 × 2400. */
    private const SCALE = 16 / 3;

    /** Longest title we will render; anything longer is cut at a word boundary. */
    public const MAX_TITLE_CHARS = 90;

    /**
     * Title size steps, keyed by the inclusive character-count ceiling.
     * Mock 34 / 24 / 19px × 16/3.
     */
    private const TITLE_STEPS = [
        18 => ['size' => 181, 'line_height' => 1.15],
        44 => ['size' => 128, 'line_height' => 1.20],
        90 => ['size' => 101, 'line_height' => 1.25],
    ];

    private const DARK = [
        'ground' => '#12151B',
        'title' => '#E8EBF0',
        'author' => '#8B95A5',
        'border' => '#262D38',
        'wordmark' => '#8B95A5',
        'stop' => '#F0B429',
        'count' => '#6B7684',
        'gradient_from' => '#6470FF',
        'gradient_to' => '#9B6BFF',
        'bookmark' => '#F0B429',
    ];

    private const LIGHT = [
        'ground' => '#F7F8FA',
        'title' => '#1A1F27',
        'author' => '#5C6675',
        'border' => '#DDE1E8',
        'wordmark' => '#5C6675',
        'stop' => '#C98A00',
        'count' => '#8B95A5',
        'gradient_from' => '#4A58E8',
        'gradient_to' => '#8B5CF6',
        'bookmark' => '#C98A00',
    ];

    /**
     * Can this installation render a cover? (GD with FreeType + the bundled faces.)
     */
    public function isAvailable(): bool
    {
        if (!function_exists('imagettftext') || !function_exists('imagecreatetruecolor')) {
            return false;
        }

        $info = function_exists('gd_info') ? gd_info() : [];
        if (empty($info['FreeType Support'])) {
            return false;
        }

        foreach (['serif', 'sans', 'sans-semibold', 'mono'] as $role) {
            if (!is_file($this->fontPath($role))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Render a cover for a novel straight from the model.
     */
    public function generateForNovel(Novel $novel, string $destPath, string $format = 'png', bool $light = false): string
    {
        $chapters = $novel->relationLoaded('chapters')
            ? $novel->chapters->count()
            : (int) ($novel->no_of_chapters ?: 0);

        return $this->generate(
            $destPath,
            (string) $novel->name,
            $novel->author,
            $chapters > 0 ? $chapters : null,
            $format,
            $light,
        );
    }

    /**
     * Render the cover to $destPath and return the path written.
     *
     * @param  string  $format  'png' (default) or 'jpg'/'jpeg'
     */
    public function generate(
        string $destPath,
        string $title,
        ?string $author = null,
        ?int $chapterCount = null,
        string $format = 'png',
        bool $light = false,
    ): string {
        if (!$this->isAvailable()) {
            throw new RuntimeException('GD with FreeType support and the bundled brand fonts are required to render a cover.');
        }

        $format = strtolower($format) === 'png' ? 'png' : 'jpg';
        $palette = $light ? self::LIGHT : self::DARK;

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($canvas, true);
        imagefilledrectangle($canvas, 0, 0, self::WIDTH, self::HEIGHT, $this->colour($canvas, $palette['ground']));

        $margin = $this->px(36);

        $this->drawGradientRule($canvas, $margin, $this->px(3), $this->px(120), $palette);
        $this->drawTitleBlock($canvas, $title, $author, $margin, $palette);
        $this->drawFooter($canvas, $chapterCount, $margin, $palette);

        $dir = dirname($destPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($canvas);
            throw new RuntimeException("Unable to create cover directory {$dir}");
        }

        $written = $format === 'png'
            ? imagepng($canvas, $destPath, 6)
            : imagejpeg($canvas, $destPath, 92);

        imagedestroy($canvas);

        if (!$written) {
            throw new RuntimeException("Unable to write cover image to {$destPath}");
        }

        return $destPath;
    }

    /**
     * Title layout decision: the size step, its line height and the wrapped
     * lines. Exposed (and pure) so it can be asserted directly in tests.
     *
     * @return array{size: int, line_height: float, lines: array<int, string>, title: string}
     */
    public function layoutTitle(string $title, ?int $maxWidth = null): array
    {
        $title = $this->truncateTitle($title);
        $maxWidth ??= self::WIDTH - (2 * $this->px(36));

        $step = self::TITLE_STEPS[90];
        foreach (self::TITLE_STEPS as $ceiling => $candidate) {
            if (mb_strlen($title) <= $ceiling) {
                $step = $candidate;
                break;
            }
        }

        $size = $step['size'];
        $lines = $this->wrap($title, $this->fontPath('serif'), $size, $maxWidth);

        // Safety valve: a single unbreakable word can be wider than the column,
        // and a heavily wrapped title can overrun the band between the rule and
        // the footer. Step down until it fits rather than clipping or colliding.
        $available = $this->titleBandHeight();
        $overflows = function (array $lines, int $size) use ($maxWidth, $available, $step) {
            if ((count($lines) * $size * $step['line_height']) > $available) {
                return true;
            }

            foreach ($lines as $line) {
                if ($this->textWidth($this->fontPath('serif'), $size, $line) > $maxWidth) {
                    return true;
                }
            }

            return false;
        };

        while ($size > 64 && $overflows($lines, $size)) {
            $size = (int) round($size * 0.88);
            $lines = $this->wrap($title, $this->fontPath('serif'), $size, $maxWidth);
        }

        return [
            'size' => $size,
            'line_height' => $step['line_height'],
            'lines' => $lines,
            'title' => $title,
        ];
    }

    /**
     * Titles over MAX_TITLE_CHARS are cut back to a word boundary and closed
     * with an ellipsis (brand pack §7).
     */
    public function truncateTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');

        if ($title === '') {
            return 'Untitled';
        }

        if (mb_strlen($title) <= self::MAX_TITLE_CHARS) {
            return $title;
        }

        // Leave room for the ellipsis so the finished string never exceeds the cap.
        $cut = mb_substr($title, 0, self::MAX_TITLE_CHARS - 1);
        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n,.;:—–-") . '…';
    }

    // -----------------------------------------------------------------
    // Drawing
    // -----------------------------------------------------------------

    private function drawGradientRule(\GdImage $canvas, int $x, int $width, int $height, array $palette): void
    {
        [$r1, $g1, $b1] = $this->rgb($palette['gradient_from']);
        [$r2, $g2, $b2] = $this->rgb($palette['gradient_to']);

        for ($y = 0; $y < $height; $y++) {
            $t = $height > 1 ? $y / ($height - 1) : 0;
            $colour = imagecolorallocate(
                $canvas,
                (int) round($r1 + ($r2 - $r1) * $t),
                (int) round($g1 + ($g2 - $g1) * $t),
                (int) round($b1 + ($b2 - $b1) * $t),
            );
            imagefilledrectangle($canvas, $x, $y, $x + $width - 1, $y, $colour);
        }
    }

    private function drawTitleBlock(\GdImage $canvas, string $title, ?string $author, int $margin, array $palette): void
    {
        $maxWidth = self::WIDTH - (2 * $margin);
        $layout = $this->layoutTitle($title, $maxWidth);
        $serif = $this->fontPath('serif');

        $lineHeight = $layout['size'] * $layout['line_height'];
        $ascent = $this->ascent($serif, $layout['size']);
        $baseline = $this->titleTop() + $ascent;

        $colour = $this->colour($canvas, $palette['title']);
        foreach ($layout['lines'] as $index => $line) {
            imagettftext($canvas, $layout['size'], 0, $margin, (int) round($baseline + $index * $lineHeight), $colour, $serif, $line);
        }

        $author = trim((string) $author);
        if ($author === '' || strcasecmp($author, 'unknown') === 0) {
            return;
        }

        $sans = $this->fontPath('sans');
        $authorSize = $this->px(12);
        $blockBottom = $this->titleTop() + ((count($layout['lines']) - 1) * $lineHeight) + $layout['size'];
        $authorBaseline = $blockBottom + $this->px(14) + $this->ascent($sans, $authorSize);

        $authorLines = $this->wrap($author, $sans, $authorSize, $maxWidth);
        $authorColour = $this->colour($canvas, $palette['author']);

        foreach (array_slice($authorLines, 0, 2) as $index => $line) {
            imagettftext($canvas, $authorSize, 0, $margin, (int) round($authorBaseline + $index * $authorSize * 1.4), $authorColour, $sans, $line);
        }
    }

    private function drawFooter(\GdImage $canvas, ?int $chapterCount, int $margin, array $palette): void
    {
        $markSize = $this->px(18);
        $rowBottom = self::HEIGHT - $margin;
        $rowTop = $rowBottom - $markSize;
        $ruleY = $rowTop - $this->px(16);
        $hairline = max(1, $this->px(1));

        imagefilledrectangle(
            $canvas,
            $margin,
            $ruleY,
            self::WIDTH - $margin - 1,
            $ruleY + $hairline - 1,
            $this->colour($canvas, $palette['border']),
        );

        $this->drawMark($canvas, $margin, $rowTop, $markSize, $palette);

        // Wordmark, optically centred against the mark.
        $sans = $this->fontPath('sans-semibold');
        $wordSize = $this->px(10);
        $tracking = $wordSize * 0.16;
        $capHeight = $this->capHeight($sans, $wordSize);
        $baseline = (int) round($rowTop + ($markSize + $capHeight) / 2);
        $x = $margin + $markSize + $this->px(10);

        $x = $this->drawTracked($canvas, $sans, $wordSize, $x, $baseline, $this->colour($canvas, $palette['wordmark']), 'NOVARR', $tracking);
        $this->drawTracked($canvas, $sans, $wordSize, $x, $baseline, $this->colour($canvas, $palette['stop']), '.', $tracking);

        if ($chapterCount === null || $chapterCount <= 0) {
            return;
        }

        $mono = $this->fontPath('mono');
        $label = number_format($chapterCount) . ' CH';
        $box = imagettfbbox($wordSize, 0, $mono, $label);
        $width = $box[2] - $box[0];

        imagettftext(
            $canvas,
            $wordSize,
            0,
            self::WIDTH - $margin - $width,
            $baseline,
            $this->colour($canvas, $palette['count']),
            $mono,
            $label,
        );
    }

    /**
     * The brand mark, drawn from the 32×32 geometry in the handoff and
     * supersampled 4× so the diagonal spine downsamples cleanly.
     */
    private function drawMark(\GdImage $canvas, int $x, int $y, int $size, array $palette): void
    {
        $ss = 4;
        $n = $size * $ss;
        $u = $n / 32; // one grid unit

        $mark = imagecreatetruecolor($n, $n);
        imagealphablending($mark, false);
        imagesavealpha($mark, true);
        imagefilledrectangle($mark, 0, 0, $n, $n, imagecolorallocatealpha($mark, 0, 0, 0, 127));

        // Mask of the three gradient spines.
        $mask = imagecreatetruecolor($n, $n);
        imagealphablending($mask, false);
        $black = imagecolorallocate($mask, 0, 0, 0);
        $white = imagecolorallocate($mask, 255, 255, 255);
        imagefilledrectangle($mask, 0, 0, $n, $n, $black);
        imagefilledrectangle($mask, (int) round(6 * $u), (int) round(5 * $u), (int) round(11 * $u) - 1, (int) round(27 * $u) - 1, $white);
        imagefilledrectangle($mask, (int) round(21 * $u), (int) round(13 * $u), (int) round(26 * $u) - 1, (int) round(27 * $u) - 1, $white);
        imagefilledpolygon($mask, [
            (int) round(11 * $u), (int) round(5 * $u),
            (int) round(16 * $u), (int) round(5 * $u),
            (int) round(26 * $u), (int) round(27 * $u),
            (int) round(21 * $u), (int) round(27 * $u),
        ], $white);

        // 135° gradient (CSS: toward the bottom-right corner).
        [$r1, $g1, $b1] = $this->rgb($palette['gradient_from']);
        [$r2, $g2, $b2] = $this->rgb($palette['gradient_to']);
        $span = max(1, (2 * $n) - 2);

        for ($py = 0; $py < $n; $py++) {
            for ($px = 0; $px < $n; $px++) {
                if ((imagecolorat($mask, $px, $py) & 0xFF) === 0) {
                    continue;
                }
                $t = ($px + $py) / $span;
                imagesetpixel($mark, $px, $py, imagecolorallocate(
                    $mark,
                    (int) round($r1 + ($r2 - $r1) * $t),
                    (int) round($g1 + ($g2 - $g1) * $t),
                    (int) round($b1 + ($b2 - $b1) * $t),
                ));
            }
        }
        imagedestroy($mask);

        // Amber bookmark on the right spine.
        imagefilledpolygon($mark, [
            (int) round(21 * $u), (int) round(5 * $u),
            (int) round(26 * $u), (int) round(5 * $u),
            (int) round(26 * $u), (int) round(13 * $u),
            (int) round(23.5 * $u), (int) round(10.8 * $u),
            (int) round(21 * $u), (int) round(13 * $u),
        ], $this->colour($mark, $palette['bookmark']));

        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $mark, $x, $y, 0, 0, $size, $size, $n, $n);
        imagedestroy($mark);
    }

    /**
     * Draw uppercase text with letter-spacing, returning the pen x after the
     * last glyph. GD has no tracking, so glyphs are placed one at a time using
     * the cumulative advance of the preceding prefix (kerning is irrelevant for
     * the tracked caps this is used for).
     */
    private function drawTracked(\GdImage $img, string $font, int $size, int $x, int $y, int $colour, string $text, float $tracking): int
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cursor = (float) $x;

        foreach ($chars as $char) {
            imagettftext($img, $size, 0, (int) round($cursor), $y, $colour, $font, $char);
            $box = imagettfbbox($size, 0, $font, $char);
            $cursor += ($box[2] - $box[0]) + $tracking;
        }

        return (int) round($cursor);
    }

    // -----------------------------------------------------------------
    // Metrics helpers
    // -----------------------------------------------------------------

    /** Top of the first title line, from the mock's 36px padding + 120px offset. */
    private function titleTop(): int
    {
        return $this->px(36 + 120);
    }

    /** Vertical room the title may occupy before it crowds the footer. */
    private function titleBandHeight(): int
    {
        $footerTop = self::HEIGHT - $this->px(36) - $this->px(18) - $this->px(16);

        return $footerTop - $this->titleTop() - $this->px(40);
    }

    /** @return array<int, string> */
    private function wrap(string $text, string $font, int $size, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return [''];
        }

        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : "{$current} {$word}";
            if ($current !== '' && $this->textWidth($font, $size, $candidate) > $maxWidth) {
                $lines[] = $current;
                $current = $word;
                continue;
            }
            $current = $candidate;
        }

        $lines[] = $current;

        return $lines;
    }

    private function textWidth(string $font, int $size, string $text): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box === false ? 0 : (int) ($box[2] - $box[0]);
    }

    /** Distance from the line top to the baseline, from the font's own extremes. */
    private function ascent(string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, 'Hbdfhklt');

        return $box === false ? $size : (int) abs($box[7]);
    }

    private function capHeight(string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, 'H');

        return $box === false ? $size : (int) abs($box[7]);
    }

    /** Scale a mock (300 × 450) measurement up to the 1600 × 2400 output. */
    private function px(float $mockPx): int
    {
        return (int) round($mockPx * self::SCALE);
    }

    public function fontPath(string $role): string
    {
        $files = [
            'serif' => 'Literata-SemiBold.ttf',
            'sans' => 'Geist-Regular.ttf',
            'sans-semibold' => 'Geist-SemiBold.ttf',
            'mono' => 'GeistMono-Regular.ttf',
        ];

        return resource_path('fonts/' . ($files[$role] ?? $files['serif']));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function colour(\GdImage $img, string $hex): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return imagecolorallocate($img, $r, $g, $b);
    }
}
