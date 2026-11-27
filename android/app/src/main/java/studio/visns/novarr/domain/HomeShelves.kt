package studio.visns.novarr.domain

import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.ContinueItem
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList

/**
 * Home as "my stuff", pure: the Favourites and Want to read shelves from the
 * local lists and the library, the reader's folders (their own reading lists)
 * with up to four covers each, what is in progress across reading and
 * listening, and the search over everything this device knows.
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
