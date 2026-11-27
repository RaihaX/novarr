package studio.visns.novarr.domain

import java.time.DayOfWeek
import java.time.LocalDate
import java.time.format.TextStyle
import java.util.Locale
import kotlin.math.abs
import kotlin.math.ceil
import kotlin.math.floor
import kotlin.math.log10
import kotlin.math.pow
import kotlin.math.roundToLong
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.ReadingStats
import studio.visns.novarr.api.StatsHeatDay

/**
 * Pure helpers for the Stats screen: number and duration formatting, the
 * heatmap's week columns, chart axes and donut angles. No Android types, so
 * all of it is unit-tested (StatsTest).
 */
object StatsFormat {
    /** The range picker's choices, in days (the server's `range`). */
    val RANGES = listOf(30, 90, 365)

    fun rangeLabel(range: Int): String = if (range >= 365) "1 year" else "$range days"

    const val UNSUPPORTED = "This server doesn't have stats yet — update Novarr."

    /** An older server answers 404: it has no stats endpoint. */
    fun isUnsupported(error: ApiError?): Boolean = error is ApiError.Http && error.status == 404

    /** What the screen says when there is no answer (and nothing saved). */
    fun failureMessage(error: ApiError?): String = when {
        isUnsupported(error) -> UNSUPPORTED
        error != null -> error.userMessage
        else -> "Couldn't load your stats."
    }

    // Numbers

    /** 950, 1.2K, 12K, 3.4M, 1.1B: one decimal below 10 of a unit, a trailing ".0" dropped. */
    fun compact(value: Long): String {
        val sign = if (value < 0) "-" else ""
        val n = abs(value)
        if (n < 1_000) return "$sign$n"
        val units = listOf("K" to 1e3, "M" to 1e6, "B" to 1e9, "T" to 1e12)
        var index = units.indexOfLast { n >= it.second }
        while (true) {
            val (suffix, size) = units[index]
            val scaled = n / size
            val text = if (scaled < 10) oneDecimal(scaled) else scaled.roundToLong().toString()
            // 999,950 rounds to "1000K": that is "1M".
            if (text.toDouble() >= 1000 && index < units.lastIndex) {
                index++
                continue
            }
            return "$sign$text$suffix"
        }
    }

    fun compact(value: Int): String = compact(value.toLong())

    /** "4.2", "12" (one decimal, a trailing ".0" dropped). */
    fun decimal(value: Double): String = if (value.isNaN() || value.isInfinite()) "0" else oneDecimal(value)

    private fun oneDecimal(value: Double): String {
        val rounded = Math.round(value * 10) / 10.0
        val whole = rounded.toLong()
        return if (rounded == whole.toDouble()) whole.toString() else String.format(Locale.ROOT, "%.1f", rounded)
    }

    /** "1,234" (grouped, in the device's locale). */
    fun grouped(value: Long, locale: Locale = Locale.getDefault()): String = java.text.NumberFormat.getIntegerInstance(locale).format(value)

    // Durations

    /** "0 min", "45 min", "3 h", "3 h 20 min", and from 100 hours just "120 h". */
    fun duration(seconds: Long): String {
        val totalMinutes = (seconds.coerceAtLeast(0) / 60)
        val hours = totalMinutes / 60
        val minutes = totalMinutes % 60
        return when {
            hours == 0L -> "$minutes min"
            hours >= 100 || minutes == 0L -> "$hours h"
            else -> "$hours h $minutes min"
        }
    }

    /** Hours with one decimal: 36000 s → "10.0", 5400 s → "1.5". */
    fun hours(seconds: Long): String = String.format(Locale.ROOT, "%.1f", seconds.coerceAtLeast(0) / 3600.0)

    /** Whole minutes (rounded down). */
    fun minutes(seconds: Long): Long = seconds.coerceAtLeast(0) / 60

    /** Per day over [days] (0 without days). */
    fun perDay(total: Double, days: Int): Double = if (days <= 0) 0.0 else total / days

    // Dates

    fun date(iso: String?): LocalDate? = iso?.takeIf { it.length >= 10 }?.let { runCatching { LocalDate.parse(it.substring(0, 10)) }.getOrNull() }

    /** "27 Nov". */
    fun shortDate(iso: String?, locale: Locale = Locale.getDefault()): String {
        val day = date(iso) ?: return iso.orEmpty()
        return "${day.dayOfMonth} ${day.month.getDisplayName(TextStyle.SHORT, locale)}"
    }

    // Heatmap

    /** One heatmap column: the week starting [start] (a Monday), Monday first; null cells are padding. */
    data class HeatWeek(val start: LocalDate, val cells: List<StatsHeatDay?>)

    /**
     * The heatmap as week columns from [startIso] (moved back to its Monday
     * if it is not one) through the last day given; each week has 7 cells
     * Monday first, days not in the list (after today, or missing) are null.
     */
    fun heatmapWeeks(startIso: String?, days: List<StatsHeatDay>): List<HeatWeek> {
        val byDate = days.mapNotNull { day -> date(day.date)?.let { it to day } }.toMap()
        if (byDate.isEmpty()) return emptyList()
        val first = date(startIso) ?: byDate.keys.min()
        val start = first.minusDays((first.dayOfWeek.value - DayOfWeek.MONDAY.value).toLong())
        val last = byDate.keys.max()
        if (last.isBefore(start)) return emptyList()
        val weeks = mutableListOf<HeatWeek>()
        var monday = start
        while (!monday.isAfter(last)) {
            weeks += HeatWeek(monday, (0L until 7L).map { byDate[monday.plusDays(it)] })
            monday = monday.plusWeeks(1)
        }
        return weeks
    }

    data class MonthLabel(val week: Int, val label: String)

    /**
     * Month names over the heatmap's columns: at the first column and at each
     * column whose Monday falls in a new month. A first label crowded by the
     * next one (fewer than [minGap] columns apart) is dropped, as GitHub does.
     */
    fun monthLabels(weeks: List<HeatWeek>, locale: Locale = Locale.getDefault(), minGap: Int = 3): List<MonthLabel> {
        val labels = mutableListOf<MonthLabel>()
        weeks.forEachIndexed { i, week ->
            if (i == 0 || week.start.month != weeks[i - 1].start.month) {
                labels += MonthLabel(i, week.start.month.getDisplayName(TextStyle.SHORT, locale))
            }
        }
        if (labels.size >= 2 && labels[1].week - labels[0].week < minGap) labels.removeAt(0)
        return labels
    }

    // Charts

    /**
     * Y-axis ticks from 0 up to at least [max]: steps of 1, 2 or 5 × 10^k, at
     * most [maxBands] bands. [whole]: never a step below 1 (counts).
     */
    fun niceTicks(max: Double, maxBands: Int = 4, whole: Boolean = true): List<Double> {
        val bands = maxBands.coerceAtLeast(1)
        if (max.isNaN() || max <= 0) return listOf(0.0, 1.0)
        val raw = max / bands
        val magnitude = 10.0.pow(floor(log10(raw)))
        var step = listOf(1.0, 2.0, 5.0, 10.0).map { it * magnitude }.first { it >= raw - 1e-9 }
        if (whole && step < 1) step = 1.0
        val count = ceil(max / step - 1e-9).toInt().coerceAtLeast(1)
        return (0..count).map { clean(it * step) }
    }

    private fun clean(value: Double): Double = Math.round(value * 1e6) / 1e6

    /** One slice of the donut: [index] into the input, angles in degrees from 12 o'clock. */
    data class Sweep(val index: Int, val start: Float, val sweep: Float)

    /** Donut slices from shares: zero (or negative) shares skipped, the rest scaled to exactly 360°. */
    fun donutSweeps(shares: List<Double>): List<Sweep> {
        val positive = shares.withIndex().filter { it.value > 0 && !it.value.isNaN() }
        val total = positive.sumOf { it.value }
        if (total <= 0) return emptyList()
        var start = 0f
        return positive.mapIndexed { i, (index, share) ->
            val sweep = if (i == positive.lastIndex) 360f - start else (share / total * 360).toFloat()
            Sweep(index, start, sweep).also { start += sweep }
        }
    }

    /** Percent of a share, rounded: 0.125 → "13%". */
    fun percent(share: Double): String = "${Math.round(share.coerceIn(0.0, 1.0) * 100)}%"

    /** A bar of the chapters / listening charts: a day, or a week from its Monday. */
    data class Point(val date: String, val chapters: Int, val words: Long, val listeningSeconds: Long)

    data class Series(val weekly: Boolean, val points: List<Point>)

    /** Longer ranges (more than 90 days) chart weeks so bars stay readable; days otherwise. */
    fun series(stats: ReadingStats): Series {
        val weekly = stats.range > 90 && stats.weekly.isNotEmpty()
        return if (weekly) {
            Series(true, stats.weekly.map { Point(it.start, it.chapters, it.words, it.listeningSeconds) })
        } else {
            Series(false, stats.daily.map { Point(it.date, it.chapters, it.words, it.listeningSeconds) })
        }
    }

    /** "5 days" / "1 day". */
    fun days(count: Int): String = if (count == 1) "1 day" else "$count days"
}
