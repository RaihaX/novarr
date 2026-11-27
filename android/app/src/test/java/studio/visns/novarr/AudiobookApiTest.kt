package studio.visns.novarr

import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.AudiobookDetail
import studio.visns.novarr.api.AudiobookEnvelope
import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.DeviceIdentity
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.playback.AudioDataSource
import studio.visns.novarr.playback.AudiobookDownloads
import studio.visns.novarr.api.AudiobookPart

/** The audiobooks API contract: decoding, every call's method, path, body and headers. */
class AudiobookApiTest {
    private val device = DeviceIdentity("a_TESTDEVICE123", "Pixel 8")

    private val detailJson = """
        {"audiobook": {"id": 12, "novel_id": 40, "title": "The Book", "author": "A. Writer", "narrator": "N. Reader",
         "cover_url": "https://h/c.jpg", "duration_ms": 41234000,
         "progress": {"position_ms": 120000, "finished": false, "updated_at": "2026-12-10T10:00:00Z"},
         "description": "About it", "year": 2019, "size_bytes": 512000000,
         "parts": [
           {"index": 1, "url": "https://h/api/v1/audiobooks/12/parts/1?signature=x", "mime": "audio/mpeg", "duration_ms": 21234000, "start_ms": 20000000, "size_bytes": 2},
           {"index": 0, "url": "https://h/api/v1/audiobooks/12/parts/0?signature=y", "mime": "audio/mpeg", "duration_ms": 20000000, "start_ms": 0, "size_bytes": 1},
           {"broken": true}
         ],
         "chapters": [{"index": 0, "title": "One", "start_ms": 0, "end_ms": 600000}, {"index": 1, "title": "", "start_ms": 600000, "end_ms": 41234000}],
         "bookmarks": [{"id": 3, "position_ms": 5000, "note": null, "created_at": "2026-12-10T09:00:00Z"}],
         "future_field": {"x": 1}}}
    """

    @Test
    fun detailDecodesFlatWithItsPartsInOrder() {
        val detail = NovarrJson.decodeFromString(AudiobookEnvelope.serializer(), detailJson).audiobook
        assertEquals(12, detail.id)
        assertEquals(40, detail.summary.novelId)
        assertEquals("N. Reader", detail.summary.narratorOrNull)
        assertEquals(120_000L, detail.summary.progress?.positionMs)
        assertEquals("2019", detail.extras.year)
        // A malformed part is skipped; parts come back in index order.
        assertEquals(listOf(0, 1), detail.parts.map { it.index })
        assertEquals("Chapter 2", detail.chapters[1].label)
        assertNull(detail.bookmarks.single().noteOrNull)
        // Cached and read back exactly the same way.
        val again = NovarrJson.decodeFromString(AudiobookDetail.serializer(), NovarrJson.encodeToString(AudiobookDetail.serializer(), detail))
        assertEquals(detail, again)
    }

    @Test
    fun summariesAreLenient() {
        val bare = NovarrJson.decodeFromString(AudiobookSummary.serializer(), """{"id": 5}""")
        assertEquals("Untitled", bare.title)
        assertNull(bare.progress)
        assertFalse(bare.started)
        val bad = NovarrJson.decodeFromString(AudiobookSummary.serializer(), """{"id": 5, "duration_ms": 1000, "progress": "weird"}""")
        assertNull(bad.progress)
        val finished = NovarrJson.decodeFromString(AudiobookSummary.serializer(), """{"id": 5, "duration_ms": 1000, "progress": {"position_ms": 10, "finished": true}}""")
        assertEquals(1f, finished.fraction)
    }

    @Test
    fun novelsCarryTheirAudiobookAndOlderServersNone() {
        val with = NovarrJson.decodeFromString(NovelSummary.serializer(), """{"id": 1, "name": "B", "audiobook": {"id": 12, "duration_ms": 41234000}}""")
        assertEquals(12, with.audiobook?.id)
        assertEquals(41_234_000L, with.audiobook?.durationMs)
        assertNull(NovarrJson.decodeFromString(NovelSummary.serializer(), """{"id": 1, "audiobook": null}""").audiobook)
        assertNull(NovarrJson.decodeFromString(NovelSummary.serializer(), """{"id": 1}""").audiobook)
        // Malformed: no audiobook, never a broken series.
        assertNull(NovarrJson.decodeFromString(NovelSummary.serializer(), """{"id": 1, "audiobook": 7}""").audiobook)
    }

    @Test
    fun metaOffersAudiobooksOnlyWhenItSaysSo() {
        assertTrue(NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"audiobooks":true}""").audiobooks)
        assertFalse(NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1}""").audiobooks)
    }

    @Test
    fun listsAndContinue() = runTest {
        val server = FakeServer { request ->
            when {
                request.url.encodedPath.endsWith("continue") -> 200 to """{"audiobooks":[{"id":1,"title":"A"}]}"""
                else -> 200 to """{"audiobooks":[{"id":1},{"id":2}],"page":2,"has_more":true}"""
            }
        }
        val client = ApiClient("http://host", server.client, device, token = "tok")
        val page = client.audiobooks(page = 2, search = "  dune ")
        assertEquals(listOf(1, 2), page.audiobooks.map { it.id })
        assertTrue(page.hasMore)
        assertEquals("http://host/api/v1/audiobooks?page=2&search=dune", server.requests[0].url.toString())
        assertEquals("Bearer tok", server.requests[0].header("Authorization"))
        assertEquals("A", client.continueListening().single().title)
        assertEquals("/api/v1/audiobooks/continue", server.requests[1].url.encodedPath)
    }

    @Test
    fun oneAudiobook() = runTest {
        val server = FakeServer { 200 to detailJson }
        val detail = ApiClient("http://host", server.client, device, token = "tok").audiobook(12)
        assertEquals(2, detail.parts.size)
        assertEquals("/api/v1/audiobooks/12", server.requests.single().url.encodedPath)
        // Never revalidated from the HTTP cache: the signed URLs inside expire.
        assertEquals("no-cache", server.requests.single().header("Cache-Control"))
    }

    @Test
    fun progressIsAPutWithRecordedAtAndDeviceHeaders() = runTest {
        val server = FakeServer { 200 to """{"progress":{"position_ms":9000,"finished":false,"updated_at":"2026-12-10T10:00:00Z"},"stale":true}""" }
        val result = ApiClient("http://host", server.client, device, token = "tok")
            .saveAudiobookProgress(12, 8_000, 1.2345f, false, "2026-12-10T09:59:00Z")
        assertTrue(result.stale)
        assertEquals(9_000L, result.progress?.positionMs)
        val request = server.requests.single()
        assertEquals("PUT", request.method)
        assertEquals("/api/v1/audiobooks/12/progress", request.url.encodedPath)
        assertEquals("a_TESTDEVICE123", request.header(DeviceIdentity.ID_HEADER))
        assertEquals("Pixel 8", request.header(DeviceIdentity.NAME_HEADER))
        val body = NovarrJson.parseToJsonElement(server.bodies.single()).jsonObject
        assertEquals("8000", body["position_ms"]!!.jsonPrimitive.content)
        assertEquals(1.23, body["speed"]!!.jsonPrimitive.content.toDouble(), 0.0001)
        assertEquals("false", body["finished"]!!.jsonPrimitive.content)
        assertEquals("2026-12-10T09:59:00Z", body["recorded_at"]!!.jsonPrimitive.content)
    }

    @Test
    fun speedIsKeptWithinTheContract() {
        val slow = ApiClient.audiobookProgressBody(1, 0.1f, false, "t")
        val fast = ApiClient.audiobookProgressBody(1, 9f, true, "t")
        assertEquals(0.5, slow["speed"]!!.jsonPrimitive.content.toDouble(), 0.0)
        assertEquals(3.0, fast["speed"]!!.jsonPrimitive.content.toDouble(), 0.0)
        assertEquals("0", ApiClient.audiobookProgressBody(-5, 1f, false, "t")["position_ms"]!!.jsonPrimitive.content)
    }

    @Test
    fun bookmarks() = runTest {
        val server = FakeServer { request ->
            if (request.method == "POST") 201 to """{"bookmark":{"id":9,"position_ms":5000,"note":"here"}}""" else 404 to """{"message":"gone"}"""
        }
        val client = ApiClient("http://host", server.client, device, token = "tok")
        val bookmark = client.createAudiobookBookmark(12, 5_000, "  here  ")
        assertEquals(9, bookmark.id)
        assertEquals("/api/v1/audiobooks/12/bookmarks", server.requests[0].url.encodedPath)
        val body = NovarrJson.parseToJsonElement(server.bodies[0]).jsonObject
        assertEquals("here", body["note"]!!.jsonPrimitive.content)
        // No note: no key at all.
        client.createAudiobookBookmark(12, 1, "   ")
        assertFalse(NovarrJson.parseToJsonElement(server.bodies[1]).jsonObject.containsKey("note"))
        // Already deleted (404) counts as deleted.
        client.deleteAudiobookBookmark(12, 9)
        assertEquals("DELETE", server.requests[2].method)
        assertEquals("/api/v1/audiobooks/12/bookmarks/9", server.requests[2].url.encodedPath)
    }

    @Test
    fun adminCalls() = runTest {
        val server = FakeServer { request ->
            if (request.url.encodedPath.endsWith("inbox")) {
                200 to """{"pending":[{"name":"Dune (Unabridged)","size_bytes":12,"status":"stabilising"}],"failed":[{"name":"x.mp3","reason":"ffprobe failed"}]}"""
            } else {
                202 to "{}"
            }
        }
        val client = ApiClient("http://host", server.client, device, token = "tok")
        val inbox = client.audiobookInbox()
        assertEquals("stabilising", inbox.pending.single().status)
        assertEquals("ffprobe failed", inbox.failed.single().reason)
        client.matchAudiobook(12, 40)
        client.matchAudiobook(12, null)
        client.scanAudiobooks()
        assertEquals("/api/v1/audiobooks/12/match", server.requests[1].url.encodedPath)
        assertEquals("40", NovarrJson.parseToJsonElement(server.bodies[1]).jsonObject["novel_id"]!!.jsonPrimitive.content)
        assertEquals(JsonNull, NovarrJson.parseToJsonElement(server.bodies[2]).jsonObject["novel_id"])
        assertEquals("POST", server.requests[3].method)
        assertEquals("/api/v1/audiobooks/scan", server.requests[3].url.encodedPath)
    }

    // Streaming headers

    @Test
    fun theTokenOnlyGoesToTheReadersOwnServer() {
        val headers = AudioDataSource.headersFor("https://novarr.example:8443/api/v1/audiobooks/1/parts/0?signature=s", "https://novarr.example:8443", "tok", device.headers)
        assertEquals("Bearer tok", headers["Authorization"])
        assertEquals("a_TESTDEVICE123", headers[DeviceIdentity.ID_HEADER])
        // Another host, port or scheme: nothing.
        assertTrue(AudioDataSource.headersFor("https://cdn.example/a.mp3", "https://novarr.example", "tok", device.headers).isEmpty())
        assertTrue(AudioDataSource.headersFor("https://novarr.example:9999/a", "https://novarr.example:8443", "tok", device.headers).isEmpty())
        assertTrue(AudioDataSource.headersFor("http://novarr.example/a", "https://novarr.example", "tok", device.headers).isEmpty())
        // A local file: nothing.
        assertTrue(AudioDataSource.headersFor("file:///data/x.m4b", "https://novarr.example", "tok", device.headers).isEmpty())
        // Sign-in off: device headers, no Authorization.
        assertNull(AudioDataSource.headersFor("https://novarr.example/a", "https://novarr.example", null, device.headers)["Authorization"])
    }

    @Test
    fun downloadFileNames() {
        assertEquals("part-000.mp3", AudiobookDownloads.fileName(AudiobookPart(0, mime = "audio/mpeg")))
        assertEquals("part-012.m4b", AudiobookDownloads.fileName(AudiobookPart(12, mime = "audio/x-m4b")))
        assertEquals("part-001.m4a", AudiobookDownloads.fileName(AudiobookPart(1, mime = "audio/mp4; codecs=mp4a")))
        assertEquals("part-002.audio", AudiobookDownloads.fileName(AudiobookPart(2, mime = null)))
    }
}
