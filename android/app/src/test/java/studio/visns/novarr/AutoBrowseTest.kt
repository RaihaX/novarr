package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.AudiobookRef
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.domain.AutoBrowse
import studio.visns.novarr.domain.AutoBrowse.Book
import studio.visns.novarr.domain.AutoSearch

/** The Android Auto browse tree and the voice / search matcher. */
class AutoBrowseTest {
    private val dune = Book(1, "Dune", author = "Frank Herbert", narrator = "Scott Brick", coverUrl = "https://n.example/c/1.jpg", durationMs = 1000, positionMs = 250)
    private val hobbit = Book(2, "The Hobbit", author = "J.R.R. Tolkien", narrator = "Andy Serkis", durationMs = 1000, finished = true, positionMs = 1000)
    private val mistborn = Book(3, "Mistborn: The Final Empire", author = "Brandon Sanderson", series = "Mistborn", downloaded = true)
    private val stand = Book(4, "Stand By Me", author = "Someone Else")

    @Test
    fun rootHasAtMostFourFoldersAndFavouritesWhenOffered() {
        val catalog = AutoBrowse.Catalog(favourites = listOf(dune), wantToRead = listOf(hobbit))
        val root = AutoBrowse.root(catalog)
        assertEquals(listOf("continue", "downloaded", "favourites", "browse"), root.map { it.id })
        assertTrue(root.all { it.browsable && !it.playable })
        // Browse then holds Want to read (not on the root) and All audiobooks.
        assertEquals(listOf("want", "all"), AutoBrowse.browse(catalog).map { it.id })
    }

    @Test
    fun withoutFavouritesWantToReadTakesItsPlace() {
        val catalog = AutoBrowse.Catalog(wantToRead = listOf(hobbit))
        assertEquals(listOf("continue", "downloaded", "want", "browse"), AutoBrowse.root(catalog).map { it.id })
        assertEquals(listOf("all"), AutoBrowse.browse(catalog).map { it.id })
        // Neither list (an older server, lists off): no such folder at all.
        val bare = AutoBrowse.Catalog()
        assertEquals(listOf("continue", "downloaded", "browse"), AutoBrowse.root(bare).map { it.id })
        assertNull(AutoBrowse.children(AutoBrowse.FAVOURITES, bare))
    }

    @Test
    fun listsWithAudiobooksShowUnderBrowse() {
        val catalog = AutoBrowse.Catalog(
            lists = listOf(AutoBrowse.Shelf(7, "Road trips", listOf(dune, mistborn)), AutoBrowse.Shelf(8, "Empty", emptyList())),
        )
        val browse = AutoBrowse.browse(catalog)
        assertEquals(listOf("all", "list:7"), browse.map { it.id })
        assertEquals("2 audiobooks", browse[1].subtitle)
        assertEquals(dune.coverUrl, browse[1].artUrl)
        assertEquals(listOf("book:1", "book:3"), AutoBrowse.children("list:7", catalog)!!.map { it.id })
        assertNull(AutoBrowse.children("list:99", catalog))
        assertNull(AutoBrowse.children("nonsense", catalog))
    }

    @Test
    fun bookNodesCarryProgressAndNames() {
        val node = AutoBrowse.bookNode(dune)
        assertTrue(node.playable && !node.browsable)
        assertEquals(AutoBrowse.Completion.PARTLY_PLAYED, node.completion)
        assertEquals(0.25, node.fraction!!, 1e-9)
        assertEquals("Frank Herbert · read by Scott Brick", node.subtitle)
        assertEquals(AutoBrowse.Completion.FULLY_PLAYED, AutoBrowse.bookNode(hobbit).completion)
        assertEquals(AutoBrowse.Completion.NOT_PLAYED, AutoBrowse.bookNode(stand).completion)
        assertTrue(AutoBrowse.bookNode(mistborn).downloaded)
        assertEquals("Read by Kate", Book(9, "X", narrator = "Kate").subtitle)
    }

    @Test
    fun mediaIdsRoundTrip() {
        assertEquals(12, AutoBrowse.parseBook(AutoBrowse.bookId(12)))
        // The player's own part ids name the book too.
        assertEquals(12, AutoBrowse.parseBook("audiobook:12:3"))
        assertNull(AutoBrowse.parseBook("book:x"))
        assertNull(AutoBrowse.parseBook("continue"))
        assertEquals(5, AutoBrowse.parseList(AutoBrowse.listId(5)))
        assertNull(AutoBrowse.parseList("book:5"))
    }

    @Test
    fun paging() {
        val items = (1..25).toList()
        assertEquals((1..10).toList(), AutoBrowse.page(items, 0, 10))
        assertEquals((21..25).toList(), AutoBrowse.page(items, 2, 10))
        assertEquals(emptyList<Int>(), AutoBrowse.page(items, 3, 10))
        assertEquals(items, AutoBrowse.page(items, 0, 0))
    }

    @Test
    fun booksForListKeepOrderAndSkipSeriesWithoutAudiobooks() {
        val novels = mapOf(
            10 to NovelSummary(10, "Dune (book)", author = "Frank Herbert", coverUrl = "c10", audiobook = AudiobookRef(1, 1000)),
            11 to NovelSummary(11, "A web novel"),
            12 to NovelSummary(12, "Sabriel", author = "Garth Nix", coverUrl = "c12", audiobook = AudiobookRef(5, 900)),
        )
        val books = AutoBrowse.booksFor(listOf(12, 11, 10, 99), novels, known = mapOf(1 to dune))
        assertEquals(listOf(5, 1), books.map { it.audiobookId })
        assertEquals("Sabriel", books[0].title)
        assertEquals(900, books[0].durationMs)
        // A known audiobook keeps what is known (narrator, progress).
        assertEquals(dune, books[1])

        val lists = listOf(
            ReadingList(1, "Want to read", novelIds = listOf(10), kind = ReadingList.KIND_WANT_TO_READ),
            ReadingList(3, "zed", novelIds = listOf(12)),
            ReadingList(2, "Alpha", novelIds = listOf(11)),
        )
        val shelves = AutoBrowse.shelves(lists, novels, mapOf(1 to dune))
        assertEquals(listOf("Alpha", "zed"), shelves.map { it.name })
        assertEquals(emptyList<Book>(), shelves[0].books)
    }

    // Search

    private val all = listOf(dune, hobbit, mistborn, stand)

    @Test
    fun exactAndPartialTitles() {
        assertEquals(dune, AutoSearch.match("Dune", all).first())
        assertEquals(hobbit, AutoSearch.match("hobbit", all).first())
        assertEquals(hobbit, AutoSearch.match("the hobbit", all).first())
        assertEquals(mistborn, AutoSearch.match("mistborn", all).first())
        assertEquals(mistborn, AutoSearch.match("final empire", all).first())
        assertEquals(emptyList<Book>(), AutoSearch.match("zzz", all))
        assertEquals(emptyList<Book>(), AutoSearch.match("   ", all))
    }

    @Test
    fun voiceWrappingAndAccents() {
        assertEquals(dune, AutoSearch.match("the audiobook Dune", all).first())
        assertEquals(dune, AutoSearch.match("Dune audio book", all).first())
        assertEquals(hobbit, AutoSearch.match("The Hobbit!", all).first())
        val accented = Book(5, "Les Misérables", author = "Victor Hugo")
        assertEquals(accented, AutoSearch.match("les miserables", all + accented).first())
    }

    @Test
    fun titleByAuthor() {
        assertEquals(mistborn, AutoSearch.match("Mistborn by Brandon Sanderson", all).first())
        // An author alone finds their books; a narrator too.
        assertEquals(hobbit, AutoSearch.match("Tolkien", all).first())
        assertEquals(dune, AutoSearch.match("Scott Brick", all).first())
        // "by" inside a title still matches the title.
        assertEquals(stand, AutoSearch.match("Stand by me", all).first())
    }

    @Test
    fun tiesKeepTheGivenOrderAndDuplicatesGo() {
        val other = Book(6, "Dune Messiah", author = "Frank Herbert")
        // Both are by Herbert: the first given (e.g. recently listened) wins.
        assertEquals(listOf(6, 1), AutoSearch.match("herbert", listOf(other, dune, other)).map { it.audiobookId })
        // An exact title beats a longer one starting the same.
        assertEquals(listOf(1, 6), AutoSearch.match("dune", listOf(other, dune)).map { it.audiobookId })
    }
}
