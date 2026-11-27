package studio.visns.novarr

import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.MangaPages
import studio.visns.novarr.api.Medium
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.NovelsEnvelope
import studio.visns.novarr.api.ReaderRequest
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.domain.LibraryFilter
import studio.visns.novarr.domain.LibraryFilter.Shelf
import studio.visns.novarr.domain.MangaModeOverride
import studio.visns.novarr.domain.Origin
import studio.visns.novarr.domain.PageReaderRedirect
import studio.visns.novarr.domain.ReadingMode
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.domain.Requests.Medium as RM

/** 1.19: comics (medium "comic"), a page medium like manga. */
class ComicsTest {
    // The medium

    @Test
    fun comicIsAPageMediumLikeManga() {
        val comic = NovelSummary(1, "Saga", medium = "comic")
        assertTrue(comic.isComic)
        assertTrue(comic.isPaged)
        assertFalse(comic.isManga)
        assertFalse(comic.isBook)
        assertTrue(NovelSummary(2, medium = "manga").isPaged)
        assertTrue(NovelSummary(3, medium = "Comic").isPaged)
        assertFalse(NovelSummary(4, medium = "novel").isPaged)
        assertFalse(NovelSummary(5, medium = "book").isPaged)
        assertFalse(NovelSummary(6, medium = "").isPaged)
        assertTrue(Medium.isPaged("comic"))
        assertFalse(Medium.isPaged(null))
    }

    @Test
    fun mediumLabels() {
        assertEquals("Comic", Medium.label("comic"))
        assertEquals("Manga", Medium.label("manga"))
        assertEquals("Book", Medium.label("book"))
        assertEquals("Web novel", Medium.label("novel"))
        assertEquals("Web novel", Medium.label(null))
        assertEquals("Comics", Shelf.COMICS.title)
        assertEquals("comics", Shelf.COMICS.noun)
    }

    @Test
    fun comicsParseFromTheLibraryListing() {
        val novels = NovarrJson.decodeFromString(
            NovelsEnvelope.serializer(),
            """{"novels":[{"id":7,"name":"Saga","medium":"comic","origin_country":"EN","chapters_total":54},{"id":8,"name":"Berserk","medium":"manga"}]}""",
        ).novels
        assertEquals("comic", novels[0].medium)
        assertTrue(novels[0].isComic)
        assertEquals(54, novels[0].chaptersTotal)
        // A comic's origin reads like a novel's, not as manga/manhwa.
        assertEquals("English original", Origin.label(novels[0]))
    }

    @Test
    fun metaSaysWhetherTheServerKnowsComics() {
        assertTrue(NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"comics":true}""").comics)
        assertFalse(NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1}""").comics)
    }

    // The reader's direction

    @Test
    fun comicsReadLeftToRightUnlessTheServerSaysOtherwise() {
        fun pages(mode: String?) = NovarrJson.decodeFromString(
            MangaPages.serializer(),
            """{"chapter":{"id":1,"novel_id":7,"label":"Issue 1","number":1,"page_count":1,"read":false,"progress":null},
               "pages":[{"page":1,"url":"u1","width":800,"height":1200}],"prev":null,"next":null""" +
                (if (mode == null) "" else ""","reading_mode":"$mode"""") + "}",
        )
        // No reading_mode (or "ltr"): left to right.
        assertEquals(ReadingMode.LEFT_TO_RIGHT, ReadingMode.from(pages(null).readingMode))
        assertEquals(ReadingMode.LEFT_TO_RIGHT, ReadingMode.from(pages("ltr").readingMode))
        // A comic file that says it is manga, or the reader's choice on the web: right to left.
        assertEquals(ReadingMode.RIGHT_TO_LEFT, ReadingMode.from(pages("rtl").readingMode))
        // This device's Pages / Scroll choice applies to comics as to manga.
        assertEquals(ReadingMode.VERTICAL, MangaModeOverride.SCROLL.resolve(ReadingMode.LEFT_TO_RIGHT))
        assertEquals(ReadingMode.RIGHT_TO_LEFT, MangaModeOverride.PAGES.resolve(ReadingMode.RIGHT_TO_LEFT))
    }

    @Test
    fun theTextEndpointsRefusalOpensThePageReader() {
        fun refusal(vararg pairs: Pair<String, Any>) = ApiError.Http(
            422, "This chapter is page images.",
            body = JsonObject(pairs.associate { (k, v) -> k to if (v is Boolean) JsonPrimitive(v) else JsonPrimitive(v.toString()) }),
        )
        assertTrue(PageReaderRedirect.applies(refusal("medium" to "comic", "pages" to true)))
        assertTrue(PageReaderRedirect.applies(refusal("medium" to "manga", "pages" to true)))
        // An older server's manga refusal had no `pages`.
        assertTrue(PageReaderRedirect.applies(refusal("medium" to "manga")))
        assertFalse(PageReaderRedirect.applies(refusal("message" to "nope")))
        assertFalse(PageReaderRedirect.applies(ApiError.Http(404, null, body = JsonObject(mapOf("pages" to JsonPrimitive(true))))))
        assertFalse(PageReaderRedirect.applies(null))
    }

    // Library

    private val library = listOf(
        NovelSummary(1, "A Web Novel"),
        NovelSummary(2, "A Manga", medium = "manga"),
        NovelSummary(3, "A Comic", medium = "comic"),
        NovelSummary(4, "A Book", medium = "book"),
    )

    @Test
    fun comicsHaveTheirOwnShelf() {
        assertEquals(listOf(1), LibraryFilter.shelf(library, Shelf.NOVELS).map { it.id })
        assertEquals(listOf(2), LibraryFilter.shelf(library, Shelf.MANGA).map { it.id })
        assertEquals(listOf(3), LibraryFilter.shelf(library, Shelf.COMICS).map { it.id })
        assertEquals(listOf(4), LibraryFilter.shelf(library, Shelf.BOOKS).map { it.id })
    }

    @Test
    fun comicsShelfWhenTheServerKnowsComicsOrTheLibraryHasOne() {
        val noComics = library.filterNot { it.isComic }
        // Comics comes after Manga.
        assertEquals(listOf(Shelf.NOVELS, Shelf.MANGA, Shelf.COMICS, Shelf.BOOKS), LibraryFilter.shelves(noComics, comics = true))
        assertEquals(listOf(Shelf.NOVELS, Shelf.MANGA, Shelf.BOOKS), LibraryFilter.shelves(noComics, comics = false))
        assertEquals(listOf(Shelf.NOVELS, Shelf.MANGA, Shelf.COMICS), LibraryFilter.shelves(emptyList(), comics = true))
        // Never hides comics the library has.
        assertEquals(listOf(Shelf.NOVELS, Shelf.MANGA, Shelf.COMICS, Shelf.BOOKS), LibraryFilter.shelves(library, comics = false))
        // A remembered Comics tab on a server without comics is Web Novels.
        assertEquals(Shelf.NOVELS, LibraryFilter.effectiveShelf(Shelf.COMICS, noComics, comics = false))
        assertEquals(Shelf.COMICS, LibraryFilter.effectiveShelf(Shelf.COMICS, noComics, comics = true))
    }

    // Requests

    @Test
    fun requestMediumsOfferComicsOnlyWhenTheServerKnowsThem() {
        assertEquals(listOf(RM.BOOK, RM.NOVEL, RM.MANGA, RM.COMIC), RM.offered(comics = true))
        assertEquals(listOf(RM.BOOK, RM.NOVEL, RM.MANGA), RM.offered(comics = false))
        assertEquals("Comics", RM.COMIC.title)
        assertEquals("Comic", RM.COMIC.singular)
        assertEquals("comic", RM.COMIC.wire)
        assertEquals(RM.COMIC, RM.from("comic"))
        assertEquals("Comic", Requests.mediumLabel("comic"))
    }

    @Test
    fun comicSearchTakesLinksAndIsbnsLikeBooksButHasNoDiscoverShelf() {
        assertTrue(RM.COMIC.takesLinks)
        assertTrue(RM.BOOK.takesLinks)
        assertFalse(RM.MANGA.takesLinks)
        assertFalse(RM.NOVEL.takesLinks)
        assertFalse(RM.COMIC.hasDiscover)
        assertTrue(RM.BOOK.hasDiscover && RM.NOVEL.hasDiscover && RM.MANGA.hasDiscover)

        val link = "https://www.goodreads.com/book/show/123-saga"
        assertTrue(Requests.queryHint(RM.COMIC, link)!!.startsWith("Novarr reads the comic"))
        assertEquals("Searching by ISBN.", Requests.queryHint(RM.COMIC, "9781607066019"))
        assertEquals(Requests.placeholder(RM.BOOK), Requests.placeholder(RM.COMIC))
    }

    @Test
    fun comicRequestsDecode() {
        val request = NovarrJson.decodeFromString(ReaderRequest.serializer(), """{"id":5,"title":"Saga","medium":"comic","status":"pending"}""")
        assertEquals("comic", request.medium)
        assertFalse(request.isManga)
        assertEquals("Comic", Requests.mediumLabel(request.medium))
    }
}
