package studio.visns.novarr.domain

import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.api.SearchResult

/**
 * Categories (Mihon style; the server's reading lists) as pure logic: the
 * long-press multi-select, the tri-state "Set categories" sheet and the one
 * memberships call it makes, the Library's category tabs, and how a Browse
 * card shows that it was already put somewhere.
 */
object Categories {
    /** How long "Mark as read/unread" waits for Undo before it is sent. */
    const val UNDO_MS = 4_500L

    /** The server takes at most this many series in one bulk call. */
    const val BULK_MAX = 500

    // Selection

    /**
     * A long-press multi-select over items keyed by [K]. Active while
     * anything is selected: taking the last item out ends it, as in Mihon.
     */
    data class Selection<K>(val keys: Set<K> = emptySet()) {
        val active: Boolean get() = keys.isNotEmpty()
        val count: Int get() = keys.size
        operator fun contains(key: K): Boolean = key in keys

        /** A long-press: starts selecting with [key], or (already selecting) toggles it. */
        fun longPress(key: K): Selection<K> = if (active) toggle(key) else Selection(setOf(key))

        /**
         * A tap: while selecting it toggles [key] (null result: not
         * selecting, so the caller opens the item as usual).
         */
        fun tap(key: K): Selection<K>? = if (active) toggle(key) else null

        fun toggle(key: K): Selection<K> = Selection(if (key in keys) keys - key else keys + key)

        /** Everything shown is selected (already all selected: unchanged). */
        fun selectAll(shown: Collection<K>): Selection<K> = if (!active) this else Selection(keys + shown)

        /** Whether "Select all" would add anything. */
        fun allSelected(shown: Collection<K>): Boolean = keys.containsAll(shown)

        /** Keeps only what is still there (after a refresh, a filter or a tab change). */
        fun retain(available: Collection<K>): Selection<K> {
            val set = available.toSet()
            return if (keys.all { it in set }) this else Selection(keys.filterTo(LinkedHashSet()) { it in set })
        }

        fun exit(): Selection<K> = Selection()
    }

    // The tri-state sheet

    /** A category's box in "Set categories": every selected series in it, none, or some (indeterminate). */
    enum class Tri { ON, OFF, MIXED }

    /** Each category's box for [novelIds]: ON when all are in it, OFF when none, MIXED otherwise. */
    fun initial(lists: List<ReadingList>, novelIds: Collection<Int>): Map<Int, Tri> = lists.associate { list ->
        val inside = novelIds.count { it in list }
        list.id to when {
            novelIds.isEmpty() || inside == 0 -> Tri.OFF
            inside == novelIds.size -> Tri.ON
            else -> Tri.MIXED
        }
    }

    /**
     * A tap on a box: mixed becomes ticked (Mihon's order), ticked becomes
     * clear, and clear ticked; a box that started mixed can go back to mixed
     * after clear (leaving those series as they are).
     */
    fun next(current: Tri, started: Tri): Tri = when (current) {
        Tri.MIXED -> Tri.ON
        Tri.ON -> Tri.OFF
        Tri.OFF -> if (started == Tri.MIXED) Tri.MIXED else Tri.ON
    }

    /** What Apply sends: the categories whose box changed to ticked (add) or to clear (remove). */
    data class Diff(val add: List<Int>, val remove: List<Int>) {
        val isEmpty: Boolean get() = add.isEmpty() && remove.isEmpty()
    }

    /** Only the changed boxes; a box still (or again) mixed changes nothing. Ids not in [initial] (a new category) count as clear before. */
    fun diff(initial: Map<Int, Tri>, current: Map<Int, Tri>): Diff {
        val add = mutableListOf<Int>()
        val remove = mutableListOf<Int>()
        current.forEach { (id, state) ->
            val before = initial[id] ?: Tri.OFF
            if (state == before) return@forEach
            when (state) {
                Tri.ON -> add += id
                Tri.OFF -> remove += id
                Tri.MIXED -> Unit
            }
        }
        return Diff(add.sorted(), remove.sorted())
    }

    /**
     * The lists as they will be after the memberships call, so the screens
     * show it at once (the server's answer replaces it). Built-ins are newest
     * first, so their additions go to the front.
     */
    fun applyMemberships(lists: List<ReadingList>, novelIds: List<Int>, add: Collection<Int>, remove: Collection<Int>): List<ReadingList> =
        lists.map { list ->
            when (list.id) {
                in add -> {
                    val missing = novelIds.filter { it !in list }.distinct()
                    if (missing.isEmpty()) list
                    else list.copy(
                        novelIds = if (list.isBuiltIn) missing + list.novelIds else list.novelIds + missing,
                        count = list.count + missing.size,
                    )
                }
                in remove -> {
                    val gone = novelIds.filter { it in list }.toSet()
                    if (gone.isEmpty()) list else list.copy(novelIds = list.novelIds.filterNot { it in gone }, count = (list.count - gone.size).coerceAtLeast(0))
                }
                else -> list
            }
        }

    // Mark as read / unread

    /** How a series looks once marked read ([read]) or unread as a whole, until the library is fetched again. */
    fun marked(novel: NovelSummary, read: Boolean): NovelSummary =
        if (read) novel.copy(chaptersRead = novel.chaptersTotal, chaptersNew = 0, readPercent = 100, finished = novel.chaptersTotal > 0)
        else novel.copy(chaptersRead = 0, readPercent = 0, finished = false)

    /** The snackbar line: "Marked 3 series as read". */
    fun markedLabel(count: Int, read: Boolean): String =
        "Marked ${if (count == 1) "1 series" else "$count series"} as ${if (read) "read" else "unread"}"

    /** Chapters still unread (the cover's badge). */
    fun unread(novel: NovelSummary): Int = (novel.chaptersTotal - novel.chaptersRead).coerceAtLeast(0)

    // Library tabs

    /** One of the Library's category tabs: All, a category, or the series in none. */
    sealed interface Tab {
        /** What is remembered for the tab ("all", "none" or the list id). */
        val key: String

        data object All : Tab { override val key get() = "all" }
        data object Uncategorised : Tab { override val key get() = "none" }
        data class Category(val list: ReadingList) : Tab { override val key get() = list.id.toString() }

        companion object {
            /** A remembered key back to a tab: "" (1.16's "all series") and unknown keys are All; a deleted category is null. */
            fun from(key: String?, lists: List<ReadingList>): Tab? = when (key) {
                null, "", "all" -> All
                "none" -> Uncategorised
                else -> key.toIntOrNull()?.let { id -> lists.firstOrNull { it.id == id }?.let(::Category) }
            }
        }
    }

    /** The tab bar: All, each category in the reader's order (hidden-on-Home ones too: that only concerns Home), then Uncategorised. */
    fun tabs(lists: List<ReadingList>): List<Tab> = buildList {
        add(Tab.All)
        ReadingLists.sorted(lists).forEach { add(Tab.Category(it)) }
        add(Tab.Uncategorised)
    }

    /** The id used for the synthetic "Uncategorised" list (never a server id). */
    const val UNCATEGORISED_ID = -1

    /**
     * The tab as the list the Library's filter takes: null for All, the
     * category, or a synthetic list of the series in no category.
     */
    fun asList(tab: Tab, lists: List<ReadingList>, novels: List<NovelSummary>): ReadingList? = when (tab) {
        Tab.All -> null
        is Tab.Category -> tab.list
        Tab.Uncategorised -> {
            val inSome = lists.flatMapTo(HashSet()) { it.novelIds }
            val ids = novels.map { it.id }.filter { it !in inSome }
            ReadingList(UNCATEGORISED_ID, UNCATEGORISED, ids.size, ids)
        }
    }

    const val UNCATEGORISED = "Uncategorised"

    fun title(tab: Tab): String = when (tab) {
        Tab.All -> "All"
        Tab.Uncategorised -> UNCATEGORISED
        is Tab.Category -> tab.list.name
    }

    // Browse / Discover

    /** A Browse card already put somewhere: shown greyed, with a short badge. */
    data class Mark(val badge: String)

    /**
     * "In Action" when the library has it in a category, "Requested ·
     * Action" when the reader's open request carries one, "Requested" for
     * the reader's own request without; null for a card nothing was done
     * with yet (shown as usual).
     */
    fun mark(result: SearchResult, lists: List<ReadingList>): Mark? {
        fun names(ids: List<Int>): String? {
            val known = ids.mapNotNull { id -> lists.firstOrNull { it.id == id } }
            if (known.isEmpty()) return null
            val sorted = ReadingLists.sorted(known)
            return sorted.first().name + if (sorted.size > 1) " +${sorted.size - 1}" else ""
        }
        names(result.inCategories)?.let { return Mark("In $it") }
        names(result.requestedCategories)?.let { return Mark("Requested · $it") }
        if (result.requested?.byMe == true && !result.inLibrary && result.novelId == null) return Mark("Requested")
        return null
    }

    /** The Browse sheet's boxes to start with: where the series is, or where the reader asked for it to go. */
    fun preTicked(result: SearchResult): Set<Int> = (result.inCategories + result.requestedCategories).toSet()

    /** What confirming the Browse sheet does. */
    sealed interface BrowseAction {
        /** In the library: one memberships call for this series. */
        data class Memberships(val novelId: Int, val add: List<Int>, val remove: List<Int>) : BrowseAction

        /** Not in the library: request it (or join, or update the reader's own request) with these categories. */
        data class Request(val categories: List<Int>) : BrowseAction

        /** Nothing changed. */
        data object Nothing : BrowseAction
    }

    fun browseAction(result: SearchResult, chosen: Set<Int>): BrowseAction {
        val novelId = result.novelId
        if (result.inLibrary || novelId != null) {
            if (novelId == null) return BrowseAction.Nothing
            val before = result.inCategories.toSet()
            val add = (chosen - before).sorted()
            val remove = (before - chosen).sorted()
            return if (add.isEmpty() && remove.isEmpty()) BrowseAction.Nothing else BrowseAction.Memberships(novelId, add, remove)
        }
        val before = result.requestedCategories.toSet()
        // A new request needs a category (the sheet is "Add to category"); the reader's own one can be changed, cleared too.
        val mine = result.requested?.byMe == true
        if (chosen == before && (mine || chosen.isEmpty())) return BrowseAction.Nothing
        if (chosen.isEmpty() && !mine) return BrowseAction.Nothing
        return BrowseAction.Request(chosen.sorted())
    }

    /** The card after the action went through (or after the server's 409 said how things are). */
    fun afterBrowse(result: SearchResult, action: BrowseAction): SearchResult = when (action) {
        is BrowseAction.Memberships -> result.copy(inCategories = ((result.inCategories.toSet() + action.add) - action.remove.toSet()).sorted())
        is BrowseAction.Request -> result.copy(
            requestedCategories = action.categories,
            // Made or joined ("Me too"): either way the reader's now, as markRequested has it.
            requested = studio.visns.novarr.api.ResultRequested(result.requested?.status ?: "pending", byMe = true),
        )
        BrowseAction.Nothing -> result
    }
}
