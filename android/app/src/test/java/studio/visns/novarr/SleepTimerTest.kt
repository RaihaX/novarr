package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.AudiobookChapter
import studio.visns.novarr.api.AudiobookPart
import studio.visns.novarr.domain.AudiobookTimeline
import studio.visns.novarr.domain.SleepTimer
import studio.visns.novarr.domain.SleepTimer.Decision

class SleepTimerTest {
    private val timeline = AudiobookTimeline.from(
        listOf(AudiobookPart(0, "u", durationMs = 1_000_000, startMs = 0)),
        listOf(AudiobookChapter(0, "A", 0, 300_000), AudiobookChapter(1, "B", 300_000, 1_000_000)),
    )

    @Test
    fun offNeverStops() {
        assertEquals(Decision.Keep, SleepTimer.decide(SleepTimer.Off, 10_000_000, 999_999))
        assertNull(SleepTimer.label(SleepTimer.Off, 0))
    }

    @Test
    fun aDurationRunsFadesAndStops() {
        val timer = SleepTimer.minutes(15, now = 1_000)
        assertEquals(1_000 + 15 * 60_000L, timer.endsAt)
        assertEquals(Decision.Keep, SleepTimer.decide(timer, 1_000 + 14 * 60_000L, 0))
        val fade = SleepTimer.decide(timer, timer.endsAt - 5_000, 0)
        assertTrue(fade is Decision.Fade && fade.volume in 0.4f..0.6f)
        assertEquals(Decision.Stop, SleepTimer.decide(timer, timer.endsAt, 0))
        assertEquals(Decision.Stop, SleepTimer.decide(timer, timer.endsAt + 60_000, 0))
    }

    @Test
    fun minutesAreKeptWithin5And120() {
        assertEquals(5, SleepTimer.minutes(1, 0).minutes)
        assertEquals(120, SleepTimer.minutes(500, 0).minutes)
        assertEquals(120 * 60_000L, SleepTimer.minutes(500, 0).endsAt)
    }

    @Test
    fun remainingAndLabels() {
        val timer = SleepTimer.minutes(30, now = 0)
        assertEquals(30 * 60_000L, SleepTimer.remaining(timer, 0))
        assertEquals(0L, SleepTimer.remaining(timer, 99 * 60_000L))
        assertEquals("30 min", SleepTimer.label(timer, 0))
        assertEquals("12 min", SleepTimer.label(timer, 30 * 60_000L - 11 * 60_000L - 30_000))
        assertEquals("45 s", SleepTimer.label(timer, 30 * 60_000L - 45_000))
        assertEquals("End of chapter", SleepTimer.label(SleepTimer.EndOfChapter(5), 0))
        assertNull(SleepTimer.remaining(SleepTimer.EndOfChapter(5), 0))
    }

    @Test
    fun extendingAddsFromTheEndOrFromNowAndIsCapped() {
        val timer = SleepTimer.minutes(10, now = 0)
        val extended = SleepTimer.extend(timer, 5, now = 60_000) as SleepTimer.Duration
        assertEquals(15 * 60_000L, extended.endsAt)
        // Already run out: five minutes from now.
        val late = SleepTimer.extend(timer, 5, now = 20 * 60_000L) as SleepTimer.Duration
        assertEquals(25 * 60_000L, late.endsAt)
        // Never more than 120 minutes ahead.
        val capped = SleepTimer.extend(SleepTimer.minutes(120, 0), 30, now = 0) as SleepTimer.Duration
        assertEquals(120 * 60_000L, capped.endsAt)
        // Only duration timers extend.
        assertEquals(SleepTimer.Off, SleepTimer.extend(SleepTimer.Off, 5, 0))
    }

    @Test
    fun endOfChapterStopsAtTheChapterThatWasPlaying() {
        val timer = SleepTimer.endOfChapter(timeline, positionMs = 100_000)
        assertEquals(300_000L, timer.chapterEndMs)
        assertEquals(Decision.Keep, SleepTimer.decide(timer, 0, 250_000))
        assertEquals(Decision.Stop, SleepTimer.decide(timer, 0, 299_800))
        assertEquals(Decision.Stop, SleepTimer.decide(timer, 0, 300_500))
    }

    @Test
    fun aSeekMovesAnEndOfChapterTimerToTheNewChapter() {
        val timer = SleepTimer.endOfChapter(timeline, positionMs = 100_000)
        val moved = SleepTimer.retarget(timer, timeline, 400_000) as SleepTimer.EndOfChapter
        assertEquals(1_000_000L, moved.chapterEndMs)
        assertEquals(Decision.Keep, SleepTimer.decide(moved, 0, 400_000))
        // Duration timers ignore seeks.
        val duration = SleepTimer.minutes(10, 0)
        assertEquals(duration, SleepTimer.retarget(duration, timeline, 999))
    }
}
