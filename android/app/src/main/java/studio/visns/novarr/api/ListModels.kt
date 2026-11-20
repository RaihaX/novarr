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
) {
    /** Membership by the ids the server sent (count can lag a series that was removed from the library). */
    operator fun contains(novelId: Int): Boolean = novelId in novelIds
}

@Serializable
data class ListsEnvelope(@Serializable(with = ReadingListList::class) val lists: List<ReadingList>)

@Serializable
data class ListEnvelope(val list: ReadingList)

object ReadingListList : LossyListSerializer<ReadingList>(ReadingList.serializer())
