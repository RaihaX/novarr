<?php

namespace App\Scraping;

/**
 * A TOC label parsed into its structured parts (see ChapterLabelParser).
 *
 * `number` is the chapter's own number (decimals kept: 374.5), `part` the
 * split index of a multi-part chapter (12 (2) → number 12, part 2) and
 * `sortKey` the ordering key: number + part/1000, so
 * 12 < 12.001 < 12.002 < 12.5 < 13. Null number / sortKey = unknown (the
 * resolver assigns one later).
 */
final readonly class ChapterLabel
{
    public const KIND_CHAPTER = 'chapter';
    public const KIND_UNKNOWN = 'unknown';

    /** Every kind the parser can emit. */
    public const KINDS = [
        'chapter', 'prologue', 'epilogue', 'interlude', 'side',
        'extra', 'notice', 'afterword', 'unknown',
    ];

    public function __construct(
        public int $volume,
        public ?float $number,
        public int $part,
        public string $kind,
        public ?string $title,
        public string $sourceLabel,
        public ?float $sortKey,
    ) {
    }

    /**
     * The value the legacy `chapter` column carries for this label: the
     * number with the part packed into its fraction by encodeChapterPart()
     * (12 part 2 → 12.2, part 10 → 12.91). 0 when the number is unknown.
     */
    public function legacyChapter(): float
    {
        if ($this->number === null) {
            return 0.0;
        }
        if ($this->part <= 0) {
            return $this->number;
        }

        return (float) encodeChapterPart((string) (int) floor($this->number), $this->part);
    }

    /** Anything but a plain numbered chapter. */
    public function isSpecial(): bool
    {
        return $this->kind !== self::KIND_CHAPTER;
    }
}
