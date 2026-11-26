package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.AuthTokenResponse
import studio.visns.novarr.api.ChapterEnvelope
import studio.visns.novarr.api.HomeFeed
import studio.visns.novarr.api.MangaPages
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.NovelDetail
import studio.visns.novarr.api.NovelEnvelope
import studio.visns.novarr.api.NovelsEnvelope
import studio.visns.novarr.api.PositionEnvelope
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.api.chapterFallbackLabel

class DecodingTest {
    @Test
    fun metaWithAuthRequired() {
        val meta = NovarrJson.decodeFromString(
            ServerMeta.serializer(),
            """{"app":"novarr","api_version":1,"server_version":"28da68d","auth":{"required":true,"methods":["password","pairing"]}}""",
        )
        assertTrue(meta.requiresSignIn)
        assertEquals(listOf("password", "pairing"), meta.auth?.methods)
        assertEquals("28da68d", meta.serverVersion)
    }

    @Test
    fun metaWithoutAuthMeansNoSignIn() {
        val old = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"server_version":null}""")
        assertFalse(old.requiresSignIn)
        assertNull(old.auth)
        val off = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"auth":{"required":false}}""")
        assertFalse(off.requiresSignIn)
    }

    @Test
    fun malformedAuthIsTreatedAsMissing() {
        val meta = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":"1","auth":"yes"}""")
        assertFalse(meta.requiresSignIn)
        assertEquals(1, meta.apiVersion)
    }

    @Test
    fun signInResponse() {
        val r = NovarrJson.decodeFromString(
            AuthTokenResponse.serializer(),
            """{"token":"abc","user":{"id":2,"name":"Reader","username":"reader","role":"reader","email":"x"}}""",
        )
        assertEquals("abc", r.token)
        assertEquals(2, r.user.id)
        assertEquals("Reader", r.user.roleLabel)
    }

    @Test
    fun novelListSkipsBrokenRowsAndDefaultsTheRest() {
        val envelope = NovarrJson.decodeFromString(
            NovelsEnvelope.serializer(),
            """{"novels":[
                {"id":1,"name":"A","medium":"manga","chapters_read":"3","tags":[{"id":1,"name":"x"},{"bad":true}]},
                {"name":"no id"},
                {"id":2}
            ]}""",
        )
        assertEquals(listOf(1, 2), envelope.novels.map { it.id })
        val a = envelope.novels[0]
        assertTrue(a.isManga)
        assertEquals(3, a.chaptersRead)
        assertEquals(1, a.tags.size)
        assertEquals("Untitled", envelope.novels[1].name)
        assertFalse(envelope.novels[1].isManga)
    }

    @Test
    fun bookSummaryDecodes() {
        val envelope = NovarrJson.decodeFromString(
            NovelsEnvelope.serializer(),
            """{"novels":[
                {"id":7,"name":"Dune","medium":"book","author":"Frank Herbert","origin_country":null,"chapters_total":"48"},
                {"id":8,"medium":"BOOK"},
                {"id":9,"medium":""},
                {"id":10,"medium":"audiobook"}
            ]}""",
        )
        val book = envelope.novels[0]
        assertTrue(book.isBook)
        assertFalse(book.isManga)
        assertEquals("Frank Herbert", book.authorOrNull)
        assertEquals(48, book.chaptersTotal)
        assertTrue(envelope.novels[1].isBook)
        // A blank or unknown medium is neither a book nor manga (it reads as a novel).
        assertFalse(envelope.novels[2].isBook)
        assertFalse(envelope.novels[3].isBook)
        assertFalse(envelope.novels[3].isManga)
    }

    @Test
    fun metaBooksFlag() {
        assertTrue(NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"books":true}""").books)
        assertFalse(NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1}""").books)
    }

    @Test(expected = Exception::class)
    fun listEnvelopeWithoutItsKeyIsAnError() {
        NovarrJson.decodeFromString(NovelsEnvelope.serializer(), """{"message":"Server Error"}""")
    }

    @Test
    fun novelDetailIsFlatAndRoundTrips() {
        val json = """{"novel":{"id":7,"name":"N","description":"Hello","source_url":"https://x","source_unavailable":{"count":2,"message":"m"},"chapters_total":10}}"""
        val detail = NovarrJson.decodeFromString(NovelEnvelope.serializer(), json).novel
        assertEquals(7, detail.summary.id)
        assertEquals(10, detail.summary.chaptersTotal)
        assertEquals("Hello", detail.description)
        assertEquals(2, detail.extras.sourceUnavailable?.count)
        // The cache writes it back flat and reads it through the same path.
        val again = NovarrJson.decodeFromString(NovelDetail.serializer(), NovarrJson.encodeToString(NovelDetail.serializer(), detail))
        assertEquals(detail, again)
    }

    @Test
    fun chapterContentIgnoresHtmlAndKeepsNeighbours() {
        val chapter = NovarrJson.decodeFromString(
            ChapterEnvelope.serializer(),
            """{"chapter":{"id":3,"novel_id":2,"label":"","chapter":12.5,"paragraphs":["a","b"],"html":"<p>a</p>","read_progress":40,
               "prev":{"id":2,"label":"Chapter 12","downloaded":true},"next":null}}""",
        ).chapter
        assertEquals("Chapter 12.5", chapter.title)
        assertEquals(listOf("a", "b"), chapter.paragraphs)
        assertEquals(40, chapter.readProgress)
        assertEquals(2, chapter.prev?.id)
        assertNull(chapter.next)
        assertTrue(chapter.hasText)
    }

    @Test
    fun homeFeedWithBadStatsStillLoads() {
        val feed = NovarrJson.decodeFromString(
            HomeFeed.serializer(),
            """{"continue":[{"novel":{"id":1},"chapter":{"id":5,"label":"Ch 5"}},{"novel":{}}],"new_chapters":[{"novel":{"id":2,"chapters_new":4},"first_chapter":"oops"}],"stats":[]}""",
        )
        assertEquals(1, feed.continueReading.size)
        assertEquals(4, feed.newChapters.single().newCount)
        assertNull(feed.newChapters.single().firstChapter)
        assertNull(feed.stats)
    }

    @Test
    fun mangaPagesKeepTheSignedUrlsAsIs() {
        val url = "http://10.0.2.2:8773/chapters/29/pages/1?reader=2&signature=abc"
        val pages = NovarrJson.decodeFromString(
            MangaPages.serializer(),
            """{"chapter":{"id":29,"novel_id":8,"label":"Chapter 1","number":1,"page_count":2,"read":false,"progress":null},
               "pages":[{"page":1,"url":"$url","width":800,"height":1200},{"page":2,"url":"u2","width":null,"height":null}],
               "prev":null,"next":{"id":30,"label":"Chapter 2","downloaded":true},"reading_mode":"rtl"}""",
        )
        assertEquals(url, pages.pages[0].url)
        assertEquals(1.5f, pages.pages[0].aspect)
        assertNull(pages.pages[1].aspect)
        assertEquals("rtl", pages.readingMode)
        assertEquals(30, pages.next?.id)
    }

    @Test
    fun positionNullOrFull() {
        assertNull(NovarrJson.decodeFromString(PositionEnvelope.serializer(), """{"position":null}""").position)
        val p = NovarrJson.decodeFromString(
            PositionEnvelope.serializer(),
            """{"position":{"chapter_id":123,"chapter_label":null,"chapter_number":"124","progress":40,"downloaded":true,
               "device_id":"k_8f3a","device_name":"Kindle","at":"2026-11-18T02:10:00+00:00","age_seconds":7200}}""",
        ).position!!
        assertEquals(123, p.chapterId)
        assertEquals("124", p.chapterNumber)
        assertEquals(7200, p.ageSeconds)
    }

    @Test
    fun fallbackLabels() {
        assertEquals("Chapter 12", chapterFallbackLabel(12.0))
        assertEquals("Chapter 12.5", chapterFallbackLabel(12.5))
        assertEquals("Chapter", chapterFallbackLabel(null))
    }
}
