package studio.visns.novarr.domain

import java.time.Instant
import kotlinx.serialization.Serializable
import studio.visns.novarr.api.AudiobookProgress

/** A whole-book position as recorded on this device. [recordedAt] is epoch ms (sent as recorded_at). */
@Serializable
data class ListeningRecord(
    val audiobookId: Int,
    val positionMs: Long,
    val speed: Float = 1f,
    val finished: Boolean = false,
    val recordedAt: Long,
)

/**
 * The rules for listening progress, pure: last write wins by when a position
 * was recorded, on this device and between this device and the server.
 */
object ListeningSync {
    /** Where to resume, and whose position that was. */
    data class Resolved(val positionMs: Long, val finished: Boolean, val fromLocal: Boolean)

    /**
     * The newer of this device's last position and the server's (by recorded
     * time). A server time that can't be read loses to a local position; a tie
     * goes to this device (it may not be sent yet).
     */
    fun resolve(local: ListeningRecord?, server: AudiobookProgress?): Resolved {
        val serverAt = server?.updatedAt?.let(::parseInstant)
        return when {
            local == null && server == null -> Resolved(0, false, fromLocal = false)
            server == null -> Resolved(local!!.positionMs, local.finished, fromLocal = true)
            local == null -> Resolved(server.positionMs, server.finished, fromLocal = false)
            serverAt == null || local.recordedAt >= serverAt -> Resolved(local.positionMs, local.finished, fromLocal = true)
            else -> Resolved(server.positionMs, server.finished, fromLocal = false)
        }
    }

    /** Within this much of the end, a book counts as listened to: playing again starts over. */
    const val END_SLACK_MS = 5_000L

    /** Where playback starts: the resolved position, or the beginning for a finished book. */
    fun startPosition(resolved: Resolved, totalMs: Long): Long = when {
        resolved.finished -> 0
        totalMs > 0 && resolved.positionMs >= totalMs - END_SLACK_MS -> 0
        else -> resolved.positionMs.coerceIn(0, if (totalMs > 0) totalMs else Long.MAX_VALUE)
    }

    /**
     * Whether [incoming] should replace [current] as an audiobook's position on
     * this device: only a position recorded at the same time or later, so a
     * late, older write never moves it back.
     */
    fun supersedes(incoming: ListeningRecord, current: ListeningRecord?): Boolean =
        current == null || incoming.recordedAt >= current.recordedAt

    /** While playing, a position is recorded (and sent) this often. */
    const val SYNC_INTERVAL_MS = 15_000L

    fun isoOf(epochMs: Long): String = Instant.ofEpochMilli(epochMs).toString()

    fun parseInstant(iso: String): Long? = runCatching { Instant.parse(iso).toEpochMilli() }.getOrNull()
        ?: runCatching { java.time.OffsetDateTime.parse(iso).toInstant().toEpochMilli() }.getOrNull()

    /** "1:02:03" / "2:03" for a duration or position. */
    fun clock(ms: Long): String {
        val total = (ms.coerceAtLeast(0) / 1000)
        val h = total / 3600
        val m = (total % 3600) / 60
        val s = total % 60
        return if (h > 0) "%d:%02d:%02d".format(h, m, s) else "%d:%02d".format(m, s)
    }

    /** "11 h 32 min" / "45 min" for a book's length. */
    fun length(ms: Long): String {
        val minutes = (ms.coerceAtLeast(0) + 30_000) / 60_000
        val h = minutes / 60
        val m = minutes % 60
        return when {
            h == 0L -> "$m min"
            m == 0L -> "$h h"
            else -> "$h h $m min"
        }
    }
}
