package studio.visns.novarr.domain

import java.text.Normalizer
import java.util.Locale
import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList

/**
 * The audiobook browse tree for Android Auto (and any other media browser:
 * Wear, a car's own system), as pure logic: which folders there are, what
 * each holds, how items are named and how far through they are. The service
 * (playback.AutoLibrary) gathers a [Catalog] from the server and the offline
 * cache and turns [Node]s into Media3 items.
 *
 *     root ─┬─ Continue listening         (started, newest first)
 *           ├─ Downloaded                 (plays with no network)
 *           ├─ Favourites                 (audiobooks of the built-in list; "Want to read" on a server without it)
 *           └─ Browse ─┬─ Want to read
 *                      ├─ All audiobooks  (paged)
 *                      └─ <each reading list holding an audiobook>
 *
 * Android Auto shows at most four root folders (as tabs), so everything past
 * the first three lives under Browse.
 */
object AutoBrowse {
    const val ROOT = "root"

    /** The root asked for by the system's "recent" media (resumption): the last audiobook only. */
    const val RECENT_ROOT = "recent"
    const val CONTINUE = "continue"
    const val DOWNLOADED = "downloaded"
    const val FAVOURITES = "favourites"
    const val BROWSE = "browse"
    const val WANT = "want"
    const val ALL = "all"
    private const val LIST_PREFIX = "list:"
    private const val BOOK_PREFIX = "book:"

    fun bookId(audiobookId: Int): String = "$BOOK_PREFIX$audiobookId"
    fun listId(listId: Int): String = "$LIST_PREFIX$listId"

    /** The audiobook a playable media id names (also the player's own "audiobook:<id>:<part>" ids), else null. */
    fun parseBook(mediaId: String?): Int? = when {
        mediaId == null -> null
        mediaId.startsWith(BOOK_PREFIX) -> mediaId.removePrefix(BOOK_PREFIX).toIntOrNull()?.takeIf { it > 0 }
        mediaId.startsWith("audiobook:") -> mediaId.split(':').getOrNull(1)?.toIntOrNull()?.takeIf { it > 0 }
        else -> null
    }

    fun parseList(mediaId: String?): Int? =
        mediaId?.takeIf { it.startsWith(LIST_PREFIX) }?.removePrefix(LIST_PREFIX)?.toIntOrNull()?.takeIf { it > 0 }

    /** How a car lays out a folder's children (the content style hints). */
    enum class Style { LIST, GRID }

    enum class Completion { NOT_PLAYED, PARTLY_PLAYED, FULLY_PLAYED }

    /** One audiobook as the car shows it. Progress is the newer of this device's and the server's. */
    data class Book(
        val audiobookId: Int,
        val title: String,
        val author: String? = null,
        val narrator: String? = null,
        val coverUrl: String? = null,
        val durationMs: Long = 0,
        val positionMs: Long = 0,
        val finished: Boolean = false,
        val downloaded: Boolean = false,
        val novelId: Int? = null,
        val series: String? = null,
    ) {
        val completion: Completion
            get() = when {
                finished -> Completion.FULLY_PLAYED
                positionMs > 0 -> Completion.PARTLY_PLAYED
                else -> Completion.NOT_PLAYED
            }

        /** 0..1 (finished is all of it). */
        val fraction: Double
            get() = when {
                finished -> 1.0
                durationMs <= 0 -> 0.0
                else -> (positionMs.toDouble() / durationMs).coerceIn(0.0, 1.0)
            }

        /** "Author", "Author · read by Narrator", or the narrator alone. */
        val subtitle: String?
            get() {
                val a = author?.takeIf { it.isNotBlank() }
                val n = narrator?.takeIf { it.isNotBlank() && !it.equals(a, ignoreCase = true) }
                return when {
                    a != null && n != null -> "$a · read by $n"
                    a != null -> a
                    n != null -> "Read by $n"
                    else -> null
                }
            }
    }

    /** A reading list and its audiobooks (in the list's order). */
    data class Shelf(val id: Int, val name: String, val books: List<Book>)

    /**
     * What the tree is built from. [favourites] / [wantToRead] are null when
     * the server has no such list (or lists are off): that folder is left out.
     */
    data class Catalog(
        val continueListening: List<Book> = emptyList(),
        val downloaded: List<Book> = emptyList(),
        val favourites: List<Book>? = null,
        val wantToRead: List<Book>? = null,
        val lists: List<Shelf> = emptyList(),
    )

    data class Node(
        val id: String,
        val title: String,
        val subtitle: String? = null,
        val artUrl: String? = null,
        val playable: Boolean = false,
        val browsable: Boolean = false,
        /** Playable items only. */
        val completion: Completion? = null,
        val fraction: Double? = null,
        /** Browsable items: how their children are laid out. */
        val browsableStyle: Style? = null,
        val playableStyle: Style? = null,
        /** A downloaded book, shown as such (and the car can tell what plays offline). */
        val downloaded: Boolean = false,
    )

    /** The root node itself (its hints apply to the root folders and anything playable directly under it). */
    fun rootNode(recent: Boolean = false): Node =
        Node(if (recent) RECENT_ROOT else ROOT, "Novarr", browsable = true, browsableStyle = Style.GRID, playableStyle = Style.LIST)

    /** The root folders: at most four (what Android Auto shows as tabs). */
    fun root(catalog: Catalog): List<Node> = buildList {
        add(folder(CONTINUE, "Continue listening"))
        add(folder(DOWNLOADED, "Downloaded"))
        when {
            catalog.favourites != null -> add(folder(FAVOURITES, "Favourites"))
            catalog.wantToRead != null -> add(folder(WANT, "Want to read"))
        }
        add(folder(BROWSE, "Browse", browsableStyle = Style.GRID))
    }

    /** Browse: Want to read (unless it is on the root already), All audiobooks, then each list holding one. */
    fun browse(catalog: Catalog): List<Node> = buildList {
        if (catalog.favourites != null && catalog.wantToRead != null) add(category(WANT, "Want to read", catalog.wantToRead))
        add(folder(ALL, "All audiobooks", grid = true))
        catalog.lists.filter { it.books.isNotEmpty() }.forEach { shelf ->
            add(category(listId(shelf.id), shelf.name, shelf.books))
        }
    }

    /**
     * The children of [parentId] from [catalog]; null for a parent this
     * can't answer alone (ALL is paged from the server, see [page]) or one
     * that doesn't exist.
     */
    fun children(parentId: String, catalog: Catalog): List<Node>? = when (parentId) {
        ROOT -> root(catalog)
        BROWSE -> browse(catalog)
        CONTINUE -> catalog.continueListening.map(::bookNode)
        DOWNLOADED -> catalog.downloaded.map(::bookNode)
        FAVOURITES -> catalog.favourites?.map(::bookNode)
        WANT -> catalog.wantToRead?.map(::bookNode)
        else -> parseList(parentId)?.let { id -> catalog.lists.firstOrNull { it.id == id }?.books?.map(::bookNode) }
    }

    fun bookNode(book: Book): Node = Node(
        id = bookId(book.audiobookId),
        title = book.title,
        subtitle = book.subtitle,
        artUrl = book.coverUrl?.takeIf { it.isNotBlank() },
        playable = true,
        completion = book.completion,
        fraction = book.fraction,
        downloaded = book.downloaded,
    )

    /** Media3's page of a whole list (page from 0). */
    fun <T> page(items: List<T>, page: Int, pageSize: Int): List<T> {
        if (pageSize <= 0 || page < 0) return items
        val from = page.toLong() * pageSize
        if (from >= items.size) return emptyList()
        return items.subList(from.toInt(), minOf(items.size, (from + pageSize).toInt()))
    }

    private fun folder(id: String, title: String, browsableStyle: Style? = null, grid: Boolean = false) =
        Node(id, title, browsable = true, browsableStyle = browsableStyle, playableStyle = if (grid) Style.GRID else Style.LIST)

    /** A Browse category: its count as the subtitle and its first cover as the tile. */
    private fun category(id: String, title: String, books: List<Book>) = Node(
        id,
        title,
        subtitle = when (books.size) {
            0 -> "No audiobooks"
            1 -> "1 audiobook"
            else -> "${books.size} audiobooks"
        },
        artUrl = books.firstNotNullOfOrNull { it.coverUrl?.takeIf(String::isNotBlank) },
        browsable = true,
        playableStyle = Style.LIST,
    )

    // Building books

    /** A Book from the server's audiobook summary, with progress already resolved. */
    fun book(summary: AudiobookSummary, positionMs: Long, finished: Boolean, downloaded: Boolean): Book = Book(
        audiobookId = summary.id,
        title = summary.title,
        author = summary.authorOrNull,
        narrator = summary.narratorOrNull,
        coverUrl = summary.coverUrl,
        durationMs = summary.durationMs,
        positionMs = positionMs,
        finished = finished,
        downloaded = downloaded,
        novelId = summary.novelId,
        series = summary.series,
    )

    /**
     * The audiobooks of a list of series, in the list's order: each series
     * with an audiobook ([NovelSummary.audiobook]) becomes a Book, preferring
     * what [known] (by audiobook id) says about it (narrator, progress), else
     * the series' own title, author and cover. Series without one are skipped.
     */
    fun booksFor(novelIds: List<Int>, novels: Map<Int, NovelSummary>, known: Map<Int, Book>): List<Book> =
        novelIds.mapNotNull { novelId ->
            val novel = novels[novelId] ?: return@mapNotNull null
            val ref = novel.audiobook ?: return@mapNotNull null
            known[ref.id] ?: Book(
                audiobookId = ref.id,
                title = novel.name,
                author = novel.authorOrNull,
                coverUrl = novel.coverUrl,
                durationMs = ref.durationMs,
                novelId = novel.id,
            )
        }.distinctBy { it.audiobookId }

    /** The reader's own lists (not the built-ins) with their audiobooks, by name. */
    fun shelves(lists: List<ReadingList>, novels: Map<Int, NovelSummary>, known: Map<Int, Book>): List<Shelf> =
        lists.filter { it.kind == null }
            .sortedWith(compareBy(String.CASE_INSENSITIVE_ORDER) { it.name })
            .map { Shelf(it.id, it.name, booksFor(it.novelIds, novels, known)) }
}

/**
 * "Hey Google, play <title> on Novarr" and the car's search box: ranks the
 * known audiobooks against what was said. Pure. Accents, case and
 * punctuation are ignored; "<title> by <author>" matches both parts; words
 * like "audiobook" or "the book" around the title are dropped.
 */
object AutoSearch {
    /** Words a voice request wraps the title in, never part of the match. */
    private val FILLER = setOf("audiobook", "audiobooks", "novarr", "please")

    /** The ranked matches (best first; ties keep [books]' order, so put recent ones first). Nothing for a blank query. */
    fun match(query: String, books: List<AutoBrowse.Book>, limit: Int = 25): List<AutoBrowse.Book> {
        val q = parse(query) ?: return emptyList()
        return books.asSequence()
            .distinctBy { it.audiobookId }
            .mapIndexed { index, book -> Triple(book, score(q, book), index) }
            .filter { it.second > 0 }
            .sortedWith(compareByDescending<Triple<AutoBrowse.Book, Int, Int>> { it.second }.thenBy { it.third })
            .map { it.first }
            .take(limit)
            .toList()
    }

    /** What was asked: the whole phrase, and an author part when it said "… by …". */
    data class Query(val text: String, val title: String, val author: String?)

    fun parse(query: String): Query? {
        val text = stripFiller(normalize(query))
        if (text.isEmpty()) return null
        val parts = text.split(" by ", limit = 2)
        val title = parts[0].trim()
        val author = parts.getOrNull(1)?.trim()?.takeIf { it.isNotEmpty() }
        return Query(text, title.ifEmpty { text }, author)
    }

    /** 0: no match. Higher is better. */
    fun score(q: Query, book: AutoBrowse.Book): Int {
        val title = normalize(book.title)
        val people = normalize(listOfNotNull(book.author, book.narrator).joinToString(" "))
        val series = normalize(book.series.orEmpty())
        // "… by <author>": the author part must match the author or narrator.
        if (q.author != null && !tokensMatch(tokens(q.author), tokens(people))) {
            // Maybe "by" is part of the title ("Stand By Me"): try the whole phrase as a title.
            return scoreTitle(q.text, title, people, series)
        }
        return scoreTitle(q.title, title, people, series)
    }

    private fun scoreTitle(asked: String, title: String, people: String, series: String): Int {
        val bare = stripArticle(title)
        val askedBare = stripArticle(asked)
        return when {
            title == asked || bare == askedBare -> 100
            title.startsWith(asked) || bare.startsWith(askedBare) -> 85
            " $title ".contains(" $asked ") -> 70
            tokensMatch(tokens(asked), tokens(title)) -> 60
            series.isNotEmpty() && tokensMatch(tokens(asked), tokens(series)) -> 45
            tokensMatch(tokens(asked), tokens("$title $series $people")) -> 35
            people.isNotEmpty() && tokensMatch(tokens(asked), tokens(people)) -> 30
            else -> 0
        }
    }

    /** Every asked word is (the start of, for 3+ letters) a word of the field. */
    private fun tokensMatch(asked: List<String>, field: List<String>): Boolean =
        asked.isNotEmpty() && asked.all { word -> field.any { it == word || (word.length >= 3 && it.startsWith(word)) } }

    private fun tokens(text: String): List<String> = text.split(' ').filter { it.isNotEmpty() }

    private fun stripArticle(text: String): String = text.removePrefix("the ").removePrefix("a ").removePrefix("an ")

    private fun stripFiller(text: String): String {
        var words = tokens(text.replace("audio book", "audiobook"))
        // Only at the edges ("play the audiobook X", "X audiobook"): inside a title they stay.
        while (words.size > 1 && words.first() in FILLER) words = words.drop(1)
        while (words.size > 1 && words.last() in FILLER) words = words.dropLast(1)
        if (words.size > 1 && words.first() == "the" && words.getOrNull(1) in FILLER) words = words.drop(2)
        return words.joinToString(" ")
    }

    /** Lowercase, accents off, anything not a letter or digit a space, single spaces. "&" reads as "and". */
    fun normalize(text: String): String {
        val decomposed = Normalizer.normalize(text.replace("&", " and "), Normalizer.Form.NFD)
            .replace(Regex("\\p{M}+"), "")
            .lowercase(Locale.ROOT)
            .replace("'", "")
            .replace("’", "")
        return decomposed.replace(Regex("[^\\p{L}\\p{N}]+"), " ").trim()
    }
}
