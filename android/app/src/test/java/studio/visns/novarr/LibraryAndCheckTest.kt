package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.DeviceIdentity
import studio.visns.novarr.api.NovelCheck
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.domain.CheckDisplay
import studio.visns.novarr.domain.CheckPolling
import studio.visns.novarr.domain.LibraryFilter
import studio.visns.novarr.domain.LibraryFilter.Filter
import studio.visns.novarr.domain.LibraryFilter.Shelf
import studio.visns.novarr.domain.LibraryFilter.Sort

class LibraryFilterTest {
    private val novels = listOf(
        NovelSummary(1, "Beta", author = "Zoë", chaptersRead = 3, chaptersTotal = 10, lastReadAt = "2026-11-01T00:00:00Z"),
        NovelSummary(2, "alpha", chaptersNew = 2, updatedAt = "2026-11-10T00:00:00Z"),
        NovelSummary(3, "Gamma", chaptersRead = 5, chaptersTotal = 5, finished = true, lastReadAt = "2026-11-05T00:00:00Z"),
        NovelSummary(4, "Manga One", medium = "manga", chaptersRead = 1),
    )

    @Test
    fun shelvesSeparateNovelsAndManga() {
        assertEquals(listOf(1, 2, 3), LibraryFilter.shelf(novels, Shelf.NOVELS).map { it.id })
        assertEquals(listOf(4), LibraryFilter.shelf(novels, Shelf.MANGA).map { it.id })
    }

    @Test
    fun filters() {
        val shelf = LibraryFilter.shelf(novels, Shelf.NOVELS)
        assertEquals(listOf(1), shelf.filter(Filter.READING::matches).map { it.id })
        assertEquals(listOf(2), shelf.filter(Filter.NEW_CHAPTERS::matches).map { it.id })
        assertEquals(listOf(2), shelf.filter(Filter.NOT_STARTED::matches).map { it.id })
        assertEquals(listOf(3), shelf.filter(Filter.FINISHED::matches).map { it.id })
    }

    @Test
    fun sorts() {
        val shelf = LibraryFilter.shelf(novels, Shelf.NOVELS)
        assertEquals(listOf(2, 1, 3), LibraryFilter.sorted(shelf, Sort.NAME).map { it.id })
        // Newest first, undated last.
        assertEquals(listOf(3, 1, 2), LibraryFilter.sorted(shelf, Sort.LAST_READ).map { it.id })
        assertEquals(listOf(2, 1, 3), LibraryFilter.sorted(shelf, Sort.RECENTLY_UPDATED).map { it.id })
    }

    @Test
    fun searchIgnoresCaseAndAccentsAndMatchesAuthors() {
        assertEquals(listOf(1), LibraryFilter.apply(novels, Filter.ALL, Sort.NAME, "zoe").map { it.id })
        assertEquals(listOf(2), LibraryFilter.apply(novels, Filter.ALL, Sort.NAME, "ALPH").map { it.id })
    }
}

class CheckPollingTest {
    private fun check(status: String, now: Int = 10, new: Int = 0, message: String? = null) =
        NovelCheck(status = status, chaptersNow = now, newChapters = new, message = message)

    @Test
    fun joinsARunningCheckInsteadOfStartingAnother() {
        assertFalse(CheckPolling.shouldStart(check("running")))
        assertTrue(CheckPolling.shouldStart(check("done")))
        assertTrue(CheckPolling.shouldStart(null))
    }

    @Test
    fun pollsWhileRunningThenReportsFound() {
        val p = CheckPolling(startedAt = 0)
        assertEquals(CheckPolling.Step.Poll(CheckPolling.INTERVAL_MS), p.receive(check("running"), 0))
        assertEquals(CheckDisplay.Checking(0), p.display)
        p.receive(check("running", now = 12, new = 2), 15_000)
        assertTrue(p.chaptersChanged)
        assertEquals("Checking… 2 new chapters so far", p.display.text)
        assertEquals(CheckPolling.Step.Finished, p.receive(check("done", now = 13, new = 3), 30_000))
        assertEquals(CheckDisplay.Found(3), p.display)
    }

    @Test
    fun failedAndNothingNew() {
        val failed = CheckPolling(0)
        failed.receive(check("failed", message = "Source down"), 0)
        assertEquals(CheckDisplay.Failed("Source down"), failed.display)
        val none = CheckPolling(0)
        none.receive(check("done"), 0)
        assertEquals("No new chapters yet", none.display.text)
    }

    @Test
    fun givesUpAfterThreeErrorsOrTenMinutes() {
        val p = CheckPolling(0)
        assertTrue(p.receiveError("x", 0) is CheckPolling.Step.Poll)
        assertTrue(p.receiveError("x", 0) is CheckPolling.Step.Poll)
        assertEquals(CheckPolling.Step.Finished, p.receiveError("boom", 0))
        assertEquals(CheckDisplay.Failed("boom"), p.display)

        val slow = CheckPolling(0)
        assertEquals(CheckPolling.Step.Finished, slow.receive(check("running"), CheckPolling.LIMIT_MS))
        assertEquals(CheckDisplay.StillRunning, slow.display)
    }
}

class DeviceIdentityTest {
    @Test
    fun keepsAValidIdAndReplacesABadOne() {
        val (kept, keptIsNew) = DeviceIdentity.resolve("a_abcdefgh123", "Pixel 8")
        assertEquals("a_abcdefgh123", kept.id)
        assertFalse(keptIsNew)
        val (fresh, freshIsNew) = DeviceIdentity.resolve("bad id!", "Pixel 8")
        assertTrue(freshIsNew)
        assertTrue(DeviceIdentity.isValidId(fresh.id))
        assertTrue(fresh.id.startsWith("a_"))
    }

    @Test
    fun namesAreShortAndNeverEmpty() {
        assertEquals("Android", DeviceIdentity.cleanName("  "))
        assertEquals("Pixel 8", DeviceIdentity.cleanName(" Pixel   8 "))
        assertEquals(40, DeviceIdentity.cleanName("x".repeat(80)).length)
        assertEquals(mapOf("X-Novarr-Device" to "a_12345678", "X-Novarr-Device-Name" to "Pixel 8"), DeviceIdentity("a_12345678", "Pixel 8").headers)
    }
}
