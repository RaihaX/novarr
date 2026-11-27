package studio.visns.novarr.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

// The reader's own stats (`GET /api/v1/stats?range=30|90|365`). Every field is
// defaulted, so an older or newer server (or a missing block) never breaks
// decoding: what is absent simply reads as zero / empty.

@Serializable
data class ReadingStats(
    @SerialName("generated_at") val generatedAt: String? = null,
    val timezone: String? = null,
    /** The server's today, `YYYY-MM-DD` in [timezone]. */
    val today: String? = null,
    val range: Int = 30,
    val headline: StatsHeadline = StatsHeadline(),
    @SerialName("range_totals") val rangeTotals: StatsRangeTotals = StatsRangeTotals(),
    /** Exactly `range` days, oldest first. */
    val daily: List<StatsDay> = emptyList(),
    /** Monday-start weeks covering the range. */
    val weekly: List<StatsWeek> = emptyList(),
    val heatmap: StatsHeatmap = StatsHeatmap(),
    @SerialName("by_medium") val byMedium: List<StatsMedium> = emptyList(),
    @SerialName("top_genres") val topGenres: List<StatsGenre> = emptyList(),
    @SerialName("top_series") val topSeries: List<StatsSeries> = emptyList(),
    @SerialName("top_audiobooks") val topAudiobooks: List<StatsAudiobook> = emptyList(),
    /** 24 entries, hour 0–23. */
    val hours: List<StatsHour> = emptyList(),
    /** 7 entries, 1 = Monday … 7 = Sunday. */
    val weekdays: List<StatsWeekday> = emptyList(),
    val year: StatsYear? = null,
    val milestones: List<StatsMilestone> = emptyList(),
) {
    /** Nothing read or listened to at all (in the range and ever). */
    val isEmpty: Boolean
        get() = headline.chaptersRead == 0 && headline.listeningSeconds == 0L && headline.wordsRead == 0L &&
            rangeTotals.chapters == 0 && rangeTotals.listeningSeconds == 0L &&
            daily.all { it.chapters == 0 && it.listeningSeconds == 0L } &&
            heatmap.days.all { it.level == 0 && it.chapters == 0 && it.minutes == 0 }
}

@Serializable
data class StatsHeadline(
    @SerialName("chapters_read") val chaptersRead: Int = 0,
    @SerialName("words_read") val wordsRead: Long = 0,
    @SerialName("series_finished") val seriesFinished: Int = 0,
    @SerialName("books_finished") val booksFinished: Int = 0,
    @SerialName("audiobooks_finished") val audiobooksFinished: Int = 0,
    @SerialName("listening_seconds") val listeningSeconds: Long = 0,
    @SerialName("current_streak") val currentStreak: Int = 0,
    @SerialName("best_streak") val bestStreak: Int = 0,
    @SerialName("active_today") val activeToday: Boolean = false,
    @SerialName("chapters_today") val chaptersToday: Int = 0,
    @SerialName("words_today") val wordsToday: Long = 0,
    @SerialName("listening_seconds_today") val listeningSecondsToday: Long = 0,
    @SerialName("chapters_week") val chaptersWeek: Int = 0,
    @SerialName("listening_seconds_week") val listeningSecondsWeek: Long = 0,
    @SerialName("chapters_per_day") val chaptersPerDay: Double = 0.0,
    @SerialName("words_per_day") val wordsPerDay: Double = 0.0,
    @SerialName("listening_minutes_per_day") val listeningMinutesPerDay: Double = 0.0,
)

@Serializable
data class StatsRangeTotals(
    val days: Int = 0,
    val chapters: Int = 0,
    val words: Long = 0,
    @SerialName("listening_seconds") val listeningSeconds: Long = 0,
    @SerialName("active_days") val activeDays: Int = 0,
)

@Serializable
data class StatsDay(
    val date: String = "",
    val chapters: Int = 0,
    val words: Long = 0,
    @SerialName("listening_seconds") val listeningSeconds: Long = 0,
)

@Serializable
data class StatsWeek(
    val start: String = "",
    val chapters: Int = 0,
    val words: Long = 0,
    @SerialName("listening_seconds") val listeningSeconds: Long = 0,
)

@Serializable
data class StatsHeatmap(
    /** A Monday. */
    val start: String? = null,
    /** Today. */
    val end: String? = null,
    @SerialName("max_minutes") val maxMinutes: Int = 0,
    val days: List<StatsHeatDay> = emptyList(),
)

@Serializable
data class StatsHeatDay(
    val date: String = "",
    val chapters: Int = 0,
    val minutes: Int = 0,
    /** 0..4: the shade. */
    val level: Int = 0,
)

@Serializable
data class StatsMedium(
    val medium: String = "",
    val label: String = "",
    val chapters: Int = 0,
    val words: Long = 0,
    @SerialName("listening_seconds") val listeningSeconds: Long = 0,
    val minutes: Int = 0,
    /** 0..1; all mediums sum to ~1 (or all 0). */
    val share: Double = 0.0,
)

@Serializable
data class StatsGenre(
    val name: String = "",
    val chapters: Int = 0,
    val minutes: Int = 0,
)

@Serializable
data class StatsSeries(
    val id: Int = 0,
    val name: String = "",
    val medium: String? = null,
    val chapters: Int = 0,
    val words: Long = 0,
    @SerialName("last_read_at") val lastReadAt: String? = null,
)

@Serializable
data class StatsAudiobook(
    val id: Int = 0,
    val title: String = "",
    val author: String? = null,
    @SerialName("novel_id") val novelId: Int? = null,
    @SerialName("listening_seconds") val listeningSeconds: Long = 0,
)

@Serializable
data class StatsHour(
    val hour: Int = 0,
    val chapters: Int = 0,
    val minutes: Int = 0,
)

@Serializable
data class StatsWeekday(
    /** 1 = Monday … 7 = Sunday. */
    val day: Int = 0,
    val label: String = "",
    val chapters: Int = 0,
    val minutes: Int = 0,
)

@Serializable
data class StatsYear(
    val year: Int = 0,
    val chapters: Int = 0,
    val words: Long = 0,
    @SerialName("listening_seconds") val listeningSeconds: Long = 0,
    @SerialName("series_finished") val seriesFinished: Int = 0,
    @SerialName("books_finished") val booksFinished: Int = 0,
    @SerialName("audiobooks_finished") val audiobooksFinished: Int = 0,
    @SerialName("active_days") val activeDays: Int = 0,
    @SerialName("top_genre") val topGenre: String? = null,
    @SerialName("top_series") val topSeries: StatsSeriesRef? = null,
    @SerialName("busiest_month") val busiestMonth: StatsMonth? = null,
)

@Serializable
data class StatsSeriesRef(
    val id: Int = 0,
    val name: String = "",
)

@Serializable
data class StatsMonth(
    /** `YYYY-MM`. */
    val month: String = "",
    val label: String = "",
    val chapters: Int = 0,
)

@Serializable
data class StatsMilestone(
    val key: String = "",
    val title: String = "",
    val detail: String = "",
    val earned: Boolean = false,
    /** 0..1. */
    val progress: Double = 0.0,
    val current: Long = 0,
    val target: Long = 0,
)
