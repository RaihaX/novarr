package studio.visns.novarr.domain

import java.time.OffsetDateTime
import kotlin.math.abs
import kotlin.math.roundToInt
import studio.visns.novarr.api.ReadingPosition

/**
 * The "read more recently on another device" prompt: the shared contract's
 * client rule as pure functions (same as the iOS app's LatestPosition).
 */
object LatestPosition {
    /** Same-chapter positions closer than this (percent points) aren't worth a prompt. */
    const val MINIMUM_PROGRESS_GAP = 5

    /**
     * The contract's client rule. [localProgress] is this device's position in
     * the opened chapter (0–100, null meaning the top). [declinedAt] is the
     * epoch-ms `at` of a position the user already said "Stay here" to.
     */
    fun shouldOffer(
        position: ReadingPosition?,
        deviceId: String,
        chapterId: Int,
        localProgress: Int?,
        localNewer: Boolean,
        declinedAt: Long?,
        canOpen: Boolean,
    ): Boolean {
        // 1. Someone else (a known device) holds the latest position.
        val other = position?.deviceId ?: return false
        if (other == deviceId) return false
        // 2. Nothing read here since then is still waiting to be sent.
        if (localNewer) return false
        // 3. It's somewhere else. Same chapter without a percentage means "here".
        if (position.chapterId == chapterId) {
            val theirs = position.progress ?: return false
            if (abs(theirs - (localProgress ?: 0)) < MINIMUM_PROGRESS_GAP) return false
        }
        // 4. Not the exact position the user already turned down (or an older one).
        if (declinedAt != null) {
            val at = parseInstant(position.at) ?: return false
            if (at <= declinedAt) return false
        }
        // 5. We can actually open it.
        return canOpen
    }

    /**
     * Rule 2: any local read-state write for the series recorded fewer than
     * `ageSeconds` ago is newer than the server's position. Only differences
     * against this device's own clock are used.
     */
    fun localIsNewer(localWriteTimes: List<Long>, ageSeconds: Int?, now: Long): Boolean {
        ageSeconds ?: return false
        return localWriteTimes.any { now - it < ageSeconds * 1000L }
    }

    /** "Chapter 124: The Gate · 40% was read on Kindle 2 hours ago. Go there?" */
    fun message(position: ReadingPosition): String {
        val chapter = position.chapterLabel?.takeIf { it.isNotBlank() }
            ?: position.chapterNumber?.takeIf { it.isNotBlank() }?.let { "Chapter $it" }
            ?: "Another chapter"
        val percent = position.progress?.takeIf { it in 1..99 }?.let { " · $it%" }.orEmpty()
        val device = position.deviceName?.takeIf { it.isNotBlank() } ?: "another device"
        val whenText = position.ageSeconds?.let(::relativeTime) ?: "recently"
        return "$chapter$percent was read on $device $whenText. Go there?"
    }

    /** "just now", "5 minutes ago", "yesterday", "3 weeks ago"... */
    fun relativeTime(ageSeconds: Int): String {
        val s = ageSeconds.coerceAtLeast(0)
        val minute = 60; val hour = 3_600; val day = 86_400; val week = 604_800; val month = 2_592_000; val year = 31_536_000
        return when {
            s < minute -> "just now"
            s < hour -> ago(s / minute, "minute")
            s < day -> ago(s / hour, "hour")
            s < 2 * day -> "yesterday"
            s < week -> ago(s / day, "day")
            s < month -> ago(s / week, "week")
            s < year -> ago(maxOf(1, s / month), "month")
            else -> ago(s / year, "year")
        }
    }

    private fun ago(count: Int, unit: String) = "$count $unit${if (count == 1) "" else "s"} ago"

    /** Item to show for a 0–100 position (paragraph or page); null means the top. */
    fun itemIndex(progress: Int, itemCount: Int): Int? {
        if (progress <= 0 || itemCount <= 1) return null
        val fraction = progress.coerceAtMost(100) / 100.0
        return (fraction * (itemCount - 1)).roundToInt()
    }

    fun parseInstant(raw: String?): Long? =
        raw?.let { runCatching { OffsetDateTime.parse(it).toInstant().toEpochMilli() }.getOrNull() }
}
