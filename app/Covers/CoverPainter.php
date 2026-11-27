<?php

namespace App\Covers;

use App\Books\BookCovers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Paints the artwork of an AI cover: a brief (CoverBrief) becomes a Stable
 * Diffusion XL prompt, sent to the cover painter service
 * (novarr.covers.ai.painter_url, on the homelab GPU):
 *
 *   POST /generate  {prompt, negative_prompt, width, height, steps, guidance, seed}
 *                   → image/png, the artwork only (the title is set later,
 *                   CoverComposer: diffusion models cannot spell)
 *   GET  /health    → {ok, model, busy}
 *
 * The painter paints one image at a time and answers 503 while busy (the
 * GPU is shared with Frigate): paint() asks again busy_retries times,
 * busy_wait seconds apart and doubling (at most MAX_WAIT), then gives up
 * with PainterBusy. The seed is the novel's own (seed()), so painting the
 * same brief again gives the same picture; "Regenerate" picks a new one.
 */
class CoverPainter
{
    public const NEGATIVE_PROMPT = 'text, letters, words, title, typography, caption, watermark, signature, logo, '
        . 'frame, border, collage, split image, blurry, lowres, jpeg artifacts, deformed, disfigured, '
        . 'bad anatomy, bad hands, extra fingers, extra limbs, cropped head, nsfw, nude';

    /** Longest prompt sent (SDXL's CLIP reads about 77 tokens; the start matters most). */
    public const MAX_PROMPT = 600;

    /** Longest wait between two tries of a busy painter (seconds). */
    public const MAX_WAIT = 120;

    /** Smallest artwork accepted (pixels a side). */
    public const MIN_SIDE = 256;

    public static function enabled(): bool
    {
        return static::url() !== '';
    }

    public static function url(): string
    {
        return rtrim(trim((string) config('novarr.covers.ai.painter_url')), '/');
    }

    /**
     * The SDXL prompt: "book cover illustration, {style}, {key_scene},
     * {subject}, {setting}, {era}, {mood} mood, {palette} palette, dramatic
     * lighting, highly detailed, no text"; empty parts left out.
     */
    public static function prompt(array $brief): string
    {
        $palette = array_values(array_filter((array) ($brief['palette'] ?? []), fn($c) => is_string($c) && trim($c) !== ''));
        $mood = trim((string) ($brief['mood'] ?? ''));
        $era = trim((string) ($brief['era'] ?? ''));
        $parts = [
            'book cover illustration',
            trim((string) ($brief['style'] ?? '')) ?: 'painterly digital art',
            trim((string) ($brief['key_scene'] ?? '')),
            trim((string) ($brief['subject'] ?? '')),
            trim((string) ($brief['setting'] ?? '')),
            $era !== '' && !in_array(strtolower($era), ['timeless', 'unknown'], true) ? $era . ' era' : '',
            $mood !== '' ? $mood . ' mood' : '',
            $palette !== [] ? static::listWords($palette) . ' palette' : '',
            'dramatic lighting',
            'highly detailed',
            'no text',
        ];
        $prompt = implode(', ', array_filter($parts, fn($p) => $p !== ''));
        $prompt = preg_replace('/\s+/u', ' ', $prompt) ?? $prompt;

        // Too long: the middle parts go first (style and scene lead; "no text" ends).
        if (mb_strlen($prompt) > self::MAX_PROMPT) {
            $keep = array_values(array_filter($parts, fn($p) => $p !== ''));
            while (count($keep) > 6 && mb_strlen(implode(', ', $keep)) > self::MAX_PROMPT) {
                array_splice($keep, count($keep) - 4, 1);
            }
            $prompt = mb_substr(implode(', ', $keep), 0, self::MAX_PROMPT);
        }

        return $prompt;
    }

    /** "a", "a and b", "a, b and c". */
    private static function listWords(array $words): string
    {
        $last = array_pop($words);

        return $words === [] ? (string) $last : implode(', ', $words) . ' and ' . $last;
    }

    /** A novel's own seed (the same each time); $variant > 0 gives another. */
    public static function seed(int $novelId, int $variant = 0): int
    {
        return (int) (crc32('novarr-cover:' . $novelId . ($variant > 0 ? ':' . $variant : '')) & 0x7FFFFFFF);
    }

    /** {ok, model, busy} from the painter, or null when it does not answer. */
    public function health(): ?array
    {
        if (!static::enabled()) {
            return null;
        }
        try {
            $response = Http::timeout(10)->connectTimeout(5)->acceptJson()->get(static::url() . '/health');
        } catch (\Throwable) {
            return null;
        }
        if (!$response->successful() && $response->status() !== 503) {
            return null;
        }
        $data = $response->json();

        return is_array($data) ? $data + ['ok' => $response->successful(), 'busy' => $response->status() === 503] : null;
    }

    /**
     * Paint the artwork: PNG bytes, checked (decodes, at least MIN_SIDE a
     * side, not one flat colour).
     *
     * @throws PainterBusy        still busy after every retry
     * @throws \RuntimeException  any other failure
     */
    public function paint(string $prompt, int $seed, ?string $negative = null): string
    {
        if (!static::enabled()) {
            throw new \RuntimeException('no cover painter is set up (NOVARR_COVERS_AI_PAINTER_URL)');
        }
        $payload = [
            'prompt' => $prompt,
            'negative_prompt' => $negative ?? self::NEGATIVE_PROMPT,
            'width' => (int) config('novarr.covers.ai.width', 832),
            'height' => (int) config('novarr.covers.ai.height', 1216),
            'steps' => (int) config('novarr.covers.ai.steps', 30),
            'guidance' => (float) config('novarr.covers.ai.guidance', 6.5),
            'seed' => $seed,
        ];
        $retries = max(0, (int) config('novarr.covers.ai.busy_retries', 6));
        $wait = max(1, (int) config('novarr.covers.ai.busy_wait', 20));

        for ($try = 0; ; $try++) {
            try {
                $response = Http::timeout(max(30, (int) config('novarr.covers.ai.paint_timeout', 180)))
                    ->connectTimeout(10)
                    ->accept('image/png')
                    ->post(static::url() . '/generate', $payload);
            } catch (\Throwable $e) {
                throw new \RuntimeException('the cover painter did not answer: ' . $e->getMessage(), 0, $e);
            }
            if ($response->status() !== 503) {
                break;
            }
            if ($try >= $retries) {
                throw new PainterBusy('the cover painter stayed busy (GPU in use)');
            }
            Sleep::for(min(self::MAX_WAIT, $wait * (2 ** $try)))->seconds();
        }
        if (!$response->successful()) {
            throw new \RuntimeException('the cover painter answered HTTP ' . $response->status() . ': ' . mb_substr(trim(strip_tags($response->body())), 0, 200));
        }

        return static::validate($response->body());
    }

    /**
     * The bytes when they are a usable PNG.
     *
     * @throws \RuntimeException
     */
    public static function validate(string $bytes): string
    {
        if (!str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            throw new \RuntimeException('the cover painter sent something that is not a PNG');
        }
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < self::MIN_SIDE || $size[1] < self::MIN_SIDE) {
            throw new \RuntimeException('the painting is unreadable or too small');
        }
        $stats = BookCovers::colourStats($bytes);
        if ($stats !== null && $stats['colours'] <= 2) {
            // A blank (e.g. all-black filtered) image.
            throw new \RuntimeException('the painting came back blank');
        }

        return $bytes;
    }
}
