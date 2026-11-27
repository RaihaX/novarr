package studio.visns.novarr.api

import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.serialization.DeserializationStrategy
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import okhttp3.CacheControl
import okhttp3.Call
import okhttp3.Callback
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import okio.BufferedSink
import okio.ForwardingSink
import okio.buffer
import okio.source
import studio.visns.novarr.store.WriteKind

/**
 * The single client for the Novarr API v1. Immutable: a new one is made
 * whenever the server or the signed-in account changes, so a request can
 * never carry another account's token.
 */
class ApiClient(
    /** Normalised base URL without a trailing slash (ServerUrl.normalize). */
    val baseUrl: String,
    private val http: OkHttpClient,
    /** Identifies this install on writes and sign-in; null sends no device headers. */
    val device: DeviceIdentity? = null,
    token: String? = null,
    /**
     * Called with the token that was rejected when an authenticated request
     * gets 401, so the app can forget it and ask for a sign-in.
     */
    private val onUnauthorized: ((rejectedToken: String?) -> Unit)? = null,
) {
    val token: String? = token?.takeIf { it.isNotEmpty() }

    /** One request ready to go, with its own overall timeout. */
    data class Prepared(val request: Request, val timeoutSeconds: Long)

    // Reads

    /** Verifies the server: it must say it is Novarr and speak a supported API version. */
    suspend fun meta(): ServerMeta {
        val meta = try {
            // Public: never carries the token.
            get("meta", ServerMeta.serializer(), timeout = 10, authenticated = false)
        } catch (e: ApiError) {
            // An HTML page, a 404 or anything unparseable means "not Novarr".
            if (e is ApiError.Decoding || (e is ApiError.Http && e.status == 404)) throw ApiError.NotNovarr
            throw e
        }
        if (!meta.app.equals("novarr", ignoreCase = true)) throw ApiError.NotNovarr
        if (meta.apiVersion < MINIMUM_API_VERSION) throw ApiError.ApiTooOld(meta.apiVersion, MINIMUM_API_VERSION)
        return meta
    }

    /** Novels and manga together; the app separates them itself. */
    suspend fun novels(): List<NovelSummary> =
        get("novels", NovelsEnvelope.serializer(), query = mapOf("medium" to "all")).novels

    suspend fun home(): HomeFeed = get("home", HomeFeed.serializer(), query = mapOf("medium" to "all"))

    suspend fun novel(id: Int): NovelDetail = get("novels/$id", NovelEnvelope.serializer()).novel

    suspend fun chapters(novelId: Int): List<ChapterListItem> =
        get("novels/$novelId/chapters", ChaptersEnvelope.serializer(), timeout = 60).chapters

    suspend fun chapter(id: Int): ChapterContent = get("chapters/$id", ChapterEnvelope.serializer(), timeout = 30).chapter

    suspend fun mangaPages(id: Int): MangaPages = get("chapters/$id/pages", MangaPages.serializer(), timeout = 30)

    /**
     * Where the series was last read, on any device; null when nobody has read
     * it yet or the server predates the endpoint (404). Short timeout: the
     * reader opens regardless and only prompts if this answers in time.
     */
    suspend fun latestPosition(novelId: Int): ReadingPosition? = try {
        get("novels/$novelId/position", PositionEnvelope.serializer(), timeout = 3, fresh = true).position
    } catch (e: ApiError.Http) {
        if (e.status == 404) null else throw e
    }

    // Writes

    /** Sends one queued write. `age` is how many milliseconds ago it was recorded. */
    suspend fun send(kind: WriteKind, age: Long) {
        val headers = device?.headers.orEmpty()
        when (kind) {
            is WriteKind.Progress -> post("chapters/${kind.chapterId}/progress", progressBody(kind, age), headers)
            is WriteKind.SetRead -> post(
                "chapters/${kind.chapterId}/read",
                buildJsonObject { put("read", kind.read); put("age", age) },
                headers,
            )
            is WriteKind.BulkRead -> post("chapters/bulk-read", bulkBody(kind.request, age), headers)
            // 201 new, 200 when the chapter already had this excerpt (its note is replaced).
            is WriteKind.CreateHighlight -> post("highlights", highlightBody(kind), headers)
        }
    }

    // Checks

    suspend fun check(novelId: Int): NovelCheck = get("novels/$novelId/check", CheckEnvelope.serializer()).check

    /** Starts a check, or joins the one already running (202 either way). */
    suspend fun startCheck(novelId: Int): NovelCheck =
        decode(CheckEnvelope.serializer(), execute(prepare("POST", "novels/$novelId/check", body = JsonObject(emptyMap()), timeout = 60))).check

    // Highlights

    suspend fun highlights(novelId: Int? = null, page: Int = 1, perPage: Int = 50): HighlightsPage {
        val query = buildMap {
            if (novelId != null) put("novel_id", novelId.toString())
            put("page", page.toString())
            put("per_page", perPage.toString())
        }
        return get("highlights", HighlightsPage.serializer(), query = query)
    }

    /** 204 even when it was already gone; a 404 means the same thing here. */
    suspend fun deleteHighlight(id: Int) {
        try {
            execute(prepare("DELETE", "highlights/$id"))
        } catch (e: ApiError.Http) {
            if (e.status != 404) throw e
        }
    }

    // Reader requests (search every source, request, the owner decides).
    // Writes here are never queued: they need an answer now, so offline they
    // simply fail and the screen says so.

    /** Starts a search: 202 {search}. 429 (busy, running, rate limited) carries Retry-After. */
    suspend fun startRequestSearch(query: String, medium: String): RequestSearch {
        val body = buildJsonObject { put("q", query); put("medium", medium) }
        return decode(SearchEnvelope.serializer(), execute(prepare("POST", "requests/searches", body = body))).search
    }

    /** One poll of a running search; 404 `search_expired` once it is gone. Never from the HTTP cache. */
    suspend fun requestSearch(id: String): RequestSearch =
        get("requests/searches/$id", SearchEnvelope.serializer(), fresh = true).search

    /** `scope` mine | all (the admin view); `status` pending | approved | declined | available. */
    suspend fun requests(scope: String = "mine", status: String? = null, page: Int = 1): RequestsPage {
        val query = buildMap {
            put("scope", scope)
            if (status != null) put("status", status)
            put("page", page.toString())
        }
        return get("requests", RequestsPage.serializer(), query = query, fresh = true)
    }

    /**
     * Requests a search result: 201 {request, joined: false}. For a series
     * someone else already asked for ("Me too", no note) the server joins
     * their request instead: 200 {request, joined: true}; an older server
     * refuses with 409 `already_requested`.
     */
    suspend fun createRequest(searchId: String, key: String, note: String?): CreatedRequest {
        val body = buildJsonObject {
            put("search_id", searchId)
            put("key", key)
            note?.let { put("note", it) }
        }
        return decode(CreatedRequest.serializer(), execute(prepare("POST", "requests", body = body)))
    }

    /** A request typed in by hand ("Request it anyway"): 201 {request}. */
    suspend fun createFreeTextRequest(title: String, medium: String, link: String?, note: String?): ReaderRequest {
        val body = buildJsonObject {
            put("title", title)
            put("medium", medium)
            link?.let { put("link", it) }
            note?.let { put("note", it) }
        }
        return decode(RequestEnvelope.serializer(), execute(prepare("POST", "requests", body = body))).request
    }

    /**
     * A page of the Discover shelf: [list] null is the medium's first list.
     * 502 `browse_unavailable`, 503 `browse_busy` (+ Retry-After), 422 a list
     * the shelf doesn't offer. Never from the HTTP cache (the app keeps its own copy).
     */
    suspend fun requestBrowse(medium: String, list: String?, page: Int = 1): BrowsePage {
        val query = buildMap {
            put("medium", medium)
            if (list != null) put("list", list)
            put("page", page.toString())
        }
        return get("requests/browse", BrowsePage.serializer(), query = query, timeout = 45, fresh = true)
    }

    /** Requests a shelf result: the same answers as [createRequest] (201 new, 200 joined). */
    suspend fun createRequestFromBrowse(medium: String, list: String, page: Int, key: String, note: String?): CreatedRequest {
        val body = buildJsonObject {
            put("list", list)
            put("page", page)
            put("key", key)
            put("medium", medium)
            note?.let { put("note", it) }
        }
        return decode(CreatedRequest.serializer(), execute(prepare("POST", "requests", body = body)))
    }

    // Ask for the audiobook

    /** Puts the book's audiobook on the owner's wanted list: the server's message. 422 when it cannot be asked for. */
    suspend fun askAudiobook(novelId: Int): String =
        decode(AudiobookAskAnswer.serializer(), execute(prepare("POST", "novels/$novelId/audiobook/ask", body = JsonObject(emptyMap())))).message
            ?: "Asked for. The audiobook shows up on this book once it is here."

    // Send to my Kindle / email

    /** The last send of this series ({status: null} when none). */
    suspend fun sendState(novelId: Int): SendState =
        get("novels/$novelId/send", SendEnvelope.serializer(), fresh = true).send ?: SendState()

    /** Starts a send: 202 queued. 422 `no_address` (with `account_url`) / `manga`, 429 `too_many`. */
    suspend fun startSend(novelId: Int): SendState =
        decode(SendEnvelope.serializer(), execute(prepare("POST", "novels/$novelId/send", body = JsonObject(emptyMap())))).send ?: SendState(status = "queued")

    /** Cancels a pending request (204); for a request this account joined, leaves it (204). */
    suspend fun cancelRequest(id: Int) {
        execute(prepare("DELETE", "requests/$id"))
    }

    /**
     * Approves with a shortlist [candidate] (its `index`), or a series page
     * [url] for a request without one. [force] adds it even though the library
     * seems to have it (after 409 `duplicate`).
     */
    suspend fun approveRequest(id: Int, candidate: Int? = null, url: String? = null, title: String? = null, force: Boolean = false): ApproveResult {
        val body = buildJsonObject {
            candidate?.let { put("candidate", it) }
            url?.let { put("url", it) }
            title?.let { put("title", it) }
            if (force) put("force", true)
        }
        return decode(ApproveResult.serializer(), execute(prepare("POST", "requests/$id/approve", body = body, timeout = 60)))
    }

    suspend fun declineRequest(id: Int, reason: String?): ReaderRequest {
        val body = buildJsonObject { reason?.let { put("reason", it) } }
        return decode(RequestEnvelope.serializer(), execute(prepare("POST", "requests/$id/decline", body = body))).request
    }

    /** Rebuilds a manga request's shortlist in the background (202). */
    suspend fun rerankRequest(id: Int): ReaderRequest =
        decode(RequestEnvelope.serializer(), execute(prepare("POST", "requests/$id/rerank", body = JsonObject(emptyMap())))).request

    /**
     * The notification feed after [since] (an opaque cursor; null: the last
     * 24 hours). [reportUnauthorized] false keeps a background poll's 401
     * from ending the session: it is simply tried again next time.
     */
    suspend fun notifications(since: String?, reportUnauthorized: Boolean = true): NotificationFeed {
        val prepared = prepare("GET", "notifications", query = since?.let { mapOf("since" to it) }.orEmpty(), fresh = true)
        return decode(NotificationFeed.serializer(), execute(prepared, reportUnauthorized = reportUnauthorized))
    }

    // New chapters (one combined notification)

    /**
     * Series with something new to read since [since] (opaque cursor; null:
     * no series, just a cursor to start from). [reportUnauthorized] false
     * keeps the background check's 401 from ending the session.
     */
    suspend fun updates(since: String?, reportUnauthorized: Boolean = true): UpdatesFeed {
        val prepared = prepare("GET", "updates", query = since?.let { mapOf("since" to it) }.orEmpty(), fresh = true)
        return decode(UpdatesFeed.serializer(), execute(prepared, reportUnauthorized = reportUnauthorized))
    }

    // Reading lists. Never queued: offline the lists are read-only.

    suspend fun lists(): List<ReadingList> = get("lists", ListsEnvelope.serializer(), fresh = true).lists

    /** 201 {list}; 422 with a message for a blank, duplicate or too long name, or too many lists. */
    suspend fun createList(name: String): ReadingList =
        decode(ListEnvelope.serializer(), execute(prepare("POST", "lists", body = buildJsonObject { put("name", name) }))).list

    suspend fun renameList(id: Int, name: String): ReadingList =
        decode(ListEnvelope.serializer(), execute(prepare("PATCH", "lists/$id", body = buildJsonObject { put("name", name) }))).list

    /** 204; a list already gone (404) counts as deleted. */
    suspend fun deleteList(id: Int) {
        try {
            execute(prepare("DELETE", "lists/$id"))
        } catch (e: ApiError.Http) {
            if (e.status != 404) throw e
        }
    }

    /** Adds ([add]) or removes a series; idempotent either way, answers the whole list. */
    suspend fun setListMembership(listId: Int, novelId: Int, add: Boolean): ReadingList {
        val method = if (add) "PUT" else "DELETE"
        return decode(ListEnvelope.serializer(), execute(prepare(method, "lists/$listId/novels/$novelId", body = if (add) JsonObject(emptyMap()) else null))).list
    }

    /** The whole "Want to read", every medium, newest first, each with started. */
    suspend fun wantToRead(): WantToReadEnvelope =
        get("want-to-read", WantToReadEnvelope.serializer(), query = mapOf("medium" to "all"), fresh = true)

    // Audiobooks (meta `audiobooks`)

    /** Every audiobook the reader can see (books' and standalone ones), a page at a time. */
    suspend fun audiobooks(page: Int = 1, search: String? = null, status: String? = null): AudiobooksPage {
        val query = buildMap {
            put("page", page.toString())
            search?.takeIf { it.isNotBlank() }?.let { put("search", it.trim()) }
            status?.let { put("status", it) }
        }
        return get("audiobooks", AudiobooksPage.serializer(), query = query, fresh = true)
    }

    /** One audiobook with its (pre-signed) parts, chapters, bookmarks and progress. Fresh: the part URLs expire. */
    suspend fun audiobook(id: Int): AudiobookDetail = get("audiobooks/$id", AudiobookEnvelope.serializer(), fresh = true).audiobook

    /** Started and not finished, newest progress first (up to 12). */
    suspend fun continueListening(): List<AudiobookSummary> =
        get("audiobooks/continue", AudiobooksEnvelope.serializer(), fresh = true).audiobooks

    /**
     * Saves a whole-book position. [recordedAtIso] is when it was recorded on
     * this device: the server keeps the newest by it and answers `stale` for an
     * older one. Device headers like chapter progress.
     */
    suspend fun saveAudiobookProgress(
        id: Int,
        positionMs: Long,
        speed: Float,
        finished: Boolean,
        recordedAtIso: String,
    ): AudiobookProgressResult {
        val body = audiobookProgressBody(positionMs, speed, finished, recordedAtIso)
        val prepared = prepare("PUT", "audiobooks/$id/progress", body = body, headers = device?.headers.orEmpty())
        return decode(AudiobookProgressResult.serializer(), execute(prepared))
    }

    /** 201 {bookmark}. */
    suspend fun createAudiobookBookmark(id: Int, positionMs: Long, note: String?): AudiobookBookmark {
        val body = buildJsonObject {
            put("position_ms", positionMs.coerceAtLeast(0))
            note?.trim()?.takeIf { it.isNotEmpty() }?.let { put("note", it.take(500)) }
        }
        return decode(AudiobookBookmarkEnvelope.serializer(), execute(prepare("POST", "audiobooks/$id/bookmarks", body = body))).bookmark
    }

    /** 204; already gone (404) counts as deleted. */
    suspend fun deleteAudiobookBookmark(id: Int, bookmarkId: Int) {
        try {
            execute(prepare("DELETE", "audiobooks/$id/bookmarks/$bookmarkId"))
        } catch (e: ApiError.Http) {
            if (e.status != 404) throw e
        }
    }

    /** Admin: the drop-in folder (waiting entries, failed ones with their reason). */
    suspend fun audiobookInbox(): AudiobookInbox = get("audiobooks/inbox", AudiobookInbox.serializer(), fresh = true)

    /** Admin: links an audiobook to a book ([novelId]) or leaves it standalone (null). */
    suspend fun matchAudiobook(id: Int, novelId: Int?) {
        val body = buildJsonObject { if (novelId != null) put("novel_id", novelId) else put("novel_id", JsonNull) }
        execute(prepare("POST", "audiobooks/$id/match", body = body))
    }

    /** Admin: queues a scan of the drop-in folder now. */
    suspend fun scanAudiobooks() {
        execute(prepare("POST", "audiobooks/scan", body = JsonObject(emptyMap())))
    }

    // Books sent from the app ("Share to Novarr")

    /** One ePub to send: [open] is called each time OkHttp writes the part (again on a retried connection). */
    class UploadFile(val name: String, /** Bytes, or -1 when unknown (then the body is sent chunked). */ val size: Long, val open: () -> java.io.InputStream)

    /**
     * Sends ePubs to the owner's approval queue: one multipart part `epub[]`
     * per file, streamed from [UploadFile.open] (never held in memory), and an
     * optional [note]. 201, or 422 with `sent`/`refused` when none were taken,
     * both answer a [BookUploadResponse]; anything else (a validation 422
     * without `sent`, 404 `requests_disabled`, 404 from a server without the
     * route, 401, 413) throws ApiError.Http — domain/BookUploads explains it.
     * [onProgress] gets (bytes written, total or -1) on OkHttp's thread.
     */
    suspend fun uploadBooks(files: List<UploadFile>, note: String?, onProgress: ((Long, Long) -> Unit)? = null): BookUploadResponse {
        val multipart = MultipartBody.Builder().setType(MultipartBody.FORM).apply {
            files.forEach { addFormDataPart("epub[]", it.name, StreamedBody(EPUB, it.size, it.open)) }
            note?.takeIf { it.isNotBlank() }?.let { addFormDataPart("note", it) }
        }.build()
        val body = if (onProgress != null) CountingBody(multipart, onProgress) else multipart
        // No overall call timeout: up to 20 x 50 MB over a phone connection.
        // Writes and the server's answer (it reads every ePub) each get their own.
        val prepared = prepare("POST", "books/uploads", rawBody = body, timeout = 0)
        val answer = try {
            execute(prepared, via = uploadHttp)
        } catch (e: ApiError.Http) {
            // None were taken: still the per-file answer. A validation 422 has no `sent`.
            if (e.status == 422 && e.body?.containsKey("sent") == true) {
                return NovarrJson.decodeFromJsonElement(BookUploadResponse.serializer(), e.body)
            }
            throw e
        }
        return decode(BookUploadResponse.serializer(), answer)
    }

    private val uploadHttp: OkHttpClient by lazy {
        http.newBuilder().writeTimeout(2, TimeUnit.MINUTES).readTimeout(5, TimeUnit.MINUTES).build()
    }

    // Accounts

    /**
     * Password sign-in; `username` may also be the email. Signed, or (401
     * `second_step_required`) a sign-in code is needed with the returned
     * challenge. 422/429 and 403 `second_step_unavailable` throw Http with
     * the server's message; a 401 here never ends a session.
     */
    suspend fun signIn(username: String, password: String, device: DeviceIdentity): SignInAnswer {
        val body = buildJsonObject {
            put("username", username)
            put("password", password)
            put("device_id", device.id)
            put("device_name", device.name)
        }
        val answer = try {
            execute(prepare("POST", "auth/token", body = body, authenticated = false), reportUnauthorized = false)
        } catch (e: ApiError.Http) {
            if (e.status == 401 && e.code == AuthCodes.SECOND_STEP_REQUIRED) {
                val required = e.body?.let { runCatching { NovarrJson.decodeFromJsonElement(SecondStepRequired.serializer(), it) }.getOrNull() }
                if (required == null || required.challenge.isBlank()) throw ApiError.Decoding("second_step_required without a challenge")
                return SignInAnswer.SecondStep(required)
            }
            throw e
        }
        return SignInAnswer.Signed(decode(AuthTokenResponse.serializer(), answer))
    }

    /**
     * Emails a sign-in code for [challenge]: 204; 404 `challenge_expired`,
     * 429 `throttled` throw Http. [device] must be the one sent with
     * `POST auth/token` (another or none answers 404).
     */
    suspend fun sendSignInCode(challenge: String, device: DeviceIdentity) {
        val body = buildJsonObject {
            put("challenge", challenge)
            put("device_id", device.id)
        }
        execute(prepare("POST", "auth/token/email-code", body = body, authenticated = false), reportUnauthorized = false)
    }

    /**
     * Finishes a sign-in with a code; [method] is "totp", "email" or
     * "recovery". 422 `wrong_code` (with `tries_left`), 404
     * `challenge_expired` and 429 throw Http. [device] must be the one sent
     * with `POST auth/token` (another or none answers 404).
     */
    suspend fun finishSignIn(challenge: String, method: String, code: String, device: DeviceIdentity): AuthTokenResponse {
        val body = buildJsonObject {
            put("challenge", challenge)
            put("method", method)
            put("code", code)
            put("device_id", device.id)
        }
        return decode(
            AuthTokenResponse.serializer(),
            execute(prepare("POST", "auth/token/second-step", body = body, authenticated = false), reportUnauthorized = false),
        )
    }

    /** Asks for a link code (201); limited by the server to 10 an hour per network address (429). */
    suspend fun startPairing(device: DeviceIdentity): PairingStart {
        val body = buildJsonObject {
            put("device_id", device.id)
            put("device_name", device.name)
        }
        return decode(
            PairingStart.serializer(),
            execute(prepare("POST", "auth/pairings", body = body, authenticated = false), reportUnauthorized = false),
        )
    }

    /** One poll of a link code: pending or linked (the token is handed over once); 404 once it is gone. */
    suspend fun pairingStatus(secret: String): PairingStatus {
        // It goes into the path: only URL-safe characters (domain/Pairing checks this first).
        if (!SAFE_PATH_SEGMENT.matches(secret)) throw ApiError.Decoding("unusable pairing secret")
        val prepared = prepare("GET", "auth/pairings/$secret", fresh = true, authenticated = false)
        return decode(PairingStatus.serializer(), execute(prepared, reportUnauthorized = false))
    }

    suspend fun me(): AuthUser = get("auth/me", AuthUserEnvelope.serializer(), fresh = true).user

    /**
     * Revokes the token in use. A 401 here means it is already gone, which is
     * what signing out wants, so it is not reported as a lost session.
     */
    suspend fun signOut() {
        execute(prepare("DELETE", "auth/token", timeout = 10), reportUnauthorized = false)
    }

    // Plumbing

    fun url(path: String, query: Map<String, String> = emptyMap()): HttpUrl =
        "$baseUrl/api/v1/$path".toHttpUrl().newBuilder().apply {
            query.forEach { (k, v) -> addQueryParameter(k, v) }
        }.build()

    /**
     * Every request to the server is built here, so the bearer token is added
     * in exactly one place. `authenticated = false` is for the public
     * endpoints (meta, password sign-in).
     */
    fun prepare(
        method: String,
        path: String,
        query: Map<String, String> = emptyMap(),
        body: JsonObject? = null,
        timeout: Long = DEFAULT_TIMEOUT,
        fresh: Boolean = false,
        authenticated: Boolean = true,
        headers: Map<String, String> = emptyMap(),
        /** A body that is not JSON (multipart); wins over [body]. */
        rawBody: RequestBody? = null,
    ): Prepared {
        val builder = Request.Builder()
            .url(url(path, query))
            .header("Accept", "application/json")
        headers.forEach { (k, v) -> builder.header(k, v) }
        if (authenticated && token != null) builder.header(AUTHORIZATION, "Bearer $token")
        if (fresh) builder.cacheControl(CacheControl.FORCE_NETWORK)
        val requestBody = rawBody ?: body?.toString()?.toRequestBody(JSON)
        builder.method(method, requestBody ?: if (method == "GET") null else ByteArray(0).toRequestBody(null))
        return Prepared(builder.build(), timeout)
    }

    private suspend fun <T> get(
        path: String,
        strategy: DeserializationStrategy<T>,
        query: Map<String, String> = emptyMap(),
        timeout: Long = DEFAULT_TIMEOUT,
        fresh: Boolean = false,
        authenticated: Boolean = true,
    ): T {
        val prepared = prepare("GET", path, query, timeout = timeout, fresh = fresh, authenticated = authenticated)
        return decode(strategy, execute(prepared, reportUnauthorized = authenticated))
    }

    private suspend fun post(path: String, body: JsonObject, headers: Map<String, String>) {
        execute(prepare("POST", path, body = body, headers = headers))
    }

    private fun <T> decode(strategy: DeserializationStrategy<T>, body: String): T = try {
        NovarrJson.decodeFromString(strategy, body)
    } catch (e: Exception) {
        throw ApiError.Decoding(e.message ?: "Bad JSON")
    }

    /** Runs the request; returns the body of a 2xx, throws ApiError otherwise. */
    suspend fun execute(prepared: Prepared, reportUnauthorized: Boolean = true, via: OkHttpClient = http): String {
        val (status, body, retryAfter) = try {
            via.newCall(prepared.request).apply { timeout().timeout(prepared.timeoutSeconds, TimeUnit.SECONDS) }.await()
        } catch (e: IOException) {
            throw ApiError.from(e)
        }
        if (status !in 200..299) {
            if (status == 401 && reportUnauthorized) onUnauthorized?.invoke(token)
            val json = errorBody(body)
            throw ApiError.Http(
                status,
                json?.get("message").stringOrNull(),
                code = json?.get("code").stringOrNull(),
                retryAfter = retryAfter,
                body = json,
            )
        }
        return body
    }

    companion object {
        const val MINIMUM_API_VERSION = 1
        const val AUTHORIZATION = "Authorization"
        const val DEFAULT_TIMEOUT = 20L
        private val JSON = "application/json".toMediaType()
        val EPUB = "application/epub+zip".toMediaType()

        /**
         * RFC 3986 unreserved characters only, so a server value can be put
         * into a path as is; never starting with a dot (no "." or "..").
         */
        val SAFE_PATH_SEGMENT = Regex("^[A-Za-z0-9_~-][A-Za-z0-9._~-]{0,511}$")

        fun progressBody(write: WriteKind.Progress, age: Long): JsonObject = buildJsonObject {
            write.progress?.let { put("progress", it) }
            // `read` only when true: the server reads its presence as "mark read".
            if (write.read) put("read", true)
            // A look-up's mark: read, but not the series' latest position.
            if (write.lookup) put("lookup", true)
            put("age", age)
        }

        /** Speed is kept within the contract's 0.5–3.0 and rounded to two decimals (decimal 3,2). */
        fun audiobookProgressBody(positionMs: Long, speed: Float, finished: Boolean, recordedAtIso: String): JsonObject = buildJsonObject {
            put("position_ms", positionMs.coerceAtLeast(0))
            put("speed", Math.round(speed.coerceIn(0.5f, 3.0f) * 100) / 100.0)
            put("finished", finished)
            put("recorded_at", recordedAtIso)
        }

        fun highlightBody(write: WriteKind.CreateHighlight): JsonObject = buildJsonObject {
            put("chapter_id", write.chapterId)
            put("excerpt", write.excerpt)
            write.note?.let { put("note", it) }
        }

        fun bulkBody(request: BulkReadRequest, age: Long): JsonObject {
            val encoded = NovarrJson.encodeToJsonElement(BulkReadRequest.serializer(), request).jsonObject
            return JsonObject(encoded + ("age" to kotlinx.serialization.json.JsonPrimitive(age)))
        }

        /** `{"message": "..."}` from an error body, if any. */
        fun errorMessage(body: String): String? = errorBody(body)?.get("message").stringOrNull()

        /** An error body as a JSON object; null for HTML, empty or malformed bodies. */
        fun errorBody(body: String): JsonObject? = runCatching { NovarrJson.parseToJsonElement(body) as? JsonObject }.getOrNull()

        private fun JsonElement?.stringOrNull(): String? =
            (this as? JsonPrimitive)?.takeIf { it !is JsonNull }?.content?.takeIf { it.isNotBlank() }
    }
}

/** A file part copied straight from its stream into the request: never read into memory. */
private class StreamedBody(
    private val type: MediaType,
    private val size: Long,
    private val open: () -> java.io.InputStream,
) : RequestBody() {
    override fun contentType(): MediaType = type
    override fun contentLength(): Long = size
    override fun writeTo(sink: BufferedSink) {
        open().source().use { sink.writeAll(it) }
    }
}

/** Reports how much of [delegate] has been written; starts over when OkHttp writes it again. */
private class CountingBody(private val delegate: RequestBody, private val onProgress: (Long, Long) -> Unit) : RequestBody() {
    override fun contentType(): MediaType? = delegate.contentType()
    override fun contentLength(): Long = delegate.contentLength()
    override fun writeTo(sink: BufferedSink) {
        val total = contentLength()
        var written = 0L
        val counting = object : ForwardingSink(sink) {
            override fun write(source: okio.Buffer, byteCount: Long) {
                super.write(source, byteCount)
                written += byteCount
                onProgress(written, total)
            }
        }.buffer()
        delegate.writeTo(counting)
        counting.flush()
    }
}

/** Status, body text and the Retry-After header; the body is read on OkHttp's thread. */
private suspend fun Call.await(): Triple<Int, String, String?> = suspendCancellableCoroutine { cont ->
    cont.invokeOnCancellation { cancel() }
    enqueue(object : Callback {
        override fun onFailure(call: Call, e: IOException) {
            if (!cont.isCancelled) cont.resumeWithException(e)
        }

        override fun onResponse(call: Call, response: Response) {
            val result = try {
                response.use { Triple(it.code, it.body.string(), it.header("Retry-After")) }
            } catch (e: IOException) {
                cont.resumeWithException(e)
                return
            }
            cont.resume(result)
        }
    })
}
