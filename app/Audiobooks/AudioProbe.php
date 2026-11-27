<?php

namespace App\Audiobooks;

/**
 * Reads an audio file's facts and pulls out its embedded cover — the one
 * seam to ffprobe / ffmpeg (App\Audiobooks\FfmpegProbe), so tests bind a
 * fake and never need the real tools.
 *
 * probe() answers:
 *   duration_ms  int > 0
 *   bitrate      bits per second, or null
 *   tags         lower-cased format tags (title, album, artist,
 *                album_artist, composer, narrator, date, genre, comment,
 *                description …), trimmed, empty ones left out
 *   chapters     [{title: ?string, start_ms: int, end_ms: int}] in order
 *                (embedded chapters, m4b / m4a mostly; [] when none)
 *   has_cover    an embedded picture (attached_pic video stream)
 */
interface AudioProbe
{
    /**
     * Null when the tools can be run, else what is wrong in plain words
     * (the scan then stops and leaves the inbox alone: a missing ffprobe
     * must never send every audiobook to _failed).
     */
    public function problem(): ?string;

    /**
     * @return array{duration_ms: int, bitrate: ?int, tags: array<string, string>, chapters: list<array{title: ?string, start_ms: int, end_ms: int}>, has_cover: bool}
     *
     * @throws ProbeFailed when the file is not audio the tools can read
     */
    public function probe(string $path): array;

    /** Write the file's embedded picture to $destination as a JPEG; false when there is none or it fails. */
    public function extractCover(string $path, string $destination): bool;
}
