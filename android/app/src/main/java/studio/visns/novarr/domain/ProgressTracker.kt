package studio.visns.novarr.domain

import kotlin.math.roundToInt

/**
 * The reader's progress-write rules (same as iOS), without timers so they can
 * be tested:
 * - positions are sent at most once every [intervalMs] while reading; the
 *   first one after a quiet spell goes straight away, later ones wait out the
 *   rest of the interval (one pending send at a time, carrying the newest);
 * - an unchanged position is never re-sent;
 * - 100 is reserved for actually reaching the end, which marks the chapter
 *   read once and cancels any pending position;
 * - leaving the chapter sends whatever is still pending;
 * - while [holding] (a latest-position check or its prompt is open),
 *   positions are still tracked but not sent; [release] lets them go,
 *   [discardPending] drops them.
 * Positions that arrive at the server late are handled there: each write
 * carries its `age`, and older writes never overwrite newer ones.
 */
class ProgressTracker(private val intervalMs: Long = DEFAULT_INTERVAL_MS, private val now: () -> Long) {
    sealed class Decision {
        data object SendNow : Decision()
        data class SendAfter(val delayMs: Long) : Decision()

        /** A send is already scheduled; it will carry the newest position. */
        data object AlreadyScheduled : Decision()

        /** Writes are held: the position is kept but nothing is scheduled. */
        data object Held : Decision()
    }

    var pending: Int? = null
        private set
    var lastSent: Int? = null
        private set
    var markedRead = false
        private set

    /** When the oldest still-unsent position was taken (for the latest-position rule). */
    var pendingSince: Long? = null
        private set
    private var lastSentAt = Long.MIN_VALUE / 2

    /** Positions are tracked but not sent (survives [reset]: it belongs to the reader, not the chapter). */
    var holding = false
        private set

    /** [pendingSince] was taken while holding. */
    private var pendingHeld = false

    /**
     * [pendingSince], unless that position was taken while holding: a scroll
     * during the latest-position check must not count as "read here more
     * recently", or it would suppress the very prompt the hold is for.
     */
    val unheldPendingSince: Long? get() = pendingSince?.takeUnless { pendingHeld }
    private var scheduled = false

    /** Starts tracking a freshly opened chapter with what the server (or cache) knows. */
    fun reset(read: Boolean, progress: Int?) {
        pending = null
        pendingSince = null
        pendingHeld = false
        lastSent = progress
        markedRead = read
        scheduled = false
    }

    /** This device's position in the open chapter, 0–100. */
    val current: Int? get() = pending ?: lastSent

    /** A new position (0–99) while reading. */
    fun moved(progress: Int): Decision {
        pending = progress.coerceIn(0, 99)
        if (pendingSince == null) {
            pendingSince = now()
            pendingHeld = holding
        }
        if (holding) return Decision.Held
        val elapsed = now() - lastSentAt
        return when {
            elapsed >= intervalMs -> Decision.SendNow.also { scheduled = false }
            scheduled -> Decision.AlreadyScheduled
            else -> Decision.SendAfter(intervalMs - elapsed).also { scheduled = true }
        }
    }

    /** The position to write now, or null when there is nothing new. */
    fun takePending(): Int? {
        scheduled = false
        if (holding) return null
        val progress = pending ?: return null
        pending = null
        pendingSince = null
        pendingHeld = false
        if (progress == lastSent) return null
        lastSent = progress
        lastSentAt = now()
        return progress
    }

    /** The end of the chapter is on screen. True when the read mark must be written (once). */
    fun reachedEnd(): Boolean {
        pending = null
        pendingSince = null
        pendingHeld = false
        scheduled = false
        if (markedRead) return false
        markedRead = true
        lastSent = 100
        lastSentAt = now()
        return true
    }

    /** Stops sending positions until [release]. */
    fun hold() {
        holding = true
    }

    /** Ends the hold; whatever is pending goes out with the next [takePending]. */
    fun release() {
        holding = false
        pendingHeld = false
    }

    /** Drops the unsent position (held or not), e.g. after "Go to latest". */
    fun discardPending() {
        pending = null
        pendingSince = null
        pendingHeld = false
        scheduled = false
    }

    companion object {
        /** Minimum time between progress writes while scrolling. */
        const val DEFAULT_INTERVAL_MS = 4_000L

        /** Position of item [index] of [count] as 0–99 (100 is "finished"). */
        fun progressFor(index: Int, count: Int): Int {
            if (count <= 1) return 0
            val fraction = index.toDouble() / (count - 1)
            return (fraction * 100).roundToInt().coerceIn(0, 99)
        }
    }
}
