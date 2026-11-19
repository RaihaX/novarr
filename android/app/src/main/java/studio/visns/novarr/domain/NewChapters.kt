package studio.visns.novarr.domain

import studio.visns.novarr.api.NewChaptersItem

/**
 * Home's "New chapters" rules. The server (HomeController::newChapters)
 * groups every chapter created in the last few days, downloaded or not, so a
 * series whose new chapters are all still queued comes with `count: 0` and
 * no `first_chapter`; one whose new chapters were all read comes with a
 * count but no `first_chapter` either. Neither has anything to read here.
 */
object NewChapters {
    /** The rows Home lists: a series with downloaded new chapters and an unread one to open. */
    fun shown(items: List<NewChaptersItem>): List<NewChaptersItem> =
        items.filter { it.newCount > 0 && it.firstChapter != null }

    /** "3 NEW"; null for zero (no badge). */
    fun badge(count: Int): String? = if (count > 0) "$count NEW" else null
}
