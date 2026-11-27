package studio.visns.novarr

import java.io.File
import java.nio.file.Files
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.domain.ProgressTracker
import studio.visns.novarr.domain.ProgressTracker.Decision
import studio.visns.novarr.domain.ReadMark
import studio.visns.novarr.store.WriteKind
import studio.visns.novarr.store.WriteQueue
import studio.visns.novarr.store.WriteQueue.FlushOutcome

/**
 * Regression tests for "the Android app never marks chapters read": finishing
 * a chapter (its end on screen, 95% of a scrolled text, or moving on with
 * Next chapter) must queue exactly one `read: true` write, which survives
 * being offline and is replayed in order.
 */
class ReadMarkTest {
    private var now = 1_000_000L

    @Test
    fun scrolledTextCountsAsReadAtNinetyFivePercent() {
        // 40 paragraphs: the bottom of paragraph 38 (index 37) is 95%.
        assertFalse(ReadMark.textEndReached(lastFullyVisible = 36, paragraphCount = 40))
        assertTrue(ReadMark.textEndReached(lastFullyVisible = 37, paragraphCount = 40))
        assertTrue(ReadMark.textEndReached(lastFullyVisible = 39, paragraphCount = 40))
        // Nothing fully on screen, or nothing to read.
        assertFalse(ReadMark.textEndReached(lastFullyVisible = -1, paragraphCount = 40))
        assertFalse(ReadMark.textEndReached(lastFullyVisible = 0, paragraphCount = 0))
        // A one-paragraph chapter is read once that paragraph's bottom is on screen.
        assertTrue(ReadMark.textEndReached(lastFullyVisible = 0, paragraphCount = 1))
        // 3 paragraphs: all three are needed (95% of 3 rounds up to 3).
        assertFalse(ReadMark.textEndReached(lastFullyVisible = 1, paragraphCount = 3))
    }

    @Test
    fun movingOnToTheNextChapterFinishesTheOneLeft() {
        assertTrue(ReadMark.finishesOnOpen(target = 12, next = 12, loaded = true))
        // Back, or a jump elsewhere, says nothing about the chapter being left.
        assertFalse(ReadMark.finishesOnOpen(target = 10, next = 12, loaded = true))
        // The last chapter has no next; a chapter still loading isn't finished.
        assertFalse(ReadMark.finishesOnOpen(target = 12, next = null, loaded = true))
        assertFalse(ReadMark.finishesOnOpen(target = 12, next = 12, loaded = false))
    }

    @Test
    fun pagingThroughAMangaChapterEndsWithOneReadMark() {
        val tracker = ProgressTracker(4_000) { now }
        tracker.reset(read = false, progress = null)
        val pages = 8
        val sent = mutableListOf<Int>()
        // Pages 1..7 (indices 0..6), a page every 1.5 s: positions throttled to one per 4 s.
        for (index in 0 until pages - 1) {
            when (tracker.moved(ProgressTracker.progressFor(index, pages))) {
                Decision.SendNow -> tracker.takePending()?.let(sent::add)
                else -> Unit
            }
            now += 1_500
            if (index % 3 == 2) tracker.takePending()?.let(sent::add) // the scheduled send fires
        }
        // The last page: the read mark, once, and nothing pending after it.
        assertTrue(tracker.reachedEnd())
        assertNull(tracker.takePending())
        assertFalse(tracker.reachedEnd())
        assertEquals(100, tracker.current)
        assertTrue(sent.all { it in 0..99 })
    }

    @Test
    fun anAlreadyReadChapterIsNotMarkedAgain() {
        val tracker = ProgressTracker(4_000) { now }
        tracker.reset(read = true, progress = 100)
        assertFalse(tracker.reachedEnd())
    }

    @Test
    fun theReadMarkIsSentAsProgressHundredWithTheReadFlag() {
        val body = ApiClient.progressBody(WriteKind.Progress(7, 100, true), age = 1_200)
        assertEquals(100, body["progress"]!!.jsonPrimitive.int)
        assertTrue(body["read"]!!.jsonPrimitive.boolean)
        assertEquals(1_200, body["age"]!!.jsonPrimitive.int)
        // A plain position never carries `read` (its presence means "mark read").
        assertNull(ApiClient.progressBody(WriteKind.Progress(7, 40, false), age = 0)["read"])
    }

    private fun queue(): WriteQueue = WriteQueue(File(Files.createTempDirectory("readmark").toFile(), "pending-writes.json")) { now }

    @Test
    fun anOfflineReadMarkIsKeptAndReplayedInOrderWithItsAge() = runTest {
        val q = queue()
        q.enqueue(WriteKind.Progress(7, 60, false), novelId = 3)
        q.enqueue(WriteKind.Progress(7, 100, true), novelId = 3)
        // Offline: nothing leaves the queue.
        val offline = q.flush { _, _ -> throw ApiError.Unreachable("offline") }
        assertEquals(FlushOutcome.Stalled(sent = 0, remaining = 2), offline)
        // A later position for the same chapter never replaces the read mark.
        q.enqueue(WriteKind.Progress(8, 5, false), novelId = 3)
        now += 60_000
        val sent = mutableListOf<Pair<WriteKind, Long>>()
        assertEquals(FlushOutcome.Drained(3, 0), q.flush { kind, age -> sent += kind to age })
        assertEquals(
            listOf(WriteKind.Progress(7, 60, false), WriteKind.Progress(7, 100, true), WriteKind.Progress(8, 5, false)),
            sent.map { it.first },
        )
        assertEquals(60_000L, sent[1].second)
    }

    @Test
    fun aReadMarkQueuedWhileAFlushRunsIsSentByThatFlush() = runTest {
        val q = queue()
        q.enqueue(WriteKind.Progress(7, 60, false))
        val gate = CompletableDeferred<Unit>()
        val sent = mutableListOf<WriteKind>()
        var outcome: FlushOutcome? = null
        launch { outcome = q.flush { kind, _ -> gate.await(); sent += kind } }
        runCurrent()
        // The reader reaches the end while the position is on its way; its own flush is turned away.
        q.enqueue(WriteKind.Progress(7, 100, true))
        assertEquals(FlushOutcome.Busy, q.flush { _, _ -> error("not while busy") })
        gate.complete(Unit)
        runCurrent()
        assertEquals(FlushOutcome.Drained(2, 0), outcome)
        assertEquals(listOf<WriteKind>(WriteKind.Progress(7, 60, false), WriteKind.Progress(7, 100, true)), sent)
        assertEquals(0, q.count())
    }
}
