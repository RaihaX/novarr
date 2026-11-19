package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.HomeFeed
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.domain.CoverTitle
import studio.visns.novarr.domain.NewChapters

class HomeAndCoverTest {
    @Test
    fun newChaptersListsOnlySeriesWithSomethingToRead() {
        // As /api/v1/home sends it: Kagurabachi's new chapters are all still queued (count 0, no
        // first_chapter); B's were all read (a count, no first_chapter); C has one to open.
        val feed = NovarrJson.decodeFromString(
            HomeFeed.serializer(),
            """{"continue":[],"new_chapters":[
                 {"novel":{"id":1,"name":"Kagurabachi"},"count":0,"first_chapter":null},
                 {"novel":{"id":2,"name":"B"},"count":3,"first_chapter":null},
                 {"novel":{"id":3,"name":"C"},"count":2,"first_chapter":{"id":30,"label":"Chapter 12"}}
               ]}""",
        )
        assertEquals(3, feed.newChapters.size)
        assertEquals(listOf(3), NewChapters.shown(feed.newChapters).map { it.novel.id })
        assertTrue(NewChapters.shown(emptyList()).isEmpty())
    }

    @Test
    fun noBadgeForZero() {
        assertNull(NewChapters.badge(0))
        assertNull(NewChapters.badge(-1))
        assertEquals("1 NEW", NewChapters.badge(1))
        assertEquals("12 NEW", NewChapters.badge(12))
    }

    @Test
    fun coverTitleWordsBreakAtSpacesAndAfterHyphens() {
        assertEquals(listOf("Max-", "Level", "Newbie"), CoverTitle.words("Max-Level Newbie"))
        assertEquals(listOf("Solo", "Leveling"), CoverTitle.words("  Solo   Leveling "))
        assertTrue(CoverTitle.words("").isEmpty())
    }

    @Test
    fun coverTitleShrinksBeforeItHyphenates() {
        // Fits: normal size, no hyphens.
        assertEquals(CoverTitle.Fit(1f, false), CoverTitle.fit(longestWord = 40f, available = 50f))
        // A bit too wide: smaller text, words kept whole.
        val shrunk = CoverTitle.fit(longestWord = 60f, available = 50f)
        assertFalse(shrunk.hyphenate)
        assertTrue(shrunk.scale < 50f / 60f && shrunk.scale >= CoverTitle.MIN_SCALE)
        // Far too wide even at the smallest size: hyphenate that word.
        assertEquals(CoverTitle.Fit(CoverTitle.MIN_SCALE, true), CoverTitle.fit(longestWord = 200f, available = 50f))
        // Nothing measured yet: as is.
        assertEquals(CoverTitle.Fit(1f, false), CoverTitle.fit(0f, 50f))
        assertEquals(CoverTitle.Fit(1f, false), CoverTitle.fit(40f, 0f))
    }
}
