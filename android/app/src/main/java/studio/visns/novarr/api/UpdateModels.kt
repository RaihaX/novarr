package studio.visns.novarr.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

// New chapters for the reader (GET /api/v1/updates?since=<cursor>). The
// server lists a series only when the reader had nothing left to read in it
// and now does; without `since` it answers no series, only a cursor.

/** One series that has something new to read. */
@Serializable
data class UpdatedSeries(
    @SerialName("novel_id") val novelId: Int,
    val name: String = "",
    val medium: String = Medium.NOVEL,
    @SerialName("new_count") val newCount: Int = 0,
    @SerialName("chapter_id") val chapterId: Int? = null,
    @SerialName("chapter_label") val chapterLabel: String? = null,
    @SerialName("latest_at") val latestAt: String? = null,
)

@Serializable
data class UpdatesFeed(
    /** Opaque: sent back as `since` next time. */
    val cursor: String? = null,
    @Serializable(with = UpdatedSeriesList::class) val series: List<UpdatedSeries> = emptyList(),
)

object UpdatedSeriesList : LossyListSerializer<UpdatedSeries>(UpdatedSeries.serializer())
