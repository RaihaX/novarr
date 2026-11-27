package studio.visns.novarr.domain

/**
 * The player's sleep timer, as pure state: off, a duration (counted on a
 * monotonic clock, e.g. SystemClock.elapsedRealtime, so a clock change can't
 * end it early), or the end of the chapter playing when it was set. The
 * player ticks it ([decide]) about once a second.
 */
sealed interface SleepTimer {
    data object Off : SleepTimer

    /** Stops at [endsAt] (monotonic ms). Counted while paused too, like most players. */
    data class Duration(val endsAt: Long, val minutes: Int) : SleepTimer

    /** Stops when playback reaches [chapterEndMs] (whole-book position). */
    data class EndOfChapter(val chapterEndMs: Long) : SleepTimer

    /** What a tick says to do. */
    sealed interface Decision {
        data object Keep : Decision

        /** Lower the volume to [volume] (0..1): the last seconds fade out. */
        data class Fade(val volume: Float) : Decision

        /** Pause now, at full volume again, and switch the timer off. */
        data object Stop : Decision
    }

    companion object {
        const val MIN_MINUTES = 5
        const val MAX_MINUTES = 120

        /** The choices in the player's menu. */
        val PRESET_MINUTES = listOf(5, 10, 15, 30, 45, 60, 90, 120)

        /** The fade before a duration timer stops. */
        const val FADE_MS = 10_000L

        /** A chapter end this close counts as reached (ticks are a second apart). */
        const val CHAPTER_END_SLACK_MS = 400L

        fun minutes(minutes: Int, now: Long): Duration {
            val m = minutes.coerceIn(MIN_MINUTES, MAX_MINUTES)
            return Duration(now + m * 60_000L, m)
        }

        fun endOfChapter(timeline: AudiobookTimeline, positionMs: Long): EndOfChapter =
            EndOfChapter(timeline.chapterEndAt(positionMs))

        /** Milliseconds left on a duration timer (never negative); null for the others. */
        fun remaining(timer: SleepTimer, now: Long): Long? = (timer as? Duration)?.let { (it.endsAt - now).coerceAtLeast(0) }

        /** "Shake to extend" / "+5 min": a duration timer gets [minutes] more (from now when it had run out), capped. */
        fun extend(timer: SleepTimer, minutes: Int, now: Long): SleepTimer = when (timer) {
            is Duration -> {
                val base = maxOf(timer.endsAt, now)
                val cap = now + MAX_MINUTES * 60_000L
                Duration(minOf(base + minutes * 60_000L, cap), timer.minutes)
            }
            else -> timer
        }

        /**
         * After a seek: an end-of-chapter timer follows the chapter now
         * playing (the reader moved on, the timer means "this chapter").
         */
        fun retarget(timer: SleepTimer, timeline: AudiobookTimeline, positionMs: Long): SleepTimer = when (timer) {
            is EndOfChapter -> endOfChapter(timeline, positionMs)
            else -> timer
        }

        /** One tick: [now] on the monotonic clock, [positionMs] the whole-book position. */
        fun decide(timer: SleepTimer, now: Long, positionMs: Long): Decision = when (timer) {
            Off -> Decision.Keep
            is Duration -> {
                val left = timer.endsAt - now
                when {
                    left <= 0 -> Decision.Stop
                    left < FADE_MS -> Decision.Fade((left.toFloat() / FADE_MS).coerceIn(0.05f, 1f))
                    else -> Decision.Keep
                }
            }
            is EndOfChapter -> if (positionMs >= timer.chapterEndMs - CHAPTER_END_SLACK_MS) Decision.Stop else Decision.Keep
        }

        /** "Sleep in 12 min", "End of chapter", or null when off. */
        fun label(timer: SleepTimer, now: Long): String? = when (timer) {
            Off -> null
            is EndOfChapter -> "End of chapter"
            is Duration -> {
                val left = remaining(timer, now) ?: 0
                val minutes = (left + 59_999) / 60_000
                if (minutes <= 1) "${(left + 999) / 1000} s" else "$minutes min"
            }
        }
    }
}
