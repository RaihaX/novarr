package studio.visns.novarr.domain

import java.time.Instant
import java.time.LocalTime
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import studio.visns.novarr.api.UpdatedSeries
import studio.visns.novarr.api.UpdatesFeed

/**
 * The one combined "new chapters" notification, as pure logic: what the
 * background check keeps between runs ([State]), how a server answer is
 * merged into it, what the notification says, and whether it may make a
 * sound. The app side (ChapterUpdatesSync, Notifications) only stores the
 * state and posts what [receive] decides.
 *
 * Never spam: there is exactly one notification; it is re-posted only when
 * its content changed; it alerts at most once per [ALERT_SPACING_MS] and
 * never in quiet hours ([QUIET_START]–[QUIET_END], local time), when it is
 * posted silently instead. Content already shown never alerts again.
 */
object ChapterUpdates {
    /** At most one sound or vibration per 6 hours. */
    const val ALERT_SPACING_MS = 6 * 60 * 60 * 1000L

    /** Quiet hours: from 22:00 (inclusive) to 07:00 (exclusive), device local time. */
    val QUIET_START: LocalTime = LocalTime.of(22, 0)
    val QUIET_END: LocalTime = LocalTime.of(7, 0)

    /** Expanded lines before "+N more". */
    const val MAX_LINES = 6

    /** Names in the collapsed text before "and N more". */
    const val MAX_NAMES = 3

    /** A series waiting in the notification. */
    @Serializable
    data class Pending(
        @SerialName("novel_id") val novelId: Int,
        val name: String,
        val medium: String = "novel",
        @SerialName("new_count") val newCount: Int,
        @SerialName("chapter_id") val chapterId: Int? = null,
        @SerialName("latest_at") val latestAt: String? = null,
    )

    /**
     * Everything kept between runs, for one server and account ([scope]):
     * the server's cursor (null: never asked, the next answer only sets it),
     * the series waiting in the notification, and when it last alerted.
     */
    @Serializable
    data class State(
        val scope: String = "",
        val cursor: String? = null,
        val pending: List<Pending> = emptyList(),
        @SerialName("last_alert_at") val lastAlertAt: Long? = null,
    ) {
        /** For another server or account this state means nothing: start over. */
        fun forScope(current: String): State = if (scope == current) this else State(scope = current)

        /** The reader opened the app, tapped or swiped the notification: nothing waits any more. */
        fun cleared(): State = copy(pending = emptyList())
    }

    /** What to do with the notification after an answer. */
    sealed interface Action {
        /** Leave it as it is (nothing new, or the first run). */
        data object None : Action

        /** Post (or update) the one notification; [alert] false posts it silently. */
        data class Post(val alert: Boolean) : Action
    }

    data class Outcome(val state: State, val action: Action)

    /**
     * Applies one answer from `GET /updates`. The first run (no cursor yet)
     * only stores the cursor. In the [foreground] the app shows new chapters
     * itself (Home), so the cursor moves but nothing is added. Otherwise the
     * series are merged ([merge]) and the notification is posted when that
     * changed anything, alerting only when [alertAllowed] and the
     * notification isn't [showing] already (an update to a notification on
     * screen is always silent: setOnlyAlertOnce).
     */
    fun receive(
        state: State,
        answer: UpdatesFeed,
        now: Long,
        localTime: LocalTime,
        foreground: Boolean,
        showing: Boolean = false,
    ): Outcome {
        val cursor = answer.cursor?.takeIf { it.isNotBlank() } ?: state.cursor
        if (state.cursor == null || foreground) return Outcome(state.copy(cursor = cursor), Action.None)
        val merged = merge(state.pending, answer.series)
        if (merged == state.pending) return Outcome(state.copy(cursor = cursor), Action.None)
        val alert = !showing && alertAllowed(state.lastAlertAt, now, localTime)
        val next = state.copy(cursor = cursor, pending = merged, lastAlertAt = if (alert) now else state.lastAlertAt)
        return Outcome(next, Action.Post(alert))
    }

    /**
     * Adds the answer's series to those pending; a series already pending is
     * replaced by the newer answer (its count and chapter). Series with
     * nothing new (count 0) are ignored.
     */
    fun merge(pending: List<Pending>, answer: List<UpdatedSeries>): List<Pending> {
        val byId = LinkedHashMap<Int, Pending>()
        pending.forEach { byId[it.novelId] = it }
        answer.filter { it.newCount > 0 }.forEach { series ->
            byId[series.novelId] = Pending(
                novelId = series.novelId,
                name = series.name.ifBlank { byId[series.novelId]?.name ?: "A series" },
                medium = series.medium,
                newCount = series.newCount,
                chapterId = series.chapterId,
                latestAt = series.latestAt,
            )
        }
        return byId.values.toList()
    }

    /** Most recent first (by `latest_at`; undated last), then by name. */
    fun ordered(pending: List<Pending>): List<Pending> =
        pending.sortedWith(
            compareByDescending<Pending> { instant(it.latestAt) ?: Instant.MIN }
                .thenBy(String.CASE_INSENSITIVE_ORDER) { it.name },
        )

    /** Sound or vibration: not in quiet hours, and not within 6 hours of the last one. */
    fun alertAllowed(lastAlertAt: Long?, now: Long, localTime: LocalTime): Boolean {
        if (isQuiet(localTime)) return false
        if (lastAlertAt == null || now < lastAlertAt) return true
        return now - lastAlertAt >= ALERT_SPACING_MS
    }

    /** 22:00 up to (not including) 07:00. */
    fun isQuiet(time: LocalTime): Boolean = !time.isBefore(QUIET_START) || time.isBefore(QUIET_END)

    // Text

    /** One series: its name; several: "New chapters in 4 series". */
    fun title(pending: List<Pending>): String =
        if (pending.size == 1) pending.single().name else "New chapters in ${pending.size} series"

    /** One series: "3 new chapters"; several: "A, B, C and 1 more" (most recent first). */
    fun text(pending: List<Pending>): String {
        if (pending.size == 1) return countText(pending.single().newCount)
        return names(ordered(pending).map { it.name })
    }

    /** "1 new chapter", "3 new chapters" (the same for manga). */
    fun countText(count: Int): String = if (count == 1) "1 new chapter" else "$count new chapters"

    /** "A and B", "A, B and C", "A, B, C and 4 more". */
    fun names(names: List<String>): String = when {
        names.isEmpty() -> ""
        names.size == 1 -> names.single()
        names.size <= MAX_NAMES -> names.dropLast(1).joinToString(", ") + " and " + names.last()
        else -> names.take(MAX_NAMES).joinToString(", ") + " and ${names.size - MAX_NAMES} more"
    }

    /** The expanded lines: "A · 3 new", most recent first, at most [MAX_LINES] then "+N more". */
    fun lines(pending: List<Pending>): List<String> {
        val sorted = ordered(pending)
        val shown = sorted.take(MAX_LINES).map { "${it.name} · ${it.newCount} new" }
        val rest = sorted.size - shown.size
        return if (rest > 0) shown + "+$rest more" else shown
    }

    /** Tapping opens this series (one pending), else Home (null). */
    fun target(pending: List<Pending>): Int? = pending.singleOrNull()?.novelId

    private fun instant(value: String?): Instant? = value?.let { runCatching { Instant.parse(it) }.getOrNull() }
}
