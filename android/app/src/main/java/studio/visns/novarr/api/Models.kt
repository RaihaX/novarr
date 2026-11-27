package studio.visns.novarr.api

import kotlinx.serialization.KSerializer
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.descriptors.SerialDescriptor
import kotlinx.serialization.descriptors.buildClassSerialDescriptor
import kotlinx.serialization.descriptors.nullable
import kotlinx.serialization.builtins.serializer
import kotlinx.serialization.encoding.Decoder
import kotlinx.serialization.encoding.Encoder
import kotlinx.serialization.json.JsonDecoder
import kotlinx.serialization.json.JsonEncoder
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject

// Models mirror the API v1 contract (app/Http/Controllers/Api/V1/Presenter.php
// and the controllers). Only ids are required; everything else has a
// default, so an older or newer server never breaks decoding.

/** `meta.auth`: missing on servers that predate accounts, which means "no sign-in". */
@Serializable
data class ServerAuth(
    val required: Boolean = false,
    /** "password", and "pairing" when the server can link a device with a code. */
    val methods: List<String> = emptyList(),
    /** This address asks for a sign-in code after the password; absent on older servers. */
    @SerialName("second_step") val secondStep: Boolean = false,
    /** False: sign-in is required but switched off at this address (nobody can sign in here). */
    val available: Boolean = true,
)

@Serializable
data class ServerMeta(
    val app: String = "",
    @SerialName("api_version") val apiVersion: Int = 0,
    @SerialName("server_version") val serverVersion: String? = null,
    // A malformed `auth` is treated like a missing one rather than failing meta.
    @Serializable(with = ServerAuthOrNull::class) val auth: ServerAuth? = null,
    /** The APK this server publishes; null when none (or the server predates the key). */
    @Serializable(with = AndroidReleaseOrNull::class) val android: AndroidRelease? = null,
    /** Reader requests (search, request, the notification feed) are offered; absent on older servers. */
    val requests: Boolean = false,
    /** `GET /updates` (new chapters since a cursor, for the one combined notification); absent on older servers. */
    val updates: Boolean = false,
    /** Reading lists (`/lists`); absent on older servers, and the app then hides lists entirely. */
    val lists: Boolean = false,
    /** The server knows books (medium "book"); absent on older servers. Informational: the app shows books whenever the library has some. */
    val books: Boolean = false,
    /** ePubs sent from the app (`POST /books/uploads`): the server's limits and its mail-in address; absent on older servers. */
    @Serializable(with = BookUploadsMetaOrNull::class)
    @SerialName("book_uploads") val bookUploads: BookUploadsMeta? = null,
    /** Audiobooks (`/audiobooks`, the player); absent on older servers, and the app then hides them entirely. */
    val audiobooks: Boolean = false,
    /** The built-in "Favourites" list (`/favourites`, `favourite` on summaries); absent on older servers, and the app then hides it. */
    val favourites: Boolean = false,
    /** The server knows comics (medium "comic": page images, like manga); absent on older servers, and the app then hides the Comics tab and request option. */
    val comics: Boolean = false,
) {
    /** Only an explicit `auth.required: true` turns sign-in on. */
    val requiresSignIn: Boolean get() = auth?.required == true

    /** Sign-in is required but this address accepts none: say so instead of asking for credentials. */
    val signInUnavailable: Boolean get() = auth?.required == true && auth.available == false

    /** The server can link this device with a code typed on the website. */
    val offersPairing: Boolean get() = auth?.methods.orEmpty().any { it.equals("pairing", ignoreCase = true) }

    /** A sign-in code will be asked for after the password at this address. */
    val asksForSecondStep: Boolean get() = auth?.required == true && auth.secondStep
}

/**
 * `meta.android`: the sideloadable APK on the server. [build] is compared
 * with this app's version code; [url] is the download page (/app/android).
 * [size] (bytes) and [sha256] (hex) let the app check what it downloads;
 * older servers send neither. [notes] is that version's release notes
 * ("What's new"), one line each; empty when the server sends none (older
 * servers) or anything but a list of strings.
 */
@Serializable
data class AndroidRelease(
    val version: String? = null,
    val build: Int? = null,
    val url: String = "",
    val size: Long? = null,
    val sha256: String? = null,
    @Serializable(with = StringList::class) val notes: List<String> = emptyList(),
)

@Serializable
data class AuthUser(
    val id: Int,
    val name: String = "",
    val username: String = "",
    val role: String = "",
    /** Only when the server sends it (newer servers); used for the "email ePubs from ..." hint. */
    val email: String? = null,
) {
    val displayName: String get() = name.ifBlank { username }

    /** Anything unrecognised is treated as a reader (least privilege). */
    val roleLabel: String
        get() = when (role.lowercase()) {
            "admin" -> "Owner"
            "reader", "" -> "Reader"
            else -> role.replaceFirstChar { it.uppercase() }
        }
}

@Serializable
data class AuthTokenResponse(val token: String, val user: AuthUser)

@Serializable
data class AuthUserEnvelope(val user: AuthUser)

@Serializable
data class NovelTag(val id: Int, val name: String = "")

object Medium {
    const val NOVEL = "novel"
    const val MANGA = "manga"

    /** Comics and graphic novels: page images like manga, read left to right unless the series says otherwise. */
    const val COMIC = "comic"

    /** Whether [medium] is read as page images (the page reader, page downloads, no ePub). */
    fun isPaged(medium: String?): Boolean =
        medium.equals(MANGA, ignoreCase = true) || medium.equals(COMIC, ignoreCase = true)

    /** A series' medium as a label ("Web novel", "Manga", "Comic", "Book"); blank or unknown reads as a web novel. */
    fun label(medium: String?): String = when (medium?.trim()?.lowercase()) {
        MANGA -> "Manga"
        COMIC -> "Comic"
        BOOK -> "Book"
        else -> "Web novel"
    }

    /** An ePub upload or Calibre import: read like a novel, complete on arrival, no origin. */
    const val BOOK = "book"
}

@Serializable
data class NovelSummary(
    val id: Int,
    val name: String = "Untitled",
    val medium: String = Medium.NOVEL,
    val author: String? = null,
    @SerialName("cover_url") val coverUrl: String? = null,
    @SerialName("cover_large_url") val coverLargeUrl: String? = null,
    val state: String = "",
    val completed: Boolean = false,
    val paused: Boolean = false,
    val origin: String? = null,
    /**
     * "JP" | "KR" | "CN" | "EN" | null; absent on older servers. Kept raw (a
     * malformed value decodes as null, never failing the series):
     * domain/Origin validates it.
     */
    @Serializable(with = StringOrNull::class)
    @SerialName("origin_country") val originCountry: String? = null,
    @Serializable(with = TagList::class) val tags: List<NovelTag> = emptyList(),
    @SerialName("chapters_total") val chaptersTotal: Int = 0,
    @SerialName("chapters_downloaded") val chaptersDownloaded: Int = 0,
    @SerialName("chapters_read") val chaptersRead: Int = 0,
    @SerialName("chapters_new") val chaptersNew: Int = 0,
    @SerialName("read_percent") val readPercent: Int = 0,
    @SerialName("download_percent") val downloadPercent: Int = 0,
    val finished: Boolean = false,
    @SerialName("last_read_at") val lastReadAt: String? = null,
    @SerialName("updated_at") val updatedAt: String? = null,
    @SerialName("resume_chapter_id") val resumeChapterId: Int? = null,
    /** On the reader's "Want to read" (an older server sends none: false). */
    @SerialName("want_to_read") val wantToRead: Boolean = false,
    /** The book's audiobook (`{id, duration_ms}`); null without one, or from a server that predates audiobooks. */
    @Serializable(with = AudiobookRefOrNull::class) val audiobook: AudiobookRef? = null,
    /** On the reader's "Favourites" (an older server sends none: false). */
    val favourite: Boolean = false,
) {
    val isManga: Boolean get() = medium.equals(Medium.MANGA, ignoreCase = true)

    /** A comic or graphic novel: page images, like manga. */
    val isComic: Boolean get() = medium.equals(Medium.COMIC, ignoreCase = true)

    /** Read as page images (manga or a comic): the page reader, page downloads, no ePub or Send to Kindle. */
    val isPaged: Boolean get() = Medium.isPaged(medium)

    /** A book (ePub/Calibre): read like a novel, but complete on arrival, never checked for new chapters. */
    val isBook: Boolean get() = medium.equals(Medium.BOOK, ignoreCase = true)
    val authorOrNull: String? get() = author?.takeIf { it.isNotBlank() }

    /** The short caption under a cover in the library grid. */
    val caption: String
        get() = when {
            chaptersNew > 0 -> "$chaptersNew NEW"
            finished -> "FINISHED"
            chaptersRead == 0 -> "NOT STARTED"
            else -> "$chaptersRead / $chaptersTotal"
        }

    val readFraction: Float get() = (readPercent / 100f).coerceIn(0f, 1f)

    val stateLabel: String
        get() = when (state.lowercase()) {
            "active" -> "Ongoing"
            "completed" -> "Completed"
            "paused" -> "Paused"
            "attention" -> "Needs attention"
            "failed" -> "Failed"
            "" -> "Unknown"
            else -> state.replaceFirstChar { it.uppercase() }
        }
}

/** "Chapter 12", "Chapter 12.5", or "Chapter" without a number. */
fun chapterFallbackLabel(number: Double?): String = when {
    number == null -> "Chapter"
    number == Math.floor(number) && !number.isInfinite() -> "Chapter ${number.toLong()}"
    else -> "Chapter ${"%.2f".format(java.util.Locale.ROOT, number).trimEnd('0').trimEnd('.')}"
}

@Serializable
data class ChapterRef(
    val id: Int,
    val label: String = "",
    val chapter: Double? = null,
    val book: Int? = null,
    val downloaded: Boolean? = null,
) {
    val title: String get() = label.ifBlank { chapterFallbackLabel(chapter) }
}

@Serializable
data class ContinueItem(val novel: NovelSummary, val chapter: ChapterRef)

@Serializable
data class NewChaptersItem(
    val novel: NovelSummary,
    val count: Int = -1,
    @Serializable(with = ChapterRefOrNull::class)
    @SerialName("first_chapter") val firstChapter: ChapterRef? = null,
) {
    val newCount: Int get() = if (count >= 0) count else novel.chaptersNew
}

@Serializable
data class HomeStats(
    val novels: Int = 0,
    @SerialName("chapters_downloaded") val chaptersDownloaded: Int = 0,
    @SerialName("chapters_queued") val chaptersQueued: Int = 0,
)

@Serializable
data class HomeFeed(
    @Serializable(with = ContinueList::class)
    @SerialName("continue") val continueReading: List<ContinueItem> = emptyList(),
    @Serializable(with = NewChaptersList::class)
    @SerialName("new_chapters") val newChapters: List<NewChaptersItem> = emptyList(),
    @Serializable(with = HomeStatsOrNull::class) val stats: HomeStats? = null,
    /** Home's "Want to read" shelf, newest first (up to 12), and how many the list holds (an older server: none). */
    @Serializable(with = WantToReadList::class)
    @SerialName("want_to_read") val wantToRead: List<WantToReadItem> = emptyList(),
    @SerialName("want_to_read_count") val wantToReadCount: Int = 0,
)

@Serializable
data class SourceUnavailable(val count: Int = 0, val message: String = "")

/** The detail-only fields of `GET /novels/{id}`, which sit beside the summary's. */
@Serializable
data class NovelExtras(
    val description: String? = null,
    @SerialName("source_url") val sourceUrl: String? = null,
    @Serializable(with = SourceUnavailableOrNull::class)
    @SerialName("source_unavailable") val sourceUnavailable: SourceUnavailable? = null,
)

/** `GET /novels/{id}`: the summary fields plus extras, flattened in one JSON object. */
@Serializable(with = NovelDetailSerializer::class)
data class NovelDetail(val summary: NovelSummary, val extras: NovelExtras = NovelExtras()) {
    val description: String? get() = extras.description?.takeIf { it.isNotBlank() }
}

@Serializable
data class ChapterListItem(
    val id: Int,
    val chapter: Double? = null,
    val book: Int? = null,
    val label: String = "",
    val downloaded: Boolean = false,
    val read: Boolean = false,
    @SerialName("read_progress") val readProgress: Int? = null,
    val note: Boolean = false,
    val state: String? = null,
    val reason: String? = null,
    @SerialName("downloaded_at") val downloadedAt: String? = null,
    @SerialName("word_count") val wordCount: Int? = null,
    @SerialName("page_count") val pageCount: Int? = null,
) {
    val title: String get() = label.ifBlank { chapterFallbackLabel(chapter) }
}

/** `GET /chapters/{id}` (text). `html` is not decoded: the app renders `paragraphs`. */
@Serializable
data class ChapterContent(
    val id: Int,
    @SerialName("novel_id") val novelId: Int = 0,
    @SerialName("novel_name") val novelName: String = "",
    val label: String = "",
    val chapter: Double? = null,
    val book: Int? = null,
    val downloaded: Boolean = true,
    val read: Boolean = false,
    @SerialName("read_progress") val readProgress: Int? = null,
    @Serializable(with = StringList::class) val paragraphs: List<String> = emptyList(),
    @Serializable(with = ChapterRefOrNull::class) val prev: ChapterRef? = null,
    @Serializable(with = ChapterRefOrNull::class) val next: ChapterRef? = null,
) {
    val title: String get() = label.ifBlank { chapterFallbackLabel(chapter) }
    val hasText: Boolean get() = downloaded && paragraphs.isNotEmpty()
}

@Serializable
data class MangaChapterInfo(
    val id: Int,
    @SerialName("novel_id") val novelId: Int = 0,
    val label: String = "",
    val number: Double? = null,
    @SerialName("page_count") val pageCount: Int? = null,
    val read: Boolean = false,
    val progress: Int? = null,
) {
    val title: String get() = label.ifBlank { chapterFallbackLabel(number) }
}

@Serializable
data class MangaPage(
    val page: Int,
    /** Pre-signed by the server: loaded as-is, without any headers. */
    val url: String = "",
    val width: Int? = null,
    val height: Int? = null,
) {
    /** Height over width, when the server knows both. */
    val aspect: Float? get() = if (width != null && height != null && width > 0 && height > 0) height.toFloat() / width else null
}

/** `GET /chapters/{id}/pages`: a manga chapter's pages. */
@Serializable
data class MangaPages(
    val chapter: MangaChapterInfo,
    @Serializable(with = MangaPageList::class) val pages: List<MangaPage> = emptyList(),
    @Serializable(with = ChapterRefOrNull::class) val prev: ChapterRef? = null,
    @Serializable(with = ChapterRefOrNull::class) val next: ChapterRef? = null,
    @SerialName("reading_mode") val readingMode: String = "ltr",
)

/** `GET /novels/{id}/position`: where the series was last read, on any device. */
@Serializable
data class ReadingPosition(
    @SerialName("chapter_id") val chapterId: Int,
    @SerialName("chapter_label") val chapterLabel: String? = null,
    @SerialName("chapter_number") val chapterNumber: String? = null,
    /** 0–100; null when the server only knows the chapter. */
    val progress: Int? = null,
    val downloaded: Boolean? = null,
    @SerialName("device_id") val deviceId: String? = null,
    @SerialName("device_name") val deviceName: String? = null,
    val at: String? = null,
    /** Seconds since `at` by the server's clock: compare against this, not wall clocks. */
    @SerialName("age_seconds") val ageSeconds: Int? = null,
)

@Serializable
data class PositionEnvelope(
    @Serializable(with = ReadingPositionOrNull::class) val position: ReadingPosition? = null,
)

@Serializable
data class Highlight(
    val id: Int,
    @SerialName("novel_id") val novelId: Int = 0,
    @SerialName("novel_name") val novelName: String = "",
    @SerialName("chapter_id") val chapterId: Int,
    @SerialName("chapter_label") val chapterLabel: String = "Chapter",
    val excerpt: String = "",
    val note: String? = null,
    @SerialName("created_at") val createdAt: String? = null,
) {
    val noteOrNull: String? get() = note?.takeIf { it.isNotBlank() }
}

@Serializable
data class HighlightsPage(
    @Serializable(with = HighlightList::class) val highlights: List<Highlight> = emptyList(),
    val page: Int = 1,
    @SerialName("has_more") val hasMore: Boolean = false,
)

@Serializable
data class NovelCheck(
    val status: String = "idle",
    @SerialName("started_at") val startedAt: String? = null,
    @SerialName("finished_at") val finishedAt: String? = null,
    @SerialName("chapters_before") val chaptersBefore: Int? = null,
    @SerialName("chapters_now") val chaptersNow: Int = 0,
    @SerialName("new_chapters") val newChapters: Int = 0,
    val message: String? = null,
)

@Serializable
data class BulkReadRequest(
    val read: Boolean,
    val scope: String,
    val ids: List<Int>? = null,
    @SerialName("novel_id") val novelId: Int? = null,
    @SerialName("anchor_id") val anchorId: Int? = null,
) {
    companion object {
        const val ALL = "all"
        const val UP_TO = "up_to"
        const val FROM = "from"
        const val IDS = "ids"

        fun all(novelId: Int, read: Boolean) = BulkReadRequest(read, ALL, novelId = novelId)
        fun upTo(chapterId: Int, novelId: Int, read: Boolean) = BulkReadRequest(read, UP_TO, novelId = novelId, anchorId = chapterId)
        fun from(chapterId: Int, novelId: Int, read: Boolean) = BulkReadRequest(read, FROM, novelId = novelId, anchorId = chapterId)
    }
}

// Envelopes. A list envelope without its key is an error (not an empty list):
// it means the server answered something else.

@Serializable
data class NovelsEnvelope(@Serializable(with = NovelList::class) val novels: List<NovelSummary>)

@Serializable
data class NovelEnvelope(val novel: NovelDetail)

@Serializable
data class ChaptersEnvelope(@Serializable(with = ChapterList::class) val chapters: List<ChapterListItem>)

@Serializable
data class ChapterEnvelope(val chapter: ChapterContent)

@Serializable
data class CheckEnvelope(val check: NovelCheck)

// Serializers.

object TagList : LossyListSerializer<NovelTag>(NovelTag.serializer())
object NovelList : LossyListSerializer<NovelSummary>(NovelSummary.serializer())
object ChapterList : LossyListSerializer<ChapterListItem>(ChapterListItem.serializer())
object ContinueList : LossyListSerializer<ContinueItem>(ContinueItem.serializer())
object NewChaptersList : LossyListSerializer<NewChaptersItem>(NewChaptersItem.serializer())
object WantToReadList : LossyListSerializer<WantToReadItem>(WantToReadItem.serializer())
object MangaPageList : LossyListSerializer<MangaPage>(MangaPage.serializer())
object HighlightList : LossyListSerializer<Highlight>(Highlight.serializer())
object StringList : LossyListSerializer<String>(String.serializer())

/** A nested object that decodes as null instead of failing its parent. */
@OptIn(kotlinx.serialization.ExperimentalSerializationApi::class)
open class OrNullSerializer<T : Any>(private val inner: KSerializer<T>) : KSerializer<T?> {
    override val descriptor: SerialDescriptor = inner.descriptor.nullable

    override fun deserialize(decoder: Decoder): T? {
        val json = decoder as? JsonDecoder ?: return inner.deserialize(decoder)
        val element = json.decodeJsonElement()
        if (element is JsonNull) return null
        return runCatching { json.json.decodeFromJsonElement(inner, element) }.getOrNull()
    }

    override fun serialize(encoder: Encoder, value: T?) {
        if (value == null) encoder.encodeNull() else inner.serialize(encoder, value)
    }
}

object ServerAuthOrNull : OrNullSerializer<ServerAuth>(ServerAuth.serializer())
object AndroidReleaseOrNull : OrNullSerializer<AndroidRelease>(AndroidRelease.serializer())
object ChapterRefOrNull : OrNullSerializer<ChapterRef>(ChapterRef.serializer())
object HomeStatsOrNull : OrNullSerializer<HomeStats>(HomeStats.serializer())
object SourceUnavailableOrNull : OrNullSerializer<SourceUnavailable>(SourceUnavailable.serializer())
object ReadingPositionOrNull : OrNullSerializer<ReadingPosition>(ReadingPosition.serializer())
object StringOrNull : OrNullSerializer<String>(String.serializer())
object BookUploadsMetaOrNull : OrNullSerializer<BookUploadsMeta>(BookUploadsMeta.serializer())

/** Reads and writes NovelDetail as one flat object, exactly as the server sends it. */
object NovelDetailSerializer : KSerializer<NovelDetail> {
    override val descriptor: SerialDescriptor = buildClassSerialDescriptor("NovelDetail")

    override fun deserialize(decoder: Decoder): NovelDetail {
        val json = decoder as JsonDecoder
        val element = json.decodeJsonElement()
        return NovelDetail(
            summary = json.json.decodeFromJsonElement(NovelSummary.serializer(), element),
            extras = runCatching { json.json.decodeFromJsonElement(NovelExtras.serializer(), element) }
                .getOrDefault(NovelExtras()),
        )
    }

    override fun serialize(encoder: Encoder, value: NovelDetail) {
        val json = encoder as JsonEncoder
        val summary = json.json.encodeToJsonElement(NovelSummary.serializer(), value.summary).jsonObject
        val extras = json.json.encodeToJsonElement(NovelExtras.serializer(), value.extras).jsonObject
        json.encodeJsonElement(JsonObject(summary + extras))
    }
}
