package studio.visns.novarr.ui.requests

import android.os.SystemClock
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.intOrNull
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.BrowseList
import studio.visns.novarr.api.BrowsePage
import studio.visns.novarr.api.ReaderRequest
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.api.RequestSearch
import studio.visns.novarr.api.SearchResult
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.Categories
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.domain.Requests.Medium
import studio.visns.novarr.domain.Requests.Section
import studio.visns.novarr.domain.RetryAfter

/** "Request it anyway": a request typed in by hand. */
data class FreeTextDraft(
    val title: String = "",
    val medium: Medium = Medium.DEFAULT,
    val link: String = "",
    val note: String = "",
)

/** A 409 `duplicate` on approve: the server's sentence, and "Add anyway" resends with force. */
data class DuplicatePrompt(val request: ReaderRequest, val message: String)

/**
 * The Requests screen: search (kept by the app per session, so it survives
 * leaving the screen), my requests, and the owner's review queue. Writes go
 * straight to the server, never through the offline write queue.
 *
 * A running search is polled every [Requests.POLL_MS] only while the screen
 * is started ([setVisible]); returning picks the polling up again.
 */
class RequestsViewModel(private val app: AppModel, initial: Section) : ViewModel() {
    private val sessionId = app.active?.id
    private val client: ApiClient? get() = app.active?.takeIf { it.id == sessionId }?.client

    var section by mutableStateOf(initial)

    // Search

    var query by mutableStateOf("")

    /** The picker's medium: Books unless the reader chose another (remembered on this device). Set with [selectMedium]. */
    var medium by mutableStateOf(Medium.DEFAULT)
        private set
    private var mediumChosen = false

    /** The picker's options: Comics only when the server knows them (meta `comics`). */
    val mediums: List<Medium> get() = Medium.offered(app.comicsOffered.value)
    var search by mutableStateOf<RequestSearch?>(null)
        private set
    var starting by mutableStateOf(false)
        private set
    var searchError by mutableStateOf<String?>(null)
        private set
    var slow by mutableStateOf(false)
        private set

    /** Search results with "Me too" running (their buttons show progress). */
    var joining by mutableStateOf(setOf<String>())
        private set

    /**
     * Whether "Me too" is offered: false once the server answered one with
     * 409 `already_requested` (a server from before "Me too").
     */
    var canJoin by mutableStateOf(true)
        private set

    /** The search button waits until then (elapsedRealtime) after a 429. */
    var blockedUntil by mutableStateOf<Long?>(null)
        private set
    private var startedAt = 0L
    private var pollJob: Job? = null
    private var visible = false

    val searching: Boolean get() = starting || search?.isRunning == true

    // Lists

    var mine by mutableStateOf<List<ReaderRequest>>(emptyList())
        private set
    var pendingLimit by mutableStateOf<Int?>(null)
        private set
    var canDecide by mutableStateOf(false)
        private set
    var review by mutableStateOf<List<ReaderRequest>>(emptyList())
        private set
    var listsLoaded by mutableStateOf(false)
        private set
    var listsFailure by mutableStateOf<String?>(null)
        private set
    var refreshing by mutableStateOf(false)
        private set
    private var rankJob: Job? = null

    /** Requests with an action running (their buttons show progress). */
    var busy by mutableStateOf(setOf<Int>())
        private set

    /** The chosen shortlist candidate per request; unset means the best one. */
    val choices = mutableStateMapOf<Int, Int>()

    /** The series page URL typed for a request without a shortlist. */
    val urls = mutableStateMapOf<Int, String>()

    // Sheets and dialogs (here, so they survive rotation)

    var requestSheet by mutableStateOf<SearchResult?>(null)
    var requestFromShelf by mutableStateOf(false)
        private set
    var requestNote by mutableStateOf("")
    var freeText by mutableStateOf<FreeTextDraft?>(null)
    var declineSheet by mutableStateOf<ReaderRequest?>(null)
    var declineReason by mutableStateOf("")
    var confirmCancel by mutableStateOf<ReaderRequest?>(null)
    var confirmLeave by mutableStateOf<ReaderRequest?>(null)
    var duplicate by mutableStateOf<DuplicatePrompt?>(null)
    var sending by mutableStateOf(false)
        private set
    var sheetError by mutableStateOf<String?>(null)
    var errorDialog by mutableStateOf<String?>(null)

    /** A one-off line for the snackbar; the screen clears it once shown. */
    var notice by mutableStateOf<String?>(null)

    // Discover shelf (GET /requests/browse), following [medium]

    /** The medium the shelf shows (it follows [medium]). */
    var browseMedium by mutableStateOf<Medium?>(null)
        private set
    var browseLists by mutableStateOf<List<BrowseList>>(emptyList())
        private set

    /** The chosen list's id; null until the server said which list is first. */
    var browseList by mutableStateOf<String?>(null)
        private set
    var browseResults by mutableStateOf<List<SearchResult>>(emptyList())
        private set
    var browseSourceName by mutableStateOf<String?>(null)
        private set
    var browseHasMore by mutableStateOf(false)
        private set
    var browseLoading by mutableStateOf(false)
        private set
    var browseLoadingMore by mutableStateOf(false)
        private set
    var browseError by mutableStateOf<String?>(null)
        private set

    /** The shelf is the saved copy (the server couldn't be reached). */
    var browseFromCache by mutableStateOf(false)
        private set
    var browseLoaded by mutableStateOf(false)
        private set
    private var browsePage = 1

    /** The page each shelf result came from: a request from the shelf names it. */
    private val browsePageOf = mutableMapOf<String, Int>()
    private var browseJob: Job? = null
    private var browseGeneration = 0

    init {
        // The same session's last search, as it was left.
        val memory = app.requestSearchMemory?.takeIf { it.first == sessionId }?.second
        if (memory != null) {
            query = memory.query
            medium = memory.medium
            mediumChosen = true
            search = memory.search
            startedAt = memory.startedAt
            blockedUntil = memory.blockedUntil?.takeIf { it > SystemClock.elapsedRealtime() }
            searchError = memory.blockedMessage.takeIf { blockedUntil != null }
            loadBrowse()
        } else {
            viewModelScope.launch {
                // The reader's last choice on this device (Books by default).
                val saved = runCatching { app.prefs.string(MEDIUM_PREF) }.getOrNull()
                // A saved Comics on a server without comics is Books.
                if (!mediumChosen) medium = Medium.from(saved).takeIf { it in mediums } ?: Medium.DEFAULT
                loadBrowse()
            }
        }
    }

    /** The picker: remembered on this device; the shelf follows. */
    fun selectMedium(next: Medium) {
        mediumChosen = true
        if (next == medium && browseMedium == next) return
        medium = next
        remember()
        viewModelScope.launch { runCatching { app.prefs.setString(MEDIUM_PREF, next.wire) } }
        loadBrowse()
    }

    /** A list chip of the shelf. */
    fun selectBrowseList(id: String) {
        if (id == browseList && browseMedium == medium && browseResults.isNotEmpty()) return
        loadBrowse(id)
    }

    /**
     * Page 1 of [list] (null: the medium's first) for the current medium,
     * cached per medium and list; offline, the saved copy shows.
     */
    fun loadBrowse(list: String? = null) {
        val target = medium
        val sameMedium = browseMedium == target
        browseJob?.cancel()
        val generation = ++browseGeneration
        if (!target.hasDiscover) {
            // No Discover lists for this medium (Comics): no request, no shelf.
            browseMedium = target
            browseList = null
            browseLists = emptyList()
            browseResults = emptyList()
            browseHasMore = false
            browseSourceName = null
            browseError = null
            browseFromCache = false
            browseLoading = false
            browseLoaded = true
            return
        }
        if (!sameMedium) {
            browseLists = emptyList()
            browseSourceName = null
            browseLoaded = false
        }
        if (!sameMedium || (list != null && list != browseList)) {
            browseResults = emptyList()
            browseHasMore = false
        }
        browseMedium = target
        browseList = list ?: browseList.takeIf { sameMedium }
        browseError = null
        browseLoading = true
        val wanted = browseList
        browseJob = viewModelScope.launch {
            try {
                val key = OfflineStore.Key.requestBrowse(target.wire, wanted)
                val loaded = app.load(key) { it.requestBrowse(target.wire, wanted) }
                val page = loaded.value
                if (generation != browseGeneration) return@launch
                if (page != null) {
                    // Saved under its own list too, so choosing that chip later shows it offline.
                    if (wanted == null && !loaded.fromCache) page.list?.let { id -> app.active?.store?.save(page, OfflineStore.Key.requestBrowse(target.wire, id)) }
                    showBrowse(page, append = false)
                    browseFromCache = loaded.fromCache
                    browseError = if (loaded.fromCache) loaded.error?.let(Requests::message) else null
                } else if ((loaded.error as? ApiError.Http)?.status == 404) {
                    // A server from before the shelf (or with it switched off): no shelf, no error.
                    browseLists = emptyList()
                    browseResults = emptyList()
                    browseError = null
                } else {
                    browseError = loaded.error?.let(Requests::message) ?: "Couldn't load this list right now."
                }
            } finally {
                if (generation == browseGeneration) {
                    browseLoading = false
                    browseLoaded = true
                }
            }
        }
    }

    /** The next page of the chosen list (not cached). */
    fun loadMoreBrowse() {
        val client = client ?: return
        val list = browseList ?: return
        val target = browseMedium ?: return
        if (browseLoadingMore || browseLoading || !browseHasMore || browsePage >= Requests.BROWSE_MAX_PAGE) return
        browseLoadingMore = true
        viewModelScope.launch {
            try {
                val next = client.requestBrowse(target.wire, list, browsePage + 1)
                app.noteSuccess()
                if (browseMedium == target && browseList == list) showBrowse(next, append = true)
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                val error = ApiError.from(e)
                app.noteFailure(error)
                notice = Requests.message(error)
            } finally {
                browseLoadingMore = false
            }
        }
    }

    private fun showBrowse(page: BrowsePage, append: Boolean) {
        browseLists = page.lists
        browseList = page.list ?: browseList ?: page.lists.firstOrNull()?.id
        browseSourceName = page.sourceName?.takeIf { it.isNotBlank() }
        browseHasMore = page.hasMore
        browsePage = page.page.coerceAtLeast(1)
        if (!append) browsePageOf.clear()
        page.results.forEach { browsePageOf.putIfAbsent(it.key, browsePage) }
        browseResults = if (append) (browseResults + page.results).distinctBy { it.key } else page.results.distinctBy { it.key }
    }

    private fun remember() {
        val id = sessionId ?: return
        app.requestSearchMemory = id to Requests.SearchMemory(query, medium, search, startedAt, blockedUntil, searchError.takeIf { blockedUntil != null })
    }

    /** The screen is started (true) or stopped: polling runs only in between. */
    fun setVisible(on: Boolean) {
        visible = on
        if (on) {
            pollSearch()
            scheduleRankingRefresh()
        } else {
            pollJob?.cancel()
            rankJob?.cancel()
            remember()
        }
    }

    // Search

    fun startSearch() {
        val text = Requests.searchText(query)
        val client = client ?: return
        if (text.isEmpty() || searching || isBlocked()) return
        pollJob?.cancel()
        searchError = null
        slow = false
        starting = true
        viewModelScope.launch {
            try {
                val started = client.startRequestSearch(text, medium.wire)
                app.noteSuccess()
                search = started
                startedAt = SystemClock.elapsedRealtime()
                remember()
                pollSearch()
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                val error = ApiError.from(e)
                app.noteFailure(error)
                if (error is ApiError.Http && error.status == 429) {
                    blockedUntil = SystemClock.elapsedRealtime() + RetryAfter.seconds(error.retryAfter, System.currentTimeMillis()) * 1000
                }
                searchError = Requests.message(error)
                remember()
            } finally {
                starting = false
            }
        }
    }

    fun isBlocked(now: Long = SystemClock.elapsedRealtime()): Boolean = RetryAfter.remaining(blockedUntil, now) > 0

    /** Called by the countdown once Retry-After has passed. */
    fun unblock() {
        if (isBlocked()) return
        if (blockedUntil != null) {
            blockedUntil = null
            searchError = null
            remember()
        }
    }

    private fun pollSearch() {
        pollJob?.cancel()
        val current = search ?: return
        if (!visible || !current.isRunning) return
        pollJob = viewModelScope.launch {
            var id = current.id
            while (isActive) {
                slow = SystemClock.elapsedRealtime() - startedAt >= Requests.SLOW_AFTER_MS
                delay(Requests.POLL_MS)
                val client = client ?: break
                try {
                    val next = client.requestSearch(id)
                    app.noteSuccess()
                    search = next
                    id = next.id
                    if (!next.isRunning) break
                } catch (e: Exception) {
                    e.rethrowIfCancellation()
                    val error = ApiError.from(e)
                    app.noteFailure(error)
                    // Offline for a moment: keep asking; the search goes on without us.
                    if (error.isConnectivity) continue
                    // 404 search_expired (or anything else): say so; the user searches again.
                    search = null
                    searchError = Requests.message(error)
                    break
                }
            }
            slow = false
            remember()
        }
    }

    /** [fromShelf]: the result is one of the Discover shelf's, requested by its list and page. */
    fun openRequestSheet(result: SearchResult, fromShelf: Boolean = false) {
        requestSheet = result
        requestFromShelf = fromShelf
        requestNote = ""
        sheetError = null
    }

    fun openFreeText() {
        // A pasted link is not a title: the free-text form starts empty then (the link goes in its own field).
        val typed = search?.query ?: query.trim()
        val isLink = Requests.looksLikeUrl(typed)
        freeText = FreeTextDraft(
            title = if (isLink) "" else typed.take(255),
            medium = Medium.from(search?.medium, medium),
            link = if (isLink) typed.take(Requests.LINK_MAX) else "",
        )
        sheetError = null
    }

    /** POST /requests for a shelf result: its medium, list and page, and the key. */
    private suspend fun createFromShelf(client: ApiClient, result: SearchResult, note: String?, categories: List<Int>? = null) =
        client.createRequestFromBrowse(
            (browseMedium ?: medium).wire,
            browseList ?: error("no list"),
            browsePageOf[result.key] ?: 1,
            result.key,
            note,
            categories,
        )

    fun sendRequest() {
        val result = requestSheet ?: return
        val shelf = requestFromShelf
        val searchId = search?.id
        if (!shelf && searchId == null) return
        val client = client ?: return
        sending = true
        sheetError = null
        viewModelScope.launch {
            try {
                val note = requestNote.trim().takeIf { it.isNotEmpty() }?.take(Requests.NOTE_MAX)
                if (shelf) createFromShelf(client, result, note) else client.createRequest(searchId!!, result.key, note)
                app.noteSuccess()
                updateResults { Requests.markRequested(it, result.key) }
                requestSheet = null
                notice = "Requested. You'll hear when the owner decides."
                loadLists()
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                val error = ApiError.from(e)
                app.noteFailure(error)
                if (correctCard(error, result)) requestSheet = null else sheetError = Requests.message(error)
            } finally {
                sending = false
            }
        }
    }

    /**
     * "Me too" on someone else's request: the same POST as Request, without
     * a note. The server joins their request (200, joined), so this account
     * is told when it's ready; an older server refuses with 409
     * `already_requested`, and then the card just says "Requested".
     */
    fun meToo(result: SearchResult, fromShelf: Boolean = false) {
        val searchId = search?.id
        if (!fromShelf && searchId == null) return
        val client = client ?: return
        if (result.key in joining) return
        joining = joining + result.key
        viewModelScope.launch {
            try {
                val created = if (fromShelf) createFromShelf(client, result, null) else client.createRequest(searchId!!, result.key, null)
                app.noteSuccess()
                updateResults { Requests.markRequested(it, result.key) }
                notice = if (created.joined) Requests.JOINED_NOTICE else "Requested. You'll hear when the owner decides."
                loadLists()
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                val error = ApiError.from(e)
                app.noteFailure(error)
                if (Requests.code(error) == "already_requested") canJoin = false
                if (!correctCard(error, result)) errorDialog = Requests.message(error)
            } finally {
                joining = joining - result.key
            }
        }
    }

    /**
     * A refusal that means the card was out of date (in the library now,
     * already requested): corrects the card and says why. False for any
     * other error, which the caller shows.
     */
    private fun correctCard(error: ApiError, result: SearchResult): Boolean {
        val body = (error as? ApiError.Http)?.body
        return when (Requests.code(error)) {
            "in_library" -> {
                val novelId = (body?.get("novel_id") as? JsonPrimitive)?.intOrNull
                updateResults { list -> list.map { if (it.key == result.key) it.copy(inLibrary = true, novelId = novelId ?: it.novelId) else it } }
                errorDialog = Requests.message(error)
                true
            }
            "already_requested" -> {
                val byMe = (body?.get("by_me") as? JsonPrimitive)?.booleanOrNull ?: false
                updateResults { Requests.markTaken(it, result.key, byMe) }
                errorDialog = Requests.message(error)
                true
            }
            else -> false
        }
    }

    fun sendFreeText() {
        val draft = freeText ?: return
        val client = client ?: return
        if (draft.title.isBlank()) return
        sending = true
        sheetError = null
        viewModelScope.launch {
            try {
                client.createFreeTextRequest(
                    draft.title.trim(),
                    draft.medium.wire,
                    draft.link.trim().takeIf { it.isNotEmpty() },
                    draft.note.trim().takeIf { it.isNotEmpty() }?.take(Requests.NOTE_MAX),
                )
                app.noteSuccess()
                freeText = null
                notice = "Requested. You'll hear when the owner decides."
                loadLists()
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                app.noteFailure(ApiError.from(e))
                sheetError = Requests.message(e)
            } finally {
                sending = false
            }
        }
    }

    // Long-press a card: "Add to category"

    /** The card whose "Add to category" sheet is open (null: closed). */
    var categorySheet by mutableStateOf<SearchResult?>(null)
        private set
    private var categoryFromShelf = false
    var categorySending by mutableStateOf(false)
        private set

    fun openCategorySheet(result: SearchResult, fromShelf: Boolean) {
        categorySheet = result
        categoryFromShelf = fromShelf
    }

    fun closeCategorySheet() {
        if (!categorySending) categorySheet = null
    }

    /**
     * The sheet's Save: in the library, one memberships call; not yet, a
     * request (new, "Me too", or the reader's own updated) carrying the
     * categories, which the server fills once the series arrives. The
     * card then shows where it went (greyed, "In …" / "Requested · …").
     */
    fun confirmCategories(chosen: Set<Int>) {
        val result = categorySheet ?: return
        val action = Categories.browseAction(result, chosen)
        val fromShelf = categoryFromShelf
        val searchId = search?.id
        val client = client ?: return
        when (action) {
            Categories.BrowseAction.Nothing -> categorySheet = null
            is Categories.BrowseAction.Memberships -> {
                categorySending = true
                viewModelScope.launch {
                    val failure = app.lists.setMemberships(listOf(action.novelId), action.add, action.remove)
                    categorySending = false
                    if (failure == null) {
                        updateResults { list -> list.map { if (it.key == result.key) Categories.afterBrowse(it, action) else it } }
                        categorySheet = null
                        notice = "Saved."
                    } else {
                        notice = failure
                    }
                }
            }
            is Categories.BrowseAction.Request -> {
                if (!fromShelf && searchId == null) return
                categorySending = true
                viewModelScope.launch {
                    try {
                        val created = if (fromShelf) createFromShelf(client, result, null, action.categories) else client.createRequest(searchId!!, result.key, null, action.categories)
                        app.noteSuccess()
                        updateResults { list -> list.map { if (it.key == result.key) Categories.afterBrowse(it, action) else it } }
                        categorySheet = null
                        notice = when {
                            created.joined -> "Me too: you'll be told when it's ready, and it goes into your categories."
                            else -> "Requested. Once it's in the library it goes into your categories."
                        }
                        loadLists()
                    } catch (e: Exception) {
                        e.rethrowIfCancellation()
                        val error = ApiError.from(e)
                        app.noteFailure(error)
                        val body = (error as? ApiError.Http)?.body
                        when (Requests.code(error)) {
                            // In the library already: the server put it in the categories straight away.
                            "in_library" -> {
                                val novelId = (body?.get("novel_id") as? JsonPrimitive)?.intOrNull
                                val added = ids(body, "added_to_categories") ?: action.categories
                                updateResults { list ->
                                    list.map { if (it.key == result.key) it.copy(inLibrary = true, novelId = novelId ?: it.novelId, inCategories = (it.inCategories + added).distinct().sorted()) else it }
                                }
                                categorySheet = null
                                notice = "It's in the library already, so it was added to your categories."
                                app.lists.refresh()
                                app.libraryChanged()
                            }
                            // Asked for already: the server updated (or, by someone else on an older server, kept) the choice.
                            "already_requested" -> {
                                val byMe = (body?.get("by_me") as? JsonPrimitive)?.booleanOrNull ?: false
                                val remembered = ids(body, "requested_categories")
                                updateResults { list ->
                                    Requests.markTaken(list, result.key, byMe).map {
                                        if (it.key == result.key && remembered != null) it.copy(requestedCategories = remembered) else it
                                    }
                                }
                                categorySheet = null
                                notice = if (remembered != null) "Saved: it goes into your categories once it's in the library." else Requests.message(error)
                            }
                            else -> notice = Requests.message(error)
                        }
                    } finally {
                        categorySending = false
                    }
                }
            }
        }
    }

    /** An array of ids in an error body ([key]); null when the server sent none (an older server). */
    private fun ids(body: kotlinx.serialization.json.JsonObject?, key: String): List<Int>? =
        (body?.get(key) as? kotlinx.serialization.json.JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.intOrNull }

    /** Applies to the search's results and the shelf's alike (a group key names the same series in both). */
    private fun updateResults(transform: (List<SearchResult>) -> List<SearchResult>) {
        search = search?.let { it.copy(results = transform(it.results)) }
        browseResults = transform(browseResults)
        remember()
    }

    // Lists

    fun loadLists(pull: Boolean = false) {
        val client = client ?: return
        if (pull) refreshing = true
        viewModelScope.launch {
            try {
                val first = client.requests("mine")
                val all = first.requests.toMutableList()
                var page = first
                while (page.hasMore && page.page < MAX_PAGES) {
                    page = client.requests("mine", page = page.page + 1)
                    all += page.requests
                }
                mine = all.distinctBy { it.id }
                pendingLimit = first.pendingLimit
                canDecide = first.canDecide
                review = if (first.canDecide) loadReview(client) else emptyList()
                listsFailure = null
                app.noteSuccess()
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                val error = ApiError.from(e)
                app.noteFailure(error)
                listsFailure = Requests.message(error)
            } finally {
                listsLoaded = true
                refreshing = false
            }
            scheduleRankingRefresh()
        }
    }

    /** Everyone's pending requests, every page, oldest first. */
    private suspend fun loadReview(client: ApiClient): List<ReaderRequest> {
        val all = mutableListOf<ReaderRequest>()
        var page = 0
        do {
            val next = client.requests("all", status = "pending", page = ++page)
            all += next.requests
        } while (next.hasMore && page < MAX_PAGES)
        return Requests.reviewQueue(all)
    }

    /** While a manga shortlist is being ranked, look again every few seconds (screen visible only). */
    private fun scheduleRankingRefresh() {
        rankJob?.cancel()
        if (!visible || review.none(Requests::rankingPending)) return
        rankJob = viewModelScope.launch {
            delay(RANK_REFRESH_MS)
            loadLists()
        }
    }

    // Actions

    private fun act(request: ReaderRequest, block: suspend (ApiClient) -> Unit) {
        val client = client ?: return
        if (request.id in busy) return
        busy = busy + request.id
        viewModelScope.launch {
            try {
                block(client)
                app.noteSuccess()
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                val error = ApiError.from(e)
                app.noteFailure(error)
                if (Requests.code(error) == "duplicate") {
                    duplicate = DuplicatePrompt(request, Requests.message(error))
                } else {
                    errorDialog = Requests.message(error)
                }
            } finally {
                busy = busy - request.id
            }
        }
    }

    fun cancel(request: ReaderRequest) {
        confirmCancel = null
        act(request) { client ->
            client.cancelRequest(request.id)
            mine = mine.filterNot { it.id == request.id }
            notice = "Request cancelled."
            loadLists()
        }
    }

    /** Leaves a request this account joined with "Me too" (DELETE, 204): no longer told about it. */
    fun leave(request: ReaderRequest) {
        confirmLeave = null
        act(request) { client ->
            client.cancelRequest(request.id)
            mine = mine.filterNot { it.id == request.id }
            notice = "You left the request for ${request.title}."
            loadLists()
        }
    }

    /** [force]: "Add anyway" after a 409 duplicate. */
    fun approve(request: ReaderRequest, force: Boolean = false) {
        duplicate = null
        val url = urls[request.id]?.trim().orEmpty()
        val needsUrl = Requests.needsUrl(request)
        if (needsUrl && url.isEmpty()) return
        act(request) { client ->
            val result = if (needsUrl) {
                client.approveRequest(request.id, url = url, title = request.title, force = force)
            } else {
                client.approveRequest(request.id, candidate = Requests.selectedCandidate(request.candidates, choices[request.id]), force = force)
            }
            review = review.filterNot { it.id == request.id }
            notice = if (result.created) "Approved. ${request.title} is being added to the library." else "Approved. ${request.title} is already in the library."
            app.libraryChanged()
            loadLists()
        }
    }

    fun openDecline(request: ReaderRequest) {
        declineSheet = request
        declineReason = ""
        sheetError = null
    }

    fun decline() {
        val request = declineSheet ?: return
        val client = client ?: return
        sending = true
        sheetError = null
        viewModelScope.launch {
            try {
                client.declineRequest(request.id, declineReason.trim().takeIf { it.isNotEmpty() }?.take(Requests.NOTE_MAX))
                app.noteSuccess()
                declineSheet = null
                review = review.filterNot { it.id == request.id }
                notice = "Declined."
                loadLists()
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                app.noteFailure(ApiError.from(e))
                sheetError = Requests.message(e)
            } finally {
                sending = false
            }
        }
    }

    fun rerank(request: ReaderRequest) = act(request) { client ->
        val updated = client.rerankRequest(request.id)
        review = review.map { if (it.id == updated.id) updated else it }
        scheduleRankingRefresh()
    }

    private companion object {
        const val MEDIUM_PREF = "requests.medium"
        const val MAX_PAGES = 10
        const val RANK_REFRESH_MS = 5_000L
    }
}
