package studio.visns.novarr

import java.nio.file.Files
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.domain.AudiobookUploads
import studio.visns.novarr.domain.AudiobookUploads.Chunk
import studio.visns.novarr.domain.AudiobookUploads.Picked
import studio.visns.novarr.domain.AudiobookUploads.Problem
import studio.visns.novarr.domain.AudiobookUploads.Status
import studio.visns.novarr.store.AudiobookUploadEntry
import studio.visns.novarr.store.AudiobookUploadStore
import studio.visns.novarr.store.UploadFileRef

class AudiobookUploadsTest {
    private val mb = 1024L * 1024
    private val chunk = 8 * mb

    // Chunk planning

    @Test
    fun filesAreCutIntoEightMegabyteChunksWithASmallerLastOne() {
        val plan = AudiobookUploads.plan(listOf(20 * mb, 8 * mb, 1), chunk)
        assertEquals(3 + 1 + 1, plan.size)
        assertEquals(listOf(0, 1, 2), plan.filter { it.file == 0 }.map { it.index })
        assertEquals(Chunk(0, 2, 16 * mb, 4 * mb, 20 * mb), plan[2])
        assertEquals(Chunk(1, 0, 0, 8 * mb, 8 * mb), plan[3])
        assertEquals(Chunk(2, 0, 0, 1, 1), plan[4])
        assertEquals(20 * mb + 8 * mb + 1, AudiobookUploads.bytesOf(plan))
        // Every byte once, no gaps.
        plan.groupBy { it.file }.values.forEach { chunks ->
            chunks.zipWithNext().forEach { (a, b) -> assertEquals(a.end, b.offset) }
        }
    }

    @Test
    fun chunkCountsAndRanges() {
        assertEquals(0, AudiobookUploads.chunkCount(0, chunk))
        assertEquals(1, AudiobookUploads.chunkCount(1, chunk))
        assertEquals(1, AudiobookUploads.chunkCount(chunk, chunk))
        assertEquals(2, AudiobookUploads.chunkCount(chunk + 1, chunk))
        // A 3 GB file: 384 chunks.
        assertEquals(384, AudiobookUploads.chunkCount(3L * 1024 * mb, chunk))
        assertEquals("bytes 0-8388607/20971520", Chunk(0, 0, 0, chunk, 20 * mb).contentRange)
        assertEquals("bytes 16777216-20971519/20971520", Chunk(0, 2, 16 * mb, 4 * mb, 20 * mb).contentRange)
        assertEquals("bytes 0-0/1", Chunk(0, 0, 0, 1, 1).contentRange)
    }

    @Test
    fun serverChunkSizeIsBytesOrMegabytesWithTheDefaultForNonsense() {
        assertEquals(chunk, AudiobookUploads.chunkSize(null))
        assertEquals(chunk, AudiobookUploads.chunkSize(0))
        assertEquals(chunk, AudiobookUploads.chunkSize(-5))
        assertEquals(4 * mb, AudiobookUploads.chunkSize(4))
        assertEquals(chunk, AudiobookUploads.chunkSize(1000))
        assertEquals(5 * mb, AudiobookUploads.chunkSize(5 * mb))
    }

    // Resume

    @Test
    fun resumeSendsOnlyWhatTheServerLacks() {
        val plan = AudiobookUploads.plan(listOf(20 * mb, 16 * mb, 3 * mb), chunk)
        val missing = AudiobookUploads.missing(plan, present = mapOf(0 to setOf(0, 2), 1 to setOf(0, 1)), doneFiles = setOf(2))
        assertEquals(listOf(0 to 1), missing.map { it.file to it.index })
        assertEquals(chunk, AudiobookUploads.bytesOf(missing))
        // Nothing known: everything; everything present: nothing.
        assertEquals(plan, AudiobookUploads.missing(plan, emptyMap()))
        assertTrue(AudiobookUploads.missing(plan, emptyMap(), doneFiles = setOf(0, 1, 2)).isEmpty())
        // Chunk numbers the plan doesn't have are ignored.
        assertEquals(plan.size, AudiobookUploads.missing(plan, mapOf(0 to setOf(99), 7 to setOf(0))).size)
    }

    private fun parse(json: String) = AudiobookUploads.parseUpload(NovarrJson.parseToJsonElement(json))!!

    @Test
    fun presentChunksAreReadPerFileInSeveralShapes() {
        val arrays = parse("""{"upload":{"id":12,"status":"uploading","chunk_size":8388608,"files":[{"index":0,"name":"a.m4b","size":1,"chunks":[0,1,"2"]},{"index":1,"present":{"0":true,"1":false,"3":1}},{"received_chunks":[]},{"done":true}]}}""")
        assertEquals("12", arrays.id)
        assertEquals(Status.UPLOADING, arrays.status)
        assertEquals(chunk, arrays.chunkSize)
        assertEquals(setOf(0, 1, 2), arrays.present[0])
        assertEquals(setOf(0, 3), arrays.present[1])
        assertEquals(emptySet<Int>(), arrays.present[2])
        assertEquals(setOf(3), arrays.doneFiles)
    }

    @Test
    fun presentChunksMayComeAtTheTopKeyedByFile() {
        val keyed = parse("""{"id":"ab12","status":"uploading","chunks":{"0":[0,1],"1":[2]}}""")
        assertEquals("ab12", keyed.id)
        assertEquals(mapOf(0 to setOf(0, 1), 1 to setOf(2)), keyed.present)
        val nested = parse("""{"upload":{"id":3,"present":[[0],[0,1]]}}""")
        assertEquals(mapOf(0 to setOf(0), 1 to setOf(0, 1)), nested.present)
    }

    @Test
    fun unreadableAnswersMeanNothingPresent() {
        val odd = parse("""{"upload":{"id":1,"status":"weird","files":"nope","chunks":5}}""")
        assertTrue(odd.present.isEmpty())
        assertNull(odd.status)
        assertEquals("weird", odd.rawStatus)
        assertNull(AudiobookUploads.parseUpload(NovarrJson.parseToJsonElement("[1,2]")))
        assertNull(AudiobookUploads.parseUpload(null))
        val bad = parse("""{"upload":{"id":1,"files":[{"chunks":[-1,"x",null,4]}]}}""")
        assertEquals(setOf(4), bad.present[0])
    }

    @Test
    fun statusesAndReasons() {
        assertEquals(Status.PENDING, parse("""{"upload":{"id":1,"status":"pending"}}""").status)
        assertEquals(Status.APPROVED, parse("""{"upload":{"id":1,"status":"APPROVED"}}""").status)
        val declined = parse("""{"upload":{"id":1,"status":"declined","decline_reason":"Wrong book."}}""")
        assertEquals(Status.DECLINED, declined.status)
        assertEquals("Wrong book.", declined.message)
        val failed = parse("""{"message":"Not an audio file: a.mp3","upload":{"id":1,"status":"failed"}}""")
        assertEquals(Status.FAILED, failed.status)
        assertEquals("Not an audio file: a.mp3", failed.message)
        assertTrue(Status.PENDING.finished && Status.FAILED.finished && !Status.UPLOADING.finished && !Status.COMPLETING.finished)
        assertEquals("Waiting for the owner", Status.PENDING.label)
        assertEquals("In the library", Status.APPROVED.label)
    }

    // Retries

    @Test
    fun backoffDoublesUpToAMinuteWithJitterAndRetryAfterWins() {
        assertEquals(listOf(1_000L, 2_000L, 4_000L, 8_000L, 16_000L, 32_000L, 60_000L, 60_000L), (0..7).map { AudiobookUploads.backoffMillis(it) })
        assertEquals(750L, AudiobookUploads.backoffMillis(0, jitter = 1.0))
        assertEquals(45_000L, AudiobookUploads.backoffMillis(10, jitter = 1.0))
        assertEquals(60_000L, AudiobookUploads.backoffMillis(100))
        assertEquals(7_000L, AudiobookUploads.backoffMillis(0, retryAfter = "7"))
        assertEquals(300_000L, AudiobookUploads.backoffMillis(0, retryAfter = "9999"))
        assertEquals(1_000L, AudiobookUploads.backoffMillis(0, retryAfter = "Wed, 21 Oct 2015 07:28:00 GMT"))
    }

    @Test
    fun onlyTransientFailuresAreRetried() {
        assertTrue(AudiobookUploads.isRetryable(ApiError.TimedOut))
        assertTrue(AudiobookUploads.isRetryable(ApiError.Unreachable("reset")))
        assertTrue(AudiobookUploads.isRetryable(ApiError.Http(503, null)))
        assertTrue(AudiobookUploads.isRetryable(ApiError.Http(429, null)))
        assertFalse(AudiobookUploads.isRetryable(ApiError.Http(422, null)))
        assertFalse(AudiobookUploads.isRetryable(ApiError.Http(401, null)))
    }

    // Picked files

    @Test
    fun audioFilesByExtensionOrTypeWithLimits() {
        assertNull(AudiobookUploads.problem(Picked("Dune.m4b", "application/octet-stream", 100)))
        assertNull(AudiobookUploads.problem(Picked("01 Part.MP3", null, 100)))
        assertNull(AudiobookUploads.problem(Picked("track", "audio/mpeg", 100)))
        assertEquals(Problem.NOT_AUDIO, AudiobookUploads.problem(Picked("cover.jpg", "audio/mpeg", 100)))
        assertEquals(Problem.NOT_AUDIO, AudiobookUploads.problem(Picked("book.wav", "audio/wav", 100)))
        assertEquals(Problem.UNKNOWN_SIZE, AudiobookUploads.problem(Picked("a.mp3", null, null)))
        assertEquals(Problem.EMPTY, AudiobookUploads.problem(Picked("a.mp3", null, 0)))
        assertEquals(Problem.UNREADABLE, AudiobookUploads.problem(Picked("a.mp3", null, 1, readable = false)))
        assertNull(AudiobookUploads.problem(Picked("a.flac", null, AudiobookUploads.MAX_FILE_BYTES)))
        assertEquals(Problem.TOO_LARGE, AudiobookUploads.problem(Picked("a.flac", null, AudiobookUploads.MAX_FILE_BYTES + 1)))
        assertNull(AudiobookUploads.tooMuch(200, AudiobookUploads.MAX_TOTAL_BYTES))
        assertTrue(AudiobookUploads.tooMuch(201, 1)!!.contains("200"))
        assertTrue(AudiobookUploads.tooMuch(2, AudiobookUploads.MAX_TOTAL_BYTES + 1)!!.contains("6 GB"))
    }

    @Test
    fun uploadNamesKeepTheExtensionAndPartsSortNaturally() {
        assertEquals("Dune.m4b", AudiobookUploads.uploadName("primary:Audio/Dune.m4b", 0, null))
        assertEquals("track.mp3", AudiobookUploads.uploadName("track", 0, "audio/mpeg"))
        assertEquals("part-003.m4a", AudiobookUploads.uploadName(null, 2, "audio/mp4"))
        assertEquals("ab.opus", AudiobookUploads.uploadName("a\"b.opus", 0, null))
        val names = listOf("Part 10.mp3", "Part 2.mp3", "part 1.mp3", "Part 02b.mp3", "Intro.mp3")
        assertEquals(listOf("Intro.mp3", "part 1.mp3", "Part 2.mp3", "Part 02b.mp3", "Part 10.mp3"), names.sortedWith(AudiobookUploads::naturalOrder))
    }

    // The API (against a fake server)

    @Test
    fun createSendsTheFilesBookAndNoteAndReadsTheId() = runTest {
        val server = FakeServer { 201 to """{"upload":{"id":42,"chunk_size":8388608}}""" }
        val created = ApiClient("http://host", server.client, token = "t")
            .createAudiobookUpload(listOf("01.mp3" to 10L, "02.mp3" to 20L), novelId = 7, note = " thanks ")
        assertEquals("42", created.id)
        assertEquals(chunk, created.chunkSize)
        val request = server.requests.single()
        assertEquals("POST", request.method)
        assertEquals("http://host/api/v1/audiobook-uploads", request.url.toString())
        assertEquals("Bearer t", request.header("Authorization"))
        val body = NovarrJson.parseToJsonElement(server.bodies.single()).jsonObject
        assertEquals(listOf("01.mp3", "02.mp3"), body["files"]!!.jsonArray.map { it.jsonObject["name"]!!.jsonPrimitive.content })
        assertEquals(listOf("10", "20"), body["files"]!!.jsonArray.map { it.jsonObject["size"]!!.jsonPrimitive.content })
        assertEquals("7", body["novel_id"]!!.jsonPrimitive.content)
        assertEquals("thanks", body["note"]!!.jsonPrimitive.content)

        // No book, no note: neither is sent.
        val bare = FakeServer { 201 to """{"upload":{"id":"x1"}}""" }
        ApiClient("http://host", bare.client, token = "t").createAudiobookUpload(listOf("a.m4b" to 1L), null, "  ")
        val sent = NovarrJson.parseToJsonElement(bare.bodies.single()).jsonObject
        assertFalse("novel_id" in sent || "note" in sent)
    }

    @Test
    fun createWithoutAUsableIdIsADecodingError() = runTest {
        for (answer in listOf("""{"upload":{}}""", """{"upload":{"id":"../x"}}""", "")) {
            val server = FakeServer { 201 to answer }
            try {
                ApiClient("http://host", server.client, token = "t").createAudiobookUpload(listOf("a.mp3" to 1L), null, null)
                fail("expected a decoding error for $answer")
            } catch (e: ApiError.Decoding) {
                // expected
            }
        }
    }

    @Test
    fun chunksArePutWithTheirRangeAndRawBytes() = runTest {
        val server = FakeServer { 204 to "" }
        val piece = Chunk(file = 1, index = 2, offset = 16 * mb, length = 5, fileSize = 16 * mb + 5)
        ApiClient("http://host", server.client, token = "t").putAudiobookChunk("42", piece, "HELLO".toByteArray().toRequestBody("application/octet-stream".toMediaType()))
        val request = server.requests.single()
        assertEquals("PUT", request.method)
        assertEquals("/api/v1/audiobook-uploads/42/files/1/chunks/2", request.url.encodedPath)
        assertEquals((16 * mb).toString(), request.url.queryParameter("offset"))
        assertEquals("bytes 16777216-16777220/16777221", request.header("Content-Range"))
        assertEquals("Bearer t", request.header("Authorization"))
        assertEquals("HELLO", server.bodies.single())
    }

    @Test
    fun statusCompleteAndCancel() = runTest {
        val server = FakeServer { request ->
            when {
                request.method == "GET" -> 200 to """{"upload":{"id":42,"status":"uploading","files":[{"index":0,"chunks":[0]}]}}"""
                request.url.encodedPath.endsWith("/complete") -> 200 to """{"upload":{"id":42,"status":"pending"}}"""
                request.method == "DELETE" -> 404 to """{"message":"Not found"}"""
                else -> 500 to ""
            }
        }
        val client = ApiClient("http://host", server.client, token = "t")
        assertEquals(mapOf(0 to setOf(0)), client.audiobookUpload("42").present)
        assertEquals(Status.PENDING, client.completeAudiobookUpload("42")?.status)
        client.cancelAudiobookUpload("42") // 404: already gone, fine
        assertEquals(listOf("GET", "POST", "DELETE"), server.requests.map { it.method })
        assertEquals("http://host/api/v1/audiobook-uploads/42/complete", server.requests[1].url.toString())
    }

    @Test
    fun aRefusedCompleteCarriesTheServersReason() = runTest {
        val server = FakeServer { 422 to """{"message":"02.mp3 isn't an audio file.","upload":{"id":42,"status":"failed"}}""" }
        try {
            ApiClient("http://host", server.client, token = "t").completeAudiobookUpload("42")
            fail("expected 422")
        } catch (e: ApiError.Http) {
            assertEquals(422, e.status)
            assertEquals("02.mp3 isn't an audio file.", AudiobookUploads.failureMessage(e))
        }
        assertTrue(AudiobookUploads.failureMessage(ApiError.Http(404, "Not Found")).contains("update Novarr"))
        assertTrue(AudiobookUploads.failureMessage(ApiError.Http(401, null)).contains("signed out"))
        val validation = JsonObject(mapOf("errors" to JsonObject(mapOf("files" to JsonArray(listOf(kotlinx.serialization.json.JsonPrimitive("Too many files.")))))))
        assertEquals("Too many files.", AudiobookUploads.failureMessage(ApiError.Http(422, null, body = validation)))
    }

    @Test
    fun anUnsafeIdNeverReachesThePath() = runTest {
        val server = FakeServer { 200 to "{}" }
        try {
            ApiClient("http://host", server.client, token = "t").audiobookUpload("../../admin")
            fail("expected a decoding error")
        } catch (e: ApiError.Decoding) {
            assertTrue(server.requests.isEmpty())
        }
    }

    // Saved state (per account)

    @Test
    fun uploadsAreSavedChangedAndRemoved() = runTest {
        val root = Files.createTempDirectory("uploads").toFile()
        val store = AudiobookUploadStore(root)
        val entry = AudiobookUploadEntry(
            localId = "l1",
            files = listOf(UploadFileRef("content://x/1", "01.mp3", 10), UploadFileRef("content://x/2", "02.mp3", 20)),
            bookTitle = "Dune",
        )
        store.put(entry)
        assertEquals(30L, store.get("l1")!!.totalBytes)
        assertEquals(Status.QUEUED, store.get("l1")!!.state)
        store.change("l1") { it.copy(serverId = "42", status = Status.UPLOADING.name, doneBytes = 10) }
        // A fresh store on the same folder (a restart) reads it back.
        val again = AudiobookUploadStore(root).get("l1")!!
        assertEquals("42", again.serverId)
        assertEquals(Status.UPLOADING, again.state)
        assertEquals("Dune", again.title)
        assertNull(store.change("nope") { it })
        store.remove("l1")
        assertTrue(store.all().isEmpty())
        // An unknown status reads as failed, never crashes.
        assertEquals(Status.FAILED, entry.copy(status = "???").state)
        assertEquals("01", entry.copy(bookTitle = null).title)
        root.deleteRecursively()
    }
}
