package studio.visns.novarr.domain

import studio.visns.novarr.api.AudiobookChapter
import studio.visns.novarr.api.AudiobookDetail
import studio.visns.novarr.api.AudiobookPart

/**
 * An audiobook's whole-book timeline: parts (files, played back to back as
 * one playlist) and chapters (positions in the whole book, across parts).
 * Everything the player shows or saves is a whole-book position; the
 * player itself only knows (part index, offset in that part). Pure Kotlin.
 */
class AudiobookTimeline(parts: List<Span>, chapters: List<AudiobookChapter>) {
    /** One part: where it starts in the whole book and how long it is. */
    data class Span(val index: Int, val startMs: Long, val durationMs: Long) {
        val endMs: Long get() = startMs + durationMs
    }

    /** A position in one part. */
    data class PartPosition(val partIndex: Int, val offsetMs: Long)

    val parts: List<Span> = parts
    val totalMs: Long = parts.lastOrNull()?.endMs ?: 0L

    /** The server's chapters (sorted, clamped to the book), or one per part when it sent none. */
    val chapters: List<AudiobookChapter> = normaliseChapters(chapters, this.parts, totalMs)

    /** Whole-book position → part and offset. Clamped to the book; the very end is the last part's end. */
    fun toPart(positionMs: Long): PartPosition {
        if (parts.isEmpty()) return PartPosition(0, 0)
        val position = positionMs.coerceIn(0, totalMs)
        // The last part that starts at or before the position (zero-length parts are skipped over).
        var found = 0
        for ((i, span) in parts.withIndex()) {
            if (span.startMs <= position && (span.durationMs > 0 || i == parts.lastIndex)) found = i else if (span.startMs > position) break
        }
        val span = parts[found]
        // At a part's exact end, prefer the next part's start (seamless playlist).
        return PartPosition(found, (position - span.startMs).coerceIn(0, span.durationMs.coerceAtLeast(0)))
    }

    /** Part and offset → whole-book position, clamped to the part and the book. */
    fun toBook(partIndex: Int, offsetMs: Long): Long {
        val span = parts.getOrNull(partIndex) ?: return if (partIndex < 0) 0 else totalMs
        val offset = if (span.durationMs > 0) offsetMs.coerceIn(0, span.durationMs) else offsetMs.coerceAtLeast(0)
        return span.startMs + offset
    }

    /** The chapter playing at [positionMs] (the last one starting at or before it); -1 when there are none. */
    fun chapterIndexAt(positionMs: Long): Int {
        if (chapters.isEmpty()) return -1
        var found = 0
        for ((i, chapter) in chapters.withIndex()) {
            if (chapter.startMs <= positionMs) found = i else break
        }
        return found
    }

    fun chapterAt(positionMs: Long): AudiobookChapter? = chapters.getOrNull(chapterIndexAt(positionMs))

    /**
     * Where "previous chapter" goes: the current chapter's start when more than
     * [restartWindowMs] into it, else the previous chapter's start.
     */
    fun previousChapterTarget(positionMs: Long, restartWindowMs: Long = RESTART_WINDOW_MS): Long {
        val i = chapterIndexAt(positionMs)
        if (i < 0) return 0
        val current = chapters[i]
        return if (positionMs - current.startMs > restartWindowMs || i == 0) current.startMs else chapters[i - 1].startMs
    }

    /** Where "next chapter" goes: the next chapter's start; null in the last chapter. */
    fun nextChapterTarget(positionMs: Long): Long? {
        val i = chapterIndexAt(positionMs)
        return chapters.getOrNull(i + 1)?.startMs
    }

    /** The chapter's end at [positionMs] (the end of chapter sleep timer); the book's end without chapters. */
    fun chapterEndAt(positionMs: Long): Long = chapterAt(positionMs)?.endMs ?: totalMs

    /** Elapsed and length of the chapter at [positionMs], for the chapter scrubber. */
    fun chapterProgress(positionMs: Long): Pair<Long, Long> {
        val chapter = chapterAt(positionMs) ?: return positionMs.coerceIn(0, totalMs) to totalMs
        val length = (chapter.endMs - chapter.startMs).coerceAtLeast(0)
        return (positionMs - chapter.startMs).coerceIn(0, length) to length
    }

    /** A skip of [deltaMs] (negative: back) from [positionMs], kept inside the book. */
    fun skip(positionMs: Long, deltaMs: Long): Long = (positionMs + deltaMs).coerceIn(0, totalMs)

    companion object {
        /** Within this much of a chapter's start, "previous" goes to the chapter before. */
        const val RESTART_WINDOW_MS = 3_000L

        fun of(detail: AudiobookDetail): AudiobookTimeline = from(detail.parts, detail.chapters)

        /**
         * Builds the parts' spans. The server's start_ms is used when it is
         * consistent (first at 0, never going back); otherwise the parts are
         * laid end to end by their durations.
         */
        fun from(parts: List<AudiobookPart>, chapters: List<AudiobookChapter>): AudiobookTimeline {
            val sorted = parts.sortedBy { it.index }
            val starts = sorted.map { it.startMs }
            val consistent = sorted.isNotEmpty() && starts.first() == 0L &&
                starts.zipWithNext().all { (a, b) -> b >= a } &&
                (sorted.size == 1 || starts.last() > 0) &&
                sorted.zipWithNext().all { (a, b) -> a.startMs + a.durationMs <= b.startMs + TOLERANCE_MS }
            var cursor = 0L
            val spans = sorted.mapIndexed { i, part ->
                val start = if (consistent) part.startMs else cursor
                val duration = part.durationMs.coerceAtLeast(0)
                cursor = start + duration
                Span(i, start, if (consistent && i < sorted.lastIndex) (sorted[i + 1].startMs - start).coerceAtLeast(0) else duration)
            }
            return AudiobookTimeline(spans, chapters)
        }

        /** A few ms of rounding between a part's start and the one before's end is not an inconsistency. */
        private const val TOLERANCE_MS = 1_000L

        private fun normaliseChapters(chapters: List<AudiobookChapter>, parts: List<Span>, total: Long): List<AudiobookChapter> {
            val usable = chapters.filter { it.startMs >= 0 && (total == 0L || it.startMs < total) }.sortedBy { it.startMs }
            if (usable.isEmpty()) {
                return parts.mapIndexed { i, span -> AudiobookChapter(i, "Part ${i + 1}", span.startMs, span.endMs) }
            }
            // A missing or bad end becomes the next chapter's start (or the book's end).
            return usable.mapIndexed { i, chapter ->
                val nextStart = usable.getOrNull(i + 1)?.startMs ?: total
                val end = if (chapter.endMs > chapter.startMs && chapter.endMs <= maxOf(nextStart, chapter.startMs)) chapter.endMs else nextStart
                chapter.copy(index = i, endMs = end)
            }
        }
    }
}
