package studio.visns.novarr.domain

import java.text.Collator
import java.text.Normalizer
import java.util.Locale
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList

/** Pure filter/sort/search logic for the library grid (same rules as iOS). */
object LibraryFilter {
    enum class Shelf(val title: String) { NOVELS("Novels"), MANGA("Manga") }

    enum class Filter(val title: String) {
        ALL("All"), READING("Reading"), NEW_CHAPTERS("New chapters"), NOT_STARTED("Not started"), FINISHED("Finished");

        fun matches(novel: NovelSummary): Boolean = when (this) {
            ALL -> true
            READING -> novel.chaptersRead > 0 && !novel.finished
            NEW_CHAPTERS -> novel.chaptersNew > 0
            NOT_STARTED -> novel.chaptersRead == 0
            FINISHED -> novel.finished
        }
    }

    enum class Sort(val title: String) { LAST_READ("Last read"), NAME("Name"), RECENTLY_UPDATED("Recently updated") }

    fun shelf(novels: List<NovelSummary>, shelf: Shelf): List<NovelSummary> =
        novels.filter { it.isManga == (shelf == Shelf.MANGA) }

    /**
     * The grid: [novels] in [list] (when one is chosen: Library's list
     * filter, combined with the status [filter]), matching [search], sorted.
     */
    fun apply(novels: List<NovelSummary>, filter: Filter, sort: Sort, search: String, list: ReadingList? = null): List<NovelSummary> {
        val query = search.trim()
        val filtered = ReadingLists.filter(novels, list).filter { novel ->
            filter.matches(novel) &&
                (query.isEmpty() || matches(novel.name, query) || novel.authorOrNull?.let { matches(it, query) } == true)
        }
        return sorted(filtered, sort)
    }

    fun sorted(novels: List<NovelSummary>, sort: Sort): List<NovelSummary> = when (sort) {
        Sort.NAME -> novels.sortedWith(nameOrder)
        Sort.LAST_READ -> novels.sortedWith(descendingDate { it.lastReadAt })
        Sort.RECENTLY_UPDATED -> novels.sortedWith(descendingDate { it.updatedAt })
    }

    // Looked up per comparison, so a locale change while the app runs is honoured.
    private val nameOrder = Comparator<NovelSummary> { a, b ->
        val collator = Collator.getInstance().apply { strength = Collator.SECONDARY }
        val order = collator.compare(a.name, b.name)
        if (order != 0) order else a.id.compareTo(b.id)
    }

    /** Newest first; series without a date go last, then by name. */
    private fun descendingDate(date: (NovelSummary) -> String?) = Comparator<NovelSummary> { a, b ->
        val da = LatestPosition.parseInstant(date(a))
        val db = LatestPosition.parseInstant(date(b))
        when {
            da != null && db != null && da != db -> db.compareTo(da)
            da != null && db == null -> -1
            da == null && db != null -> 1
            else -> nameOrder.compare(a, b)
        }
    }

    /** Case- and accent-insensitive "contains". */
    fun matches(text: String, query: String): Boolean = fold(text).contains(fold(query))

    private fun fold(text: String): String =
        Normalizer.normalize(text, Normalizer.Form.NFD).replace(Regex("\\p{Mn}+"), "").lowercase(Locale.ROOT)
}
