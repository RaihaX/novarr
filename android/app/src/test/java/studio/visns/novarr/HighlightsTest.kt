package studio.visns.novarr

import java.io.File
import java.nio.file.Files
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.DeviceIdentity
import studio.visns.novarr.domain.Highlights
import studio.visns.novarr.domain.Highlights.Span
import studio.visns.novarr.store.PendingWrite
import studio.visns.novarr.store.WriteKind
import studio.visns.novarr.store.WriteQueue
import studio.visns.novarr.store.hasUnsentChapterWrite
import studio.visns.novarr.store.unsentHighlights

class HighlightMatchTest {
    private val chapter = listOf(
        "The rain had not stopped for three days.",
        "  She opened the door,   and the wind came in.  ",
        "",
        "Nobody spoke.\tNobody moved.",
    )

    @Test
    fun withinOneParagraph() {
        assertEquals(listOf(Span(0, 4, 8)), Highlights.locate(chapter, "rain"))
        // Text and offsets in the original paragraph, not the normalised copy.
        val spans = Highlights.locate(chapter, "opened the door")!!
        assertEquals(listOf(Span(1, 6, 21)), spans)
        assertEquals("opened the door", chapter[1].substring(6, 21))
    }

    @Test
    fun spanningTwoParagraphs() {
        // As the website copies a selection: paragraphs separated by line breaks.
        val spans = Highlights.locate(chapter, "three days.\n\nShe opened")!!
        assertEquals(listOf(Span(0, 29, 40), Span(1, 2, 12)), spans)
        assertEquals("three days.", chapter[0].substring(29, 40))
        assertEquals("She opened", chapter[1].substring(2, 12))
        // The empty paragraph between is skipped like any other whitespace.
        assertEquals(
            listOf(Span(1, 33, 46), Span(3, 0, 13)),
            Highlights.locate(chapter, "wind came in. Nobody spoke."),
        )
    }

    @Test
    fun whitespaceDifferencesDontMatter() {
        assertEquals(listOf(Span(1, 25, 46)), Highlights.locate(chapter, "and the wind\ncame   in."))
        // Tabs and non-breaking spaces in the chapter, a plain space in the excerpt.
        assertEquals(listOf(Span(3, 14, 27)), Highlights.locate(chapter, "  Nobody moved. "))
        assertEquals("door, and", Highlights.normalize(" door,\n\t and "))
    }

    @Test
    fun notFoundOrEmpty() {
        assertNull(Highlights.locate(chapter, "a sentence from another chapter"))
        assertNull(Highlights.locate(chapter, "RAIN")) // case matters
        assertNull(Highlights.locate(chapter, "   \n "))
        assertNull(Highlights.locate(emptyList(), "rain"))
        // A missing excerpt is skipped; the others still show.
        assertEquals(mapOf(0 to listOf(4..7)), Highlights.ranges(chapter, listOf("nowhere", "rain")))
    }

    @Test
    fun overlappingHighlightsAreMerged() {
        val ranges = Highlights.ranges(
            chapter,
            listOf("The rain had", "had not stopped", "days.", "stopped for", "She opened"),
        )
        // "The rain had" + "had not stopped" + "stopped for" overlap; "days." stays apart.
        assertEquals(listOf(0..27, 35..39), ranges[0])
        assertEquals(listOf(2..11), ranges[1])
        // The same excerpt twice is one range.
        assertEquals(mapOf(0 to listOf(4..7)), Highlights.ranges(chapter, listOf("rain", "rain")))
    }

    @Test
    fun mergeJoinsTouchingRangesAndKeepsGaps() {
        assertEquals(listOf(0..9, 12..14), Highlights.merge(listOf(12..14, 5..9, 0..4)))
        assertEquals(listOf(0..10), Highlights.merge(listOf(0..10, 2..3)))
        assertEquals(emptyList<IntRange>(), Highlights.merge(listOf(IntRange.EMPTY)))
    }

    @Test
    fun clipKeepsWithinTheServerLimit() {
        assertEquals("abc", Highlights.clip("  abc \n"))
        val long = "x".repeat(2500)
        assertEquals(Highlights.MAX_LENGTH, Highlights.clip(long).length)
        // Never half of a surrogate pair at the cut.
        val emoji = "a".repeat(Highlights.MAX_LENGTH - 1) + "😀" + "b"
        val clipped = Highlights.clip(emoji)
        assertEquals(Highlights.MAX_LENGTH - 1, clipped.length)
        assertFalse(Character.isHighSurrogate(clipped.last()))
    }
}

class HighlightWriteTest {
    private var clock = 1_000_000L
    private fun file(): File = File(Files.createTempDirectory("queue").toFile(), "pending-writes.json")

    @Test
    fun aHighlightIsQueuedPersistedAndSent() = runTest {
        val f = file()
        WriteQueue(f) { clock }.enqueue(WriteKind.CreateHighlight(7, "She opened the door", "Good line"), novelId = 3)
        // Survives a restart.
        val reopened = WriteQueue(f) { clock }
        assertEquals(listOf(WriteKind.CreateHighlight(7, "She opened the door", "Good line")), reopened.items().map { it.kind })

        val server = FakeServer { 201 to """{"highlight":{"id":1,"chapter_id":7}}""" }
        val client = ApiClient("http://host", server.client, DeviceIdentity("a_TESTDEVICE123", "Pixel 8"), token = "t")
        reopened.flush { kind, age -> client.send(kind, age) }
        assertEquals(0, reopened.count())
        assertEquals("/api/v1/highlights", server.requests.single().url.encodedPath)
        assertEquals("""{"chapter_id":7,"excerpt":"She opened the door","note":"Good line"}""", server.bodies.single())
    }

    @Test
    fun noNoteSendsNoNoteKey() {
        assertEquals(
            """{"chapter_id":7,"excerpt":"x"}""",
            ApiClient.highlightBody(WriteKind.CreateHighlight(7, "x", null)).toString(),
        )
    }

    @Test
    fun highlightsDontMovePositionOrCountAsChapterState() {
        val write = WriteKind.CreateHighlight(7, "x")
        assertFalse(write.movesPosition)
        assertFalse(write.changesReadState)
        assertFalse(hasUnsentChapterWrite(listOf(PendingWrite("id", write, 0L, 3)), 7))
    }

    @Test
    fun unsentHighlightsAreFoundPerSeries() {
        val writes = listOf(
            PendingWrite("1", WriteKind.CreateHighlight(7, "a"), 0L, 3),
            PendingWrite("2", WriteKind.Progress(7, 10, false), 0L, 3),
            PendingWrite("3", WriteKind.CreateHighlight(9, "b"), 0L, 4),
        )
        assertEquals(listOf(WriteKind.CreateHighlight(7, "a")), unsentHighlights(writes, 3))
    }

    @Test
    fun aQueuePersistedBeforeHighlightsAndLookUpsStillDecodes() = runTest {
        val f = file()
        f.writeText(
            """[{"id":"a","kind":{"type":"progress","chapterId":5,"progress":40,"read":false},"recordedAt":1,"novelId":2},""" +
                """{"id":"b","kind":{"type":"set_read","chapterId":6,"read":true},"recordedAt":2}]""",
        )
        val q = WriteQueue(f) { clock }
        assertEquals(listOf(WriteKind.Progress(5, 40, false), WriteKind.SetRead(6, true)), q.items().map { it.kind })
    }

    @Test
    fun aLookUpsReadMarkLeavesTheLatestPositionAlone() {
        val mark = WriteKind.Progress(5, 100, true, lookup = true)
        assertFalse(mark.movesPosition)
        assertTrue(mark.changesReadState)
        assertTrue(WriteKind.Progress(5, 100, true).movesPosition)
        assertEquals("""{"progress":100,"read":true,"lookup":true,"age":3}""", ApiClient.progressBody(mark, 3).toString())
        assertEquals("""{"progress":100,"read":true,"age":3}""", ApiClient.progressBody(WriteKind.Progress(5, 100, true), 3).toString())
    }
}
