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
    /** null: the reader's own list; "want_to_read" / "favourites": the built-in ones (never renamed or deleted, newest first). */
    val kind: String? = null,
) {
    /** Membership by the ids the server sent (count can lag a series that was removed from the library). */
    operator fun contains(novelId: Int): Boolean = novelId in novelIds

    /** The built-in "Want to read" list. */
    val isWantToRead: Boolean get() = kind == KIND_WANT_TO_READ

    /** The built-in "Favourites" list. */
    val isFavourites: Boolean get() = kind == KIND_FAVOURITES

    /** A built-in list (any kind, including one a newer server adds): not the reader's own. */
    val isBuiltIn: Boolean get() = kind != null

    companion object {
        const val KIND_WANT_TO_READ = "want_to_read"
        const val KIND_FAVOURITES = "favourites"
    }
}

/** PUT/DELETE /novels/{id}/favourite: {favourite, list}. */
@Serializable
data class FavouriteAnswer(val favourite: Boolean = false, val list: ReadingList? = null)

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
