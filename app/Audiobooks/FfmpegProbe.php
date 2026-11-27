<?php

namespace App\Audiobooks;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * App\Audiobooks\AudioProbe through the real ffprobe / ffmpeg
 * (config novarr.audiobooks.ffprobe / .ffmpeg, on PATH by default).
 *
 * Commands are argument arrays run without a shell (Symfony Process), so a
 * file name is never interpreted: "Book; rm -rf ~.mp3" is just a name.
 * Each call is bounded by novarr.audiobooks.probe_timeout seconds.
 */
class FfmpegProbe implements AudioProbe
{
    public function problem(): ?string
    {
        if ($this->binary('ffprobe') === null) {
            return 'ffprobe was not found (' . config('novarr.audiobooks.ffprobe') . '): install ffmpeg on the server, or set NOVARR_FFPROBE to its path.';
        }

        return null;
    }

    public function probe(string $path): array
    {
        $ffprobe = $this->binary('ffprobe');
        if ($ffprobe === null) {
            throw new ProbeFailed((string) $this->problem());
        }

        $process = new Process([
            $ffprobe, '-v', 'error', '-print_format', 'json',
            '-show_format', '-show_streams', '-show_chapters',
            $path,
        ]);
        $process->setTimeout($this->timeout());

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new ProbeFailed('ffprobe failed (' . $e->getMessage() . ')');
        }

        if (!$process->isSuccessful()) {
            $why = trim(strtok(trim($process->getErrorOutput()), "\n") ?: '');
            // ffprobe's lines start with the path; the reason is enough.
            $why = trim(str_replace($path, '', $why), " :");
            // …and the demuxer's "[mp3 @ 0x7af3018000] " prefix is noise.
            $why = trim(preg_replace('/^\[[^\]]*@\s*0x[0-9a-f]+\]\s*/i', '', $why) ?? $why);

            throw new ProbeFailed($why !== '' ? $why : 'ffprobe could not make sense of it (it may be damaged, or not audio)');
        }

        $data = json_decode($process->getOutput(), true);
        if (!is_array($data)) {
            throw new ProbeFailed('ffprobe gave no answer for it');
        }

        return static::parse($data);
    }

    /**
     * ffprobe's JSON (-show_format -show_streams -show_chapters) as
     * AudioProbe::probe()'s answer. Public and static so it is tested on
     * recorded output without the tool.
     */
    public static function parse(array $data): array
    {
        $streams = is_array($data['streams'] ?? null) ? $data['streams'] : [];
        $audio = null;
        $hasCover = false;
        foreach ($streams as $stream) {
            $type = $stream['codec_type'] ?? null;
            if ($type === 'audio' && $audio === null) {
                $audio = $stream;
            }
            if ($type === 'video' && (int) ($stream['disposition']['attached_pic'] ?? 0) === 1) {
                $hasCover = true;
            }
        }
        if ($audio === null) {
            throw new ProbeFailed('there is no audio in it');
        }

        $format = is_array($data['format'] ?? null) ? $data['format'] : [];
        $seconds = (float) ($format['duration'] ?? $audio['duration'] ?? 0);
        if ($seconds <= 0) {
            throw new ProbeFailed('its length could not be read (it may be damaged or incomplete)');
        }

        // Ogg / Opus keep their tags on the stream, the others on the container.
        $tags = [];
        foreach ([$audio['tags'] ?? [], $format['tags'] ?? []] as $set) {
            foreach ((array) $set as $key => $value) {
                $value = is_scalar($value) ? trim((string) $value) : '';
                if ($value !== '') {
                    $tags[strtolower((string) $key)] = $value;
                }
            }
        }

        $chapters = [];
        foreach ((array) ($data['chapters'] ?? []) as $chapter) {
            $start = (int) round(((float) ($chapter['start_time'] ?? 0)) * 1000);
            $end = (int) round(((float) ($chapter['end_time'] ?? 0)) * 1000);
            if ($end <= $start) {
                continue;
            }
            $title = trim((string) ($chapter['tags']['title'] ?? $chapter['tags']['TITLE'] ?? ''));
            $chapters[] = ['title' => $title !== '' ? $title : null, 'start_ms' => $start, 'end_ms' => $end];
        }
        usort($chapters, fn($a, $b) => $a['start_ms'] <=> $b['start_ms']);

        $bitrate = (int) ($format['bit_rate'] ?? $audio['bit_rate'] ?? 0);

        return [
            'duration_ms' => (int) round($seconds * 1000),
            'bitrate' => $bitrate > 0 ? $bitrate : null,
            'tags' => $tags,
            'chapters' => $chapters,
            'has_cover' => $hasCover,
        ];
    }

    public function extractCover(string $path, string $destination): bool
    {
        $ffmpeg = $this->binary('ffmpeg');
        if ($ffmpeg === null) {
            return false;
        }

        // The first picture stream, re-encoded as a JPEG whatever it was.
        $process = new Process([
            $ffmpeg, '-v', 'error', '-nostdin', '-y', '-i', $path,
            '-map', '0:v:0', '-frames:v', '1', '-c:v', 'mjpeg', '-q:v', '2', '-f', 'image2',
            $destination,
        ]);
        $process->setTimeout($this->timeout());

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful() && is_file($destination) && filesize($destination) > 0;
    }

    /** The tool's path when it can be run, else null. */
    private function binary(string $tool): ?string
    {
        $configured = trim((string) config("novarr.audiobooks.{$tool}", $tool)) ?: $tool;
        if (str_contains($configured, '/')) {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        // PHP-FPM and cron can run with a short PATH: the usual places too.
        return (new ExecutableFinder())->find($configured, null, ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin']);
    }

    private function timeout(): int
    {
        return max(5, (int) config('novarr.audiobooks.probe_timeout', 120));
    }
}
