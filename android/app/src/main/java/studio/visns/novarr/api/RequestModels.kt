package studio.visns.novarr.api

import kotlinx.serialization.KSerializer
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.builtins.serializer
import kotlinx.serialization.descriptors.PrimitiveKind
import kotlinx.serialization.descriptors.PrimitiveSerialDescriptor
import kotlinx.serialization.descriptors.SerialDescriptor
import kotlinx.serialization.descriptors.nullable
import kotlinx.serialization.encoding.Decoder
import kotlinx.serialization.encoding.Encoder
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonDecoder
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive

// Reader requests (app/Http/Controllers/Api/V1/RequestPresenter.php,
// RequestController, RequestSearchController, NotificationController).
// As elsewhere, only ids are required and one bad row never empties a list.

/** `search.results[].sources[]`: one source that has the series. */
@Serializable
data class ResultSource(
    val source: String = "",
    @SerialName("source_name") val sourceName: String = "",
    val chapters: Int? = null,
    val status: String? = null,
)

/** `search.results[].requested`: someone (maybe this account) has an open request for it. */
@Serializable
data class ResultRequested(
    val status: String = "pending",
    @SerialName("by_me") val byMe: Boolean = false,
)

/** One series a search found, grouped across sources. [key] is what a request from it sends. */
@Serializable
data class SearchResult(
    val key: String,
    val title: String = "",
    val author: String? = null,
    @SerialName("cover_url") val coverUrl: String? = null,
    val description: String? = null,
    val medium: String = "novel",
    @Serializable(with = ResultSourceList::class) val sources: List<ResultSource> = emptyList(),
    @SerialName("in_library") val inLibrary: Boolean = false,
    @SerialName("novel_id") val novelId: Int? = null,
    @Serializable(with = ResultRequestedOrNull::class) val requested: ResultRequested? = null,
    /** A book read from a pasted link (or a catalogue entry) may name its series; absent otherwise. */
    @Serializable(with = LooseString::class) val series: String? = null,
    /** "1", 1 or 1.5 on the wire: kept as text ([studio.visns.novarr.domain.Requests.seriesLabel]). */
    @SerialName("series_index") @Serializable(with = LooseString::class) val seriesIndex: String? = null,
    /** The reader's categories holding the series, when it is in the library; empty otherwise (or on an older server). */
    @SerialName("in_categories") @Serializable(with = IdList::class) val inCategories: List<Int> = emptyList(),
    /** The categories the reader chose on their open request for it; empty otherwise (or on an older server). */
    @SerialName("requested_categories") @Serializable(with = IdList::class) val requestedCategories: List<Int> = emptyList(),
)

/** One list of the Discover shelf: `id` is what browse and a browse request send. */
@Serializable
data class BrowseList(val id: String, val name: String = "")

/**
 * GET /requests/browse: a page of one list of the medium's shelf. [lists]
 * empty (and [list] null) when the shelf has nothing to show. Results are
 * shaped like a search's (what the library has is left out by the server).
 */
@Serializable
data class BrowsePage(
    val medium: String = "manga",
    @Serializable(with = BrowseListList::class) val lists: List<BrowseList> = emptyList(),
    @Serializable(with = LooseString::class) val list: String? = null,
    @SerialName("source_name") @Serializable(with = LooseString::class) val sourceName: String? = null,
    val page: Int = 1,
    @SerialName("has_more") val hasMore: Boolean = false,
    @Serializable(with = SearchResultList::class) val results: List<SearchResult> = emptyList(),
)

/** A source the search couldn't ask ("Couldn't reach: X, Y."). */
@Serializable
data class SkippedSource(
    val source: String = "",
    @SerialName("source_name") val sourceName: String = "",
    val reason: String? = null,
)

/** GET /requests/searches/{id}: running → done (results) or failed (message). */
@Serializable
data class RequestSearch(
    val id: String,
    val status: String = "running",
    val message: String? = null,
    val query: String = "",
    val medium: String = "novel",
    /** Null on the wire until done; empty here. */
    @Serializable(with = SearchResultList::class) val results: List<SearchResult> = emptyList(),
    @SerialName("skipped_sources") @Serializable(with = SkippedSourceList::class) val skippedSources: List<SkippedSource> = emptyList(),
) {
    val isRunning: Boolean get() = status == "running"
    val isDone: Boolean get() = status == "done"
    val isFailed: Boolean get() = status == "failed"
}

@Serializable
data class SearchEnvelope(val search: RequestSearch)

@Serializable
data class Requester(val id: Int = 0, val name: String? = null)

/**
 * One entry of a request's `candidates`. The reader view sends only source
 * names (strings): those decode with just [sourceName] and a null [index].
 * The admin view sends the ranked shortlist, best first, with [index] (what
 * approve's `candidate` takes) and the ranking details.
 */
@Serializable
data class RequestCandidate(
    val index: Int? = null,
    val source: String = "",
    @SerialName("source_name") val sourceName: String = "",
    val url: String = "",
    val title: String = "",
    val chapters: Int? = null,
    val status: String? = null,
    @Serializable(with = StringList::class) val languages: List<String> = emptyList(),
    @SerialName("chapters_available") val chaptersAvailable: Int? = null,
    @SerialName("highest_chapter") @Serializable(with = LooseString::class) val highestChapter: String? = null,
    val gaps: Int? = null,
    @SerialName("updated_at") val updatedAt: String? = null,
    @Serializable(with = LooseString::class) val error: String? = null,
    @Serializable(with = LooseString::class) val reason: String? = null,
) {
    /** From the admin view's shortlist (approvable), not a bare source name. */
    val isDetailed: Boolean get() = index != null
}

@Serializable
data class ReaderRequest(
    val id: Int,
    val title: String = "",
    val author: String? = null,
    @SerialName("cover_url") val coverUrl: String? = null,
    val description: String? = null,
    val medium: String = "novel",
    val note: String? = null,
    val link: String? = null,
    /** pending | approved | declined | available */
    val status: String = "pending",
    @SerialName("decline_reason") val declineReason: String? = null,
    @SerialName("requested_by") @Serializable(with = RequesterOrNull::class) val requestedBy: Requester? = null,
    @SerialName("novel_id") val novelId: Int? = null,
    @SerialName("created_at") val createdAt: String? = null,
    @SerialName("decided_at") val decidedAt: String? = null,
    @SerialName("available_at") val availableAt: String? = null,
    @Serializable(with = CandidateList::class) val candidates: List<RequestCandidate> = emptyList(),
    @SerialName("candidates_ranked_at") val candidatesRankedAt: String? = null,
    @SerialName("can_decide") val canDecide: Boolean = false,
    /**
     * The server lets this account withdraw it: the requester while pending,
     * a follower while pending or approved (not yet available). Null when the
     * server didn't say (an older server).
     */
    @SerialName("can_cancel") val canCancel: Boolean? = null,
    /** How many other readers joined it ("Me too"); absent on older servers. */
    @SerialName("also_wanted_by") @Serializable(with = LooseCount::class) val alsoWantedBy: Int = 0,
    /** This account's part in it: requester | follower; null when neither (or an older server). */
    @Serializable(with = LooseString::class) val mine: String? = null,
    /** The admin view: who joined it. */
    @Serializable(with = RequesterList::class) val followers: List<Requester> = emptyList(),
    /** A book asked for by title: no ePub yet, the owner finds one. Absent on older servers. */
    @Serializable(with = LooseFlag::class) val wanted: Boolean = false,
    /** The categories this account chose for it (it is added there once available); empty on an older server. */
    @SerialName("requested_categories") @Serializable(with = IdList::class) val requestedCategories: List<Int> = emptyList(),
) {
    val isManga: Boolean get() = medium == "manga"

    /** This account joined someone else's request ("Me too") rather than making it. */
    val isFollower: Boolean get() = mine == "follower"
}

@Serializable
data class RequestEnvelope(val request: ReaderRequest)

/**
 * POST /requests from a search result: 201 {request, joined: false} for a
 * new request, 200 {request, joined: true} when it joined someone else's
 * ("Me too"). Older servers send no `joined`.
 */
@Serializable
data class CreatedRequest(
    @Serializable(with = ReaderRequestOrNull::class) val request: ReaderRequest? = null,
    val joined: Boolean = false,
)

/** GET /requests. [pendingLimit] is null in the admin view (no limit). */
@Serializable
data class RequestsPage(
    @Serializable(with = ReaderRequestList::class) val requests: List<ReaderRequest> = emptyList(),
    val page: Int = 1,
    @SerialName("has_more") val hasMore: Boolean = false,
    @SerialName("can_decide") val canDecide: Boolean = false,
    @SerialName("pending_limit") val pendingLimit: Int? = null,
)

@Serializable
data class ApproveResult(
    @Serializable(with = ReaderRequestOrNull::class) val request: ReaderRequest? = null,
    @SerialName("novel_id") val novelId: Int? = null,
    val created: Boolean = false,
)

/** One item of the notification feed. kind: request_new | request_approved | request_declined | request_available. */
@Serializable
data class FeedItem(
    val id: Long,
    val kind: String = "",
    val title: String = "",
    val body: String = "",
    @SerialName("request_id") val requestId: Int? = null,
    @SerialName("novel_id") val novelId: Int? = null,
    val at: String? = null,
)

/** GET /notifications?since=: always store [cursor] for the next call. */
@Serializable
data class NotificationFeed(
    val cursor: String? = null,
    @SerialName("has_more") val hasMore: Boolean = false,
    @Serializable(with = FeedItemList::class) val items: List<FeedItem> = emptyList(),
)

/** "Send to my Kindle / email" (GET / POST /novels/{id}/send): status null (none yet) | queued | sending | sent | failed. */
@Serializable
data class SendState(
    @Serializable(with = LooseString::class) val status: String? = null,
    @Serializable(with = LooseString::class) val message: String? = null,
    @Serializable(with = LooseString::class) val to: String? = null,
)

@Serializable
data class SendEnvelope(@Serializable(with = SendStateOrNull::class) val send: SendState? = null)

object SendStateOrNull : OrNullSerializer<SendState>(SendState.serializer())
object BrowseListList : LossyListSerializer<BrowseList>(BrowseList.serializer())
object ResultSourceList : LossyListSerializer<ResultSource>(ResultSource.serializer())
object SearchResultList : LossyListSerializer<SearchResult>(SearchResult.serializer())
object SkippedSourceList : LossyListSerializer<SkippedSource>(SkippedSource.serializer())
object ReaderRequestList : LossyListSerializer<ReaderRequest>(ReaderRequest.serializer())
object RequesterList : LossyListSerializer<Requester>(Requester.serializer())
object FeedItemList : LossyListSerializer<FeedItem>(FeedItem.serializer())
object ResultRequestedOrNull : OrNullSerializer<ResultRequested>(ResultRequested.serializer())
object RequesterOrNull : OrNullSerializer<Requester>(Requester.serializer())
object ReaderRequestOrNull : OrNullSerializer<ReaderRequest>(ReaderRequest.serializer())

/**
 * `candidates` in either shape: strings (reader view: source names) or
 * objects (admin view: the ranked shortlist). Anything else is skipped.
 */
object CandidateList : KSerializer<List<RequestCandidate>> {
    private val strict = ListSerializer(RequestCandidate.serializer())
    override val descriptor: SerialDescriptor = strict.descriptor

    override fun deserialize(decoder: Decoder): List<RequestCandidate> {
        val json = decoder as? JsonDecoder ?: return strict.deserialize(decoder)
        val array = json.decodeJsonElement() as? JsonArray ?: return emptyList()
        return array.mapNotNull { item ->
            when (item) {
                is JsonObject -> runCatching { json.json.decodeFromJsonElement(RequestCandidate.serializer(), item) }.getOrNull()
                is JsonPrimitive -> item.takeIf { it !is JsonNull && it.content.isNotBlank() }?.let { RequestCandidate(sourceName = it.content) }
                else -> null
            }
        }
    }

    override fun serialize(encoder: Encoder, value: List<RequestCandidate>) = strict.serialize(encoder, value)
}

/** A count: a whole number (or one in a string), never negative; 0 for anything else. */
object LooseCount : KSerializer<Int> {
    override val descriptor: SerialDescriptor = PrimitiveSerialDescriptor("LooseCount", PrimitiveKind.INT)

    override fun deserialize(decoder: Decoder): Int {
        val json = decoder as? JsonDecoder ?: return decoder.decodeInt().coerceAtLeast(0)
        val element = json.decodeJsonElement()
        return ((element as? JsonPrimitive)?.takeIf { it !is JsonNull }?.content?.trim()?.toIntOrNull() ?: 0).coerceAtLeast(0)
    }

    override fun serialize(encoder: Encoder, value: Int) = encoder.encodeInt(value)
}

/** true, "true", 1 or "1" as true; anything else (null, objects, unreadable) false. */
object LooseFlag : KSerializer<Boolean> {
    override val descriptor: SerialDescriptor = PrimitiveSerialDescriptor("LooseFlag", PrimitiveKind.BOOLEAN)

    override fun deserialize(decoder: Decoder): Boolean {
        val json = decoder as? JsonDecoder ?: return decoder.decodeBoolean()
        val content = (json.decodeJsonElement() as? JsonPrimitive)?.takeIf { it !is JsonNull }?.content?.trim()?.lowercase()
        return content == "true" || content == "1"
    }

    override fun serialize(encoder: Encoder, value: Boolean) = encoder.encodeBoolean(value)
}

/** A string, number or boolean as text; null for null, objects and arrays. */
@OptIn(kotlinx.serialization.ExperimentalSerializationApi::class)
object LooseString : KSerializer<String?> {
    override val descriptor: SerialDescriptor = PrimitiveSerialDescriptor("LooseString", PrimitiveKind.STRING).nullable

    override fun deserialize(decoder: Decoder): String? {
        val json = decoder as? JsonDecoder ?: return decoder.decodeString()
        val element = json.decodeJsonElement()
        return (element as? JsonPrimitive)?.takeIf { it !is JsonNull }?.content
    }

    override fun serialize(encoder: Encoder, value: String?) {
        if (value == null) encoder.encodeNull() else String.serializer().serialize(encoder, value)
    }
}
