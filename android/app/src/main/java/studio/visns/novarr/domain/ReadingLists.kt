package studio.visns.novarr.domain

import java.util.Locale
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList

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

    /** One-tap lists offered while the reader has none. */
    val STARTERS = listOf("Favourites", "To read", "Finished")

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
        if (lists.any { it.id != renaming && fold(it.name) == key }) return "You already have a list called “$cleaned”."
        if (renaming == null && lists.size >= MAX_LISTS) return "You can have up to $MAX_LISTS lists."
        return null
    }

    /** The starters not already taken, so a tap can't hit a duplicate. */
    fun starters(lists: List<ReadingList>): List<String> =
        STARTERS.filter { starter -> lists.none { fold(it.name) == fold(starter) } }

    private fun fold(name: String): String = clean(name).lowercase(Locale.ROOT)

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

    /** Adds or removes a series in one list, keeping its count in step. */
    fun apply(lists: List<ReadingList>, toggle: Toggle): List<ReadingList> = lists.map { list ->
        if (list.id != toggle.listId) return@map list
        val has = toggle.novelId in list
        when {
            toggle.add && !has -> list.copy(novelIds = list.novelIds + toggle.novelId, count = list.count + 1)
            !toggle.add && has -> list.copy(novelIds = list.novelIds - toggle.novelId, count = (list.count - 1).coerceAtLeast(0))
            else -> list
        }
    }

    /** A list from the server replaces ours (or is added at the end). */
    fun replace(lists: List<ReadingList>, answer: ReadingList): List<ReadingList> =
        if (lists.any { it.id == answer.id }) lists.map { if (it.id == answer.id) answer else it } else lists + answer

    /** In name order, as the sheets show them. */
    fun sorted(lists: List<ReadingList>): List<ReadingList> =
        lists.sortedWith(compareBy(String.CASE_INSENSITIVE_ORDER) { it: ReadingList -> it.name }.thenBy { it.id })

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

    /** The remembered list choice, if that list still exists. */
    fun selected(lists: List<ReadingList>, listId: Int?): ReadingList? = listId?.let { id -> lists.firstOrNull { it.id == id } }
}
