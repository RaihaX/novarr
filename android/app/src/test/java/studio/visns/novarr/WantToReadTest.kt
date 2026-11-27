package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.HomeFeed
import studio.visns.novarr.api.ListsEnvelope
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.api.WantToReadEnvelope
import studio.visns.novarr.api.WantToReadItem
import studio.visns.novarr.domain.ReadingLists
import studio.visns.novarr.domain.ReadingLists.Toggle

/** "Want to read", the built-in reading list: decoding, its rules and Home's / the screen's shelf logic. */
class WantToReadTest {
    private val wanted = ReadingList(7, "Want to read", 2, listOf(30, 10), kind = ReadingList.KIND_WANT_TO_READ)
    private val favourites = ReadingList(1, "Favourites", 1, listOf(10))
    private val zebra = ReadingList(2, "Aardvark", 0)

    private fun item(id: Int, started: Boolean = false, read: Int = 0) =
        WantToReadItem(NovelSummary(id, "Series $id", chaptersRead = read), "2026-12-08T10:00:00Z", started)

    // Decoding

    @Test
    fun listsDecodeTheirKindAndAnOlderServerHasNone() {
        val json = """{"lists":[
            {"id":7,"name":"Want to read","count":1,"novel_ids":[3],"kind":"want_to_read"},
            {"id":1,"name":"Favourites","count":0,"novel_ids":[],"kind":null},
            {"id":2,"name":"Old","count":0,"novel_ids":[]}
        ]}"""
        val lists = NovarrJson.decodeFromString(ListsEnvelope.serializer(), json).lists
        assertEquals(listOf(true, false, false), lists.map { it.isWantToRead })
        assertEquals(7, ReadingLists.wantToRead(lists)?.id)
        assertEquals(listOf(1, 2), ReadingLists.own(lists).map { it.id })
        assertNull(ReadingLists.wantToRead(lists.drop(1)))
    }

    @Test
    fun summariesHomeAndTheWholeListDecode() {
        val summary = NovarrJson.decodeFromString(NovelSummary.serializer(), """{"id":3,"name":"A","want_to_read":true}""")
        assertTrue(summary.wantToRead)
        assertFalse(NovarrJson.decodeFromString(NovelSummary.serializer(), """{"id":3,"name":"A"}""").wantToRead)

        val home = NovarrJson.decodeFromString(
            HomeFeed.serializer(),
            """{"continue":[],"new_chapters":[],"want_to_read":[{"novel":{"id":3,"name":"A"},"added_at":"2026-12-08T10:00:00Z","started":true},{"broken":1}],"want_to_read_count":14}""",
        )
        // A malformed item is skipped, never failing Home.
        assertEquals(listOf(3), home.wantToRead.map { it.novel.id })
        assertTrue(home.wantToRead[0].started)
        assertEquals(14, home.wantToReadCount)
        // An older server: neither field.
        val old = NovarrJson.decodeFromString(HomeFeed.serializer(), """{"continue":[],"new_chapters":[]}""")
        assertEquals(emptyList<WantToReadItem>(), old.wantToRead)
        assertEquals(0, old.wantToReadCount)

        val whole = NovarrJson.decodeFromString(
            WantToReadEnvelope.serializer(),
            """{"list":{"id":7,"name":"Want to read","count":1,"novel_ids":[3],"kind":"want_to_read"},"items":[{"novel":{"id":3,"name":"A"},"added_at":null,"started":false}]}""",
        )
        assertTrue(whole.list!!.isWantToRead)
        assertNull(whole.items.single().addedAt)
    }

    // Its rules

    @Test
    fun itsNameIsReservedAndItIsOutsideTheCap() {
        assertEquals("“Want to read” is already one of your lists.", ReadingLists.nameProblem("  want   TO read ", listOf(favourites)))
        assertNull(ReadingLists.nameProblem("Want to read later", listOf(favourites)))
        // 30 of the reader's own lists and the built-in one: full; 29 and the built-in one: room for one more.
        val thirty = (1..30).map { ReadingList(it, "L$it") }
        assertEquals("You can have up to 30 lists.", ReadingLists.nameProblem("One more", thirty + wanted))
        assertNull(ReadingLists.nameProblem("One more", thirty.drop(1) + wanted))
        // Starters are offered while the reader has none of their own.
        assertEquals(listOf("Favourites", "To read", "Finished"), ReadingLists.starters(listOf(wanted)))
    }

    @Test
    fun itIsListedFirst() {
        assertEquals(listOf(7, 2, 1), ReadingLists.sorted(listOf(favourites, wanted, zebra)).map { it.id })
    }

    @Test
    fun anAddGoesToTheFrontAndRollsBack() {
        val state = ReadingLists.State(listOf(wanted, favourites))
        val started = ReadingLists.begin(state, 7, 99, add = true)
        assertNotNull(started)
        assertEquals(listOf(99, 30, 10), ReadingLists.wantToRead(started!!.lists)!!.novelIds)
        assertEquals(3, ReadingLists.wantToRead(started.lists)!!.count)
        assertTrue(ReadingLists.isWanted(started.lists, 99))
        assertTrue(started.busy(7, 99))
        // Another list still adds at the end.
        assertEquals(listOf(10, 99), ReadingLists.apply(listOf(favourites), Toggle(1, 99, true)).single().novelIds)

        val failed = ReadingLists.fail(started, Toggle(7, 99, true))
        assertEquals(listOf(30, 10), ReadingLists.wantToRead(failed.lists)!!.novelIds)
        assertFalse(ReadingLists.isWanted(failed.lists, 99))
        assertFalse(failed.busy(7, 99))

        // Already on it: nothing to start.
        assertNull(ReadingLists.begin(state, 7, 30, add = true))
    }

    @Test
    fun notWantedWhileTheListIsUnknown() {
        assertFalse(ReadingLists.isWanted(emptyList(), 30))
        assertFalse(ReadingLists.isWanted(listOf(favourites), 10))
        assertTrue(ReadingLists.isWanted(listOf(wanted), 10))
    }

    // Shelves

    @Test
    fun aSeriesTakenOffGoesAtOnce() {
        val items = listOf(item(30), item(10))
        assertEquals(items, ReadingLists.shown(items, null))
        assertEquals(listOf(30), ReadingLists.shown(items, wanted.copy(novelIds = listOf(30))).map { it.novel.id })
        assertEquals(emptyList<WantToReadItem>(), ReadingLists.shown(items, wanted.copy(novelIds = emptyList())))
    }

    @Test
    fun onlyAnAdditionNeedsAFetch() {
        val items = listOf(item(30), item(10))
        assertFalse(ReadingLists.needsRefetch(items, null))
        assertFalse(ReadingLists.needsRefetch(items, wanted))
        assertFalse(ReadingLists.needsRefetch(items, wanted.copy(novelIds = listOf(10))))
        assertTrue(ReadingLists.needsRefetch(items, wanted.copy(novelIds = listOf(99, 30, 10))))
        // Home holds the newest 12 only: an older series beyond them needs nothing.
        val twelve = (1..12).map { item(it) }
        assertFalse(ReadingLists.needsRefetch(twelve, wanted.copy(novelIds = (1..12).toList() + 13), limit = 12))
        assertTrue(ReadingLists.needsRefetch(twelve, wanted.copy(novelIds = listOf(13) + (1..12).toList()), limit = 12))
    }

    @Test
    fun startedIsTheServersWordOrAChapterRead() {
        assertFalse(ReadingLists.started(item(1)))
        assertTrue(ReadingLists.started(item(1, started = true)))
        assertTrue(ReadingLists.started(item(1, read = 2)))
    }
}
