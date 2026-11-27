package studio.visns.novarr

import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AudiobookDetail
import studio.visns.novarr.api.AudiobookInbox
import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.BrowsePage
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.ReaderRequest
import studio.visns.novarr.api.SendEnvelope
import studio.visns.novarr.api.SendState
import studio.visns.novarr.domain.LibraryFilter
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.domain.Requests.Medium
import studio.visns.novarr.domain.SendToKindle
import studio.visns.novarr.domain.SendToKindle.UiState
import studio.visns.novarr.store.OfflineStore

/** 1.14: books in Requests, the Discover shelf, Send to Kindle, audiobook series. */
class RequestsBooksTest {
    // Medium

    @Test
    fun booksComeFirstAndAreTheDefault() {
        assertEquals(listOf(Medium.BOOK, Medium.NOVEL, Medium.MANGA), Medium.entries.toList())
        assertEquals(Medium.BOOK, Medium.DEFAULT)
        assertEquals(Medium.BOOK, Requests.SearchMemory().medium)
        assertEquals(listOf("Books", "Web Novels", "Manga"), Medium.entries.map { it.title })
    }

    @Test
    fun mediumMapping() {
        assertEquals(Medium.BOOK, Medium.from("book"))
        assertEquals(Medium.NOVEL, Medium.from("novel"))
        assertEquals(Medium.MANGA, Medium.from(" Manga "))
        // Unknown or missing: the safe default, or the caller's fallback.
        assertEquals(Medium.BOOK, Medium.from("audiobook"))
        assertEquals(Medium.BOOK, Medium.from(null))
        assertEquals(Medium.MANGA, Medium.from("comic", Medium.MANGA))
        assertNull(Medium.fromOrNull("comic"))
    }

    @Test
    fun bookRequestsAreNoLongerLabelledNovel() {
        assertEquals("Book", Requests.mediumLabel("book"))
        assertEquals("Web Novel", Requests.mediumLabel("novel"))
        assertEquals("Manga", Requests.mediumLabel("manga"))
        // A medium from a newer server is shown by its own name, not as another.
        assertEquals("Comic", Requests.mediumLabel("comic"))
        assertEquals("", Requests.mediumLabel(null))
    }

    @Test
    fun webNovelsShelfLabel() {
        assertEquals("Web Novels", LibraryFilter.Shelf.NOVELS.title)
        assertEquals("web novels", LibraryFilter.Shelf.NOVELS.noun)
    }

    // The search field

    @Test
    fun urlDetectionFollowsTheServer() {
        assertTrue(Requests.looksLikeUrl("https://www.goodreads.com/book/show/1-scion"))
        assertTrue(Requests.looksLikeUrl("goodreads.com/book/show/1"))
        assertTrue(Requests.looksLikeUrl("amazon.co.uk/dp/B000"))
        assertTrue(Requests.looksLikeUrl("www.example.com/x"))
        assertTrue(Requests.looksLikeUrl("hardcover.app/books/scion"))
        assertTrue(Requests.looksLikeUrl("books.google.com/books?id=1"))
        // Titles stay titles.
        assertFalse(Requests.looksLikeUrl("Dr.Stone/Reboot"))
        assertFalse(Requests.looksLikeUrl("The Way of Kings"))
        assertFalse(Requests.looksLikeUrl("https://x.com/a b"))
        assertFalse(Requests.looksLikeUrl(""))
        assertFalse(Requests.looksLikeUrl("example.com/book"))
    }

    @Test
    fun isbnDetection() {
        assertTrue(Requests.looksLikeIsbn("9780765326355"))
        assertTrue(Requests.looksLikeIsbn("978-0-7653-2635-5"))
        assertTrue(Requests.looksLikeIsbn("ISBN: 0765326353"))
        assertTrue(Requests.looksLikeIsbn("080442957X"))
        assertFalse(Requests.looksLikeIsbn("1234567890123"))
        assertFalse(Requests.looksLikeIsbn("12345"))
        assertFalse(Requests.looksLikeIsbn("Mistborn 1"))
    }

    @Test
    fun queryLimitsAndHints() {
        val link = "https://www.goodreads.com/book/show/" + "1".repeat(300)
        assertEquals(Requests.LINK_MAX, Requests.queryLimit(link))
        assertEquals(Requests.QUERY_MAX, Requests.queryLimit("a title"))
        assertEquals(link, Requests.searchText("  $link "))
        assertEquals(Requests.QUERY_MAX, Requests.searchText("x".repeat(150)).length)
        assertEquals("a b", Requests.searchText(" a   b "))

        assertTrue(Requests.queryHint(Medium.BOOK, link)!!.startsWith("Novarr reads the book"))
        assertTrue(Requests.queryHint(Medium.MANGA, link)!!.startsWith("Links work for books only"))
        assertEquals("Searching by ISBN.", Requests.queryHint(Medium.BOOK, "9780765326355"))
        assertNull(Requests.queryHint(Medium.NOVEL, "9780765326355"))
        assertNull(Requests.queryHint(Medium.BOOK, "Mistborn"))
        assertEquals("Title, author, ISBN — or paste a Goodreads / Amazon link", Requests.placeholder(Medium.BOOK))
    }

    @Test
    fun seriesLabels() {
        assertEquals("Scion #1", Requests.seriesLabel("Scion", "1"))
        assertEquals("Scion #1", Requests.seriesLabel("Scion", "1.0"))
        assertEquals("Scion #1.5", Requests.seriesLabel("Scion", "1.5"))
        assertEquals("Scion", Requests.seriesLabel("Scion", null))
        assertEquals("Scion #II", Requests.seriesLabel(" Scion ", "II"))
        assertNull(Requests.seriesLabel(" ", "1"))
        assertNull(Requests.seriesLabel(null, "1"))
    }

    // Requests payloads

    @Test
    fun linkResultsCarryTheirSeriesLeniently() {
        val page = NovarrJson.decodeFromString(
            BrowsePage.serializer(),
            """{"medium":"book","lists":[{"id":"trending","name":"Trending today"},{"bad":1},{"id":"classics"}],
               "list":"trending","source_name":"Open Library","page":1,"has_more":true,"surprise":{"x":1},
               "results":[{"key":"k1","title":"Scion","series":"Scion","series_index":1,"in_library":false},
                          {"key":"k2","title":"Other","series_index":"2.5"},{"title":"no key"}]}""",
        )
        assertEquals(listOf("trending", "classics"), page.lists.map { it.id })
        assertEquals("Open Library", page.sourceName)
        assertEquals(2, page.results.size)
        assertEquals("Scion #1", Requests.seriesLabel(page.results[0].series, page.results[0].seriesIndex))
        assertNull(Requests.seriesLabel(page.results[1].series, page.results[1].seriesIndex))
    }

    @Test
    fun emptyShelfDecodes() {
        val page = NovarrJson.decodeFromString(
            BrowsePage.serializer(),
            """{"medium":"novel","lists":[],"list":null,"source_name":null,"page":1,"has_more":false,"results":[]}""",
        )
        assertTrue(page.lists.isEmpty())
        assertNull(page.list)
    }

    @Test
    fun wantedBookRequests() {
        fun request(json: String) = NovarrJson.decodeFromString(ReaderRequest.serializer(), json)
        assertTrue(Requests.showsWanted(request("""{"id":1,"medium":"book","status":"pending","wanted":true}""")))
        assertTrue(Requests.showsWanted(request("""{"id":1,"medium":"book","status":"approved","wanted":"1"}""")))
        assertFalse(Requests.showsWanted(request("""{"id":1,"medium":"book","status":"available","wanted":true}""")))
        assertFalse(Requests.showsWanted(request("""{"id":1,"medium":"book","status":"pending","wanted":false}""")))
        // An older server sends no `wanted`; a bad one sends an object.
        assertFalse(Requests.showsWanted(request("""{"id":1,"medium":"book","status":"pending"}""")))
        assertFalse(Requests.showsWanted(request("""{"id":1,"status":"pending","wanted":{"x":1}}""")))
    }

    @Test
    fun browseCacheKeysArePerMediumAndList() {
        assertEquals("requests/browse/book/_first.json", OfflineStore.Key.requestBrowse("book", null).path)
        assertEquals("requests/browse/book/hardcover_trending.json", OfflineStore.Key.requestBrowse("book", "hardcover_trending").path)
        assertEquals("requests/browse/manga/etcpasswd.json", OfflineStore.Key.requestBrowse("manga", "../etc/passwd").path)
    }

    // Send to my Kindle / email

    @Test
    fun sendPhases() {
        assertEquals(SendToKindle.Phase.NONE, SendToKindle.phase(SendState()))
        assertEquals(SendToKindle.Phase.NONE, SendToKindle.phase(null))
        assertEquals(SendToKindle.Phase.RUNNING, SendToKindle.phase(SendState("queued")))
        assertEquals(SendToKindle.Phase.RUNNING, SendToKindle.phase(SendState("sending")))
        assertEquals(SendToKindle.Phase.RUNNING, SendToKindle.phase(SendState("retrying")))
        assertEquals(SendToKindle.Phase.SENT, SendToKindle.phase(SendState("SENT")))
        assertEquals(SendToKindle.Phase.FAILED, SendToKindle.phase(SendState("failed")))
    }

    @Test
    fun sendPolling() {
        // Running: ask again until the give-up time.
        assertNull(SendToKindle.next(SendState("queued"), 0))
        assertNull(SendToKindle.next(SendState("sending"), SendToKindle.GIVE_UP_MS - 1))
        assertTrue(SendToKindle.next(SendState("sending"), SendToKindle.GIVE_UP_MS) is UiState.StillSending)
        // The server's message as is (it carries the approved-sender reminder).
        assertEquals(UiState.Sent("Sent to me@kindle.com. Approve the sender."), SendToKindle.next(SendState("sent", "Sent to me@kindle.com. Approve the sender."), 5))
        assertEquals(UiState.Sent(SendToKindle.SENT_FALLBACK), SendToKindle.next(SendState("sent"), 5))
        assertEquals(UiState.Failed("SMTP said no"), SendToKindle.next(SendState("failed", "SMTP said no"), 5))
        assertEquals(UiState.Failed(SendToKindle.FAILED_FALLBACK), SendToKindle.next(SendState("failed", " "), 5))
        // Forgotten by the server mid-way.
        assertTrue(SendToKindle.next(SendState(), 5) is UiState.StillSending)
    }

    @Test
    fun sendRefusals() {
        val noAddress = ApiError.Http(
            422, "Add your Kindle or email address on your Account page first.", "no_address",
            body = JsonObject(mapOf("code" to JsonPrimitive("no_address"), "account_url" to JsonPrimitive("https://novarr.example/account#send-to"))),
        )
        assertEquals(UiState.NoAddress(SendToKindle.NO_ADDRESS_MESSAGE, "https://novarr.example/account#send-to"), SendToKindle.refusal(noAddress, "https://host"))
        val relative = noAddress.copy(body = JsonObject(mapOf("account_url" to JsonPrimitive("/account#send-to"))))
        assertEquals("https://host/account#send-to", (SendToKindle.refusal(relative, "https://host/") as UiState.NoAddress).accountUrl)
        val missing = noAddress.copy(body = null)
        assertNull((SendToKindle.refusal(missing, "https://host") as UiState.NoAddress).accountUrl)

        val tooMany = ApiError.Http(429, "That is a lot of sending: try again in a while.", "too_many")
        assertEquals(UiState.Failed("That is a lot of sending: try again in a while."), SendToKindle.refusal(tooMany, null))
        val manga = ApiError.Http(422, "A manga has no ePub to send.", "manga")
        assertEquals(UiState.Failed("A manga has no ePub to send."), SendToKindle.refusal(manga, null))
    }

    @Test
    fun resolveOnlyHttpLinks() {
        assertNull(SendToKindle.resolve("https://host", "javascript:alert(1)"))
        assertNull(SendToKindle.resolve(null, "/account"))
        assertNull(SendToKindle.resolve("https://host", null))
    }

    @Test
    fun sendEnvelopeDecodes() {
        val queued = NovarrJson.decodeFromString(SendEnvelope.serializer(), """{"send":{"status":"queued","message":null,"to":"me@kindle.com","extra":1}}""")
        assertEquals(SendState("queued", null, "me@kindle.com"), queued.send)
        val none = NovarrJson.decodeFromString(SendEnvelope.serializer(), """{"send":{"status":null,"message":null,"to":null}}""")
        assertEquals(SendToKindle.Phase.NONE, SendToKindle.phase(none.send))
        assertNull(NovarrJson.decodeFromString(SendEnvelope.serializer(), """{"send":"weird"}""").send)
    }

    // Audiobooks

    @Test
    fun audiobookDetailSeries() {
        val detail = NovarrJson.decodeFromString(
            AudiobookDetail.serializer(),
            """{"id":3,"title":"Skyward","narrator":"Suzy Jackson","duration_ms":1000,"parts":[],"chapters":[],"bookmarks":[],
               "series":"Skyward","series_index":1.0,"asin":"B07H5B7B8X","year":2018}""",
        )
        assertEquals("Skyward #1", detail.summary.seriesLabel)
        assertEquals("B07H5B7B8X", detail.summary.asin)
        val plain = NovarrJson.decodeFromString(AudiobookSummary.serializer(), """{"id":4,"title":"Alone","series":null,"series_index":null}""")
        assertNull(plain.seriesLabel)
        val odd = NovarrJson.decodeFromString(AudiobookSummary.serializer(), """{"id":5,"series":{"name":"x"},"series_index":[1]}""")
        assertNull(odd.seriesLabel)
    }

    @Test
    fun unknownInboxStatesDecode() {
        val inbox = NovarrJson.decodeFromString(
            AudiobookInbox.serializer(),
            """{"inbox":"/data/inbox","settle_seconds":120,"last_scan":{"at":1},"problem":null,"unmatched_count":2,
               "pending":[{"name":"Dune","kind":"folder","audio_files":3,"size_bytes":12,"state":"downloading","stable_seconds":0,"note":"Grabbed"},
                          {"name":"Torrent","state":"seeding","size_bytes":"34"},
                          {"name":"Future","state":{"new":"shape"}}],
               "failed":[{"name":"x.mp3","kind":"file","reason":"ffprobe failed","failed_at":"2026-11-01T00:00:00Z"}]}""",
        )
        assertEquals(listOf("downloading", "seeding", null), inbox.pending.map { it.state })
        assertEquals(12L, inbox.pending[0].sizeBytes)
        assertEquals(34L, inbox.pending[1].sizeBytes)
        assertEquals("ffprobe failed", inbox.failed.single().reason)
    }
}
