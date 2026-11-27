package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.AudiobookProgress
import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.ChapterRef
import studio.visns.novarr.api.ContinueItem
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.domain.HomeShelves
import studio.visns.novarr.domain.ReadingLists

/** Home as "my stuff" (favourites, folders, in progress, search) and the built-in Favourites list. */
class HomeShelvesTest {
    private val novels = listOf(
        NovelSummary(1, "Solo Leveling: Ragnarok", medium = "manga", coverUrl = "c1"),
        NovelSummary(2, "Attack on Titan", medium = "manga", author = "Hajime Isayama", coverUrl = "c2"),
        NovelSummary(3, "Lord of the Mysteries", author = "Cuttlefish", coverUrl = "c3"),
        NovelSummary(4, "Piranesi", medium = "book", author = "Susanna Clarke", coverUrl = null),
        NovelSummary(5, "Les Misérables", medium = "book", author = "Victor Hugo", coverUrl = "c5"),
    ).associateBy { it.id }

    private val want = ReadingList(10, "Want to read", novelIds = listOf(3), kind = ReadingList.KIND_WANT_TO_READ)
    private val favourites = ReadingList(11, "Favourites", count = 2, novelIds = listOf(2, 1), kind = ReadingList.KIND_FAVOURITES)
    private val action = ReadingList(12, "Manga – Action", count = 3, novelIds = listOf(1, 2, 99))
    private val classics = ReadingList(13, "classics", count = 2, novelIds = listOf(4, 5))

    @Test
    fun favouritesIsBuiltInAndNotAFolder() {
        val lists = listOf(classics, favourites, action, want)
        assertTrue(favourites.isFavourites && favourites.isBuiltIn)
        assertEquals(listOf(12, 13), ReadingLists.own(lists).map { it.id }.sorted())
        assertEquals(listOf(10, 11, 13, 12), ReadingLists.sorted(lists).map { it.id })
        assertEquals(favourites, ReadingLists.favourites(lists))
        assertTrue(ReadingLists.isFavourite(lists, 2))
        assertFalse(ReadingLists.isFavourite(lists, 3))
        assertFalse(ReadingLists.isFavourite(emptyList(), 2))
    }

    @Test
    fun favouritesNameIsReservedOnlyWhereTheServerHasIt() {
        assertEquals("“Favourites” is already one of your categories.", ReadingLists.nameProblem(" favourites ", listOf(favourites)))
        // An older server: "Favourites" is still an ordinary name.
        assertNull(ReadingLists.nameProblem("Favourites", listOf(action)))
        assertEquals(listOf("To read", "Finished"), ReadingLists.starters(emptyList()))
    }

    @Test
    fun addingToFavouritesPutsItFirst() {
        val added = ReadingLists.apply(listOf(favourites), ReadingLists.Toggle(11, 3, add = true)).single()
        assertEquals(listOf(3, 2, 1), added.novelIds)
        assertEquals(3, added.count)
    }

    @Test
    fun shelvesAndFoldersFromTheLibrary() {
        assertEquals(listOf(2, 1), HomeShelves.shelf(favourites, novels).map { it.id })
        assertEquals(emptyList<NovelSummary>(), HomeShelves.shelf(null, novels))
        val folders = HomeShelves.folders(listOf(favourites, want, action, classics), novels)
        // Only the reader's own lists, by name; unknown series don't count; covers without a URL are skipped.
        assertEquals(listOf("classics", "Manga – Action"), folders.map { it.name })
        assertEquals(listOf(2, 2), folders.map { it.count })
        assertEquals(listOf("c5"), folders[0].covers)
        assertEquals(listOf("c1", "c2"), folders[1].covers)
        // Before the library is known: the server's count.
        assertEquals(3, HomeShelves.folders(listOf(action), emptyMap()).single().count)
        // Never more than four covers.
        val big = ReadingList(14, "Big", novelIds = listOf(1, 2, 3, 5, 1))
        assertEquals(4, HomeShelves.folders(listOf(big), novels).single().covers.size)
    }

    private fun reading(id: Int) = ContinueItem(novels.getValue(id), ChapterRef(100 + id, "Chapter $id"))
    private fun listening(id: Int, position: Long = 10, finished: Boolean = false) =
        AudiobookSummary(id, title = "Book $id", durationMs = 100, progress = AudiobookProgress(position, finished))

    @Test
    fun inProgressInterleavesAndSkipsFinished() {
        val items = HomeShelves.inProgress(listOf(reading(1), reading(2), reading(3)), listOf(listening(7), listening(8, finished = true), listening(9)))
        assertEquals(listOf("r-1", "l-7", "r-2", "l-9", "r-3"), items.map { it.key })
    }

    @Test
    fun quickResumeTakesTheLatestOfEachThatIsOn() {
        val r = listOf(reading(1), reading(2))
        val l = listOf(listening(7, finished = true), listening(9))
        assertEquals(listOf("r-1", "l-9"), HomeShelves.quickResume(r, l, showReading = true, showListening = true).map { it.key })
        assertEquals(listOf("l-9"), HomeShelves.quickResume(r, l, showReading = false, showListening = true).map { it.key })
        assertEquals(emptyList<String>(), HomeShelves.quickResume(r, l, showReading = false, showListening = false).map { it.key })
        assertEquals(emptyList<String>(), HomeShelves.quickResume(emptyList(), emptyList(), true, true).map { it.key })
    }

    @Test
    fun searchTitlesAndAuthors() {
        val all = novels.values
        assertEquals(listOf(2), HomeShelves.search("attack", all).map { it.id })
        assertEquals(listOf(2), HomeShelves.search("isayama", all).map { it.id })
        assertEquals(listOf(5), HomeShelves.search("miserables", all).map { it.id })
        assertEquals(listOf(1), HomeShelves.search("solo leveling", all).map { it.id })
        assertEquals(emptyList<Int>(), HomeShelves.search("  ", all).map { it.id })
        // A title starting with the query comes before one merely containing it.
        val two = listOf(NovelSummary(20, "The Lord"), NovelSummary(21, "Lord of Light"))
        assertEquals(listOf(21, 20), HomeShelves.search("lord", two).map { it.id })
    }

    @Test
    fun serverFieldsDecode() {
        val meta = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"favourites":true}""")
        assertTrue(meta.favourites)
        assertFalse(NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr"}""").favourites)
        val novel = NovarrJson.decodeFromString(NovelSummary.serializer(), """{"id":3,"name":"X","favourite":true}""")
        assertTrue(novel.favourite)
        val list = NovarrJson.decodeFromString(ReadingList.serializer(), """{"id":7,"name":"Favourites","count":1,"novel_ids":[12],"kind":"favourites"}""")
        assertTrue(list.isFavourites)
    }
}
