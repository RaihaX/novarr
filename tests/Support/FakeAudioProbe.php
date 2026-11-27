<?php

namespace Tests\Support;

use App\Audiobooks\AudioProbe;
use App\Audiobooks\ProbeFailed;

/**
 * App\Audiobooks\AudioProbe for tests: no ffprobe / ffmpeg. A fake audio
 * file's bytes are its probe result as JSON — {"duration_ms": 60000,
 * "tags": {...}, "chapters": [...], "has_cover": true} (see audioFile()) —
 * and anything that is not such JSON is "unreadable". Bound for every test
 * by Tests\TestCase.
 */
class FakeAudioProbe implements AudioProbe
{
    /** What problem() says (null: the tools are there). */
    public ?string $problem = null;

    /** @var list<string> paths probed, in order */
    public array $probed = [];

    public function problem(): ?string
    {
        return $this->problem;
    }

    public function probe(string $path): array
    {
        $this->probed[] = $path;
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !isset($data['duration_ms'])) {
            throw new ProbeFailed('Invalid data found when processing input');
        }

        return [
            'duration_ms' => (int) $data['duration_ms'],
            'bitrate' => $data['bitrate'] ?? null,
            'tags' => array_change_key_case((array) ($data['tags'] ?? []), CASE_LOWER),
            'chapters' => array_values((array) ($data['chapters'] ?? [])),
            'has_cover' => (bool) ($data['has_cover'] ?? false),
        ];
    }

    public function extractCover(string $path, string $destination): bool
    {
        return file_put_contents($destination, static::jpeg()) > 0;
    }

    /** A real (tiny) JPEG, so cover thumbnails can be made from it. */
    public static function jpeg(int $width = 60, int $height = 90): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 90, 160));
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    /**
     * Bytes of a fake audio file: its probe result. $salt makes otherwise
     * equal files differ (the fingerprint reads the bytes).
     */
    public static function audioFile(int $durationMs, array $tags = [], array $chapters = [], bool $cover = false, string $salt = ''): string
    {
        return json_encode([
            'duration_ms' => $durationMs,
            'tags' => $tags,
            'chapters' => $chapters,
            'has_cover' => $cover,
            'salt' => $salt,
        ]);
    }
}
