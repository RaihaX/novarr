package studio.visns.novarr

import java.io.File
import java.nio.file.Files
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.store.WriteKind
import studio.visns.novarr.store.WriteQueue
import studio.visns.novarr.store.WriteQueue.FlushOutcome

class WriteQueueTest {
    private var clock = 1_000_000L
    private fun file(): File = File(Files.createTempDirectory("queue").toFile(), "pending-writes.json")
    private fun queue(f: File = file()) = WriteQueue(f) { clock }

    @Test
    fun sendsOldestFirstWithAges() = runTest {
        val q = queue()
        q.enqueue(WriteKind.SetRead(1, true), novelId = 9)
        clock += 500
        q.enqueue(WriteKind.SetRead(2, false), novelId = 9)
        clock += 250
        val sent = mutableListOf<Pair<WriteKind, Long>>()
        val outcome = q.flush { kind, age -> sent += kind to age }
        assertEquals(FlushOutcome.Drained(2, 0), outcome)
        assertEquals(listOf(WriteKind.SetRead(1, true) to 750L, WriteKind.SetRead(2, false) to 250L), sent)
        assertEquals(0, q.count())
    }

    @Test
    fun newerPositionReplacesAnUnsentOneForTheSameChapter() = runTest {
        val q = queue()
        q.enqueue(WriteKind.Progress(5, 10, false))
        q.enqueue(WriteKind.Progress(6, 10, false))
        q.enqueue(WriteKind.Progress(5, 30, false))
        assertEquals(
            listOf(WriteKind.Progress(6, 10, false), WriteKind.Progress(5, 30, false)),
            q.items().map { it.kind },
        )
    }

    @Test
    fun readMarksAndExplicitMarksAreNeverMerged() = runTest {
        val q = queue()
        q.enqueue(WriteKind.Progress(5, 100, true))
        q.enqueue(WriteKind.SetRead(5, false))
        q.enqueue(WriteKind.SetRead(5, true))
        q.enqueue(WriteKind.Progress(5, 20, false))
        q.enqueue(WriteKind.BulkRead(BulkReadRequest.all(1, true)))
        assertEquals(5, q.count())
    }

    @Test
    fun stallsOnARetryableFailureAndKeepsOrder() = runTest {
        val q = queue()
        q.enqueue(WriteKind.SetRead(1, true))
        q.enqueue(WriteKind.SetRead(2, true))
        q.enqueue(WriteKind.SetRead(3, true))
        var calls = 0
        val outcome = q.flush { _, _ -> if (++calls == 2) throw ApiError.Unreachable("offline") }
        assertEquals(FlushOutcome.Stalled(sent = 1, remaining = 2), outcome)
        assertEquals(listOf(2, 3), q.items().map { (it.kind as WriteKind.SetRead).chapterId })
    }

    @Test
    fun unauthenticatedKeepsEveryRemainingWrite() = runTest {
        val f = file()
        val q = queue(f)
        q.enqueue(WriteKind.Progress(1, 40, false), novelId = 2)
        q.enqueue(WriteKind.SetRead(2, true), novelId = 2)
        val outcome = q.flush { _, _ -> throw ApiError.Http(401, "Unauthenticated.") }
        assertEquals(FlushOutcome.Unauthenticated(sent = 0, remaining = 2), outcome)
        // Still on disk for the next sign-in.
        assertEquals(2, queue(f).count())
    }

    @Test
    fun dropsWritesTheServerRejectsForGood() = runTest {
        val q = queue()
        q.enqueue(WriteKind.SetRead(404, true))
        q.enqueue(WriteKind.SetRead(2, true))
        val sent = mutableListOf<WriteKind>()
        val outcome = q.flush { kind, _ ->
            if ((kind as WriteKind.SetRead).chapterId == 404) throw ApiError.Http(404, null)
            sent += kind
        }
        assertEquals(FlushOutcome.Drained(sent = 1, dropped = 1), outcome)
        assertEquals(listOf<WriteKind>(WriteKind.SetRead(2, true)), sent)
    }

    @Test
    fun survivesARelaunch() = runTest {
        val f = file()
        queue(f).apply {
            enqueue(WriteKind.Progress(7, 55, false), novelId = 3)
            enqueue(WriteKind.BulkRead(BulkReadRequest.from(8, 3, false)), novelId = 3)
        }
        clock += 60_000
        val reopened = queue(f)
        val items = reopened.items()
        assertEquals(2, items.size)
        assertEquals(WriteKind.Progress(7, 55, false), items[0].kind)
        assertEquals(3, items[0].novelId)
        assertEquals(60_000L, items[0].age(clock))
        assertTrue(items[1].kind is WriteKind.BulkRead)
    }

    @Test
    fun aSecondFlushWhileOneRunsIsBusy() = runTest {
        val q = queue()
        q.enqueue(WriteKind.SetRead(1, true))
        var inner: FlushOutcome? = null
        q.flush { _, _ -> inner = q.flush { _, _ -> } }
        assertEquals(FlushOutcome.Busy, inner)
    }

    @Test
    fun onlyPositionMovingWritesCountForTheLatestPositionRule() {
        assertTrue(WriteKind.Progress(1, 10, false).movesPosition)
        assertTrue(WriteKind.Progress(1, null, true).movesPosition)
        assertTrue(WriteKind.SetRead(1, true).movesPosition)
        assertEquals(false, WriteKind.SetRead(1, false).movesPosition)
        assertEquals(false, WriteKind.BulkRead(BulkReadRequest.all(1, true)).movesPosition)
    }
}
