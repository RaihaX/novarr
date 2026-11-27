package studio.visns.novarr.domain

import java.text.Collator
import java.text.Normalizer
import java.util.Locale
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList

/** Pure filter/sort/search logic for the library grid (same rules as iOS). */
object LibraryFilter {
    /** [noun]: the shelf's series in running text ("No books yet"). */
    enum class Shelf(val title: String, val noun: String) {
        NOVELS("Web Novels", "web novels"), MANGA("Manga", "manga"), COMICS("Comics", "comics"), BOOKS("Books", "books");

        /** Novels takes every series that is neither manga, a comic nor a book (including a blank or unknown medium). */
        fun matches(novel: NovelSummary): Boolean = when (this) {
            NOVELS -> !novel.isManga && !novel.isComic && !novel.isBook
            MANGA -> novel.isManga
            COMICS -> novel.isComic
            BOOKS -> novel.isBook
        }
    }

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

    fun shelf(novels: List<NovelSummary>, shelf: Shelf): List<NovelSummary> = novels.filter(shelf::matches)

    /**
     * The shelves the picker offers: Books only when the library has at least
     * one book, so a library without books looks exactly as before; Comics
     * when the server knows comics ([comics]: meta `comics`) or the library
     * has one (never hiding series the reader has).
     */
    fun shelves(novels: List<NovelSummary>, comics: Boolean = false): List<Shelf> = Shelf.entries.filter { shelf ->
        when (shelf) {
            Shelf.BOOKS -> novels.any { it.isBook }
            Shelf.COMICS -> comics || novels.any { it.isComic }
            else -> true
        }
    }

    /** The shelf in effect: a remembered shelf that isn't offered (Books with no books) falls back to Novels. */
    fun effectiveShelf(shelf: Shelf, novels: List<NovelSummary>, comics: Boolean = false): Shelf =
        if (shelf in shelves(novels, comics)) shelf else Shelf.NOVELS

    /**
     * The grid: [novels] in [list] (when one is chosen: Library's list
     * filter), matching the status [filter] and the [origin] filter and
     * [search], sorted.
     */
    fun apply(
        novels: List<NovelSummary>,
        filter: Filter,
        sort: Sort,
        search: String,
        list: ReadingList? = null,
        origin: Origin.Filter = Origin.Filter.ALL,
    ): List<NovelSummary> {
        val query = search.trim()
        val filtered = ReadingLists.filter(novels, list).filter { novel ->
            filter.matches(novel) && origin.matches(novel) &&
                (query.isEmpty() || matches(novel.name, query) || novel.authorOrNull?.let { matches(it, query) } == true)
        }
        return sorted(filtered, sort)
    }

    /**
     * A chip's or Origin option's count: the series in [list] matching both
     * the status [filter] and the [origin] filter (search is not counted).
     * Status chips pass the chosen origin; Origin options pass the chosen status.
     */
    fun count(novels: List<NovelSummary>, list: ReadingList?, filter: Filter, origin: Origin.Filter = Origin.Filter.ALL): Int =
        ReadingLists.filter(novels, list).count { filter.matches(it) && origin.matches(it) }

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
