package studio.visns.novarr

import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.domain.LibraryFilter
import studio.visns.novarr.domain.LibraryFilter.Filter
import studio.visns.novarr.domain.LibraryFilter.Sort
import studio.visns.novarr.domain.ReadingLists
import studio.visns.novarr.domain.ReadingLists.Toggle

class ReadingListsTest {
    private val favourites = ReadingList(1, "Favourites", 2, listOf(5, 9))
    private val toRead = ReadingList(2, "To read", 0, emptyList())

    // Names

    @Test
    fun namesMirrorTheServerLimits() {
        val lists = listOf(favourites, toRead)
        assertEquals("Enter a name.", ReadingLists.nameProblem("   ", lists))
        assertNull(ReadingLists.nameProblem("x".repeat(40), lists))
        assertEquals("Names can be up to 40 characters.", ReadingLists.nameProblem("x".repeat(41), lists))
        // Surrounding spaces don't count; an emoji is one character.
        assertNull(ReadingLists.nameProblem("  " + "x".repeat(40) + "  ", lists))
        assertNull(ReadingLists.nameProblem("📚".repeat(40), lists))
        assertEquals("You already have a list called “favourites”.", ReadingLists.nameProblem(" favourites ", lists))
        assertNull(ReadingLists.nameProblem("Later", lists))
    }

    @Test
    fun renamingMayKeepItsOwnNameButNotTakeAnother() {
        val lists = listOf(favourites, toRead)
        assertNull(ReadingLists.nameProblem("FAVOURITES", lists, renaming = 1))
        assertEquals("You already have a list called “To read”.", ReadingLists.nameProblem("To read", lists, renaming = 1))
    }

    @Test
    fun atMostThirtyLists() {
        val thirty = (1..30).map { ReadingList(it, "L$it") }
        assertEquals("You can have up to 30 lists.", ReadingLists.nameProblem("One more", thirty))
        assertNull(ReadingLists.nameProblem("L1 renamed", thirty, renaming = 1))
        assertNull(ReadingLists.nameProblem("One more", thirty.drop(1)))
    }

    @Test
    fun startersSkipTakenNames() {
        assertEquals(listOf("Favourites", "To read", "Finished"), ReadingLists.starters(emptyList()))
        assertEquals(listOf("Finished"), ReadingLists.starters(listOf(favourites, toRead.copy(name = "to read"))))
    }

    // Optimistic membership

    @Test
    fun toggleShowsAtOnceAndTheAnswerReplacesIt() {
        val state = ReadingLists.State(listOf(favourites, toRead))
        val started = ReadingLists.begin(state, 2, 5, add = true)!!
        assertTrue(5 in started.lists[1])
        assertEquals(1, started.lists[1].count)
        assertTrue(started.busy(2, 5))
        // A second tap while it is on its way does nothing.
        assertNull(ReadingLists.begin(started, 2, 5, add = false))
        val done = ReadingLists.succeed(started, Toggle(2, 5, true), ReadingList(2, "To read", 1, listOf(5)))
        assertEquals(ReadingList(2, "To read", 1, listOf(5)), done.lists[1])
        assertFalse(done.busy(2, 5))
    }

    @Test
    fun aFailedToggleIsRolledBack() {
        val state = ReadingLists.State(listOf(favourites, toRead))
        val removing = ReadingLists.begin(state, 1, 9, add = false)!!
        assertFalse(9 in removing.lists[0])
        assertEquals(1, removing.lists[0].count)
        val rolledBack = ReadingLists.fail(removing, Toggle(1, 9, false))
        assertEquals(state, rolledBack)
    }

    @Test
    fun rollbackKeepsOtherChangesMadeMeanwhile() {
        val state = ReadingLists.State(listOf(favourites, toRead))
        val a = ReadingLists.begin(state, 2, 5, add = true)!!
        val b = ReadingLists.begin(a, 1, 5, add = false)!!
        // The first fails, the second is still on its way.
        val after = ReadingLists.fail(b, Toggle(2, 5, true))
        assertFalse(5 in after.lists[1])
        assertFalse(5 in after.lists[0])
        assertEquals(setOf(Toggle(1, 5, false)), after.inFlight)
    }

    @Test
    fun anAnswerKeepsOtherChangesToTheSameListOnTop() {
        val state = ReadingLists.State(listOf(toRead))
        val a = ReadingLists.begin(state, 2, 5, add = true)!!
        val b = ReadingLists.begin(a, 2, 6, add = true)!!
        // The server answered the first before it saw the second.
        val after = ReadingLists.succeed(b, Toggle(2, 5, true), ReadingList(2, "To read", 1, listOf(5)))
        assertEquals(listOf(5, 6), after.lists.single().novelIds)
        assertEquals(2, after.lists.single().count)
    }

    @Test
    fun beginRefusesNoOpsAndUnknownLists() {
        val state = ReadingLists.State(listOf(favourites))
        assertNull(ReadingLists.begin(state, 1, 5, add = true))
        assertNull(ReadingLists.begin(state, 1, 6, add = false))
        assertNull(ReadingLists.begin(state, 99, 5, add = true))
    }

    @Test
    fun replaceAddsAnUnknownList() {
        assertEquals(listOf(favourites, toRead), ReadingLists.replace(listOf(favourites), toRead))
        assertEquals(listOf(favourites.copy(name = "Faves")), ReadingLists.replace(listOf(favourites), favourites.copy(name = "Faves")))
    }

    @Test
    fun sortedByName() {
        val lists = listOf(ReadingList(1, "b"), ReadingList(2, "A"), ReadingList(3, "c"))
        assertEquals(listOf(2, 1, 3), ReadingLists.sorted(lists).map { it.id })
    }

    // Library filter

    private val novels = listOf(
        NovelSummary(5, "Beta", chaptersRead = 2),
        NovelSummary(9, "Alpha"),
        NovelSummary(12, "Manga", medium = "manga"),
        NovelSummary(20, "Other"),
    )

    @Test
    fun filterShowsTheListsSeriesCombinedWithTheOtherFilters() {
        val list = ReadingList(1, "Favourites", 3, listOf(5, 9, 12, 404))
        assertEquals(listOf(9, 5, 12), LibraryFilter.apply(novels, Filter.ALL, Sort.NAME, "", list).map { it.id })
        assertEquals(listOf(5), LibraryFilter.apply(novels, Filter.READING, Sort.NAME, "", list).map { it.id })
        assertEquals(listOf(9), LibraryFilter.apply(novels, Filter.ALL, Sort.NAME, "alp", list).map { it.id })
        // No list: everything, as before.
        assertEquals(4, LibraryFilter.apply(novels, Filter.ALL, Sort.NAME, "", null).size)
        // A series not in the library (404) is skipped.
        assertEquals(listOf(5, 9, 12), ReadingLists.filter(novels, list).map { it.id })
    }

    @Test
    fun selectedForgetsADeletedList() {
        assertEquals(favourites, ReadingLists.selected(listOf(favourites), 1))
        assertNull(ReadingLists.selected(listOf(favourites), 2))
        assertNull(ReadingLists.selected(listOf(favourites), null))
    }

    @Test
    fun emptyReasons() {
        val novelsShelf = LibraryFilter.shelf(novels, LibraryFilter.Shelf.NOVELS)
        assertEquals(ReadingLists.Empty.NoSeries, ReadingLists.emptyReason(toRead, novels, novelsShelf))
        assertEquals(ReadingLists.Empty.OtherShelf(1), ReadingLists.emptyReason(ReadingList(3, "M", 1, listOf(12)), novels, novelsShelf))
        assertEquals(ReadingLists.Empty.Filtered, ReadingLists.emptyReason(favourites, novels, novelsShelf))
    }

    // Wire format and client

    @Test
    fun decodesTheContractSamples() {
        val lists = NovarrJson.decodeFromString(
            studio.visns.novarr.api.ListsEnvelope.serializer(),
            """{"lists":[{"id":1,"name":"Favourites","count":3,"novel_ids":[5,9,12]},{"name":"no id"}]}""",
        ).lists
        assertEquals(listOf(ReadingList(1, "Favourites", 3, listOf(5, 9, 12))), lists)
        val meta = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"updates":true,"lists":true}""")
        assertTrue(meta.updates)
        assertTrue(meta.lists)
        val old = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1}""")
        assertFalse(old.updates)
        assertFalse(old.lists)
    }

    @Test
    fun clientSpeaksTheContract() = runTest {
        val listJson = """{"list":{"id":3,"name":"Later","count":1,"novel_ids":[7]}}"""
        val server = FakeServer { request ->
            when {
                request.method == "DELETE" && request.url.encodedPath == "/api/v1/lists/3" -> 204 to ""
                request.url.encodedPath == "/api/v1/lists" && request.method == "GET" -> 200 to """{"lists":[]}"""
                request.url.encodedPath == "/api/v1/updates" -> 200 to """{"cursor":"c2","series":[]}"""
                request.method == "POST" -> 201 to listJson
                else -> 200 to listJson
            }
        }
        val client = ApiClient("http://host:8080", server.client, token = "tok")
        assertEquals(emptyList<ReadingList>(), client.lists())
        assertEquals(3, client.createList("Later").id)
        client.renameList(3, "Soon")
        client.setListMembership(3, 7, add = true)
        client.setListMembership(3, 7, add = false)
        client.deleteList(3)
        assertEquals("c2", client.updates("c1").cursor)
        client.updates(null)

        val calls = server.requests.map { "${it.method} ${it.url.encodedPath}${it.url.encodedQuery?.let { q -> "?$q" } ?: ""}" }
        assertEquals(
            listOf(
                "GET /api/v1/lists",
                "POST /api/v1/lists",
                "PATCH /api/v1/lists/3",
                "PUT /api/v1/lists/3/novels/7",
                "DELETE /api/v1/lists/3/novels/7",
                "DELETE /api/v1/lists/3",
                "GET /api/v1/updates?since=c1",
                "GET /api/v1/updates",
            ),
            calls,
        )
        assertEquals("""{"name":"Later"}""", server.bodies[1])
        assertEquals("""{"name":"Soon"}""", server.bodies[2])
        assertTrue(server.requests.all { it.header("Authorization") == "Bearer tok" })
    }

    @Test
    fun aRefusedNameCarriesTheServersMessage() = runTest {
        val server = FakeServer { 422 to """{"message":"You already have a list with that name."}""" }
        try {
            ApiClient("http://host:8080", server.client, token = "tok").createList("Favourites")
            fail("expected 422")
        } catch (e: ApiError.Http) {
            assertEquals(422, e.status)
            assertNotNull(e.serverMessage)
            assertEquals("You already have a list with that name.", e.serverMessage)
        }
    }
}
