package studio.visns.novarr.store

import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.KSerializer
import kotlinx.serialization.builtins.ListSerializer
import studio.visns.novarr.api.AudiobookDetail
import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.AudiobooksPage
import studio.visns.novarr.api.AuthUser
import studio.visns.novarr.api.BrowsePage
import studio.visns.novarr.api.ChapterContent
import studio.visns.novarr.api.ChapterListItem
import studio.visns.novarr.api.Highlight
import studio.visns.novarr.api.HomeFeed
import studio.visns.novarr.api.MangaPages
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.NovelDetail
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.api.ServerUrl
import studio.visns.novarr.api.WantToReadEnvelope

/**
 * On-disk cache for one (server, user): list responses as JSON plus chapter
 * content for offline reading. Lives in the app's files dir (not the cache
 * dir) so the system never purges chapters the user downloaded on purpose.
 *
 * Layout under [root]:
 *   novels.json, home.json, lists.json, want-to-read.json, pending-writes.json
 *   novels/<id>/detail.json, novels/<id>/chapters.json, novels/<id>/highlights.json
 *   novels/<id>/content/<chapterId>.json          a text chapter
 *   novels/<id>/content/<chapterId>.manga/        a manga chapter: page images,
 *                                                 then pages.json written last
 */
class OfflineStore(val root: File) {
    /** A cached response, by where it lives under the root. */
    class Key<T> private constructor(val path: String, val serializer: KSerializer<T>) {
        companion object {
            val novels = Key("novels.json", ListSerializer(NovelSummary.serializer()))
            val home = Key("home.json", HomeFeed.serializer())

            /** The reader's lists, saved with each library refresh so they show offline (read-only). */
            val lists = Key("lists.json", ListSerializer(ReadingList.serializer()))

            /** The whole "Want to read" (its screen), so it shows offline too. */
            val wantToRead = Key("want-to-read.json", WantToReadEnvelope.serializer())
            fun novel(id: Int) = Key("novels/$id/detail.json", NovelDetail.serializer())
            fun chapters(id: Int) = Key("novels/$id/chapters.json", ListSerializer(ChapterListItem.serializer()))

            /** A series' highlights as the reader last saw them, so they are drawn offline too. */
            fun highlights(id: Int) = Key("novels/$id/highlights.json", ListSerializer(Highlight.serializer()))

            /** The Audiobooks screen's first page and Home's "Continue listening", so they show offline. */
            val audiobooks = Key("audiobooks.json", AudiobooksPage.serializer())
            val continueListening = Key("continue-listening.json", ListSerializer(AudiobookSummary.serializer()))

            /** The first page of a Requests Discover list per medium ([list] FIRST_LIST: the medium's first list), so the shelf shows offline. */
            fun requestBrowse(medium: String, list: String?) =
                Key("requests/browse/${safe(medium)}/${safe(list ?: FIRST_LIST)}.json", BrowsePage.serializer())

            const val FIRST_LIST = "_first"
            private fun safe(part: String) = part.filter { it.isLetterOrDigit() || it == '_' || it == '-' }.ifEmpty { "_" }

            /** An audiobook as last fetched (its part URLs may have expired: local files or a fresh fetch play). */
            fun audiobook(id: Int) = Key("audiobooks/$id/detail.json", AudiobookDetail.serializer())
        }
    }

    /** A downloaded audiobook: every part is in [audiobookFilesDir], then the marker is written last. */
    @kotlinx.serialization.Serializable
    data class AudiobookDownload(
        val audiobookId: Int,
        val title: String,
        /** Part index → file name in [audiobookFilesDir]. */
        val files: Map<Int, String>,
        val bytes: Long = 0,
    )

    fun audiobookDir(id: Int) = File(root, "audiobooks/$id")
    fun audiobookFilesDir(id: Int) = File(audiobookDir(id), "files")
    private fun audiobookMarker(id: Int) = File(audiobookDir(id), "download.json")

    val listeningFile: File get() = File(root, ListeningStore.FILE)

    /** The complete download of an audiobook, or null (none, or still downloading). */
    suspend fun audiobookDownload(id: Int): AudiobookDownload? = io {
        read(audiobookMarker(id), AudiobookDownload.serializer())?.takeIf { download ->
            download.files.values.all { File(audiobookFilesDir(id), it).isFile }
        }
    }

    /** Local files for each part of a complete download (empty when there is none). */
    suspend fun audiobookFiles(id: Int): Map<Int, File> =
        audiobookDownload(id)?.files?.mapValues { File(audiobookFilesDir(id), it.value) }.orEmpty()

    suspend fun saveAudiobookDownload(download: AudiobookDownload) = io { write(audiobookMarker(download.audiobookId), AudiobookDownload.serializer(), download) }

    /** Removes the downloaded files (the cached detail stays). */
    suspend fun removeAudiobookDownload(id: Int) = io {
        audiobookMarker(id).delete()
        audiobookFilesDir(id).deleteRecursively()
        Unit
    }

    /** Downloaded audiobooks, largest first. */
    suspend fun audiobookDownloads(): List<AudiobookDownload> = io {
        File(root, "audiobooks").listFiles()?.mapNotNull { it.name.toIntOrNull() }.orEmpty().mapNotNull { id ->
            read(audiobookMarker(id), AudiobookDownload.serializer())?.let { it.copy(bytes = sizeOf(audiobookFilesDir(id))) }
        }.sortedByDescending { it.bytes }
    }

    data class NovelDownloads(val novelId: Int, val name: String, val chapterCount: Int, val bytes: Long)

    init {
        root.mkdirs()
    }

    val writeQueueFile: File get() = File(root, WRITE_QUEUE_FILE)

    // Responses

    suspend fun <T> save(value: T, key: Key<T>) = io { write(File(root, key.path), key.serializer, value) }

    suspend fun <T> load(key: Key<T>): T? = io { read(File(root, key.path), key.serializer) }

    // Chapter content

    private fun contentDir(novelId: Int) = File(root, "novels/$novelId/content")
    private fun textFile(novelId: Int, chapterId: Int) = File(contentDir(novelId), "$chapterId.json")
    fun mangaDir(novelId: Int, chapterId: Int) = File(contentDir(novelId), "$chapterId.manga")
    private fun mangaIndex(novelId: Int, chapterId: Int) = File(mangaDir(novelId, chapterId), "pages.json")

    /** Only real content is worth keeping offline. */
    suspend fun saveChapter(chapter: ChapterContent) = io {
        if (chapter.hasText) write(textFile(chapter.novelId, chapter.id), ChapterContent.serializer(), chapter)
    }

    suspend fun chapter(id: Int, novelId: Int): ChapterContent? = io { read(textFile(novelId, id), ChapterContent.serializer()) }

    /** A downloaded manga chapter: the pages index and the folder holding page images named by page number. */
    suspend fun mangaChapter(id: Int, novelId: Int): MangaPages? = io { read(mangaIndex(novelId, id), MangaPages.serializer()) }

    /** The local image of a page, when downloaded. */
    fun pageFile(novelId: Int, chapterId: Int, page: Int): File = File(mangaDir(novelId, chapterId), page.toString())

    /** Marks a manga chapter complete: call after every page image is in [mangaDir]. */
    suspend fun saveMangaIndex(pages: MangaPages, novelId: Int) = io {
        write(mangaIndex(novelId, pages.chapter.id), MangaPages.serializer(), pages)
    }

    suspend fun hasChapter(id: Int, novelId: Int): Boolean = io {
        textFile(novelId, id).exists() || mangaIndex(novelId, id).exists()
    }

    /**
     * Keeps the cached copy's read state in step with what the user did, so
     * reopening offline restores the right position.
     */
    suspend fun updateChapterState(id: Int, novelId: Int, progress: Int?, read: Boolean?) = io {
        read(textFile(novelId, id), ChapterContent.serializer())?.let { chapter ->
            write(
                textFile(novelId, id), ChapterContent.serializer(),
                chapter.copy(readProgress = progress ?: chapter.readProgress, read = read ?: chapter.read),
            )
        }
        read(mangaIndex(novelId, id), MangaPages.serializer())?.let { pages ->
            val info = pages.chapter.copy(progress = progress ?: pages.chapter.progress, read = read ?: pages.chapter.read)
            write(mangaIndex(novelId, id), MangaPages.serializer(), pages.copy(chapter = info))
        }
    }

    suspend fun cachedChapterIds(novelId: Int): Set<Int> = io { cachedIds(contentDir(novelId)) }

    suspend fun removeChapters(novelId: Int) = io { contentDir(novelId).deleteRecursively() }

    /** Series with offline chapters, largest first. */
    suspend fun downloads(): List<NovelDownloads> = io {
        val ids = File(root, "novels").listFiles()?.mapNotNull { it.name.toIntOrNull() }.orEmpty()
        val library = read(File(root, Key.novels.path), Key.novels.serializer).orEmpty().associate { it.id to it.name }
        ids.mapNotNull { novelId ->
            val dir = contentDir(novelId)
            val count = cachedIds(dir).size
            if (count == 0) return@mapNotNull null
            val name = library[novelId]
                ?: read(File(root, Key.novel(novelId).path), NovelDetail.serializer())?.summary?.name
                ?: "Series $novelId"
            NovelDownloads(novelId, name, count, sizeOf(dir))
        }.sortedByDescending { it.bytes }
    }

    suspend fun totalSize(): Long = io { sizeOf(root) }

    suspend fun offlineChaptersSize(): Long = downloads().sumOf { it.bytes }

    /**
     * Removes everything cached for this (server, user) — lists and chapters —
     * but never the unsynced write queue or the ownership marker.
     */
    suspend fun clearAll() = io {
        root.listFiles()?.filter { it.name !in KEEP }?.forEach { it.deleteRecursively() }
        Unit
    }

    private fun cachedIds(dir: File): Set<Int> = dir.listFiles().orEmpty().mapNotNull { file ->
        when {
            file.isFile && file.name.endsWith(".json") -> file.name.removeSuffix(".json").toIntOrNull()
            file.isDirectory && file.name.endsWith(".manga") && File(file, "pages.json").exists() ->
                file.name.removeSuffix(".manga").toIntOrNull()
            else -> null
        }
    }.toSet()

    private fun <T> write(file: File, serializer: KSerializer<T>, value: T) {
        runCatching {
            file.parentFile?.mkdirs()
            atomicWrite(file, NovarrJson.encodeToString(serializer, value).toByteArray())
        }
    }

    private fun <T> read(file: File, serializer: KSerializer<T>): T? =
        if (!file.exists()) null else runCatching { NovarrJson.decodeFromString(serializer, file.readText()) }.getOrNull()

    private suspend fun <T> io(block: () -> T): T = withContext(Dispatchers.IO) { block() }

    companion object {
        const val WRITE_QUEUE_FILE = "pending-writes.json"

        /** Marks the server directory as belonging to one signed-in user; holds their id. */
        const val OWNER_MARKER_FILE = ".owner-user-id"
        // Listening progress not sent yet is kept like the write queue.
        private val KEEP = setOf(WRITE_QUEUE_FILE, OWNER_MARKER_FILE, ListeningStore.FILE)

        fun sizeOf(dir: File): Long = dir.walkTopDown().filter { it.isFile }.sumOf { it.length() }

        /** The per-server directory used with sign-in off. */
        fun directory(base: File, server: String): File = File(base, ServerUrl.storageKey(server))

        /** A signed-in user's own directory: a sibling of the server's, so two accounts never mix. */
        fun directory(base: File, server: String, userId: Int): File =
            File(base, "${ServerUrl.storageKey(server)}@user-$userId")

        /**
         * Where a session's cache and write queue live (same rule as iOS):
         * - sign-in off (`user == null`): the server directory;
         * - signed in: the user's own directory, except that the first admin
         *   (the owner) to sign in on a server that already has data from
         *   before accounts adopts it (marked with their id), so their offline
         *   chapters and unsent writes carry over. Readers never adopt it: its
         *   queued writes are the owner's.
         */
        fun resolveDirectory(base: File, server: String, user: AuthUser?): File {
            val shared = directory(base, server)
            if (user == null) return shared

            val own = directory(base, server, user.id)
            val marker = File(shared, OWNER_MARKER_FILE)
            val claimedBy = runCatching { marker.readText().trim().toInt() }.getOrNull()

            if (claimedBy == user.id) return shared
            if (own.exists()) return own

            val sharedHasData = shared.listFiles().orEmpty().isNotEmpty()
            if (claimedBy == null && user.role.equals("admin", ignoreCase = true) && sharedHasData) {
                if (runCatching { marker.writeText(user.id.toString()) }.isSuccess) return shared
            }
            return own
        }
    }
}
