package studio.visns.novarr.domain

import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.ContinueItem
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.store.HomeLayout

/**
 * Home, pure: which sections show and in what order ([sections]: 1.18's
 * summary of the categories, or 1.16's layout on a first run), the
 * Favourites and Want to read shelves from the local lists and the library,
 * 1.16's category tiles with up to four covers each, what is in progress
 * across reading and listening, and the search over everything this device
 * knows.
 */
object HomeShelves {
    /** Covers on a folder tile. */
    const val COLLAGE = 4

    /** A folder tile: one of the reader's own lists. */
    data class Folder(val id: Int, val name: String, val count: Int, val covers: List<String>)

    /** One thing in progress: a series being read or an audiobook being listened to, newest first as the server sent them. */
    sealed interface InProgress {
        val key: String
        val title: String
        val coverUrl: String?
        val fraction: Float

        data class Reading(val item: ContinueItem) : InProgress {
            override val key get() = "r-${item.novel.id}"
            override val title get() = item.novel.name
            override val coverUrl get() = item.novel.coverUrl
            override val fraction get() = item.novel.readFraction
        }

        data class Listening(val book: AudiobookSummary) : InProgress {
            override val key get() = "l-${book.id}"
            override val title get() = book.title
            override val coverUrl get() = book.coverUrl
            override val fraction get() = book.fraction
        }
    }

    /** A list's series as the library has them, in the list's order (newest first for the built-ins); unknown ids are skipped. */
    fun shelf(list: ReadingList?, novels: Map<Int, NovelSummary>): List<NovelSummary> =
        list?.novelIds.orEmpty().mapNotNull { novels[it] }.distinctBy { it.id }

    /** The reader's own lists as folders, by name, each with its first covers. */
    fun folders(lists: List<ReadingList>, novels: Map<Int, NovelSummary>): List<Folder> =
        ReadingLists.sorted(ReadingLists.own(lists)).map { list ->
            val members = shelf(list, novels)
            Folder(
                id = list.id,
                name = list.name,
                // The library's count when it knows the series, else the server's.
                count = if (novels.isEmpty()) list.count else members.size,
                covers = members.mapNotNull { it.coverUrl?.takeIf(String::isNotBlank) }.take(COLLAGE),
            )
        }

    // Home's sections (1.18: a summary of the categories)

    /** One of Home's sections, top to bottom; the screen draws each (and skips one with nothing to show, like an empty New chapters). */
    sealed interface Section {
        val key: String

        data object Search : Section { override val key get() = "search" }

        /**
         * STATS SLOT: the "Your stats" card, right after Search. Only emitted
         * when the screen was given a card (HomeScreen's `statsCard`), so it is
         * hidden by default.
         */
        data object Stats : Section { override val key get() = "stats" }

        /** No category holds anything yet: a one-tap "Create your first category" (1.16's layout stays below until then). */
        data object FirstCategory : Section { override val key get() = "first-category" }

        /** The compact Continue strip (its halves follow Settings › Home's Continue reading / listening). */
        data object Continue : Section { override val key get() = "quick" }

        /** One visible, non-empty category: its name, count and "See all", and a row of its covers. */
        data class Category(val list: ReadingList, val novels: List<NovelSummary>, val count: Int) : Section {
            override val key get() = "category-${list.id}"
        }

        /** The "Manage categories" link under the categories. */
        data object ManageCategories : Section { override val key get() = "manage-categories" }

        data object Favourites : Section { override val key get() = "favourites" }
        data object WantToRead : Section { override val key get() = "want-to-read" }

        /** "Reading & listening": everything in progress, as covers. */
        data object InProgress : Section { override val key get() = "in-progress" }

        /** 1.16's folder tiles (only in the first-run layout). */
        data object Folders : Section { override val key get() = "folders" }
        data object NewChapters : Section { override val key get() = "new-chapters" }
        data object Library : Section { override val key get() = "library" }
    }

    /**
     * Whether Home keeps 1.16's layout: the server has no categories (an
     * older server), or none of the reader's categories holds a series yet
     * (built-ins empty, own ones empty or none) — then Home also offers to
     * create the first one.
     */
    fun firstRun(lists: List<ReadingList>, listsOffered: Boolean, listsLoaded: Boolean): Boolean =
        !listsOffered || (listsLoaded && lists.none { it.novelIds.isNotEmpty() })

    /**
     * Home top to bottom. 1.18's default: Search, [STATS SLOT], one section
     * per visible (not hidden on Home), non-empty category in the reader's
     * order, "Manage categories", New chapters, the Library button. Settings ›
     * Home can bring back Continue, Favourites, Want to read and Reading &
     * listening (before the categories, where 1.16 had them; a built-in shown
     * as its own shelf isn't repeated as a category). [firstRun] keeps 1.16's
     * layout. [hasStats]: a stats card was given (the slot is empty otherwise).
     * [wantToReadShelf]: the server's Home feed has Want to read items.
     */
    fun sections(
        lists: List<ReadingList>,
        novels: Map<Int, NovelSummary>,
        layout: HomeLayout,
        listsOffered: Boolean,
        listsLoaded: Boolean,
        hasStats: Boolean = false,
        wantToReadShelf: Boolean = false,
    ): List<Section> = buildList {
        add(Section.Search)
        // STATS SLOT: emitted only when HomeScreen got a statsCard.
        if (hasStats) add(Section.Stats)
        val favourites = if (listsOffered) ReadingLists.favourites(lists) else null
        val want = if (listsOffered) ReadingLists.wantToRead(lists) else null
        val hasWant = want != null || wantToReadShelf
        when {
            firstRun(lists, listsOffered, listsLoaded) -> {
                if (listsOffered) add(Section.FirstCategory)
                add(Section.Continue)
                if (favourites != null) add(Section.Favourites)
                if (hasWant) add(Section.WantToRead)
                add(Section.InProgress)
                if (listsOffered) add(Section.Folders)
            }
            else -> {
                if (layout.showContinue) add(Section.Continue)
                val showFavourites = layout.showFavourites && favourites != null
                val showWant = layout.showWantToRead && hasWant
                if (showFavourites) add(Section.Favourites)
                if (showWant) add(Section.WantToRead)
                if (layout.showInProgress) add(Section.InProgress)
                // Still loading the categories: nothing in their place yet (rather than a flash of the old layout).
                if (listsLoaded) {
                    ReadingLists.sorted(lists)
                        .filter { !it.hiddenOnHome && it.novelIds.isNotEmpty() }
                        .filterNot { (showFavourites && it.isFavourites) || (showWant && it.isWantToRead) }
                        .forEach { list ->
                            val members = shelf(list, novels)
                            // The library's count when it knows the series, else the server's.
                            add(Section.Category(list, members, if (novels.isEmpty()) list.count else members.size))
                        }
                    add(Section.ManageCategories)
                }
            }
        }
        add(Section.NewChapters)
        add(Section.Library)
    }

    /** Reading, then listening, interleaved by position so neither crowds the other out. */
    fun inProgress(reading: List<ContinueItem>, listening: List<AudiobookSummary>): List<InProgress> {
        val r = reading.map(InProgress::Reading)
        val l = listening.filter { it.started && it.progress?.finished != true }.map(InProgress::Listening)
        val out = mutableListOf<InProgress>()
        for (i in 0 until maxOf(r.size, l.size)) {
            r.getOrNull(i)?.let(out::add)
            l.getOrNull(i)?.let(out::add)
        }
        return out
    }

    /** The compact Continue strip: the latest of each that is switched on. */
    fun quickResume(reading: List<ContinueItem>, listening: List<AudiobookSummary>, showReading: Boolean, showListening: Boolean): List<InProgress> =
        listOfNotNull(
            reading.firstOrNull()?.takeIf { showReading }?.let(InProgress::Reading),
            listening.firstOrNull { it.progress?.finished != true }?.takeIf { showListening }?.let(InProgress::Listening),
        )

    /** Series of the library matching [query] (title or author; accents, case and punctuation ignored), best first. */
    fun search(query: String, novels: Collection<NovelSummary>, limit: Int = 40): List<NovelSummary> {
        val q = AutoSearch.normalize(query)
        if (q.isEmpty()) return emptyList()
        val words = q.split(' ')
        return novels.asSequence()
            .mapNotNull { novel ->
                val title = AutoSearch.normalize(novel.name)
                val author = AutoSearch.normalize(novel.author.orEmpty())
                val score = when {
                    title == q -> 4
                    title.startsWith(q) -> 3
                    " $title ".contains(" $q") -> 2
                    words.all { w -> "$title $author".contains(w) } -> 1
                    else -> 0
                }
                if (score == 0) null else novel to score
            }
            .sortedWith(compareByDescending<Pair<NovelSummary, Int>> { it.second }.thenBy(String.CASE_INSENSITIVE_ORDER) { it.first.name })
            .map { it.first }
            .take(limit)
            .toList()
    }
}
