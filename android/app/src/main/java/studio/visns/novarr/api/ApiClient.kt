package studio.visns.novarr.api

import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlinx.coroutines.suspendCancellableCoroutine
import kotlinx.serialization.DeserializationStrategy
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import okhttp3.CacheControl
import okhttp3.Call
import okhttp3.Callback
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
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

    // Accounts

    /** Password sign-in; `username` may also be the email. 422/429 throw Http with the server's message. */
    suspend fun signIn(username: String, password: String, device: DeviceIdentity): AuthTokenResponse {
        val body = buildJsonObject {
            put("username", username)
            put("password", password)
            put("device_id", device.id)
            put("device_name", device.name)
        }
        return decode(
            AuthTokenResponse.serializer(),
            execute(prepare("POST", "auth/token", body = body, authenticated = false), reportUnauthorized = false),
        )
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
    ): Prepared {
        val builder = Request.Builder()
            .url(url(path, query))
            .header("Accept", "application/json")
        headers.forEach { (k, v) -> builder.header(k, v) }
        if (authenticated && token != null) builder.header(AUTHORIZATION, "Bearer $token")
        if (fresh) builder.cacheControl(CacheControl.FORCE_NETWORK)
        val requestBody = body?.toString()?.toRequestBody(JSON)
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
    suspend fun execute(prepared: Prepared, reportUnauthorized: Boolean = true): String {
        val (status, body) = try {
            http.newCall(prepared.request).apply { timeout().timeout(prepared.timeoutSeconds, TimeUnit.SECONDS) }.await()
        } catch (e: IOException) {
            throw ApiError.from(e)
        }
        if (status !in 200..299) {
            if (status == 401 && reportUnauthorized) onUnauthorized?.invoke(token)
            throw ApiError.Http(status, errorMessage(body))
        }
        return body
    }

    companion object {
        const val MINIMUM_API_VERSION = 1
        const val AUTHORIZATION = "Authorization"
        const val DEFAULT_TIMEOUT = 20L
        private val JSON = "application/json".toMediaType()

        fun progressBody(write: WriteKind.Progress, age: Long): JsonObject = buildJsonObject {
            write.progress?.let { put("progress", it) }
            // `read` only when true: the server reads its presence as "mark read".
            if (write.read) put("read", true)
            put("age", age)
        }

        fun bulkBody(request: BulkReadRequest, age: Long): JsonObject {
            val encoded = NovarrJson.encodeToJsonElement(BulkReadRequest.serializer(), request).jsonObject
            return JsonObject(encoded + ("age" to kotlinx.serialization.json.JsonPrimitive(age)))
        }

        /** `{"message": "..."}` from an error body, if any. */
        fun errorMessage(body: String): String? = runCatching {
            NovarrJson.parseToJsonElement(body).jsonObject["message"]?.jsonPrimitive?.content
        }.getOrNull()?.takeIf { it.isNotBlank() }
    }
}

/** Status and body text; the body is read on OkHttp's thread. */
private suspend fun Call.await(): Pair<Int, String> = suspendCancellableCoroutine { cont ->
    cont.invokeOnCancellation { cancel() }
    enqueue(object : Callback {
        override fun onFailure(call: Call, e: IOException) {
            if (!cont.isCancelled) cont.resumeWithException(e)
        }

        override fun onResponse(call: Call, response: Response) {
            val result = try {
                response.use { it.code to (it.body.string()) }
            } catch (e: IOException) {
                cont.resumeWithException(e)
                return
            }
            cont.resume(result)
        }
    })
}
