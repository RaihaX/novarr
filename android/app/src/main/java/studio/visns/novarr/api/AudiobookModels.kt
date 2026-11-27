package studio.visns.novarr.api

import kotlinx.serialization.KSerializer
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.descriptors.SerialDescriptor
import kotlinx.serialization.descriptors.buildClassSerialDescriptor
import kotlinx.serialization.encoding.Decoder
import kotlinx.serialization.encoding.Encoder
import kotlinx.serialization.json.JsonDecoder
import kotlinx.serialization.json.JsonEncoder
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject

// Audiobooks (API v1, meta `audiobooks: true`). Like the rest of the models,
// only ids are required so an older or newer server never breaks decoding.

/** `audiobook` on a novel payload: the book has an audiobook (null: none, or an older server). */
@Serializable
data class AudiobookRef(
    val id: Int,
    @SerialName("duration_ms") val durationMs: Long = 0,
)

/** Where the reader is in an audiobook (whole-book position). */
@Serializable
data class AudiobookProgress(
    @SerialName("position_ms") val positionMs: Long = 0,
    val finished: Boolean = false,
    /** ISO 8601; when the server recorded it (last write wins by the client's recorded_at). */
    @SerialName("updated_at") val updatedAt: String? = null,
    /** Not in the summary's contract, but the PUT answer may carry it. */
    val speed: Double? = null,
)

/** One audiobook in a list (`GET /audiobooks`, `/audiobooks/continue`). */
@Serializable
data class AudiobookSummary(
    val id: Int,
    @SerialName("novel_id") val novelId: Int? = null,
    val title: String = "Untitled",
    val author: String? = null,
    val narrator: String? = null,
    @SerialName("cover_url") val coverUrl: String? = null,
    @SerialName("duration_ms") val durationMs: Long = 0,
    @Serializable(with = AudiobookProgressOrNull::class) val progress: AudiobookProgress? = null,
) {
    val authorOrNull: String? get() = author?.takeIf { it.isNotBlank() }
    val narratorOrNull: String? get() = narrator?.takeIf { it.isNotBlank() }

    /** 0..1 through the book; finished counts as all of it. */
    val fraction: Float
        get() = when {
            progress == null -> 0f
            progress.finished -> 1f
            durationMs <= 0 -> 0f
            else -> (progress.positionMs.toFloat() / durationMs).coerceIn(0f, 1f)
        }

    val started: Boolean get() = progress != null && (progress.positionMs > 0 || progress.finished)
}

/** A file of the audiobook. [url] is pre-signed (valid 24 h): it plays without headers. */
@Serializable
data class AudiobookPart(
    val index: Int,
    val url: String = "",
    val mime: String? = null,
    @SerialName("duration_ms") val durationMs: Long = 0,
    @SerialName("start_ms") val startMs: Long = 0,
    @SerialName("size_bytes") val sizeBytes: Long = 0,
)

/** A chapter: positions in the WHOLE book, across parts. */
@Serializable
data class AudiobookChapter(
    val index: Int,
    val title: String = "",
    @SerialName("start_ms") val startMs: Long = 0,
    @SerialName("end_ms") val endMs: Long = 0,
) {
    val label: String get() = title.ifBlank { "Chapter ${index + 1}" }
}

@Serializable
data class AudiobookBookmark(
    val id: Int,
    @SerialName("position_ms") val positionMs: Long = 0,
    val note: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
) {
    val noteOrNull: String? get() = note?.takeIf { it.isNotBlank() }
}

/** The detail-only fields of `GET /audiobooks/{id}`, beside the summary's. */
@Serializable
data class AudiobookExtras(
    val description: String? = null,
    val year: String? = null,
    @SerialName("size_bytes") val sizeBytes: Long = 0,
    @Serializable(with = AudiobookPartList::class) val parts: List<AudiobookPart> = emptyList(),
    @Serializable(with = AudiobookChapterList::class) val chapters: List<AudiobookChapter> = emptyList(),
    @Serializable(with = AudiobookBookmarkList::class) val bookmarks: List<AudiobookBookmark> = emptyList(),
)

/** `GET /audiobooks/{id}`: Summary + extras, flattened in one JSON object. */
@Serializable(with = AudiobookDetailSerializer::class)
data class AudiobookDetail(val summary: AudiobookSummary, val extras: AudiobookExtras = AudiobookExtras()) {
    val id: Int get() = summary.id
    val parts: List<AudiobookPart> get() = extras.parts.sortedBy { it.index }
    val chapters: List<AudiobookChapter> get() = extras.chapters.sortedBy { it.startMs }
    val bookmarks: List<AudiobookBookmark> get() = extras.bookmarks
    val description: String? get() = extras.description?.takeIf { it.isNotBlank() }
}

@Serializable
data class AudiobooksPage(
    @Serializable(with = AudiobookSummaryList::class) val audiobooks: List<AudiobookSummary>,
    val page: Int = 1,
    @SerialName("has_more") val hasMore: Boolean = false,
)

@Serializable
data class AudiobooksEnvelope(@Serializable(with = AudiobookSummaryList::class) val audiobooks: List<AudiobookSummary>)

@Serializable
data class AudiobookEnvelope(val audiobook: AudiobookDetail)

/** `PUT /audiobooks/{id}/progress`: what the server now has; [stale] when ours was older and ignored. */
@Serializable
data class AudiobookProgressResult(
    @Serializable(with = AudiobookProgressOrNull::class) val progress: AudiobookProgress? = null,
    val stale: Boolean = false,
)

@Serializable
data class AudiobookBookmarkEnvelope(val bookmark: AudiobookBookmark)

/**
 * Admin: `GET /audiobooks/inbox`. The contract only says "pending entries +
 * failed with reasons", so every field is optional and both lists are lossy.
 */
@Serializable
data class AudiobookInbox(
    @Serializable(with = InboxEntryList::class) val pending: List<AudiobookInboxEntry> = emptyList(),
    @Serializable(with = InboxEntryList::class) val failed: List<AudiobookInboxEntry> = emptyList(),
)

@Serializable
data class AudiobookInboxEntry(
    val name: String = "",
    @SerialName("size_bytes") val sizeBytes: Long? = null,
    val status: String? = null,
    val reason: String? = null,
    @SerialName("modified_at") val modifiedAt: String? = null,
)

object AudiobookSummaryList : LossyListSerializer<AudiobookSummary>(AudiobookSummary.serializer())
object AudiobookPartList : LossyListSerializer<AudiobookPart>(AudiobookPart.serializer())
object AudiobookChapterList : LossyListSerializer<AudiobookChapter>(AudiobookChapter.serializer())
object AudiobookBookmarkList : LossyListSerializer<AudiobookBookmark>(AudiobookBookmark.serializer())
object InboxEntryList : LossyListSerializer<AudiobookInboxEntry>(AudiobookInboxEntry.serializer())
object AudiobookProgressOrNull : OrNullSerializer<AudiobookProgress>(AudiobookProgress.serializer())
object AudiobookRefOrNull : OrNullSerializer<AudiobookRef>(AudiobookRef.serializer())

/** Reads and writes AudiobookDetail as one flat object, exactly as the server sends it. */
object AudiobookDetailSerializer : KSerializer<AudiobookDetail> {
    override val descriptor: SerialDescriptor = buildClassSerialDescriptor("AudiobookDetail")

    override fun deserialize(decoder: Decoder): AudiobookDetail {
        val json = decoder as JsonDecoder
        val element = json.decodeJsonElement()
        return AudiobookDetail(
            summary = json.json.decodeFromJsonElement(AudiobookSummary.serializer(), element),
            extras = runCatching { json.json.decodeFromJsonElement(AudiobookExtras.serializer(), element) }
                .getOrDefault(AudiobookExtras()),
        )
    }

    override fun serialize(encoder: Encoder, value: AudiobookDetail) {
        val json = encoder as JsonEncoder
        val summary = json.json.encodeToJsonElement(AudiobookSummary.serializer(), value.summary).jsonObject
        val extras = json.json.encodeToJsonElement(AudiobookExtras.serializer(), value.extras).jsonObject
        json.encodeJsonElement(JsonObject(summary + extras))
    }
}
