package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.api.ReadingPosition
import studio.visns.novarr.domain.LatestPosition
import studio.visns.novarr.domain.ProgressTracker
import studio.visns.novarr.domain.ProgressTracker.Decision
import studio.visns.novarr.domain.ReadingMode
import studio.visns.novarr.store.PendingWrite
import studio.visns.novarr.store.WriteKind
import studio.visns.novarr.store.hasUnsentChapterWrite

class ProgressTrackerTest {
    private var now = 100_000L
    private val tracker = ProgressTracker(4_000) { now }

    @Test
    fun firstPositionGoesAtOnceThenThrottles() {
        tracker.reset(read = false, progress = null)
        assertEquals(Decision.SendNow, tracker.moved(10))
        assertEquals(10, tracker.takePending())
        now += 1_000
        assertEquals(Decision.SendAfter(3_000), tracker.moved(20))
        now += 500
        // Already scheduled: the pending send will carry the newest position.
        assertEquals(Decision.AlreadyScheduled, tracker.moved(25))
        now += 2_500
        assertEquals(25, tracker.takePending())
        now += 4_000
        assertEquals(Decision.SendNow, tracker.moved(30))
    }

    @Test
    fun unchangedPositionIsNotResent() {
        tracker.reset(read = false, progress = 40)
        tracker.moved(40)
        assertNull(tracker.takePending())
        assertNull(tracker.takePending())
    }

    @Test
    fun hundredIsReservedForTheEnd() {
        tracker.reset(read = false, progress = null)
        tracker.moved(150)
        assertEquals(99, tracker.takePending())
        assertEquals(99, ProgressTracker.progressFor(9, 10))
        assertEquals(0, ProgressTracker.progressFor(0, 10))
        assertEquals(50, ProgressTracker.progressFor(5, 11))
        assertEquals(0, ProgressTracker.progressFor(0, 1))
    }

    @Test
    fun reachingTheEndMarksReadOnceAndDropsThePendingPosition() {
        tracker.reset(read = false, progress = 30)
        tracker.moved(80)
        assertTrue(tracker.reachedEnd())
        assertNull(tracker.takePending())
        assertFalse(tracker.reachedEnd())
        assertEquals(100, tracker.current)
    }

    @Test
    fun anAlreadyReadChapterIsNotMarkedAgain() {
        tracker.reset(read = true, progress = 100)
        assertFalse(tracker.reachedEnd())
    }

    @Test
    fun pendingSinceTracksTheOldestUnsentPosition() {
        tracker.reset(read = false, progress = null)
        tracker.moved(5)
        tracker.takePending()
        now += 1_000
        tracker.moved(6)
        val since = tracker.pendingSince
        now += 500
        tracker.moved(7)
        assertEquals(since, tracker.pendingSince)
        tracker.takePending()
        assertNull(tracker.pendingSince)
    }

    @Test
    fun heldPositionsAreTrackedButNotSentUntilReleased() {
        tracker.reset(read = false, progress = 10)
        tracker.hold()
        assertEquals(Decision.Held, tracker.moved(30))
        now += 10_000
        assertEquals(Decision.Held, tracker.moved(35))
        assertNull(tracker.takePending())
        assertEquals(35, tracker.current)
        tracker.release()
        assertEquals(35, tracker.takePending())
        assertEquals(Decision.SendAfter(4_000), tracker.moved(40))
    }

    @Test
    fun holdSurvivesAChapterReset() {
        tracker.hold()
        tracker.reset(read = false, progress = null)
        assertTrue(tracker.holding)
        assertEquals(Decision.Held, tracker.moved(5))
    }

    @Test
    fun discardingDropsTheHeldPosition() {
        tracker.reset(read = false, progress = 10)
        tracker.hold()
        tracker.moved(60)
        tracker.discardPending()
        tracker.release()
        assertNull(tracker.takePending())
        assertEquals(10, tracker.current)
        assertNull(tracker.pendingSince)
    }

    @Test
    fun positionsTakenWhileHoldingDontCountAsReadHereMoreRecently() {
        tracker.reset(read = false, progress = null)
        tracker.hold()
        tracker.moved(20)
        assertEquals(now, tracker.pendingSince)
        assertNull(tracker.unheldPendingSince)
        // Released: from now on it is an ordinary unsent position.
        tracker.release()
        assertEquals(tracker.pendingSince, tracker.unheldPendingSince)
    }

    @Test
    fun anUnsentPositionFromBeforeTheHoldStillCounts() {
        tracker.reset(read = false, progress = null)
        tracker.moved(5)
        tracker.takePending()
        now += 1_000
        tracker.moved(10) // scheduled, not yet sent
        val since = now
        tracker.hold()
        now += 500
        tracker.moved(15)
        assertEquals(since, tracker.unheldPendingSince)
    }

    @Test
    fun theReadMarkIsWrittenWhileHolding() {
        tracker.reset(read = false, progress = 10)
        tracker.hold()
        tracker.moved(80)
        assertTrue(tracker.reachedEnd())
        assertNull(tracker.pendingSince)
        tracker.release()
        assertNull(tracker.takePending())
    }
}

class UnsentChapterWriteTest {
    private fun write(kind: WriteKind) = PendingWrite("id", kind, 0L, 9)

    @Test
    fun onlyWritesForThatChapterCount() {
        val writes = listOf(write(WriteKind.Progress(1, 40, false)), write(WriteKind.SetRead(2, true)))
        assertTrue(hasUnsentChapterWrite(writes, 1))
        assertTrue(hasUnsentChapterWrite(writes, 2))
        assertFalse(hasUnsentChapterWrite(writes, 3))
        assertFalse(hasUnsentChapterWrite(emptyList(), 1))
    }

    @Test
    fun markingUnreadCountsBulkMarkingDoesNot() {
        assertTrue(hasUnsentChapterWrite(listOf(write(WriteKind.SetRead(3, false))), 3))
        val bulk = write(WriteKind.BulkRead(BulkReadRequest(read = true, scope = "up_to", novelId = 9, anchorId = 3)))
        assertFalse(hasUnsentChapterWrite(listOf(bulk), 3))
    }
}

class LatestPositionTest {
    private val me = "a_ME0000000"
    private fun position(
        chapter: Int = 124,
        progress: Int? = 40,
        device: String? = "k_kindle01",
        at: String? = "2026-11-18T02:10:00+00:00",
        downloaded: Boolean? = true,
    ) = ReadingPosition(chapter, "Chapter 124: The Gate", "124", progress, downloaded, device, "Kindle", at, 7200)

    private fun offer(
        p: ReadingPosition?,
        chapter: Int = 100,
        local: Int? = null,
        newer: Boolean = false,
        declined: Long? = null,
        canOpen: Boolean = true,
    ) = LatestPosition.shouldOffer(p, me, chapter, local, newer, declined, canOpen)

    @Test
    fun offersAnotherDevicesPositionElsewhere() = assertTrue(offer(position()))

    @Test
    fun rule1NeedsAnotherKnownDevice() {
        assertFalse(offer(null))
        assertFalse(offer(position(device = null)))
        assertFalse(offer(position(device = me)))
    }

    @Test
    fun rule2LocalUnsentProgressWins() = assertFalse(offer(position(), newer = true))

    @Test
    fun rule3SameChapterNeedsFivePoints() {
        assertFalse(offer(position(chapter = 100, progress = 40), chapter = 100, local = 37))
        assertTrue(offer(position(chapter = 100, progress = 40), chapter = 100, local = 35))
        assertTrue(offer(position(chapter = 100, progress = 40), chapter = 100, local = null))
        // Same chapter without a percentage means "here".
        assertFalse(offer(position(chapter = 100, progress = null), chapter = 100))
    }

    @Test
    fun rule4DeclinedPositionIsNotOfferedAgain() {
        val at = LatestPosition.parseInstant("2026-11-18T02:10:00+00:00")!!
        assertFalse(offer(position(), declined = at))
        assertTrue(offer(position(at = "2026-11-18T03:00:00Z"), declined = at))
        assertFalse(offer(position(at = null), declined = at))
    }

    @Test
    fun rule5MustBeOpenable() = assertFalse(offer(position(), canOpen = false))

    @Test
    fun localIsNewerComparesAgesOnThisDevicesClock() {
        val now = 10_000_000L
        assertTrue(LatestPosition.localIsNewer(listOf(now - 60_000), ageSeconds = 7200, now = now))
        assertFalse(LatestPosition.localIsNewer(listOf(now - 8_000_000), ageSeconds = 7200, now = now))
        assertFalse(LatestPosition.localIsNewer(listOf(now), ageSeconds = null, now = now))
        assertFalse(LatestPosition.localIsNewer(emptyList(), ageSeconds = 7200, now = now))
    }

    @Test
    fun messageFollowsTheContract() {
        assertEquals("Chapter 124: The Gate · 40% was read on Kindle 2 hours ago. Go there?", LatestPosition.message(position()))
        val bare = ReadingPosition(5, null, "6", progress = 100, deviceName = null, ageSeconds = 30)
        assertEquals("Chapter 6 was read on another device just now. Go there?", LatestPosition.message(bare))
    }

    @Test
    fun relativeTimes() {
        assertEquals("1 minute ago", LatestPosition.relativeTime(60))
        assertEquals("5 minutes ago", LatestPosition.relativeTime(300))
        assertEquals("yesterday", LatestPosition.relativeTime(90_000))
        assertEquals("3 days ago", LatestPosition.relativeTime(3 * 86_400))
        assertEquals("2 weeks ago", LatestPosition.relativeTime(14 * 86_400))
        assertEquals("1 year ago", LatestPosition.relativeTime(400 * 86_400))
    }

    @Test
    fun progressToItem() {
        assertNull(LatestPosition.itemIndex(0, 50))
        assertNull(LatestPosition.itemIndex(50, 1))
        assertEquals(49, LatestPosition.itemIndex(100, 50))
        assertEquals(5, LatestPosition.itemIndex(50, 11))
    }
}

class RecheckOnReturnTest {
    @Test
    fun asksAgainOnlyAfterAMinuteAway() {
        assertFalse(LatestPosition.recheckOnReturn(null, 1_000_000))
        assertFalse(LatestPosition.recheckOnReturn(1_000_000, 1_059_999))
        assertTrue(LatestPosition.recheckOnReturn(1_000_000, 1_060_000))
        assertTrue(LatestPosition.recheckOnReturn(1_000_000, 5_000_000))
    }
}

class ReadingModeTest {
    @Test
    fun followsTheSeriesReadingMode() {
        assertEquals(ReadingMode.RIGHT_TO_LEFT, ReadingMode.from("rtl"))
        assertEquals(ReadingMode.LEFT_TO_RIGHT, ReadingMode.from("ltr"))
        assertEquals(ReadingMode.VERTICAL, ReadingMode.from(" Vertical "))
        assertTrue(ReadingMode.from("rtl").paged && ReadingMode.from("rtl").rightToLeft)
        assertFalse(ReadingMode.from("vertical").paged)
    }

    @Test
    fun unknownModesFallBackToLeftToRightLikeTheServer() {
        assertEquals(ReadingMode.LEFT_TO_RIGHT, ReadingMode.from(null))
        assertEquals(ReadingMode.LEFT_TO_RIGHT, ReadingMode.from("webtoon"))
    }
}
