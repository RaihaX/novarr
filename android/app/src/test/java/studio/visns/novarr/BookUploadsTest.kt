package studio.visns.novarr

import java.io.ByteArrayInputStream
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AuthUser
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.domain.BookUploads
import studio.visns.novarr.domain.BookUploads.Limits
import studio.visns.novarr.domain.BookUploads.Picked
import studio.visns.novarr.domain.BookUploads.Problem

class BookUploadsTest {
    private val limits = Limits(maxMb = 50, maxFiles = 20)

    // Files

    @Test
    fun epubsAreKnownByNameOrByTypeWhenTheNameHasNoExtension() {
        assertTrue(BookUploads.isEpub("Dune.epub", null))
        assertTrue(BookUploads.isEpub("DUNE.EPUB", "application/octet-stream"))
        assertTrue(BookUploads.isEpub("document", "application/epub+zip"))
        assertTrue(BookUploads.isEpub(null, "application/epub+zip"))
        assertFalse(BookUploads.isEpub("notes.pdf", "application/epub+zip"))
        assertFalse(BookUploads.isEpub("archive.zip", "application/octet-stream"))
        assertFalse(BookUploads.isEpub("document", "application/octet-stream"))
        assertFalse(BookUploads.isEpub(null, null))
    }

    @Test
    fun problemsComeInOrderUnreadableTypeEmptySize() {
        assertNull(BookUploads.problem(Picked("a.epub", null, 1_000), limits))
        assertNull(BookUploads.problem(Picked("a.epub", null, null), limits))
        assertEquals(Problem.UNREADABLE, BookUploads.problem(Picked("a.pdf", null, 1, readable = false), limits))
        assertEquals(Problem.NOT_EPUB, BookUploads.problem(Picked("a.pdf", null, 1), limits))
        assertEquals(Problem.EMPTY, BookUploads.problem(Picked("a.epub", null, 0), limits))
        assertNull(BookUploads.problem(Picked("a.epub", null, 50L * 1024 * 1024), limits))
        assertEquals(Problem.TOO_LARGE, BookUploads.problem(Picked("a.epub", null, 50L * 1024 * 1024 + 1), limits))
        assertEquals("Only ePub files (.epub) can be added.", BookUploads.message(Problem.NOT_EPUB, limits))
        assertEquals("Too large: ePubs can be up to 50 MB.", BookUploads.message(Problem.TOO_LARGE, limits))
    }

    @Test
    fun countIsCheckedAgainstTheLimit() {
        assertNull(BookUploads.tooMany(20, limits))
        assertTrue(BookUploads.tooMany(21, limits)!!.contains("up to 20"))
        assertNull(BookUploads.tooMany(3, Limits(50, 3)))
        assertTrue(BookUploads.tooMany(4, Limits(50, 3)) != null)
    }

    @Test
    fun uploadNamesLoseFoldersAndAlwaysEndInEpub() {
        assertEquals("Dune.epub", BookUploads.uploadName("Dune.epub", 0))
        assertEquals("Dune.epub", BookUploads.uploadName("primary:Download/Dune.epub", 0))
        assertEquals("document.epub", BookUploads.uploadName("document", 0))
        assertEquals("book-3.epub", BookUploads.uploadName(null, 2))
        assertEquals("book-1.epub", BookUploads.uploadName("  ", 0))
        assertEquals("ab.epub", BookUploads.uploadName("a\"b.epub", 0))
        assertEquals("Ünïcødé.EPUB", BookUploads.uploadName("Ünïcødé.EPUB", 0))
    }

    @Test
    fun noteIsClampedTo500() {
        assertEquals(500, BookUploads.clampNote("x".repeat(600)).length)
        assertEquals("short", BookUploads.clampNote("short"))
    }

    // Meta

    @Test
    fun limitsComeFromMetaWithDefaults() {
        val meta = NovarrJson.decodeFromString(
            ServerMeta.serializer(),
            """{"app":"novarr","api_version":1,"book_uploads":{"max_mb":10,"max_files":5,"email":"books@example.test"}}""",
        )
        assertEquals(Limits(10, 5), Limits.from(meta.bookUploads))
        assertEquals("books@example.test", meta.bookUploads?.email)

        val old = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1}""")
        assertNull(old.bookUploads)
        assertEquals(Limits(50, 20), Limits.from(old.bookUploads))

        val noEmail = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"book_uploads":{"max_mb":0,"email":null}}""")
        assertNull(noEmail.bookUploads?.email)
        assertEquals(Limits(50, 20), Limits.from(noEmail.bookUploads))

        val malformed = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"book_uploads":"yes"}""")
        assertNull(malformed.bookUploads)
    }

    @Test
    fun accountEmailIsOptional() {
        assertNull(NovarrJson.decodeFromString(AuthUser.serializer(), """{"id":1,"name":"A"}""").email)
        assertEquals("a@b.test", NovarrJson.decodeFromString(AuthUser.serializer(), """{"id":1,"email":"a@b.test"}""").email)
    }

    // The upload

    private fun file(name: String, text: String) = ApiClient.UploadFile(name, text.length.toLong()) { ByteArrayInputStream(text.toByteArray()) }

    @Test
    fun uploadIsOneMultipartPartPerFileWithTheNoteAndToken() = runTest {
        val server = FakeServer { 201 to """{"message":"2 books were sent to the owner.","sent":[{"request_id":12,"title":"Dune"},{"request_id":13,"title":"Emma"}],"refused":[]}""" }
        val progress = mutableListOf<Long>()
        val answer = ApiClient("http://host:8080", server.client, token = "tok")
            .uploadBooks(listOf(file("Dune.epub", "DUNE-BYTES"), file("Emma.epub", "EMMA-BYTES")), "for the shelf") { written, _ -> progress += written }

        val request = server.requests.single()
        assertEquals("POST", request.method)
        assertEquals("http://host:8080/api/v1/books/uploads", request.url.toString())
        assertEquals("Bearer tok", request.header("Authorization"))
        assertTrue(request.body!!.contentType().toString().startsWith("multipart/form-data"))
        val body = server.bodies.single()
        assertEquals(2, Regex("""name="epub\[]"; filename="[^"]+\.epub"""").findAll(body).count())
        assertTrue(body.contains("filename=\"Dune.epub\""))
        assertTrue(body.contains("Content-Type: application/epub+zip"))
        assertTrue(body.contains("DUNE-BYTES") && body.contains("EMMA-BYTES"))
        assertTrue(body.contains("name=\"note\"") && body.contains("for the shelf"))
        assertEquals(request.body!!.contentLength(), progress.last())

        assertEquals("2 books were sent to the owner.", answer.message)
        assertEquals(listOf("Dune", "Emma"), answer.sent.map { it.title })
        assertEquals(12, answer.sent.first().requestId)
    }

    @Test
    fun aBlankNoteIsNotSent() = runTest {
        val server = FakeServer { 201 to """{"sent":[{"request_id":1,"title":"A"}],"refused":[]}""" }
        ApiClient("http://host", server.client, token = "t").uploadBooks(listOf(file("a.epub", "x")), "  ")
        assertFalse(server.bodies.single().contains("name=\"note\""))
    }

    @Test
    fun noneTaken422StillAnswersPerFile() = runTest {
        val server = FakeServer {
            422 to """{"message":"No books were sent.","sent":[],"refused":[{"file":"x.epub","message":"This book is already in the library: Dune.","code":"in_library"},{"file":"y.epub","message":"Can't read it.","code":"unreadable"}]}"""
        }
        val answer = ApiClient("http://host", server.client, token = "t").uploadBooks(listOf(file("x.epub", "x"), file("y.epub", "y")), null)
        assertTrue(answer.sent.isEmpty())
        assertEquals(listOf("in_library", "unreadable"), answer.refused.map { it.code })
        assertEquals("This book is already in the library: Dune.", answer.refused.first().message)
    }

    private suspend fun failure(status: Int, body: String): ApiError {
        val server = FakeServer { status to body }
        try {
            ApiClient("http://host", server.client, token = "t").uploadBooks(listOf(file("a.epub", "x")), null)
        } catch (e: ApiError) {
            return e
        }
        fail("expected an ApiError")
        throw AssertionError()
    }

    @Test
    fun validation422ShowsItsMessage() = runTest {
        val error = failure(422, """{"message":"The epub.0 field must be a file of type: epub.","errors":{"epub.0":["The epub.0 field must be a file of type: epub."]}}""")
        assertEquals("The epub.0 field must be a file of type: epub.", BookUploads.failureMessage(error))
        // Without a message, the first error sentence.
        val bare = failure(422, """{"errors":{"epub":["Too many files."]}}""")
        assertEquals("Too many files.", BookUploads.failureMessage(bare))
    }

    @Test
    fun notFoundIsRequestsOffOrAnOldServer() = runTest {
        val off = failure(404, """{"message":"Requests are switched off.","code":"requests_disabled"}""")
        assertEquals("Requests are switched off.", BookUploads.failureMessage(off))
        val offBare = failure(404, """{"code":"requests_disabled"}""")
        assertTrue(BookUploads.failureMessage(offBare).startsWith("Requests are switched off"))
        val old = failure(404, """{"message":"Not Found"}""")
        assertEquals("This server is too old to accept books from the app — update Novarr.", BookUploads.failureMessage(old))
        val html = failure(404, "<html>Not found</html>")
        assertEquals("This server is too old to accept books from the app — update Novarr.", BookUploads.failureMessage(html))
    }

    @Test
    fun signedOutAndTooLargeHaveTheirOwnSentences() = runTest {
        var reported = false
        val server = FakeServer { 401 to """{"message":"Unauthenticated."}""" }
        val error = try {
            ApiClient("http://host", server.client, token = "t", onUnauthorized = { reported = true }).uploadBooks(listOf(file("a.epub", "x")), null)
            null
        } catch (e: ApiError) {
            e
        }
        assertTrue(reported)
        assertTrue(error!!.isUnauthenticated)
        assertTrue(BookUploads.failureMessage(error).contains("sign in"))
        assertTrue(BookUploads.failureMessage(failure(413, "<html>413</html>")).contains("too large"))
        assertEquals(ApiError.TimedOut.userMessage, BookUploads.failureMessage(ApiError.TimedOut))
    }
}
