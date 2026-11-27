package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import studio.visns.novarr.api.AudiobookChapter
import studio.visns.novarr.api.AudiobookPart
import studio.visns.novarr.domain.AudiobookTimeline
import studio.visns.novarr.domain.AudiobookTimeline.PartPosition

/** Whole-book position <-> (part, offset), chapters and skips across parts. */
class AudiobookTimelineTest {
    private fun part(index: Int, start: Long, duration: Long) = AudiobookPart(index, "https://h/p/$index", durationMs = duration, startMs = start)

    // Three files: 0-10 min, 10-25 min, 25-30 min.
    private val parts = listOf(part(0, 0, 600_000), part(1, 600_000, 900_000), part(2, 1_500_000, 300_000))
    private val chapters = listOf(
        AudiobookChapter(0, "Opening", 0, 400_000),
        AudiobookChapter(1, "Two", 400_000, 1_000_000),
        AudiobookChapter(2, "Three", 1_000_000, 1_800_000),
    )
    private val timeline = AudiobookTimeline.from(parts, chapters)

    @Test
    fun totalIsTheEndOfTheLastPart() {
        assertEquals(1_800_000, timeline.totalMs)
    }

    @Test
    fun bookPositionsMapToTheirPart() {
        assertEquals(PartPosition(0, 0), timeline.toPart(0))
        assertEquals(PartPosition(0, 599_999), timeline.toPart(599_999))
        // A part's exact end is the next part's start.
        assertEquals(PartPosition(1, 0), timeline.toPart(600_000))
        assertEquals(PartPosition(1, 400_000), timeline.toPart(1_000_000))
        assertEquals(PartPosition(2, 100_000), timeline.toPart(1_600_000))
    }

    @Test
    fun positionsOutsideTheBookAreClamped() {
        assertEquals(PartPosition(0, 0), timeline.toPart(-5_000))
        assertEquals(PartPosition(2, 300_000), timeline.toPart(1_800_000))
        assertEquals(PartPosition(2, 300_000), timeline.toPart(9_999_999))
    }

    @Test
    fun partOffsetsMapBackToTheBook() {
        assertEquals(0L, timeline.toBook(0, 0))
        assertEquals(1_000_000L, timeline.toBook(1, 400_000))
        assertEquals(1_600_000L, timeline.toBook(2, 100_000))
        // An offset past a part's length is clamped to it.
        assertEquals(1_500_000L, timeline.toBook(1, 2_000_000))
        // An unknown part: the start or the end of the book.
        assertEquals(0L, timeline.toBook(-1, 10))
        assertEquals(1_800_000L, timeline.toBook(7, 10))
    }

    @Test
    fun roundTripsForEveryPosition() {
        for (position in 0L..1_800_000L step 7_919) {
            val part = timeline.toPart(position)
            assertEquals(position, timeline.toBook(part.partIndex, part.offsetMs))
        }
    }

    @Test
    fun inconsistentStartsFallBackToDurationsLaidEndToEnd() {
        // An older or buggy payload: every start_ms 0.
        val laid = AudiobookTimeline.from(listOf(part(0, 0, 1_000), part(1, 0, 2_000), part(2, 0, 500)), emptyList())
        assertEquals(listOf(0L, 1_000L, 3_000L), laid.parts.map { it.startMs })
        assertEquals(3_500L, laid.totalMs)
        assertEquals(PartPosition(1, 500), laid.toPart(1_500))
        // Out of order in the payload: sorted by index first.
        val shuffled = AudiobookTimeline.from(listOf(part(1, 1_000, 2_000), part(0, 0, 1_000)), emptyList())
        assertEquals(listOf(0L, 1_000L), shuffled.parts.map { it.startMs })
    }

    @Test
    fun chapterLookup() {
        assertEquals(0, timeline.chapterIndexAt(0))
        assertEquals(0, timeline.chapterIndexAt(399_999))
        assertEquals(1, timeline.chapterIndexAt(400_000))
        assertEquals(2, timeline.chapterIndexAt(1_799_000))
        assertEquals("Three", timeline.chapterAt(1_200_000)?.title)
        assertEquals(1_000_000L, timeline.chapterEndAt(500_000))
        assertEquals(100_000L to 600_000L, timeline.chapterProgress(500_000))
    }

    @Test
    fun previousChapterRestartsTheCurrentOneUnlessAtItsStart() {
        // 10 s into chapter 2: back to its start.
        assertEquals(400_000L, timeline.previousChapterTarget(410_000))
        // 2 s into chapter 2: the chapter before.
        assertEquals(0L, timeline.previousChapterTarget(402_000))
        // In the first chapter: its start.
        assertEquals(0L, timeline.previousChapterTarget(1_000))
    }

    @Test
    fun nextChapter() {
        assertEquals(400_000L, timeline.nextChapterTarget(10))
        assertEquals(1_000_000L, timeline.nextChapterTarget(400_000))
        assertNull(timeline.nextChapterTarget(1_200_000))
    }

    @Test
    fun skipsCrossPartsAndStayInTheBook() {
        assertEquals(570_000L, timeline.skip(600_000 + 0, -30_000))
        assertEquals(PartPosition(0, 570_000), timeline.toPart(timeline.skip(600_000, -30_000)))
        assertEquals(0L, timeline.skip(10_000, -30_000))
        assertEquals(1_800_000L, timeline.skip(1_790_000, 30_000))
    }

    @Test
    fun withoutChaptersEachPartIsOne() {
        val plain = AudiobookTimeline.from(parts, emptyList())
        assertEquals(listOf("Part 1", "Part 2", "Part 3"), plain.chapters.map { it.title })
        assertEquals(listOf(0L, 600_000L, 1_500_000L), plain.chapters.map { it.startMs })
        assertEquals(1, plain.chapterIndexAt(700_000))
    }

    @Test
    fun badChapterEndsBecomeTheNextStart() {
        val messy = AudiobookTimeline.from(
            parts,
            listOf(
                AudiobookChapter(5, "B", 600_000, 0),
                AudiobookChapter(4, "A", 0, 900_000),
                // Past the end of the book: dropped.
                AudiobookChapter(6, "Ghost", 5_000_000, 6_000_000),
            ),
        )
        assertEquals(listOf("A", "B"), messy.chapters.map { it.title })
        assertEquals(listOf(0, 1), messy.chapters.map { it.index })
        assertEquals(listOf(600_000L, 1_800_000L), messy.chapters.map { it.endMs })
    }

    @Test
    fun anEmptyBookIsSafe() {
        val empty = AudiobookTimeline.from(emptyList(), emptyList())
        assertEquals(0L, empty.totalMs)
        assertEquals(PartPosition(0, 0), empty.toPart(1_000))
        assertEquals(-1, empty.chapterIndexAt(0))
        assertNull(empty.nextChapterTarget(0))
        assertEquals(0L, empty.previousChapterTarget(0))
    }

    @Test
    fun aSingleFileBook() {
        val single = AudiobookTimeline.from(listOf(part(0, 0, 3_600_000)), listOf(AudiobookChapter(0, "Only", 0, 3_600_000)))
        assertEquals(PartPosition(0, 1_800_000), single.toPart(1_800_000))
        assertEquals(3_600_000L, single.totalMs)
    }
}
