package studio.visns.novarr.domain

import kotlin.math.ceil

/**
 * When the readers count a chapter as finished (the read mark, `read: true`
 * on the progress write), kept apart from the screens so it can be tested:
 * - the end of the chapter is on screen (the last manga page, the last text
 *   page, the end-of-chapter footer);
 * - in a scrolled text chapter, the bottom of at least [TEXT_END_FRACTION]
 *   of its paragraphs has been on screen (a long last paragraph or a tall
 *   footer must not keep a chapter that was read to its last lines unread);
 * - the reader moves on to the chapter's next chapter (Next chapter in the
 *   bars or on the end page, a page key or tap past the end): moving forward
 *   says "done with this one", as the web reader and KOReader treat it.
 *
 * The server marks a chapter read too when a position of
 * [SERVER_FINISHED_PROGRESS] or more arrives (App\Services\ReadState::
 * FINISHED_PROGRESS), so a dropped read flag still ends up read.
 */
object ReadMark {
    /** Share of a scrolled chapter's paragraphs that must have been on screen. */
    const val TEXT_END_FRACTION = 0.95

    /** Mirrors the server's ReadState::FINISHED_PROGRESS (documentation and tests only). */
    const val SERVER_FINISHED_PROGRESS = 98

    /**
     * Whether a scrolled text chapter counts as read: [lastFullyVisible] is
     * the index (0-based) of the last paragraph whose bottom is on screen,
     * or -1 when none is.
     */
    fun textEndReached(lastFullyVisible: Int, paragraphCount: Int): Boolean {
        if (paragraphCount <= 0 || lastFullyVisible < 0) return false
        val needed = ceil(paragraphCount * TEXT_END_FRACTION).toInt().coerceIn(1, paragraphCount)
        return lastFullyVisible + 1 >= needed
    }

    /**
     * Opening [target] from a loaded chapter whose next chapter is [next]
     * finishes the chapter being left (true): it is marked read first.
     * Going back, or jumping elsewhere (Go to latest, the chapter list), doesn't.
     */
    fun finishesOnOpen(target: Int, next: Int?, loaded: Boolean): Boolean =
        loaded && next != null && target == next
}
