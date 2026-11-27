<?php

namespace App\Covers;

use App\Novel;

/**
 * Sets the title and author on a painted artwork (GD + FreeType, like
 * App\Services\DefaultCoverGenerator): the final cover is WIDTH × HEIGHT
 * (2:3), the artwork full-bleed (scaled to cover, centred, cropped), a soft
 * dark gradient at the top behind the title and at the bottom behind the
 * author, tinted with the artwork's own darkest tone.
 *
 * The title is auto-fitted: the largest size (STYLES[...]['max'] down to
 * 'min') at which it wraps to at most MAX_LINES lines inside the margins; a
 * title too long even then is cut with an ellipsis. Typography follows the
 * medium:
 *
 *   Book       Playfair Display, as written (a bookshop novel)
 *   Web Novel  Cinzel, capitals, a thin rule under it (light-novel / web-serial feel)
 *   Manga      Geist SemiBold, capitals, tight
 *
 * The author is set small in Geist SemiBold capitals, letter-spaced. The
 * faces are in resources/fonts (SIL Open Font License, OFL-*.txt beside
 * them). A title in a script these faces lack (Chinese, Japanese, Korean)
 * would render as boxes: it is left off and only the author is set.
 */
class CoverComposer
{
    public const WIDTH = 1000;
    public const HEIGHT = 1500;
    public const MARGIN = 90;
    /** Lines a title may take; a long one may take LONG_LINES at the smallest sizes. */
    public const MAX_LINES = 3;
    public const LONG_LINES = 4;

    /** GD sets type in points at 96 dpi: pixels per point. */
    private const PX_PER_PT = 96 / 72;
    public const MAX_TITLE_CHARS = 120;

    public const FONTS = [
        'playfair' => 'PlayfairDisplay.ttf',
        'cinzel' => 'Cinzel.ttf',
        'sans' => 'Geist-SemiBold.ttf',
        'sans-regular' => 'Geist-Regular.ttf',
    ];

    /** Per medium: title face, size range (px), line height, capitals, tracking (em), rule under the title. */
    public const STYLES = [
        'book' => ['font' => 'playfair', 'max' => 128, 'min' => 52, 'line' => 1.1, 'upper' => false, 'tracking' => 0.0, 'rule' => false],
        'novel' => ['font' => 'cinzel', 'max' => 112, 'min' => 46, 'line' => 1.14, 'upper' => true, 'tracking' => 0.03, 'rule' => true],
        'manga' => ['font' => 'sans', 'max' => 124, 'min' => 50, 'line' => 1.02, 'upper' => true, 'tracking' => 0.0, 'rule' => false],
    ];

    public const AUTHOR_SIZE = 30;
    public const AUTHOR_TRACKING = 0.16;

    public static function available(): bool
    {
        if (!function_exists('imagettftext') || empty((function_exists('gd_info') ? gd_info() : [])['FreeType Support'])) {
            return false;
        }
        foreach (array_keys(self::FONTS) as $role) {
            if (!is_file(static::font($role))) {
                return false;
            }
        }

        return true;
    }

    public static function font(string $role): string
    {
        return resource_path('fonts/' . (self::FONTS[$role] ?? self::FONTS['sans']));
    }

    /** The style key for a series' medium. */
    public static function styleFor(Novel|string $medium): string
    {
        $medium = $medium instanceof Novel ? (string) $medium->medium : $medium;

        return match ($medium) {
            Novel::MEDIUM_BOOK, 'book' => 'book',
            Novel::MEDIUM_MANGA, 'manga' => 'manga',
            default => 'novel',
        };
    }

    /**
     * The finished cover's bytes.
     *
     * @param  string  $style   a STYLES key (styleFor())
     * @param  string  $format  'jpg' (stored covers) or 'png'
     *
     * @throws \RuntimeException  GD / fonts missing, the artwork unreadable
     */
    public function compose(string $art, string $title, ?string $author, string $style = 'novel', string $format = 'jpg'): string
    {
        if (!static::available()) {
            throw new \RuntimeException('GD with FreeType and the bundled cover fonts are needed to set the title.');
        }
        $source = @imagecreatefromstring($art);
        if ($source === false) {
            throw new \RuntimeException('the painting could not be read');
        }
        $spec = self::STYLES[$style] ?? self::STYLES['novel'];
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);

        // Full bleed: scale to cover, centre, crop.
        $sw = imagesx($source);
        $sh = imagesy($source);
        $scale = max(self::WIDTH / $sw, self::HEIGHT / $sh);
        $cw = (int) round(self::WIDTH / $scale);
        $ch = (int) round(self::HEIGHT / $scale);
        imagecopyresampled($canvas, $source, 0, 0, (int) (($sw - $cw) / 2), (int) (($sh - $ch) / 2), self::WIDTH, self::HEIGHT, $cw, $ch);
        imagedestroy($source);

        $tint = $this->shadowTone($canvas);
        $title = static::displayTitle($title, $spec['upper']);
        $layout = $title !== '' ? $this->fitTitle($title, $spec) : ['lines' => [], 'size' => $spec['min']];
        $lineHeight = (int) round($layout['size'] * self::PX_PER_PT * $spec['line']);
        $titleHeight = count($layout['lines']) * $lineHeight;

        // Gradients: behind the title (deeper for a taller block) and the author.
        $topBand = (int) min(self::HEIGHT * 0.55, self::MARGIN + $titleHeight + 220);
        $this->gradient($canvas, 0, $topBand, $tint, 0.82, true);
        $this->gradient($canvas, self::HEIGHT - (int) (self::HEIGHT * 0.22), self::HEIGHT, $tint, 0.78, false);

        $ink = imagecolorallocate($canvas, 247, 243, 234);
        $font = static::font($spec['font']);
        $tracking = $spec['tracking'] * $layout['size'];
        $y = self::MARGIN + (int) round($layout['size'] * self::PX_PER_PT * 0.75);
        foreach ($layout['lines'] as $line) {
            $width = $this->textWidth($font, $layout['size'], $line, $tracking);
            $x = (int) round((self::WIDTH - $width) / 2);
            $this->shadowed($canvas, $font, $layout['size'], $x, $y, $line, $tracking, $ink);
            $y += $lineHeight;
        }
        if ($spec['rule'] && $layout['lines'] !== []) {
            $ruleY = $y - $lineHeight + (int) round($layout['size'] * self::PX_PER_PT * 0.4);
            $gold = imagecolorallocatealpha($canvas, 232, 214, 160, 30);
            imagefilledrectangle($canvas, (int) (self::WIDTH / 2 - 70), $ruleY, (int) (self::WIDTH / 2 + 70), $ruleY + 2, $gold);
        }

        $author = static::displayAuthor((string) $author);
        if ($author !== '') {
            $authorFont = static::font('sans');
            $size = self::AUTHOR_SIZE;
            $authorTracking = self::AUTHOR_TRACKING * $size;
            while ($size > 18 && $this->textWidth($authorFont, $size, $author, self::AUTHOR_TRACKING * $size) > self::WIDTH - 2 * self::MARGIN) {
                $size -= 2;
                $authorTracking = self::AUTHOR_TRACKING * $size;
            }
            $width = $this->textWidth($authorFont, $size, $author, $authorTracking);
            $soft = imagecolorallocate($canvas, 232, 228, 220);
            $this->shadowed($canvas, $authorFont, $size, (int) round((self::WIDTH - $width) / 2), self::HEIGHT - 84, $author, $authorTracking, $soft);
        }

        ob_start();
        if ($format === 'png') {
            imagepng($canvas, null, 6);
        } else {
            imageinterlace($canvas, true);
            imagejpeg($canvas, null, 90);
        }
        imagedestroy($canvas);

        return (string) ob_get_clean();
    }

    /**
     * The title as set: one line of text, at most MAX_TITLE_CHARS (cut at a
     * word), capitals when the style asks; '' when its script is not in the
     * faces (CJK and the like).
     */
    public static function displayTitle(string $title, bool $upper = false): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if ($title === '' || preg_match('/[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}\p{Thai}\p{Arabic}\p{Hebrew}\p{Devanagari}]/u', $title)) {
            return '';
        }
        if (mb_strlen($title) > self::MAX_TITLE_CHARS) {
            $cut = mb_substr($title, 0, self::MAX_TITLE_CHARS);
            $space = mb_strrpos($cut, ' ');
            $title = rtrim($space !== false ? mb_substr($cut, 0, $space) : $cut, ' ,;:-') . '…';
        }

        return $upper ? mb_strtoupper($title) : $title;
    }

    /** The author line: capitals, the first few names only. */
    public static function displayAuthor(string $author): string
    {
        $author = trim(preg_replace('/\s+/u', ' ', $author) ?? $author);
        if ($author === '' || in_array(strtolower($author), ['unknown', 'unknown author', 'anonymous'], true)
            || preg_match('/[\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}]/u', $author)) {
            return '';
        }
        if (mb_strlen($author) > 48) {
            $author = rtrim(mb_substr($author, 0, 46), ' ,;&') . '…';
        }

        return mb_strtoupper($author);
    }

    /**
     * The largest size (points) the title fits at: ≤ MAX_LINES lines in the
     * margins from 'max' down to 'min', then ≤ LONG_LINES at the smallest
     * sizes; past that the last line ends in an ellipsis (at a word).
     *
     * @return array{lines: list<string>, size: int}
     */
    public function fitTitle(string $title, array $spec): array
    {
        $font = static::font($spec['font']);
        $maxWidth = self::WIDTH - 2 * self::MARGIN;
        $min = (int) $spec['min'];
        foreach ([[(int) $spec['max'], $min, self::MAX_LINES], [$min + 8, $min - 8, self::LONG_LINES]] as [$from, $to, $maxLines]) {
            for ($size = $from; $size >= $to; $size -= 4) {
                $lines = $this->wrap($title, $font, $size, $maxWidth, $spec['tracking'] * $size);
                if ($lines !== null && count($lines) <= $maxLines) {
                    return ['lines' => $lines, 'size' => $size];
                }
            }
        }
        // Still too long: the first lines, the last one cut at a word.
        $size = $min - 8;
        $tracking = $spec['tracking'] * $size;
        $lines = $this->wrap($title, $font, $size, $maxWidth, $tracking, true) ?? [$title];
        if (count($lines) > self::LONG_LINES) {
            $lines = array_slice($lines, 0, self::LONG_LINES);
            $words = preg_split('/\s+/u', $lines[self::LONG_LINES - 1], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            while (count($words) > 1 && $this->textWidth($font, $size, implode(' ', $words) . '…', $tracking) > $maxWidth) {
                array_pop($words);
            }
            $lines[self::LONG_LINES - 1] = rtrim(implode(' ', $words), ' ,;:-…') . '…';
        }

        return ['lines' => $lines, 'size' => $size];
    }

    /**
     * Greedy word wrap; null when a single word is wider than $maxWidth
     * (unless $force: then it gets a line of its own anyway).
     *
     * @return ?list<string>
     */
    private function wrap(string $text, string $font, int $size, int $maxWidth, float $tracking, bool $force = false): ?array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (!$force && $this->textWidth($font, $size, $word, $tracking) > $maxWidth) {
                return null;
            }
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $this->textWidth($font, $size, $candidate, $tracking) > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    private function textWidth(string $font, int $size, string $text, float $tracking = 0.0): int
    {
        if ($tracking <= 0.0) {
            $box = imagettfbbox($size, 0, $font, $text);

            return $box === false ? 0 : (int) abs($box[2] - $box[0]);
        }
        $width = 0.0;
        $chars = mb_str_split($text);
        foreach ($chars as $char) {
            $width += $this->advance($font, $size, $char);
        }

        return (int) round($width + $tracking * max(0, count($chars) - 1));
    }

    private function advance(string $font, int $size, string $char): float
    {
        if ($char === ' ') {
            $box = imagettfbbox($size, 0, $font, 'n n');
            $n = imagettfbbox($size, 0, $font, 'nn');

            return $box === false || $n === false ? $size * 0.3 : (float) abs(($box[2] - $box[0]) - ($n[2] - $n[0]));
        }
        $box = imagettfbbox($size, 0, $font, $char);

        return $box === false ? 0.0 : (float) abs($box[2] - $box[0]);
    }

    /** Text with a soft drop shadow (a few offset, translucent copies), then the ink. */
    private function shadowed(\GdImage $canvas, string $font, int $size, int $x, int $y, string $text, float $tracking, int $ink): void
    {
        $shadow = imagecolorallocatealpha($canvas, 0, 0, 0, 96);
        foreach ([[0, 3], [2, 4], [-2, 4], [0, 6]] as [$dx, $dy]) {
            $this->draw($canvas, $font, $size, $x + $dx, $y + $dy, $text, $tracking, $shadow);
        }
        $this->draw($canvas, $font, $size, $x, $y, $text, $tracking, $ink);
    }

    private function draw(\GdImage $canvas, string $font, int $size, int $x, int $y, string $text, float $tracking, int $colour): void
    {
        if ($tracking <= 0.0) {
            imagettftext($canvas, $size, 0, $x, $y, $colour, $font, $text);

            return;
        }
        $cursor = (float) $x;
        foreach (mb_str_split($text) as $char) {
            if ($char !== ' ') {
                imagettftext($canvas, $size, 0, (int) round($cursor), $y, $colour, $font, $char);
            }
            $cursor += $this->advance($font, $size, $char) + $tracking;
        }
    }

    /**
     * A vertical gradient from $opacity (at the edge) to clear: $fromTop
     * fades downwards from $y0, else upwards from $y1.
     *
     * @param  array{0: int, 1: int, 2: int}  $tint
     */
    private function gradient(\GdImage $canvas, int $y0, int $y1, array $tint, float $opacity, bool $fromTop): void
    {
        $height = max(1, $y1 - $y0);
        for ($i = 0; $i < $height; $i++) {
            $t = $fromTop ? $i / $height : 1 - $i / $height;
            $alpha = $opacity * (1 - $t) ** 1.3;
            if ($alpha <= 0.005) {
                continue;
            }
            $colour = imagecolorallocatealpha($canvas, $tint[0], $tint[1], $tint[2], (int) round(127 * (1 - $alpha)));
            imagefilledrectangle($canvas, 0, $y0 + $i, self::WIDTH - 1, $y0 + $i, $colour);
        }
    }

    /**
     * A very dark tone of the artwork (its average colour at a tenth of its
     * brightness), so the gradients look part of the picture.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function shadowTone(\GdImage $canvas): array
    {
        $pixel = imagecreatetruecolor(1, 1);
        imagecopyresampled($pixel, $canvas, 0, 0, 0, 0, 1, 1, self::WIDTH, self::HEIGHT);
        $rgb = imagecolorat($pixel, 0, 0);
        imagedestroy($pixel);

        return [(int) ((($rgb >> 16) & 0xFF) * 0.12), (int) ((($rgb >> 8) & 0xFF) * 0.12), (int) (($rgb & 0xFF) * 0.12)];
    }
}
