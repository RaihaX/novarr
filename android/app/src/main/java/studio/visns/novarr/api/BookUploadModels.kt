package studio.visns.novarr.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

// ePubs sent from the app ("Share to Novarr"): POST /books/uploads, the same
// approval queue as the website's "Upload a book" page.

/** `meta.book_uploads`: absent on servers that predate uploads from the app. */
@Serializable
data class BookUploadsMeta(
    @SerialName("max_mb") val maxMb: Int? = null,
    @SerialName("max_files") val maxFiles: Int? = null,
    /** Where ePubs can also be emailed to; null when the server has no mail-in address. */
    val email: String? = null,
)

/** One ePub that became a request waiting for the owner. */
@Serializable
data class SentBook(
    @SerialName("request_id") val requestId: Int? = null,
    val title: String = "",
)

/** One ePub the server would not take, with its sentence and a machine code (in_library, already_requested, pending_limit, unreadable, failed). */
@Serializable
data class RefusedBook(
    val file: String = "",
    val message: String = "",
    val code: String? = null,
)

/** The answer to an upload: 201 when at least one was sent, 422 (with `sent`) when none were. */
@Serializable
data class BookUploadResponse(
    val message: String? = null,
    @Serializable(with = SentBookList::class) val sent: List<SentBook> = emptyList(),
    @Serializable(with = RefusedBookList::class) val refused: List<RefusedBook> = emptyList(),
)

object SentBookList : LossyListSerializer<SentBook>(SentBook.serializer())
object RefusedBookList : LossyListSerializer<RefusedBook>(RefusedBook.serializer())
