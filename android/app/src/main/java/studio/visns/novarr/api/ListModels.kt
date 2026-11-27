package studio.visns.novarr.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

// Reading lists (GET/POST /lists, PATCH/DELETE /lists/{id},
// PUT/DELETE /lists/{id}/novels/{novelId}). A list is the reader's own;
// every change answers the whole list, which replaces the local copy.

@Serializable
data class ReadingList(
    val id: Int,
    val name: String = "",
    val count: Int = 0,
    @SerialName("novel_ids") val novelIds: List<Int> = emptyList(),
    /** null: the reader's own list; "want_to_read": the built-in one (never renamed or deleted, newest first). */
    val kind: String? = null,
) {
    /** Membership by the ids the server sent (count can lag a series that was removed from the library). */
    operator fun contains(novelId: Int): Boolean = novelId in novelIds

    /** The built-in "Want to read" list. */
    val isWantToRead: Boolean get() = kind == KIND_WANT_TO_READ

    companion object {
        const val KIND_WANT_TO_READ = "want_to_read"
    }
}

/** One series on "Want to read" (GET /want-to-read, Home's shelf): when it was added, and whether the reader has begun it. */
@Serializable
data class WantToReadItem(
    val novel: NovelSummary,
    @SerialName("added_at") val addedAt: String? = null,
    val started: Boolean = false,
)

/** GET /want-to-read: the list itself and its series, newest first. */
@Serializable
data class WantToReadEnvelope(
    val list: ReadingList? = null,
    @Serializable(with = WantToReadList::class) val items: List<WantToReadItem> = emptyList(),
)

@Serializable
data class ListsEnvelope(@Serializable(with = ReadingListList::class) val lists: List<ReadingList>)

@Serializable
data class ListEnvelope(val list: ReadingList)

object ReadingListList : LossyListSerializer<ReadingList>(ReadingList.serializer())
