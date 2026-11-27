package studio.visns.novarr

import java.time.LocalDate
import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.ReadingStats
import studio.visns.novarr.api.StatsDay
import studio.visns.novarr.api.StatsHeatDay
import studio.visns.novarr.api.StatsWeek
import studio.visns.novarr.domain.StatsFormat

/** The Stats screen: decoding `GET /stats`, formatting, the heatmap layout, chart ticks and donut angles. */
class StatsTest {
    private val sample = """
        {
          "generated_at": "2026-11-27T09:40:00Z",
          "timezone": "Australia/Perth",
          "today": "2026-11-27",
          "range": 30,
          "headline": {
            "chapters_read": 1234, "words_read": 2500000, "series_finished": 12, "books_finished": 3,
            "audiobooks_finished": 2, "listening_seconds": 36000,
            "current_streak": 5, "best_streak": 21, "active_today": true,
            "chapters_today": 3, "words_today": 9000, "listening_seconds_today": 1200,
            "chapters_week": 20, "listening_seconds_week": 5400,
            "chapters_per_day": 4.2, "words_per_day": 9000.5, "listening_minutes_per_day": 12.3
          },
          "range_totals": {"days": 30, "chapters": 126, "words": 270015, "listening_seconds": 22140, "active_days": 18},
          "daily": [{"date": "2026-10-29", "chapters": 0, "words": 0, "listening_seconds": 0}],
          "weekly": [{"start": "2026-10-27", "chapters": 10, "words": 20000, "listening_seconds": 600}],
          "heatmap": {"start": "2025-11-24", "end": "2026-11-27", "max_minutes": 180,
                      "days": [{"date": "2025-11-24", "chapters": 0, "minutes": 0, "level": 0}]},
          "by_medium": [{"medium": "novel", "label": "Web novels", "chapters": 80, "words": 200000, "listening_seconds": 0, "minutes": 800, "share": 0.5},
                        {"medium": "manga", "label": "Manga", "chapters": 40, "words": 0, "listening_seconds": 0, "minutes": 200, "share": 0.125},
                        {"medium": "book", "label": "Books", "chapters": 6, "words": 70015, "listening_seconds": 0, "minutes": 280, "share": 0.175},
                        {"medium": "audiobook", "label": "Audiobooks", "chapters": 0, "words": 0, "listening_seconds": 22140, "minutes": 369, "share": 0.2}],
          "top_genres": [{"name": "Fantasy", "chapters": 60, "minutes": 700}],
          "top_series": [{"id": 12, "name": "Shadow Slave", "medium": "novel", "chapters": 40, "words": 120000, "last_read_at": "2026-11-27T08:00:00Z"}],
          "top_audiobooks": [{"id": 3, "title": "Project Hail Mary", "author": "Andy Weir", "novel_id": 55, "listening_seconds": 14000}],
          "hours": [{"hour": 0, "chapters": 1, "minutes": 10}],
          "weekdays": [{"day": 1, "label": "Mon", "chapters": 20, "minutes": 140}],
          "year": {"year": 2026, "chapters": 900, "words": 2000000, "listening_seconds": 30000,
                   "series_finished": 8, "books_finished": 2, "audiobooks_finished": 1, "active_days": 200,
                   "top_genre": "Fantasy", "top_series": {"id": 12, "name": "Shadow Slave"},
                   "busiest_month": {"month": "2026-11", "label": "November", "chapters": 140}},
          "milestones": [{"key": "chapters_100", "title": "Century", "detail": "Read 100 chapters", "earned": true, "progress": 1.0, "current": 1234, "target": 100}]
        }
    """.trimIndent()

    private fun decode(json: String) = NovarrJson.decodeFromString(ReadingStats.serializer(), json)

    // Decoding

    @Test
    fun decodesTheSampleAnswer() {
        val stats = decode(sample)
        assertEquals("2026-11-27", stats.today)
        assertEquals(30, stats.range)
        assertEquals(1234, stats.headline.chaptersRead)
        assertEquals(2_500_000L, stats.headline.wordsRead)
        assertEquals(36_000L, stats.headline.listeningSeconds)
        assertTrue(stats.headline.activeToday)
        assertEquals(1200L, stats.headline.listeningSecondsToday)
        assertEquals(9000.5, stats.headline.wordsPerDay, 1e-9)
        assertEquals(18, stats.rangeTotals.activeDays)
        assertEquals(270_015L, stats.rangeTotals.words)
        assertEquals("2026-10-29", stats.daily.single().date)
        assertEquals("2026-10-27", stats.weekly.single().start)
        assertEquals("2025-11-24", stats.heatmap.start)
        assertEquals(180, stats.heatmap.maxMinutes)
        assertEquals(listOf("novel", "manga", "book", "audiobook"), stats.byMedium.map { it.medium })
        assertEquals(0.125, stats.byMedium[1].share, 1e-9)
        assertEquals("Fantasy", stats.topGenres.single().name)
        assertEquals(12, stats.topSeries.single().id)
        assertEquals(55, stats.topAudiobooks.single().novelId)
        assertEquals("Mon", stats.weekdays.single().label)
        assertEquals("Shadow Slave", stats.year?.topSeries?.name)
        assertEquals("November", stats.year?.busiestMonth?.label)
        assertEquals(100L, stats.milestones.single().target)
        assertFalse(stats.isEmpty)
    }

    @Test
    fun decodesAnEmptyObjectWithDefaults() {
        val stats = decode("{}")
        assertEquals(30, stats.range)
        assertEquals(0, stats.headline.chaptersRead)
        assertTrue(stats.daily.isEmpty())
        assertTrue(stats.heatmap.days.isEmpty())
        assertNull(stats.heatmap.start)
        assertNull(stats.year)
        assertTrue(stats.isEmpty)
    }

    @Test
    fun decodesMissingFieldsNullsAndUnknownKeys() {
        val stats = decode(
            """
            {"range": 90, "headline": {"chapters_read": 4, "best_streak": null}, "future_field": [1,2],
             "daily": [{"date": "2026-11-27"}], "heatmap": {"days": [{"date": "2026-11-27", "level": 3}]},
             "year": {"year": 2026, "top_genre": null, "top_series": null, "busiest_month": null},
             "by_medium": [{"medium": "novel"}], "milestones": [{"title": "First"}]}
            """.trimIndent(),
        )
        assertEquals(90, stats.range)
        assertEquals(4, stats.headline.chaptersRead)
        assertEquals(0, stats.headline.bestStreak)
        assertEquals(0, stats.daily.single().chapters)
        assertEquals(3, stats.heatmap.days.single().level)
        assertNull(stats.year?.topGenre)
        assertNull(stats.year?.topSeries)
        assertNull(stats.year?.busiestMonth)
        assertEquals(0.0, stats.byMedium.single().share, 0.0)
        assertFalse(stats.milestones.single().earned)
        assertFalse(stats.isEmpty)
    }

    @Test
    fun anOlderServerIsUnsupported() {
        assertTrue(StatsFormat.isUnsupported(ApiError.Http(404, null)))
        assertFalse(StatsFormat.isUnsupported(ApiError.Http(500, null)))
        assertFalse(StatsFormat.isUnsupported(ApiError.TimedOut))
        assertFalse(StatsFormat.isUnsupported(null))
        assertEquals("This server doesn't have stats yet — update Novarr.", StatsFormat.failureMessage(ApiError.Http(404, "Not Found")))
        assertEquals(ApiError.TimedOut.userMessage, StatsFormat.failureMessage(ApiError.TimedOut))
    }

    // Formatting

    @Test
    fun compactNumbers() {
        assertEquals("0", StatsFormat.compact(0L))
        assertEquals("950", StatsFormat.compact(950L))
        assertEquals("999", StatsFormat.compact(999L))
        assertEquals("1K", StatsFormat.compact(1_000L))
        assertEquals("1.2K", StatsFormat.compact(1_234L))
        assertEquals("12K", StatsFormat.compact(12_345L))
        assertEquals("270K", StatsFormat.compact(270_015L))
        assertEquals("1M", StatsFormat.compact(999_950L))
        assertEquals("2.5M", StatsFormat.compact(2_500_000L))
        assertEquals("3.4M", StatsFormat.compact(3_400_000L))
        assertEquals("1.1B", StatsFormat.compact(1_100_000_000L))
        assertEquals("-1.5K", StatsFormat.compact(-1_500L))
    }

    @Test
    fun durations() {
        assertEquals("0 min", StatsFormat.duration(0))
        assertEquals("0 min", StatsFormat.duration(59))
        assertEquals("45 min", StatsFormat.duration(45 * 60L))
        assertEquals("1 h", StatsFormat.duration(3600))
        assertEquals("3 h 20 min", StatsFormat.duration(3 * 3600L + 20 * 60))
        assertEquals("99 h 59 min", StatsFormat.duration(99 * 3600L + 59 * 60))
        assertEquals("120 h", StatsFormat.duration(120 * 3600L + 30 * 60))
        assertEquals("0 min", StatsFormat.duration(-5))
    }

    @Test
    fun hoursDecimalsAndRates() {
        assertEquals("10.0", StatsFormat.hours(36_000))
        assertEquals("1.5", StatsFormat.hours(5_400))
        assertEquals("0.0", StatsFormat.hours(0))
        assertEquals("4.2", StatsFormat.decimal(4.2))
        assertEquals("12", StatsFormat.decimal(12.0))
        assertEquals("0", StatsFormat.decimal(Double.NaN))
        assertEquals(4.2, StatsFormat.perDay(126.0, 30), 1e-9)
        assertEquals(0.0, StatsFormat.perDay(10.0, 0), 0.0)
        assertEquals(20L, StatsFormat.minutes(1_200))
        assertEquals("1 day", StatsFormat.days(1))
        assertEquals("21 days", StatsFormat.days(21))
        assertEquals("13%", StatsFormat.percent(0.125))
        assertEquals("1 year", StatsFormat.rangeLabel(365))
        assertEquals("90 days", StatsFormat.rangeLabel(90))
        assertEquals("27 Nov", StatsFormat.shortDate("2026-11-27", Locale.ENGLISH))
    }

    // Heatmap

    private fun heatDays(from: LocalDate, through: LocalDate): List<StatsHeatDay> =
        generateSequence(from) { it.plusDays(1) }.takeWhile { !it.isAfter(through) }
            .map { StatsHeatDay(it.toString(), level = it.dayOfMonth % 5) }.toList()

    @Test
    fun heatmapIsWeekColumnsMondayFirstWithThePartialWeekPadded() {
        val start = LocalDate.parse("2025-11-24") // a Monday
        val end = LocalDate.parse("2026-11-27") // a Friday
        val weeks = StatsFormat.heatmapWeeks(start.toString(), heatDays(start, end))
        assertEquals(53, weeks.size)
        assertTrue(weeks.all { it.cells.size == 7 && it.start.dayOfWeek.value == 1 })
        assertEquals("2025-11-24", weeks.first().cells.first()?.date)
        assertEquals("2025-11-30", weeks.first().cells.last()?.date)
        val last = weeks.last()
        assertEquals(LocalDate.parse("2026-11-23"), last.start)
        assertEquals("2026-11-27", last.cells[4]?.date)
        assertNull(last.cells[5])
        assertNull(last.cells[6])
    }

    @Test
    fun heatmapStartNotAMondayAndGapsAreHandled() {
        // Wednesday start: the week begins on its Monday, the first two cells are padding; a missing day is null.
        val days = listOf(StatsHeatDay("2026-11-04", level = 1), StatsHeatDay("2026-11-06", level = 2), StatsHeatDay("2026-11-10", level = 4))
        val weeks = StatsFormat.heatmapWeeks("2026-11-04", days)
        assertEquals(2, weeks.size)
        assertEquals(LocalDate.parse("2026-11-02"), weeks[0].start)
        assertNull(weeks[0].cells[0])
        assertEquals(1, weeks[0].cells[2]?.level)
        assertNull(weeks[0].cells[3])
        assertEquals(2, weeks[0].cells[4]?.level)
        assertEquals(4, weeks[1].cells[1]?.level)
        // No start given: from the first day; nothing at all: no weeks.
        assertEquals(2, StatsFormat.heatmapWeeks(null, days).size)
        assertTrue(StatsFormat.heatmapWeeks("2026-11-02", emptyList()).isEmpty())
    }

    @Test
    fun monthLabelsSitWhereTheMonthChanges() {
        val start = LocalDate.parse("2025-11-24")
        val weeks = StatsFormat.heatmapWeeks(start.toString(), heatDays(start, LocalDate.parse("2026-11-27")))
        val labels = StatsFormat.monthLabels(weeks, Locale.ENGLISH)
        // The first column (Nov 2025) is dropped: December starts one column later.
        assertEquals("Dec", labels.first().label)
        assertEquals(1, labels.first().week)
        assertEquals("Nov", labels.last().label)
        assertEquals(12, labels.size)
        assertTrue(labels.zipWithNext().all { (a, b) -> b.week > a.week })
        // A first month with room keeps its label.
        val roomy = StatsFormat.heatmapWeeks("2026-01-05", heatDays(LocalDate.parse("2026-01-05"), LocalDate.parse("2026-02-20")))
        assertEquals(listOf("Jan" to 0, "Feb" to 4), StatsFormat.monthLabels(roomy, Locale.ENGLISH).map { it.label to it.week })
    }

    // Charts

    @Test
    fun niceTicks() {
        assertEquals(listOf(0.0, 1.0), StatsFormat.niceTicks(0.0))
        assertEquals(listOf(0.0, 1.0, 2.0, 3.0), StatsFormat.niceTicks(3.0))
        assertEquals(listOf(0.0, 2.0, 4.0, 6.0, 8.0), StatsFormat.niceTicks(7.0))
        assertEquals(listOf(0.0, 5.0, 10.0), StatsFormat.niceTicks(10.0))
        assertEquals(listOf(0.0, 50.0, 100.0, 150.0, 200.0), StatsFormat.niceTicks(180.0))
        assertEquals(listOf(0.0, 200.0, 400.0, 600.0), StatsFormat.niceTicks(401.0))
        assertEquals(listOf(0.0, 1.0), StatsFormat.niceTicks(0.3))
        assertEquals(listOf(0.0, 0.1, 0.2, 0.3), StatsFormat.niceTicks(0.3, whole = false))
        // Never more than 4 bands, always reaching the maximum, steps of 1/2/5 × 10^k.
        for (max in listOf(1.0, 9.0, 11.0, 23.0, 99.0, 101.0, 999.0, 12_345.0)) {
            val ticks = StatsFormat.niceTicks(max)
            assertTrue("$max: $ticks", ticks.size - 1 in 1..4)
            assertTrue("$max: $ticks", ticks.last() >= max)
            val step = ticks[1]
            val mantissa = step / Math.pow(10.0, Math.floor(Math.log10(step)))
            assertTrue("$max: $step", listOf(1.0, 2.0, 5.0).any { Math.abs(it - mantissa) < 1e-9 })
        }
    }

    @Test
    fun donutSweepsSkipZeroesAndSumTo360() {
        val sweeps = StatsFormat.donutSweeps(listOf(0.5, 0.125, 0.0, 0.175, 0.2))
        assertEquals(listOf(0, 1, 3, 4), sweeps.map { it.index })
        assertEquals(360f, sweeps.sumOf { it.sweep.toDouble() }.toFloat(), 1e-3f)
        assertEquals(0f, sweeps.first().start, 0f)
        assertEquals(180f, sweeps[0].sweep, 1e-3f)
        assertEquals(180f, sweeps[1].start, 1e-3f)
        assertEquals(45f, sweeps[1].sweep, 1e-3f)
        // Each slice starts where the previous ended.
        assertTrue(sweeps.zipWithNext().all { (a, b) -> Math.abs(a.start + a.sweep - b.start) < 1e-3 })
        // Shares not summing to 1 are scaled; none at all: no slices.
        val scaled = StatsFormat.donutSweeps(listOf(1.0, 1.0))
        assertEquals(listOf(180f, 180f), scaled.map { it.sweep })
        assertTrue(StatsFormat.donutSweeps(listOf(0.0, 0.0)).isEmpty())
        assertTrue(StatsFormat.donutSweeps(emptyList()).isEmpty())
        assertEquals(360f, StatsFormat.donutSweeps(listOf(0.0, 0.3)).single().sweep, 0f)
    }

    @Test
    fun aYearChartsWeeksAndShorterRangesChartDays() {
        val daily = (1..30).map { StatsDay("2026-11-%02d".format(it), chapters = it) }
        val weekly = listOf(StatsWeek("2026-11-02", chapters = 7), StatsWeek("2026-11-09", chapters = 8))
        val month = StatsFormat.series(ReadingStats(range = 30, daily = daily, weekly = weekly))
        assertFalse(month.weekly)
        assertEquals(30, month.points.size)
        val quarter = StatsFormat.series(ReadingStats(range = 90, daily = daily, weekly = weekly))
        assertFalse(quarter.weekly)
        val year = StatsFormat.series(ReadingStats(range = 365, daily = daily, weekly = weekly))
        assertTrue(year.weekly)
        assertEquals(listOf(7, 8), year.points.map { it.chapters })
        assertEquals("2026-11-02", year.points.first().date)
        // A year without weeks (an older answer) falls back to days.
        assertFalse(StatsFormat.series(ReadingStats(range = 365, daily = daily)).weekly)
    }
}
