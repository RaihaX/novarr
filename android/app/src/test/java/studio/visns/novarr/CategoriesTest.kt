package studio.visns.novarr

import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.BrowsePage
import studio.visns.novarr.api.BulkReadAnswer
import studio.visns.novarr.api.ListsEnvelope
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.api.RequestEnvelope
import studio.visns.novarr.api.ResultRequested
import studio.visns.novarr.api.SearchEnvelope
import studio.visns.novarr.api.SearchResult
import studio.visns.novarr.domain.Categories
import studio.visns.novarr.domain.Categories.BrowseAction
import studio.visns.novarr.domain.Categories.Tab
import studio.visns.novarr.domain.Categories.Tri
import studio.visns.novarr.domain.HomeShelves
import studio.visns.novarr.domain.HomeShelves.Section
import studio.visns.novarr.domain.ReadingLists
import studio.visns.novarr.store.HomeLayout

/** 1.18's categories: multi-select, the tri-state sheet, the Library tabs, Browse marks, Home's sections and the wire format. */
class CategoriesTest {
    private val want = ReadingList(10, "Want to read", 1, listOf(3), kind = ReadingList.KIND_WANT_TO_READ, position = 0)
    private val favourites = ReadingList(11, "Favourites", 2, listOf(2, 1), kind = ReadingList.KIND_FAVOURITES, position = 1)
    private val action = ReadingList(12, "Action", 2, listOf(1, 2), position = 3)
    private val comfort = ReadingList(13, "Comfort reads", 1, listOf(4), position = 2)
    private val empty = ReadingList(14, "Someday", 0, emptyList(), position = 4)
    private val lists = listOf(want, favourites, action, comfort, empty)

    private val novels = listOf(
        NovelSummary(1, "One", medium = "manga", chaptersTotal = 10, chaptersRead = 4, readPercent = 40),
        NovelSummary(2, "Two", chaptersTotal = 5, chaptersRead = 0),
        NovelSummary(3, "Three", medium = "book", chaptersTotal = 1),
        NovelSummary(4, "Four", chaptersTotal = 3, chaptersRead = 3, readPercent = 100, finished = true),
        NovelSummary(5, "Five", chaptersTotal = 7, chaptersNew = 2),
    )
    private val byId = novels.associateBy { it.id }

    // Selection

    @Test
    fun longPressStartsSelectingAndTapsToggle() {
        var s = Categories.Selection<Int>()
        assertFalse(s.active)
        // Not selecting: a tap opens the item (null), nothing changes.
        assertNull(s.tap(1))
        s = s.longPress(1)
        assertTrue(s.active)
        assertEquals(setOf(1), s.keys)
        s = s.tap(2)!!
        s = s.tap(3)!!
        assertEquals(3, s.count)
        // A long-press while selecting toggles too.
        s = s.longPress(3)
        assertEquals(setOf(1, 2), s.keys)
        s = s.tap(2)!!
        // Taking the last one out ends selecting.
        s = s.tap(1)!!
        assertFalse(s.active)
        assertNull(s.tap(1))
    }

    @Test
    fun selectAllRetainAndExit() {
        var s = Categories.Selection<Int>().longPress(2)
        assertFalse(s.allSelected(listOf(1, 2, 3)))
        s = s.selectAll(listOf(1, 2, 3))
        assertEquals(setOf(1, 2, 3), s.keys)
        assertTrue(s.allSelected(listOf(1, 2, 3)))
        // Select all does nothing while not selecting.
        assertFalse(Categories.Selection<Int>().selectAll(listOf(1, 2)).active)
        // A series gone after a refresh is no longer selected; unchanged keeps the same object.
        assertEquals(setOf(1, 3), s.retain(listOf(1, 3, 9)).keys)
        assertSame(s, s.retain(listOf(1, 2, 3, 4)))
        assertFalse(s.retain(emptyList()).active)
        assertFalse(s.exit().active)
    }

    // The tri-state sheet

    @Test
    fun initialBoxesFollowTheSelection() {
        val boxes = Categories.initial(lists, listOf(1, 2))
        assertEquals(Tri.ON, boxes[11]) // both favourites
        assertEquals(Tri.ON, boxes[12]) // both action
        assertEquals(Tri.OFF, boxes[13])
        assertEquals(Tri.OFF, boxes[10])
        val mixed = Categories.initial(lists, listOf(1, 4))
        assertEquals(Tri.MIXED, mixed[11])
        assertEquals(Tri.MIXED, mixed[13])
        assertEquals(Tri.OFF, mixed[14])
        // Hidden-on-Home and built-ins are all there.
        assertEquals(lists.map { it.id }.toSet(), boxes.keys)
    }

    @Test
    fun tapsCycleMixedTickedClear() {
        assertEquals(Tri.ON, Categories.next(Tri.MIXED, Tri.MIXED))
        assertEquals(Tri.OFF, Categories.next(Tri.ON, Tri.MIXED))
        assertEquals(Tri.MIXED, Categories.next(Tri.OFF, Tri.MIXED))
        // A box that started ticked or clear never becomes mixed.
        assertEquals(Tri.ON, Categories.next(Tri.OFF, Tri.ON))
        assertEquals(Tri.OFF, Categories.next(Tri.ON, Tri.OFF))
    }

    @Test
    fun applySendsOnlyWhatChanged() {
        val initial = mapOf(11 to Tri.MIXED, 12 to Tri.ON, 13 to Tri.OFF, 14 to Tri.OFF)
        assertTrue(Categories.diff(initial, initial).isEmpty)
        val current = mapOf(11 to Tri.MIXED, 12 to Tri.OFF, 13 to Tri.ON, 14 to Tri.OFF, 99 to Tri.ON)
        val diff = Categories.diff(initial, current)
        // 99: made in the sheet (unknown before): counts as clear before, so it is added.
        assertEquals(listOf(13, 99), diff.add)
        assertEquals(listOf(12), diff.remove)
        // Mixed → ticked adds, mixed → clear removes, mixed left alone does nothing.
        assertEquals(Categories.Diff(listOf(11), emptyList()), Categories.diff(initial, initial + (11 to Tri.ON)))
        assertEquals(Categories.Diff(emptyList(), listOf(11)), Categories.diff(initial, initial + (11 to Tri.OFF)))
    }

    @Test
    fun membershipsShowAtOnce() {
        val after = Categories.applyMemberships(lists, listOf(4, 5), add = listOf(12, 11), remove = listOf(13))
        val byList = after.associateBy { it.id }
        assertEquals(listOf(1, 2, 4, 5), byList.getValue(12).novelIds)
        assertEquals(4, byList.getValue(12).count)
        // The built-ins are newest first.
        assertEquals(listOf(4, 5, 2, 1), byList.getValue(11).novelIds)
        assertEquals(emptyList<Int>(), byList.getValue(13).novelIds)
        assertEquals(0, byList.getValue(13).count)
        // Already in: unchanged (idempotent).
        assertEquals(action, Categories.applyMemberships(lists, listOf(1), listOf(12), emptyList()).first { it.id == 12 })
    }

    // Mark as read / unread

    @Test
    fun markedReadAndUnread() {
        val read = Categories.marked(byId.getValue(5), read = true)
        assertEquals(7, read.chaptersRead)
        assertEquals(0, read.chaptersNew)
        assertEquals(100, read.readPercent)
        assertTrue(read.finished)
        val unread = Categories.marked(byId.getValue(4), read = false)
        assertEquals(0, unread.chaptersRead)
        assertFalse(unread.finished)
        assertEquals(3, Categories.unread(unread))
        assertEquals(6, Categories.unread(byId.getValue(1)))
        assertEquals("Marked 3 series as read", Categories.markedLabel(3, true))
        assertEquals("Marked 1 series as unread", Categories.markedLabel(1, false))
    }

    // Library tabs

    @Test
    fun tabsAreAllThenCategoriesInOrderThenUncategorised() {
        val tabs = Categories.tabs(lists)
        assertEquals(listOf("All", "Want to read", "Favourites", "Comfort reads", "Action", "Someday", "Uncategorised"), tabs.map(Categories::title))
        assertEquals(Tab.All, tabs.first())
        assertEquals(Tab.Uncategorised, tabs.last())
    }

    @Test
    fun uncategorisedIsTheSeriesInNoCategory() {
        val list = Categories.asList(Tab.Uncategorised, lists, novels)!!
        assertEquals(listOf(5), list.novelIds)
        assertEquals(Categories.UNCATEGORISED_ID, list.id)
        assertNull(Categories.asList(Tab.All, lists, novels))
        assertEquals(action, Categories.asList(Tab.Category(action), lists, novels))
    }

    @Test
    fun rememberedTabKeys() {
        assertEquals(Tab.All, Tab.from(null, lists))
        // 1.16 saved "" for all series, and a list id for a list.
        assertEquals(Tab.All, Tab.from("", lists))
        assertEquals(Tab.Category(action), Tab.from("12", lists))
        assertEquals(Tab.Uncategorised, Tab.from("none", lists))
        // Deleted elsewhere: null, so the screen goes back to All.
        assertNull(Tab.from("99", lists))
        assertEquals("12", Tab.Category(action).key)
    }

    @Test
    fun sortedFollowsPositionsElseThe116Order() {
        assertEquals(listOf(10, 11, 13, 12, 14), ReadingLists.sorted(lists).map { it.id })
        // An older server sends no positions: Want to read, Favourites, then by name.
        val old = lists.map { it.copy(position = 0) }
        assertEquals(listOf(10, 11, 12, 13, 14), ReadingLists.sorted(old).map { it.id })
    }

    // Browse / Discover

    private val fresh = SearchResult("k1", "Fresh")

    @Test
    fun browseMarksAndBadges() {
        assertNull(Categories.mark(fresh, lists))
        assertEquals("In Action", Categories.mark(fresh.copy(inLibrary = true, novelId = 1, inCategories = listOf(12)), lists)?.badge)
        // Several: the first in the reader's order, and how many more.
        assertEquals("In Favourites +1", Categories.mark(fresh.copy(inLibrary = true, novelId = 1, inCategories = listOf(12, 11)), lists)?.badge)
        assertEquals("Requested · Comfort reads", Categories.mark(fresh.copy(requested = ResultRequested("pending", true), requestedCategories = listOf(13)), lists)?.badge)
        // The reader's own request without categories still greys the card.
        assertEquals("Requested", Categories.mark(fresh.copy(requested = ResultRequested("pending", true)), lists)?.badge)
        // Someone else's request, or in the library in no category: shown as usual.
        assertNull(Categories.mark(fresh.copy(requested = ResultRequested("pending", false)), lists))
        assertNull(Categories.mark(fresh.copy(inLibrary = true, novelId = 1), lists))
        // An id the lists don't have (deleted since) doesn't name anything.
        assertNull(Categories.mark(fresh.copy(inLibrary = true, novelId = 1, inCategories = listOf(404)), lists))
    }

    @Test
    fun browseConfirm() {
        val inLibrary = fresh.copy(inLibrary = true, novelId = 7, inCategories = listOf(12))
        assertEquals(setOf(12), Categories.preTicked(inLibrary))
        assertEquals(BrowseAction.Memberships(7, add = listOf(13), remove = listOf(12)), Categories.browseAction(inLibrary, setOf(13)))
        assertEquals(BrowseAction.Nothing, Categories.browseAction(inLibrary, setOf(12)))
        // Not in the library: a request with the categories; nothing chosen asks for nothing.
        assertEquals(BrowseAction.Request(listOf(12, 13)), Categories.browseAction(fresh, setOf(13, 12)))
        assertEquals(BrowseAction.Nothing, Categories.browseAction(fresh, emptySet()))
        // The reader's own request: changing (or clearing) the choice resends it.
        val mine = fresh.copy(requested = ResultRequested("pending", true), requestedCategories = listOf(12))
        assertEquals(setOf(12), Categories.preTicked(mine))
        assertEquals(BrowseAction.Nothing, Categories.browseAction(mine, setOf(12)))
        assertEquals(BrowseAction.Request(emptyList()), Categories.browseAction(mine, emptySet()))
        // Someone else's: "Me too" with categories.
        assertEquals(BrowseAction.Request(listOf(11)), Categories.browseAction(fresh.copy(requested = ResultRequested("approved", false)), setOf(11)))
    }

    @Test
    fun cardAfterConfirm() {
        val requested = Categories.afterBrowse(fresh.copy(requested = ResultRequested("approved", false)), BrowseAction.Request(listOf(13)))
        assertEquals(listOf(13), requested.requestedCategories)
        assertEquals(ResultRequested("approved", true), requested.requested)
        assertEquals("Requested · Comfort reads", Categories.mark(requested, lists)?.badge)
        val moved = Categories.afterBrowse(fresh.copy(inLibrary = true, novelId = 7, inCategories = listOf(12)), BrowseAction.Memberships(7, listOf(13), listOf(12)))
        assertEquals(listOf(13), moved.inCategories)
    }

    // Home

    private fun sections(
        lists: List<ReadingList> = this.lists,
        layout: HomeLayout = HomeLayout(),
        offered: Boolean = true,
        loaded: Boolean = true,
        stats: Boolean = false,
    ) = HomeShelves.sections(lists, byId, layout, listsOffered = offered, listsLoaded = loaded, hasStats = stats)

    @Test
    fun homeIsTheVisibleNonEmptyCategoriesInOrder() {
        val hidden = lists.map { if (it.id == 11) it.copy(hiddenOnHome = true) else it }
        val shown = sections(hidden)
        assertEquals(
            listOf("search", "category-10", "category-13", "category-12", "manage-categories", "new-chapters", "library"),
            shown.map { it.key },
        )
        // Want to read's row: its series from the library, and the count.
        val wantRow = shown.filterIsInstance<Section.Category>().first()
        assertEquals(listOf(3), wantRow.novels.map { it.id })
        assertEquals(1, wantRow.count)
        // Nothing of 1.16's by default.
        assertTrue(shown.none { it is Section.Continue || it is Section.Favourites || it is Section.WantToRead || it is Section.InProgress || it is Section.Folders })
    }

    @Test
    fun theStatsSlotOnlyWithACard() {
        assertTrue(Section.Stats !in sections())
        val withStats = sections(stats = true)
        assertEquals(listOf(Section.Search, Section.Stats), withStats.take(2))
        // In the first-run layout too.
        assertEquals(Section.Stats, sections(lists = listOf(empty), stats = true)[1])
    }

    @Test
    fun settingsBringBackTheOldShelves() {
        val layout = HomeLayout(showContinue = true, showFavourites = true, showWantToRead = true, showInProgress = true)
        val shown = sections(layout = layout).map { it.key }
        assertEquals(
            listOf("search", "quick", "favourites", "want-to-read", "in-progress", "category-13", "category-12", "manage-categories", "new-chapters", "library"),
            shown,
        )
        // Favourites back as its own shelf isn't repeated as a category; Want to read still is.
        val onlyFavourites = sections(layout = HomeLayout(showFavourites = true)).map { it.key }
        assertTrue("favourites" in onlyFavourites && "category-11" !in onlyFavourites && "category-10" in onlyFavourites)
    }

    @Test
    fun firstRunKeepsThe116Layout() {
        // Built-ins empty and the reader's own categories empty (or none): "Create your first category" and 1.16's shelves.
        val nothing = listOf(want.copy(novelIds = emptyList(), count = 0), favourites.copy(novelIds = emptyList(), count = 0), empty)
        assertTrue(HomeShelves.firstRun(nothing, listsOffered = true, listsLoaded = true))
        assertEquals(
            listOf("search", "first-category", "quick", "favourites", "want-to-read", "in-progress", "folders", "new-chapters", "library"),
            sections(lists = nothing).map { it.key },
        )
        // One series anywhere (a built-in counts) ends it.
        assertFalse(HomeShelves.firstRun(nothing + action, listsOffered = true, listsLoaded = true))
        // A server without categories: 1.16's layout without the card or folders.
        assertEquals(
            listOf("search", "quick", "in-progress", "new-chapters", "library"),
            sections(lists = emptyList(), offered = false).map { it.key },
        )
        // Still loading the categories: neither layout yet.
        assertEquals(listOf("search", "new-chapters", "library"), sections(lists = emptyList(), loaded = false).map { it.key })
    }

    // Wire format

    @Test
    fun decodesTheNewListFieldsWithDefaults() {
        val decoded = NovarrJson.decodeFromString(
            ListsEnvelope.serializer(),
            """{"lists":[{"id":1,"name":"A","count":1,"novel_ids":[5],"kind":null,"position":2,"hidden_on_home":true,"extra":1},{"id":2,"name":"B"}]}""",
        ).lists
        assertEquals(2, decoded[0].position)
        assertTrue(decoded[0].hiddenOnHome)
        // An older server: position 0, shown on Home.
        assertEquals(0, decoded[1].position)
        assertFalse(decoded[1].hiddenOnHome)
    }

    @Test
    fun decodesResultAndRequestCategories() {
        val search = NovarrJson.decodeFromString(
            SearchEnvelope.serializer(),
            """{"search":{"id":"s","status":"done","results":[
                {"key":"a","title":"A","in_library":true,"novel_id":3,"in_categories":[12,"x",13],"requested_categories":[]},
                {"key":"b","title":"B","requested":{"status":"pending","by_me":true},"requested_categories":[14]},
                {"key":"c","title":"C","in_categories":null}
            ]}}""",
        ).search
        // One unreadable id is skipped, never the whole result.
        assertEquals(listOf(12, 13), search.results[0].inCategories)
        assertEquals(listOf(14), search.results[1].requestedCategories)
        assertEquals(emptyList<Int>(), search.results[2].inCategories)
        assertEquals(emptyList<Int>(), search.results[2].requestedCategories)
        val browse = NovarrJson.decodeFromString(BrowsePage.serializer(), """{"results":[{"key":"z","in_categories":[1]}]}""")
        assertEquals(listOf(1), browse.results[0].inCategories)
        val request = NovarrJson.decodeFromString(RequestEnvelope.serializer(), """{"request":{"id":9,"requested_categories":[2,3]}}""").request
        assertEquals(listOf(2, 3), request.requestedCategories)
        assertEquals(emptyList<Int>(), NovarrJson.decodeFromString(RequestEnvelope.serializer(), """{"request":{"id":9}}""").request.requestedCategories)
        assertEquals(BulkReadAnswer(true, 2, 31), NovarrJson.decodeFromString(BulkReadAnswer.serializer(), """{"success":true,"count":2,"chapters":31}"""))
    }

    @Test
    fun clientSpeaksTheNewEndpoints() = runTest {
        val index = """{"lists":[{"id":3,"name":"Later","count":1,"novel_ids":[7],"position":0,"hidden_on_home":false}]}"""
        val server = FakeServer { request ->
            when (request.url.encodedPath) {
                "/api/v1/lists/order", "/api/v1/lists/memberships" -> 200 to index
                "/api/v1/lists/3" -> 200 to """{"list":{"id":3,"name":"Later","hidden_on_home":true}}"""
                "/api/v1/novels/bulk-read" -> 200 to """{"success":true,"count":2,"chapters":9}"""
                "/api/v1/requests/5/categories" -> 200 to """{"request":{"id":5,"requested_categories":[3]}}"""
                else -> 201 to """{"request":{"id":5},"joined":false}"""
            }
        }
        val client = ApiClient("http://host:8080", server.client, token = "tok")
        assertEquals(listOf(3), client.reorderLists(listOf(3, 1)).map { it.id })
        assertTrue(client.setListHiddenOnHome(3, true).hiddenOnHome)
        assertEquals(1, client.setMemberships(listOf(7, 8), add = listOf(3), remove = listOf(4)).size)
        assertEquals(9, client.bulkReadNovels(listOf(7, 8), read = true).chapters)
        assertEquals(listOf(3), client.setRequestCategories(5, listOf(3)).requestedCategories)
        client.createRequest("s1", "k", null, categories = listOf(3))
        client.createRequestFromBrowse("manga", "popular", 2, "k2", null, categories = listOf(3, 4))
        // No categories chosen: the body is as before.
        client.createRequest("s1", "k3", null)

        val calls = server.requests.map { "${it.method} ${it.url.encodedPath}" }
        assertEquals(
            listOf(
                "PATCH /api/v1/lists/order",
                "PATCH /api/v1/lists/3",
                "PUT /api/v1/lists/memberships",
                "POST /api/v1/novels/bulk-read",
                "PUT /api/v1/requests/5/categories",
                "POST /api/v1/requests",
                "POST /api/v1/requests",
                "POST /api/v1/requests",
            ),
            calls,
        )
        assertEquals("""{"ids":[3,1]}""", server.bodies[0])
        assertEquals("""{"hidden_on_home":true}""", server.bodies[1])
        assertEquals("""{"novel_ids":[7,8],"add":[3],"remove":[4]}""", server.bodies[2])
        assertEquals("""{"ids":[7,8],"read":true}""", server.bodies[3])
        assertEquals("""{"categories":[3]}""", server.bodies[4])
        assertEquals("""{"search_id":"s1","key":"k","categories":[3]}""", server.bodies[5])
        assertEquals("""{"list":"popular","page":2,"key":"k2","medium":"manga","categories":[3,4]}""", server.bodies[6])
        assertEquals("""{"search_id":"s1","key":"k3"}""", server.bodies[7])
    }
}
