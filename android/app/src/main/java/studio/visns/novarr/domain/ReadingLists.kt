package studio.visns.novarr.domain

import java.util.Locale
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.api.WantToReadItem

/**
 * Reading lists as pure logic: name checks that mirror the server's limits
 * (so most mistakes are caught before a round trip; the server's 422 message
 * still wins), optimistic membership changes with rollback, and the
 * library's list filter.
 */
object ReadingLists {
    /** The server's limits: names up to 40 characters, at most 30 lists per reader. */
    const val NAME_MAX = 40
    const val MAX_LISTS = 30

    /** One-tap lists offered while the reader has none ("Favourites" is built in now). */
    val STARTERS = listOf("To read", "Finished")

    /** The built-in list's name (the server's App\ReadingList::WANT_TO_READ_NAME): reserved. */
    const val WANT_TO_READ = "Want to read"

    /** The other built-in list's name (App\ReadingList::FAVOURITES_NAME): reserved on servers that have it. */
    const val FAVOURITES = "Favourites"

    /** The reader's own lists: everything but the built-ins ("Want to read", "Favourites"). */
    fun own(lists: List<ReadingList>): List<ReadingList> = lists.filterNot { it.isBuiltIn }

    /** The built-in "Want to read", once the server sent it (an older server has none). */
    fun wantToRead(lists: List<ReadingList>): ReadingList? = lists.firstOrNull { it.isWantToRead }

    /** The built-in "Favourites", once the server sent it (an older server has none). */
    fun favourites(lists: List<ReadingList>): ReadingList? = lists.firstOrNull { it.isFavourites }

    /** Whether [novelId] is a favourite (false while the list is unknown). */
    fun isFavourite(lists: List<ReadingList>, novelId: Int): Boolean = favourites(lists)?.let { novelId in it } ?: false

    // Names

    /** Trimmed (what is sent; Laravel trims input too). */
    fun clean(name: String): String = name.trim()

    /** Characters as the server counts them (code points, so an emoji is one). */
    fun length(name: String): Int = clean(name).let { it.codePointCount(0, it.length) }

    /**
     * Why [name] can't be used, or null when it can. [renaming] is the list
     * being renamed (its own name is not a duplicate); a new list also needs
     * room under [MAX_LISTS].
     */
    fun nameProblem(name: String, lists: List<ReadingList>, renaming: Int? = null): String? {
        val cleaned = clean(name)
        if (cleaned.isEmpty()) return "Enter a name."
        if (length(cleaned) > NAME_MAX) return "Names can be up to $NAME_MAX characters."
        val key = fold(cleaned)
        if (key == fold(WANT_TO_READ)) return "“$WANT_TO_READ” is already one of your categories."
        if (key == fold(FAVOURITES) && lists.any { it.isFavourites }) return "“$FAVOURITES” is already one of your categories."
        if (lists.any { it.id != renaming && fold(it.name) == key }) return "You already have a category called “$cleaned”."
        if (renaming == null && own(lists).size >= MAX_LISTS) return "You can have up to $MAX_LISTS categories."
        return null
    }

    /** The starters not already taken, so a tap can't hit a duplicate. */
    fun starters(lists: List<ReadingList>): List<String> =
        STARTERS.filter { starter -> own(lists).none { fold(it.name) == fold(starter) } }

    private fun fold(name: String): String = clean(name).lowercase(Locale.ROOT).replace(Regex("\\s+"), " ")

    // Membership, optimistic

    /** A membership change sent and not answered yet. */
    data class Toggle(val listId: Int, val novelId: Int, val add: Boolean)

    /** The lists as shown, with the changes still on their way. */
    data class State(val lists: List<ReadingList> = emptyList(), val inFlight: Set<Toggle> = emptySet()) {
        fun busy(listId: Int, novelId: Int): Boolean = inFlight.any { it.listId == listId && it.novelId == novelId }
    }

    /**
     * Shows [novelId] in or out of [listId] straight away. Null when that
     * change can't start: the list is unknown, it is already as asked, or a
     * change for the same list and series is still on its way (one at a time,
     * so answers can't arrive out of order).
     */
    fun begin(state: State, listId: Int, novelId: Int, add: Boolean): State? {
        val list = state.lists.firstOrNull { it.id == listId } ?: return null
        if (state.busy(listId, novelId) || (novelId in list) == add) return null
        val toggle = Toggle(listId, novelId, add)
        return State(apply(state.lists, toggle), state.inFlight + toggle)
    }

    /**
     * The server answered [toggle] with its copy of the list: that copy
     * replaces ours, with any other change to the same list still on its way
     * applied on top.
     */
    fun succeed(state: State, toggle: Toggle, answer: ReadingList): State {
        val others = state.inFlight - toggle
        var lists = replace(state.lists, answer)
        others.filter { it.listId == answer.id }.forEach { lists = apply(lists, it) }
        return State(lists, others)
    }

    /** [toggle] failed: undone (whatever else changed meanwhile stays). */
    fun fail(state: State, toggle: Toggle): State =
        State(apply(state.lists, toggle.copy(add = !toggle.add)), state.inFlight - toggle)

    /** Adds or removes a series in one list, keeping its count in step ("Want to read" is newest first: added at the front). */
    fun apply(lists: List<ReadingList>, toggle: Toggle): List<ReadingList> = lists.map { list ->
        if (list.id != toggle.listId) return@map list
        val has = toggle.novelId in list
        when {
            toggle.add && !has -> list.copy(
                // The built-ins are newest first: added at the front.
                novelIds = if (list.isBuiltIn) listOf(toggle.novelId) + list.novelIds else list.novelIds + toggle.novelId,
                count = list.count + 1,
            )
            !toggle.add && has -> list.copy(novelIds = list.novelIds - toggle.novelId, count = (list.count - 1).coerceAtLeast(0))
            else -> list
        }
    }

    /** A list from the server replaces ours (or is added at the end). */
    fun replace(lists: List<ReadingList>, answer: ReadingList): List<ReadingList> =
        if (lists.any { it.id == answer.id }) lists.map { if (it.id == answer.id) answer else it } else lists + answer

    /**
     * The reader's order (`position`, set in Manage categories). A server
     * from before positions sends none (all 0): then "Want to read" first,
     * then "Favourites", then in name order, as 1.16 showed them.
     */
    fun sorted(lists: List<ReadingList>): List<ReadingList> =
        if (lists.any { it.position != 0 }) lists.sortedWith(compareBy<ReadingList> { it.position }.thenBy { it.id })
        else lists.sortedWith(
            compareBy<ReadingList> { if (it.isWantToRead) 0 else if (it.isBuiltIn) 1 else 2 }
                .thenBy(String.CASE_INSENSITIVE_ORDER) { it.name }
                .thenBy { it.id },
        )

    /** The lists [novelId] is in. */
    fun containing(lists: List<ReadingList>, novelId: Int): List<ReadingList> = lists.filter { novelId in it }

    // Library filter

    /** The series of [list] among [novels] (a series the library doesn't have, or no longer has, is skipped). */
    fun filter(novels: List<NovelSummary>, list: ReadingList?): List<NovelSummary> {
        if (list == null) return novels
        val ids = list.novelIds.toSet()
        return novels.filter { it.id in ids }
    }

    /** Why a list filter shows nothing on this shelf. */
    sealed interface Empty {
        /** Nothing in the list at all (that the library has). */
        data object NoSeries : Empty

        /** Its series are all on the other shelf ([count] of them). */
        data class OtherShelf(val count: Int) : Empty

        /** Some are on this shelf, but the status filter hides them. */
        data object Filtered : Empty
    }

    fun emptyReason(list: ReadingList, library: List<NovelSummary>, shelf: List<NovelSummary>): Empty {
        val inLibrary = filter(library, list).size
        val onShelf = filter(shelf, list).size
        return when {
            inLibrary == 0 -> Empty.NoSeries
            onShelf == 0 -> Empty.OtherShelf(inLibrary)
            else -> Empty.Filtered
        }
    }

    // Want to read

    /** Whether [novelId] is on "Want to read" (false while the list is unknown). */
    fun isWanted(lists: List<ReadingList>, novelId: Int): Boolean = wantToRead(lists)?.let { novelId in it } ?: false

    /**
     * The server's items as shown, given the local copy of the list: a series
     * taken off since (here or on another screen) goes at once; with no local
     * copy, the server's items as they are.
     */
    fun shown(items: List<WantToReadItem>, list: ReadingList?): List<WantToReadItem> {
        if (list == null) return items
        val ids = list.novelIds.toSet()
        return items.filter { it.novel.id in ids }
    }

    /**
     * Whether the local list holds a series the server's items (its first
     * [limit]) don't have yet — added since they were fetched, so they need
     * fetching again to show it. Removals never need a fetch ([shown] hides them).
     */
    fun needsRefetch(items: List<WantToReadItem>, list: ReadingList?, limit: Int = Int.MAX_VALUE): Boolean {
        if (list == null) return false
        val have = items.map { it.novel.id }.toSet()
        return list.novelIds.take(limit).any { it !in have }
    }

    /** "Started" under a cover: the server's word, or reading seen here since (a chapter read). */
    fun started(item: WantToReadItem): Boolean = item.started || item.novel.chaptersRead > 0

    /** The remembered list choice, if that list still exists. */
    fun selected(lists: List<ReadingList>, listId: Int?): ReadingList? = listId?.let { id -> lists.firstOrNull { it.id == id } }
}
