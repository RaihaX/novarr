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
import studio.visns.novarr.api.ReaderRequest
import studio.visns.novarr.api.RequestSearch
import studio.visns.novarr.api.SearchResult
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.domain.Requests.Medium
import studio.visns.novarr.domain.Requests.Section
import studio.visns.novarr.domain.RetryAfter

/** "Request it anyway": a request typed in by hand. */
data class FreeTextDraft(
    val title: String = "",
    val medium: Medium = Medium.NOVEL,
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
    var medium by mutableStateOf(Medium.NOVEL)
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

    init {
        // The same session's last search, as it was left.
        app.requestSearchMemory?.takeIf { it.first == sessionId }?.second?.let { memory ->
            query = memory.query
            medium = memory.medium
            search = memory.search
            startedAt = memory.startedAt
            blockedUntil = memory.blockedUntil?.takeIf { it > SystemClock.elapsedRealtime() }
            searchError = memory.blockedMessage.takeIf { blockedUntil != null }
        }
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
        val text = query.trim().take(Requests.QUERY_MAX)
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

    fun openRequestSheet(result: SearchResult) {
        requestSheet = result
        requestNote = ""
        sheetError = null
    }

    fun openFreeText() {
        freeText = FreeTextDraft(title = search?.query ?: query.trim(), medium = Medium.from(search?.medium ?: medium.wire))
        sheetError = null
    }

    fun sendRequest() {
        val result = requestSheet ?: return
        val searchId = search?.id ?: return
        val client = client ?: return
        sending = true
        sheetError = null
        viewModelScope.launch {
            try {
                client.createRequest(searchId, result.key, requestNote.trim().takeIf { it.isNotEmpty() }?.take(Requests.NOTE_MAX))
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
    fun meToo(result: SearchResult) {
        val searchId = search?.id ?: return
        val client = client ?: return
        if (result.key in joining) return
        joining = joining + result.key
        viewModelScope.launch {
            try {
                val created = client.createRequest(searchId, result.key, null)
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

    private fun updateResults(transform: (List<SearchResult>) -> List<SearchResult>) {
        search = search?.let { it.copy(results = transform(it.results)) }
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
        const val MAX_PAGES = 10
        const val RANK_REFRESH_MS = 5_000L
    }
}
