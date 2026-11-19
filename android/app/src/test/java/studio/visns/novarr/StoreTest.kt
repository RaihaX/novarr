package studio.visns.novarr

import java.io.File
import java.nio.file.Files
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.AuthUser
import studio.visns.novarr.api.ChapterContent
import studio.visns.novarr.api.MangaChapterInfo
import studio.visns.novarr.api.MangaPage
import studio.visns.novarr.api.MangaPages
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.store.Credential
import studio.visns.novarr.store.CredentialStore
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.store.SecretBox

class StoreTest {
    private val server = "http://10.0.2.2:8773"
    private fun tempDir(): File = Files.createTempDirectory("store").toFile()
    private val owner = AuthUser(1, "Owner", "admin", "admin")
    private val reader = AuthUser(2, "Reader", "reader", "reader")

    @Test
    fun eachServerAndUserGetsItsOwnDirectory() {
        val base = tempDir()
        val off = OfflineStore.resolveDirectory(base, server, null)
        val a = OfflineStore.resolveDirectory(base, server, reader)
        val b = OfflineStore.resolveDirectory(base, server, AuthUser(3, "", "c", "reader"))
        val otherServer = OfflineStore.resolveDirectory(base, "http://other:8080", reader)
        assertEquals(File(base, "http_10_0_2_2_8773"), off)
        assertEquals(File(base, "http_10_0_2_2_8773@user-2"), a)
        assertNotEquals(a, b)
        assertNotEquals(a, otherServer)
    }

    @Test
    fun theOwnerAdoptsTheCacheFromBeforeAccounts() {
        val base = tempDir()
        val shared = OfflineStore.directory(base, server).apply { mkdirs() }
        File(shared, OfflineStore.WRITE_QUEUE_FILE).writeText("[]")
        assertEquals(shared, OfflineStore.resolveDirectory(base, server, owner))
        assertEquals("1", File(shared, OfflineStore.OWNER_MARKER_FILE).readText())
        // Readers never take it (its queued writes are the owner's)...
        assertEquals(OfflineStore.directory(base, server, 2), OfflineStore.resolveDirectory(base, server, reader))
        // ...and the owner keeps getting it.
        assertEquals(shared, OfflineStore.resolveDirectory(base, server, owner))
    }

    @Test
    fun aReaderDoesNotAdoptAnUnclaimedCache() {
        val base = tempDir()
        val shared = OfflineStore.directory(base, server).apply { mkdirs() }
        File(shared, "novels.json").writeText("[]")
        assertEquals(OfflineStore.directory(base, server, 2), OfflineStore.resolveDirectory(base, server, reader))
        assertFalse(File(shared, OfflineStore.OWNER_MARKER_FILE).exists())
    }

    @Test
    fun cachesResponsesAndChaptersAndKeepsQueueOnClear() = runTest {
        val store = OfflineStore(tempDir())
        store.save(listOf(NovelSummary(1, "One")), OfflineStore.Key.novels)
        assertEquals("One", store.load(OfflineStore.Key.novels)?.single()?.name)

        store.saveChapter(ChapterContent(10, novelId = 1, paragraphs = listOf("text")))
        store.saveChapter(ChapterContent(11, novelId = 1, paragraphs = emptyList())) // nothing worth keeping
        assertEquals(setOf(10), store.cachedChapterIds(1))

        store.updateChapterState(10, 1, progress = 55, read = null)
        assertEquals(55, store.chapter(10, 1)?.readProgress)

        store.writeQueueFile.writeText("[]")
        store.clearAll()
        assertTrue(store.writeQueueFile.exists())
        assertNull(store.load(OfflineStore.Key.novels))
        assertEquals(emptySet<Int>(), store.cachedChapterIds(1))
    }

    @Test
    fun aMangaChapterCountsOnlyOnceItsIndexIsWritten() = runTest {
        val store = OfflineStore(tempDir())
        val pages = MangaPages(MangaChapterInfo(29, 8), listOf(MangaPage(1, "u1"), MangaPage(2, "u2")))
        store.pageFile(8, 29, 1).apply { parentFile?.mkdirs(); writeBytes(byteArrayOf(1, 2, 3)) }
        assertEquals(emptySet<Int>(), store.cachedChapterIds(8))
        store.saveMangaIndex(pages, 8)
        assertEquals(setOf(29), store.cachedChapterIds(8))
        assertTrue(store.hasChapter(29, 8))
        store.updateChapterState(29, 8, progress = 100, read = true)
        assertEquals(true, store.mangaChapter(29, 8)?.chapter?.read)
        assertEquals(1, store.downloads().single().chapterCount)
    }

    /** Reversible scrambling: enough to prove the file never holds the plain token. */
    private class FakeBox : SecretBox {
        override fun seal(plain: ByteArray) = plain.map { (it.toInt() xor 0x5A).toByte() }.toByteArray()
        override fun open(sealed: ByteArray) = seal(sealed)
    }

    @Test
    fun credentialsAreSealedPerServer() {
        val dir = tempDir()
        val store = CredentialStore(dir, FakeBox())
        assertTrue(store.save(Credential("secret-token-xyz", reader), server))
        assertEquals("secret-token-xyz", store.credential(server)?.token)
        assertNull(store.credential("http://other:8080"))
        dir.listFiles()!!.forEach { assertFalse(it.readText().contains("secret-token-xyz")) }
        store.remove(server)
        assertNull(store.credential(server))
    }

    @Test
    fun anUnreadableCredentialCountsAsSignedOut() {
        val dir = tempDir()
        val broken = object : SecretBox {
            override fun seal(plain: ByteArray) = plain
            override fun open(sealed: ByteArray): ByteArray = throw IllegalStateException("key gone")
        }
        val store = CredentialStore(dir, broken)
        store.save(Credential("t", reader), server)
        assertNull(store.credential(server))
    }
}
