package studio.visns.novarr

import java.time.LocalTime
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.UpdatedSeries
import studio.visns.novarr.api.UpdatesFeed
import studio.visns.novarr.domain.ChapterUpdates
import studio.visns.novarr.domain.ChapterUpdates.Action
import studio.visns.novarr.domain.ChapterUpdates.Pending
import studio.visns.novarr.domain.ChapterUpdates.State

class ChapterUpdatesTest {
    private val hour = 60 * 60 * 1000L
    private val noon = LocalTime.of(12, 0)
    private val t0 = 1_800_000_000_000L

    private fun series(id: Int, name: String, count: Int = 1, chapter: Int = id * 100, at: String? = "2026-11-20T10:00:00Z", medium: String = "novel") =
        UpdatedSeries(id, name, medium, count, chapter, "Chapter $chapter", at)

    private fun feed(cursor: String, vararg series: UpdatedSeries) = UpdatesFeed(cursor, series.toList())

    private fun pending(id: Int, name: String, count: Int = 1, at: String? = "2026-11-20T10:00:00Z") = Pending(id, name, "novel", count, id * 100, at)

    private val running = State(scope = "s", cursor = "c1")

    // Decoding (the server's sample)

    @Test
    fun decodesTheContractSample() {
        val json = """{"cursor":"abc","series":[{"novel_id":12,"name":"Lord of Mysteries","medium":"novel","new_count":3,"chapter_id":4567,"chapter_label":"Chapter 101","latest_at":"2026-11-20T10:00:00Z"},{"name":"no id"}]}"""
        val decoded = NovarrJson.decodeFromString(UpdatesFeed.serializer(), json)
        assertEquals("abc", decoded.cursor)
        // The row without an id is dropped, not the whole answer.
        assertEquals(listOf(UpdatedSeries(12, "Lord of Mysteries", "novel", 3, 4567, "Chapter 101", "2026-11-20T10:00:00Z")), decoded.series)
    }

    @Test
    fun stateSurvivesStorage() {
        val state = State("s", "c9", listOf(pending(1, "A", 2)), t0)
        val json = NovarrJson.encodeToString(State.serializer(), state)
        assertEquals(state, NovarrJson.decodeFromString(State.serializer(), json))
    }

    // receive

    @Test
    fun firstRunOnlyStoresTheCursor() {
        val out = ChapterUpdates.receive(State(scope = "s"), feed("c1", series(1, "A")), t0, noon, foreground = false)
        assertEquals("c1", out.state.cursor)
        assertTrue(out.state.pending.isEmpty())
        assertEquals(Action.None, out.action)
        assertNull(out.state.lastAlertAt)
    }

    @Test
    fun emptyAnswerMovesTheCursorAndPostsNothing() {
        val state = running.copy(pending = listOf(pending(1, "A")))
        val out = ChapterUpdates.receive(state, feed("c2"), t0, noon, foreground = false)
        assertEquals("c2", out.state.cursor)
        assertEquals(state.pending, out.state.pending)
        assertEquals(Action.None, out.action)
    }

    @Test
    fun aMissingCursorKeepsTheOldOne() {
        val out = ChapterUpdates.receive(running, UpdatesFeed(null, emptyList()), t0, noon, foreground = false)
        assertEquals("c1", out.state.cursor)
        val blank = ChapterUpdates.receive(running, UpdatesFeed("  ", emptyList()), t0, noon, foreground = false)
        assertEquals("c1", blank.state.cursor)
    }

    @Test
    fun newSeriesPostAndAlertInDaytime() {
        val out = ChapterUpdates.receive(running, feed("c2", series(1, "A", 3)), t0, noon, foreground = false)
        assertEquals(Action.Post(alert = true), out.action)
        assertEquals(listOf(Pending(1, "A", "novel", 3, 100, "2026-11-20T10:00:00Z")), out.state.pending)
        assertEquals(t0, out.state.lastAlertAt)
    }

    @Test
    fun inTheForegroundNothingIsAdded() {
        val out = ChapterUpdates.receive(running, feed("c2", series(1, "A", 3)), t0, noon, foreground = true)
        assertEquals("c2", out.state.cursor)
        assertTrue(out.state.pending.isEmpty())
        assertEquals(Action.None, out.action)
    }

    @Test
    fun theSameAnswerAgainDoesNotRepost() {
        val state = running.copy(pending = listOf(Pending(1, "A", "novel", 3, 100, "2026-11-20T10:00:00Z")))
        val out = ChapterUpdates.receive(state, feed("c2", series(1, "A", 3)), t0, noon, foreground = false)
        assertEquals(Action.None, out.action)
    }

    @Test
    fun anUpdateToANotificationOnScreenIsSilent() {
        val state = running.copy(pending = listOf(pending(1, "A")))
        val out = ChapterUpdates.receive(state, feed("c2", series(2, "B")), t0, noon, foreground = false, showing = true)
        assertEquals(Action.Post(alert = false), out.action)
        assertNull(out.state.lastAlertAt)
    }

    // merge

    @Test
    fun mergeAddsAndReplaces() {
        val before = listOf(pending(1, "A", 2), pending(2, "B", 1))
        val merged = ChapterUpdates.merge(before, listOf(series(2, "B", 5, chapter = 999, at = "2026-11-21T00:00:00Z"), series(3, "C", 1)))
        assertEquals(listOf(1, 2, 3), merged.map { it.novelId })
        assertEquals(2, merged[0].newCount)
        assertEquals(5, merged[1].newCount)
        assertEquals(999, merged[1].chapterId)
        assertEquals("2026-11-21T00:00:00Z", merged[1].latestAt)
    }

    @Test
    fun mergeIgnoresZeroCountsAndKeepsANameWhenTheAnswerHasNone() {
        val merged = ChapterUpdates.merge(listOf(pending(1, "A")), listOf(series(1, "", 4), series(2, "B", 0)))
        assertEquals(listOf(1), merged.map { it.novelId })
        assertEquals("A", merged.single().name)
        assertEquals(4, merged.single().newCount)
    }

    @Test
    fun clearedThenNew() {
        val state = State("s", "c1", listOf(pending(1, "A"), pending(2, "B")), lastAlertAt = t0)
        val cleared = state.cleared()
        assertTrue(cleared.pending.isEmpty())
        assertEquals("c1", cleared.cursor)
        assertEquals(t0, cleared.lastAlertAt)
        // Something new an hour later: it starts a fresh list, silent (the last alert was within 6 hours).
        val soon = ChapterUpdates.receive(cleared, feed("c2", series(3, "C")), t0 + hour, noon, foreground = false)
        assertEquals(listOf(3), soon.state.pending.map { it.novelId })
        assertEquals(Action.Post(alert = false), soon.action)
        // Cleared again and new 6 hours after the alert: it may sound.
        val later = ChapterUpdates.receive(soon.state.cleared(), feed("c3", series(4, "D")), t0 + 6 * hour, noon, foreground = false)
        assertEquals(listOf(4), later.state.pending.map { it.novelId })
        assertEquals(Action.Post(alert = true), later.action)
        assertEquals(t0 + 6 * hour, later.state.lastAlertAt)
    }

    @Test
    fun anotherScopeStartsOver() {
        val state = State("server-a|1", "c1", listOf(pending(1, "A")), t0)
        assertEquals(State(scope = "server-a|1", cursor = "c1", pending = listOf(pending(1, "A")), lastAlertAt = t0), state.forScope("server-a|1"))
        assertEquals(State(scope = "server-b|1"), state.forScope("server-b|1"))
    }

    // Alerts: 6 hours and quiet hours

    @Test
    fun quietHoursEdges() {
        assertFalse(ChapterUpdates.isQuiet(LocalTime.of(21, 59)))
        assertTrue(ChapterUpdates.isQuiet(LocalTime.of(22, 0)))
        assertTrue(ChapterUpdates.isQuiet(LocalTime.of(0, 0)))
        assertTrue(ChapterUpdates.isQuiet(LocalTime.of(6, 59)))
        assertFalse(ChapterUpdates.isQuiet(LocalTime.of(7, 0)))
        assertTrue(ChapterUpdates.alertAllowed(null, t0, LocalTime.of(21, 59)))
        assertFalse(ChapterUpdates.alertAllowed(null, t0, LocalTime.of(22, 0)))
        assertFalse(ChapterUpdates.alertAllowed(null, t0, LocalTime.of(6, 59)))
        assertTrue(ChapterUpdates.alertAllowed(null, t0, LocalTime.of(7, 0)))
    }

    @Test
    fun sixHourRule() {
        assertTrue(ChapterUpdates.alertAllowed(null, t0, noon))
        assertFalse(ChapterUpdates.alertAllowed(t0, t0 + 6 * hour - 1, noon))
        assertTrue(ChapterUpdates.alertAllowed(t0, t0 + 6 * hour, noon))
        // The clock went back: allowed rather than stuck silent.
        assertTrue(ChapterUpdates.alertAllowed(t0, t0 - hour, noon))
    }

    @Test
    fun atNightItPostsSilentlyAndTheMorningDoesNotReAlertTheSameContent() {
        val night = ChapterUpdates.receive(running, feed("c2", series(1, "A", 2)), t0, LocalTime.of(23, 30), foreground = false)
        assertEquals(Action.Post(alert = false), night.action)
        assertNull(night.state.lastAlertAt)
        // Morning, nothing new: nothing posted, nothing sounds.
        val morning = ChapterUpdates.receive(night.state, feed("c3"), t0 + 8 * hour, LocalTime.of(7, 30), foreground = false)
        assertEquals(Action.None, morning.action)
        // Morning, the same series answered again unchanged: still nothing.
        val again = ChapterUpdates.receive(night.state, feed("c3", series(1, "A", 2)), t0 + 8 * hour, LocalTime.of(7, 30), foreground = false)
        assertEquals(Action.None, again.action)
        // Something new in the morning: it may sound.
        val news = ChapterUpdates.receive(night.state, feed("c3", series(2, "B")), t0 + 8 * hour, LocalTime.of(7, 30), foreground = false)
        assertEquals(Action.Post(alert = true), news.action)
    }

    @Test
    fun secondNewsWithinSixHoursIsSilent() {
        val first = ChapterUpdates.receive(running, feed("c2", series(1, "A")), t0, noon, foreground = false)
        assertEquals(Action.Post(alert = true), first.action)
        val second = ChapterUpdates.receive(first.state, feed("c3", series(2, "B")), t0 + 2 * hour, noon, foreground = false)
        assertEquals(Action.Post(alert = false), second.action)
        assertEquals(t0, second.state.lastAlertAt)
    }

    // Text

    @Test
    fun oneSeriesSingularAndPlural() {
        assertEquals("A", ChapterUpdates.title(listOf(pending(1, "A", 3))))
        assertEquals("3 new chapters", ChapterUpdates.text(listOf(pending(1, "A", 3))))
        assertEquals("1 new chapter", ChapterUpdates.text(listOf(pending(1, "A", 1))))
        // Manga reads the same.
        assertEquals("2 new chapters", ChapterUpdates.text(listOf(Pending(1, "M", "manga", 2))))
        assertEquals(1, ChapterUpdates.target(listOf(pending(1, "A"))))
    }

    @Test
    fun twoThreeAndSevenSeries() {
        // Most recent first.
        val two = listOf(pending(1, "A", at = "2026-11-20T09:00:00Z"), pending(2, "B", at = "2026-11-20T10:00:00Z"))
        assertEquals("New chapters in 2 series", ChapterUpdates.title(two))
        assertEquals("B and A", ChapterUpdates.text(two))
        assertNull(ChapterUpdates.target(two))

        val three = listOf(pending(1, "A", at = "2026-11-20T03:00:00Z"), pending(2, "B", at = "2026-11-20T02:00:00Z"), pending(3, "C", at = "2026-11-20T01:00:00Z"))
        assertEquals("New chapters in 3 series", ChapterUpdates.title(three))
        assertEquals("A, B and C", ChapterUpdates.text(three))

        val seven = (1..7).map { pending(it, "S$it", count = it, at = "2026-11-20T0$it:00:00Z") }
        assertEquals("New chapters in 7 series", ChapterUpdates.title(seven))
        assertEquals("S7, S6, S5 and 4 more", ChapterUpdates.text(seven))
        assertEquals(
            listOf("S7 · 7 new", "S6 · 6 new", "S5 · 5 new", "S4 · 4 new", "S3 · 3 new", "S2 · 2 new", "+1 more"),
            ChapterUpdates.lines(seven),
        )
    }

    @Test
    fun fourSeriesTextAndLines() {
        val four = listOf(pending(1, "A", at = "2026-11-20T04:00:00Z"), pending(2, "B", at = "2026-11-20T03:00:00Z"), pending(3, "C", at = "2026-11-20T02:00:00Z"), pending(4, "D", at = null))
        assertEquals("New chapters in 4 series", ChapterUpdates.title(four))
        assertEquals("A, B, C and 1 more", ChapterUpdates.text(four))
        // Undated last; no "+N more" under 7.
        assertEquals(listOf("A · 1 new", "B · 1 new", "C · 1 new", "D · 1 new"), ChapterUpdates.lines(four))
    }

    @Test
    fun namesJoin() {
        assertEquals("", ChapterUpdates.names(emptyList()))
        assertEquals("A", ChapterUpdates.names(listOf("A")))
        assertEquals("A and B", ChapterUpdates.names(listOf("A", "B")))
        assertEquals("A, B and C", ChapterUpdates.names(listOf("A", "B", "C")))
        assertEquals("A, B, C and 1 more", ChapterUpdates.names(listOf("A", "B", "C", "D")))
    }
}
