package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.api.ChapterListItem
import studio.visns.novarr.api.ChapterRef
import studio.visns.novarr.api.ContinueItem
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.domain.ResumeRule
import studio.visns.novarr.store.PendingWrite
import studio.visns.novarr.store.WriteKind

/**
 * The shared resume rule (server: App\Services\ResumePoint) applied to the
 * server's answer plus this device's unsent writes. The same cases as the
 * iOS ResumeRuleTests, so both apps pick the same chapter.
 */
class ResumeRuleTest {
    /** Chapters 101…100+n, all downloaded unless listed in [missing]; the first [read] read. */
    private fun list(n: Int, read: Int = 0, missing: Set<Int> = emptySet(), progress: Map<Int, Int> = emptyMap()) =
        (1..n).map { i ->
            ChapterListItem(
                id = 100 + i, chapter = i.toDouble(), book = 0, label = "Chapter $i", downloaded = i !in missing,
                read = i <= read, readProgress = progress[i] ?: if (i <= read) 100 else null,
            )
        }

    private val t0 = 1_800_000_000_000L

    private fun write(kind: WriteKind, at: Long) = PendingWrite("w$at-${kind.hashCode()}", kind, t0 + at * 1000, novelId = 1)

    private fun progress(id: Int, value: Int?, read: Boolean, lookup: Boolean = false) = WriteKind.Progress(id, value, read, lookup)

    @Test
    fun nothingUnsentKeepsTheServersChoice() {
        assertEquals(103, ResumeRule.resolve(list(10, read = 4), 103, emptyList()))
        assertEquals(103, ResumeRule.resolve(emptyList(), 103, emptyList()))
        // No server answer: the first downloaded unread chapter.
        assertEquals(106, ResumeRule.resolve(list(10, read = 4, missing = setOf(5)), null, emptyList()))
    }

    @Test
    fun finishingAChapterMovesToTheNextUnreadAfterIt() {
        val pending = listOf(write(progress(105, 100, true), 1))
        assertEquals(106, ResumeRule.resolve(list(10, read = 4), 105, pending))
    }

    @Test
    fun aChapterPartReadOnThisDeviceIsResumed() {
        val pending = listOf(write(progress(105, 40, false), 1))
        assertEquals(105, ResumeRule.resolve(list(10, read = 4), 105, pending))
    }

    @Test
    fun reReadingAnEarlyChapterPartWayResumesIt() {
        // As the server will: the read chapter read last is below 90 %.
        val pending = listOf(write(progress(102, 30, false), 5))
        assertEquals(102, ResumeRule.resolve(list(10, read = 6), 107, pending))
        val finished = listOf(write(progress(102, 95, false), 5))
        assertEquals(107, ResumeRule.resolve(list(10, read = 6), 107, finished))
    }

    @Test
    fun anOlderPartReadChapterNeverPullsBack() {
        val pending = listOf(
            write(progress(103, 20, false), 1),
            write(progress(106, 100, true), 2),
        )
        assertEquals(107, ResumeRule.resolve(list(10, read = 2), 103, pending))
    }

    @Test
    fun earlierUnreadChaptersDoNotPullBack() {
        val pending = listOf(write(progress(105, 100, true), 1))
        assertEquals(106, ResumeRule.resolve(list(10), 101, pending))
    }

    @Test
    fun theNextChapterTheServerLacksIsSkipped() {
        val pending = listOf(write(progress(105, 100, true), 1))
        assertEquals(107, ResumeRule.resolve(list(10, read = 4, missing = setOf(6)), 105, pending))
    }

    @Test
    fun caughtUpGoesToTheLastDownloadedChapter() {
        val pending = listOf(write(progress(105, 100, true), 1))
        assertEquals(105, ResumeRule.resolve(list(6, read = 4, missing = setOf(6)), 105, pending))
    }

    @Test
    fun markingUnreadOrOpeningAtZeroIsNotReading() {
        val unread = listOf(write(WriteKind.SetRead(103, false), 9))
        assertEquals(107, ResumeRule.resolve(list(10, read = 6), 107, unread))
        val opened = listOf(write(progress(108, 0, false), 9))
        assertEquals(107, ResumeRule.resolve(list(10, read = 6), 107, opened))
    }

    @Test
    fun aBulkMarkTiesGoToTheFurthestChapter() {
        val pending = listOf(write(WriteKind.BulkRead(BulkReadRequest.upTo(106, novelId = 1, read = true)), 3))
        assertEquals(107, ResumeRule.resolve(list(10), 101, pending))
    }

    @Test
    fun theNewestWriteIsTheAnchor() {
        val pending = listOf(
            write(progress(108, 100, true), 1),
            write(progress(104, 60, false), 2),
        )
        assertEquals(104, ResumeRule.resolve(list(10, read = 3), 104, pending))
    }

    @Test
    fun aLookUpOfAReadChapterDoesNotMoveIt() {
        val pending = listOf(write(progress(102, 100, true, lookup = true), 9))
        assertEquals(106, ResumeRule.resolve(list(10, read = 6, progress = mapOf(6 to 40)), 106, pending))
    }

    @Test
    fun aLookUpsPositionIsNotReading() {
        // The server never stores a look-up's position (only its read mark).
        val pending = listOf(write(progress(109, 50, false, lookup = true), 9))
        assertEquals(105, ResumeRule.resolve(list(10, read = 4), 105, pending))
    }

    @Test
    fun highlightsAreNotReading() {
        val pending = listOf(write(WriteKind.CreateHighlight(109, "a passage"), 9))
        assertEquals(105, ResumeRule.resolve(list(10, read = 4), 105, pending))
    }

    @Test
    fun unfinished() {
        assertFalse(ResumeRule.isUnfinished(read = false, progress = null))
        assertFalse(ResumeRule.isUnfinished(read = false, progress = 0))
        assertTrue(ResumeRule.isUnfinished(read = false, progress = 1))
        assertTrue(ResumeRule.isUnfinished(read = false, progress = 99))
        assertFalse(ResumeRule.isUnfinished(read = false, progress = 100))
        assertFalse(ResumeRule.isUnfinished(read = true, progress = null))
        assertTrue(ResumeRule.isUnfinished(read = true, progress = 89))
        assertFalse(ResumeRule.isUnfinished(read = true, progress = 90))
    }

    @Test
    fun startedCountsPartReadAndUnsentReading() {
        assertFalse(ResumeRule.hasStarted(list(10), 0, emptyList()))
        assertTrue(ResumeRule.hasStarted(list(10), 2, emptyList()))
        // Part-read on the server (unread, 40 %): the server counts no chapter read.
        assertTrue(ResumeRule.hasStarted(list(10, progress = mapOf(1 to 40)), 0, emptyList()))
        // Read in this app, not sent yet.
        assertTrue(ResumeRule.hasStarted(list(10), 0, listOf(write(progress(101, 30, false), 1))))
        assertFalse(ResumeRule.hasStarted(list(10), 0, listOf(write(progress(101, 0, false), 1))))
    }

    @Test
    fun homeContinueItemTakesTheLocalChoice() {
        val item = ContinueItem(NovelSummary(id = 1, name = "N", resumeChapterId = 105), ChapterRef(id = 105, label = "Chapter 5"))
        val pending = listOf(write(progress(105, 100, true), 1))
        val adjusted = ResumeRule.adjusted(item, list(10, read = 4), pending)
        assertEquals(106, adjusted.chapter.id)
        assertEquals("Chapter 6", adjusted.chapter.label)
        assertEquals(106, adjusted.novel.resumeChapterId)
        assertEquals(105, ResumeRule.adjusted(item, list(10, read = 4), emptyList()).chapter.id)
    }
}
