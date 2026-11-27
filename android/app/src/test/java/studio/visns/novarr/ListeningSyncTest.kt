package studio.visns.novarr

import java.io.File
import java.nio.file.Files
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AudiobookProgress
import studio.visns.novarr.domain.ListeningRecord
import studio.visns.novarr.domain.ListeningSync
import studio.visns.novarr.store.ListeningPrefs
import studio.visns.novarr.store.ListeningStore
import studio.visns.novarr.store.ListeningStore.FlushOutcome

/** Listening progress: last write wins by recorded time, the offline queue and where playback resumes. */
class ListeningSyncTest {
    private fun file(): File = File(Files.createTempDirectory("listening").toFile(), ListeningStore.FILE)
    private fun rec(id: Int, position: Long, at: Long, finished: Boolean = false) = ListeningRecord(id, position, 1.25f, finished, at)

    // The resume rule

    private val t0 = ListeningSync.parseInstant("2026-12-10T10:00:00Z")!!

    @Test
    fun nothingAnywhereStartsAtTheBeginning() {
        assertEquals(ListeningSync.Resolved(0, false, fromLocal = false), ListeningSync.resolve(null, null))
    }

    @Test
    fun theNewerPositionWinsWhicheverSideItIsOn() {
        val server = AudiobookProgress(500_000, updatedAt = "2026-12-10T10:00:00Z")
        // This device listened later (offline, say): its position.
        assertEquals(ListeningSync.Resolved(700_000, false, true), ListeningSync.resolve(rec(1, 700_000, t0 + 60_000), server))
        // Another device listened later: the server's, even though it is EARLIER in the book.
        val older = rec(1, 900_000, t0 - 60_000)
        assertEquals(ListeningSync.Resolved(500_000, false, false), ListeningSync.resolve(older, server))
        // A tie goes to this device.
        assertTrue(ListeningSync.resolve(rec(1, 1, t0), server).fromLocal)
    }

    @Test
    fun anUnreadableServerTimeLosesToThisDevice() {
        val server = AudiobookProgress(500_000, updatedAt = "yesterday")
        assertTrue(ListeningSync.resolve(rec(1, 10, 0), server).fromLocal)
        // ...but is used when it is all there is.
        assertEquals(500_000L, ListeningSync.resolve(null, server).positionMs)
    }

    @Test
    fun serverTimesWithOffsetsParse() {
        assertEquals(t0, ListeningSync.parseInstant("2026-12-10T11:00:00+01:00"))
        assertEquals(t0 + 123, ListeningSync.parseInstant("2026-12-10T10:00:00.123Z"))
        assertNull(ListeningSync.parseInstant("not a date"))
    }

    @Test
    fun aFinishedBookStartsOver() {
        val total = 1_000_000L
        assertEquals(0L, ListeningSync.startPosition(ListeningSync.Resolved(total, true, false), total))
        // Within the last seconds counts as finished too.
        assertEquals(0L, ListeningSync.startPosition(ListeningSync.Resolved(total - 2_000, false, false), total))
        assertEquals(400_000L, ListeningSync.startPosition(ListeningSync.Resolved(400_000, false, false), total))
        // Never past the end, never negative.
        assertEquals(0L, ListeningSync.startPosition(ListeningSync.Resolved(-5, false, false), total))
    }

    // The store

    @Test
    fun anOlderPositionNeverReplacesANewerOne() = runTest {
        val store = ListeningStore(file())
        assertTrue(store.record(rec(1, 100, at = 2_000)))
        assertFalse(store.record(rec(1, 999, at = 1_000)))
        assertEquals(100L, store.latest(1)?.positionMs)
        assertTrue(store.record(rec(1, 200, at = 3_000)))
        assertEquals(200L, store.latest(1)?.positionMs)
        // Coalesced: one pending position per audiobook.
        assertEquals(1, store.pendingCount())
    }

    @Test
    fun audiobooksAreKeptApartAndSentOldestFirst() = runTest {
        val store = ListeningStore(file())
        store.record(rec(2, 50, at = 5_000))
        store.record(rec(1, 10, at = 1_000))
        val sent = mutableListOf<Int>()
        val outcome = store.flush { sent += it.audiobookId }
        assertEquals(FlushOutcome.Drained(2, 0), outcome)
        assertEquals(listOf(1, 2), sent)
        assertEquals(0, store.pendingCount())
        // Sent positions are still known (resume).
        assertEquals(50L, store.latest(2)?.positionMs)
    }

    @Test
    fun offlineKeepsEverythingAndResumesLater() = runTest {
        val store = ListeningStore(file())
        store.record(rec(1, 10, at = 1_000))
        store.record(rec(2, 20, at = 2_000))
        val outcome = store.flush { throw ApiError.Unreachable("offline") }
        assertEquals(FlushOutcome.Stalled(0, 2), outcome)
        assertEquals(2, store.pendingCount())
        assertEquals(FlushOutcome.Drained(2, 0), store.flush { })
    }

    @Test
    fun aSignOutKeepsThePositionsForTheNextSignIn() = runTest {
        val store = ListeningStore(file())
        store.record(rec(1, 10, at = 1_000))
        assertEquals(FlushOutcome.Unauthenticated(0, 1), store.flush { throw ApiError.Http(401, null) })
        assertEquals(1, store.pendingCount())
    }

    @Test
    fun aPositionTheServerRefusesIsDropped() = runTest {
        val store = ListeningStore(file())
        store.record(rec(1, 10, at = 1_000))
        store.record(rec(2, 10, at = 2_000))
        val outcome = store.flush { if (it.audiobookId == 1) throw ApiError.Http(404, "gone") }
        assertEquals(FlushOutcome.Drained(1, 1), outcome)
        assertEquals(0, store.pendingCount())
    }

    @Test
    fun aPositionRecordedWhileSendingStaysPending() = runTest {
        val store = ListeningStore(file())
        store.record(rec(1, 10, at = 1_000))
        val gate = CompletableDeferred<Unit>()
        val flushing = launch { store.flush { gate.await() } }
        runCurrent()
        // Newer than the one on the wire.
        store.record(rec(1, 20, at = 2_000))
        // A second flush while one runs does nothing.
        assertEquals(FlushOutcome.Busy, store.flush { })
        gate.complete(Unit)
        flushing.join()
        assertEquals(listOf(20L), store.pending().map { it.positionMs })
        assertEquals(FlushOutcome.Drained(1, 0), store.flush { })
        assertEquals(0, store.pendingCount())
    }

    @Test
    fun everythingSurvivesARestart() = runTest {
        val f = file()
        val store = ListeningStore(f)
        store.record(rec(1, 10, at = 1_000))
        store.record(rec(2, 20, at = 2_000, finished = true))
        store.flush { if (it.audiobookId == 2) throw ApiError.TimedOut }
        store.setLastAudiobookId(2)
        val reopened = ListeningStore(f)
        assertEquals(2, reopened.lastAudiobookId())
        assertEquals(listOf(2), reopened.pending().map { it.audiobookId })
        assertTrue(reopened.latest(2)!!.finished)
        assertEquals(10L, reopened.latest(1)?.positionMs)
        reopened.setLastAudiobookId(null)
        assertNull(ListeningStore(f).lastAudiobookId())
    }

    @Test
    fun aDamagedFileStartsEmpty() = runTest {
        val f = file()
        f.writeText("{not json")
        val store = ListeningStore(f)
        assertEquals(0, store.pendingCount())
        assertTrue(store.record(rec(1, 1, 1)))
    }

    // Formatting and preferences

    @Test
    fun clocksAndLengths() {
        assertEquals("0:00", ListeningSync.clock(0))
        assertEquals("2:03", ListeningSync.clock(123_400))
        assertEquals("1:02:03", ListeningSync.clock(3_723_000))
        assertEquals("45 min", ListeningSync.length(45 * 60_000L))
        assertEquals("11 h 32 min", ListeningSync.length((11 * 60 + 32) * 60_000L))
        assertEquals("2 h", ListeningSync.length(2 * 3_600_000L))
        assertEquals("2026-12-10T10:00:00Z", ListeningSync.isoOf(t0))
    }

    @Test
    fun speedsSnapToTwentiethsWithinTheRange() {
        assertEquals(1.25f, ListeningPrefs.validSpeed(1.26f))
        assertEquals(1.3f, ListeningPrefs.validSpeed(1.28f))
        assertEquals(0.5f, ListeningPrefs.validSpeed(0.1f))
        assertEquals(3.0f, ListeningPrefs.validSpeed(7f))
        assertEquals(1f, ListeningPrefs.validSpeed(Float.NaN))
        assertEquals(15, ListeningPrefs.validSkip(17, 15))
        assertEquals(45, ListeningPrefs.validSkip(45, 15))
    }
}
