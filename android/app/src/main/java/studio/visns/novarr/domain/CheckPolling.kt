package studio.visns.novarr.domain

import java.text.NumberFormat
import studio.visns.novarr.api.NovelCheck

/** What the app says about a "check for new chapters". */
sealed class CheckDisplay {
    /** Running on the server; [found] chapters have been listed so far. */
    data class Checking(val found: Int) : CheckDisplay()
    data class Found(val count: Int) : CheckDisplay()
    data object NoneYet : CheckDisplay()

    /** The check failed on the server (its message) or couldn't be started. */
    data class Failed(val message: String) : CheckDisplay()

    /** Still running after the app stopped watching (10 minutes). */
    data object StillRunning : CheckDisplay()

    val isChecking: Boolean get() = this is Checking

    val text: String
        get() = when (this) {
            is Checking -> if (found > 0) "Checking… ${chapters(found)} so far" else "Checking for new chapters…"
            is Found -> chapters(count)
            NoneYet -> "No new chapters yet"
            is Failed -> message
            StillRunning -> "Still checking on the server. Look again in a while."
        }

    companion object {
        fun chapters(count: Int) = if (count == 1) "1 new chapter" else "${NumberFormat.getInstance().format(count)} new chapters"
    }
}

/**
 * The polling rules for one check, as a pure state machine (same as iOS):
 * feed it each answer from the server with the current time; it says what
 * to show and whether to keep polling.
 */
class CheckPolling(private val startedAt: Long) {
    sealed class Step {
        data class Poll(val afterMs: Long) : Step()
        data object Finished : Step()
    }

    var display: CheckDisplay = CheckDisplay.Checking(0)
        private set
    var consecutiveErrors = 0
        private set

    /** True when the last answer listed a different number of chapters: reload the list. */
    var chaptersChanged = false
        private set
    private var lastChaptersNow: Int? = null

    fun receive(check: NovelCheck, now: Long): Step {
        consecutiveErrors = 0
        chaptersChanged = lastChaptersNow?.let { it != check.chaptersNow } ?: false
        lastChaptersNow = check.chaptersNow
        return when (check.status.lowercase()) {
            "done" -> {
                display = if (check.newChapters > 0) CheckDisplay.Found(check.newChapters) else CheckDisplay.NoneYet
                chaptersChanged = chaptersChanged || check.newChapters > 0
                Step.Finished
            }
            "failed" -> {
                display = CheckDisplay.Failed(check.message ?: "The check failed on the server.")
                Step.Finished
            }
            // The server forgot it (results are kept 24 h): nothing to report.
            "idle" -> {
                display = if (check.newChapters > 0) CheckDisplay.Found(check.newChapters) else CheckDisplay.NoneYet
                Step.Finished
            }
            else -> {
                display = CheckDisplay.Checking(check.newChapters)
                keepWatching(now)
            }
        }
    }

    /** A failed request. Gives up after a few in a row (a blip shouldn't end a check). */
    fun receiveError(message: String, now: Long): Step {
        consecutiveErrors++
        chaptersChanged = false
        if (consecutiveErrors >= MAX_CONSECUTIVE_ERRORS) {
            display = CheckDisplay.Failed(message)
            return Step.Finished
        }
        return keepWatching(now)
    }

    private fun keepWatching(now: Long): Step {
        if (now - startedAt >= LIMIT_MS) {
            display = CheckDisplay.StillRunning
            return Step.Finished
        }
        return Step.Poll(INTERVAL_MS)
    }

    companion object {
        const val INTERVAL_MS = 15_000L
        const val LIMIT_MS = 600_000L
        const val MAX_CONSECUTIVE_ERRORS = 3

        /** Join a check that is already running rather than ask for another. */
        fun shouldStart(current: NovelCheck?): Boolean = current?.status?.lowercase() != "running"
    }
}
