package studio.visns.novarr.app

import java.io.File
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Semaphore
import kotlinx.coroutines.sync.withPermit
import kotlinx.coroutines.withContext
import okhttp3.Request
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.MangaPages
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.store.atomicWrite

/**
 * Fetches chapters into the offline store, a few at a time, per series.
 * App-wide so a download keeps going when the user leaves the series page.
 * Text chapters are saved as JSON; manga chapters as their page images
 * (fetched from the pre-signed URLs, without headers) plus the pages index.
 */
class DownloadManager(private val app: AppModel) {
    data class Progress(val total: Int, val done: Int = 0, val failed: Int = 0) {
        val fraction: Float get() = if (total == 0) 1f else (done + failed).toFloat() / total
    }

    private val _jobs = MutableStateFlow<Map<Int, Progress>>(emptyMap())
    val jobs: StateFlow<Map<Int, Progress>> = _jobs.asStateFlow()

    /** Bumped whenever offline content changes, so screens re-read what's cached. */
    private val _revision = MutableStateFlow(0)
    val revision: StateFlow<Int> = _revision.asStateFlow()

    private val _messages = MutableStateFlow<Map<Int, String>>(emptyMap())
    val messages: StateFlow<Map<Int, String>> = _messages.asStateFlow()

    private val tasks = mutableMapOf<Int, Job>()

    fun start(novelId: Int, chapterIds: List<Int>, manga: Boolean) {
        val session = app.active ?: return
        if (tasks.containsKey(novelId)) return
        _messages.update { it - novelId }
        if (chapterIds.isEmpty()) {
            _messages.update { it + (novelId to "Everything is already on this device.") }
            return
        }
        _jobs.update { it + (novelId to Progress(chapterIds.size)) }
        tasks[novelId] = app.scope.launch {
            val gate = Semaphore(CONCURRENCY)
            try {
                coroutineScope {
                    chapterIds.map { id ->
                        async {
                            val ok = gate.withPermit {
                                fetch(session.client, session.store, novelId, id, manga)
                            }
                            record(novelId, ok)
                        }
                    }.awaitAll()
                }
                finish(novelId, cancelled = false)
            } catch (e: kotlinx.coroutines.CancellationException) {
                finish(novelId, cancelled = true)
                throw e
            }
        }
    }

    private suspend fun fetch(client: ApiClient, store: OfflineStore, novelId: Int, chapterId: Int, manga: Boolean): Boolean = try {
        if (manga) {
            saveManga(client.mangaPages(chapterId), novelId, store)
        } else {
            val chapter = client.chapter(chapterId)
            store.saveChapter(chapter.copy(novelId = if (chapter.novelId == 0) novelId else chapter.novelId))
            chapter.hasText
        }
    } catch (e: Exception) {
        e.rethrowIfCancellation()
        false
    }

    /** Every page image first, then the index that marks the chapter complete. */
    suspend fun saveManga(pages: MangaPages, novelId: Int, store: OfflineStore): Boolean {
        if (pages.pages.isEmpty()) return false
        for (page in pages.pages) {
            val target = store.pageFile(novelId, pages.chapter.id, page.page)
            if (target.exists() && target.length() > 0) continue
            if (!downloadImage(page.url, target)) return false
        }
        store.saveMangaIndex(pages, novelId)
        return true
    }

    private suspend fun downloadImage(url: String, target: File): Boolean = withContext(Dispatchers.IO) {
        runCatching {
            val call = app.imageHttp.newCall(Request.Builder().url(url).build())
            call.timeout().timeout(60, TimeUnit.SECONDS)
            call.execute().use { response ->
                if (!response.isSuccessful) return@runCatching false
                ensureActive()
                target.parentFile?.mkdirs()
                atomicWrite(target, response.body.bytes())
                true
            }
        }.getOrDefault(false)
    }

    fun cancel(novelId: Int) {
        tasks[novelId]?.cancel()
    }

    /** Stops every download, e.g. when the account or server changes. */
    fun cancelAll() {
        tasks.values.forEach { it.cancel() }
    }

    suspend fun remove(novelId: Int, store: OfflineStore) {
        cancel(novelId)
        store.removeChapters(novelId)
        _messages.update { it - novelId }
        contentChanged()
    }

    fun contentChanged() = _revision.update { it + 1 }

    private fun record(novelId: Int, ok: Boolean) {
        _jobs.update { jobs ->
            val job = jobs[novelId] ?: return@update jobs
            val next = if (ok) job.copy(done = job.done + 1) else job.copy(failed = job.failed + 1)
            // Refresh "available offline" counts as we go, but not on every chapter.
            if (ok && next.done % 25 == 0) contentChanged()
            jobs + (novelId to next)
        }
    }

    private fun finish(novelId: Int, cancelled: Boolean) {
        _jobs.value[novelId]?.let { job ->
            val message = when {
                cancelled -> "Download cancelled after ${job.done} chapters."
                job.failed > 0 -> "Saved ${job.done} chapters; ${job.failed} couldn't be fetched."
                else -> "Saved ${job.done} chapters for offline reading."
            }
            _messages.update { it + (novelId to message) }
        }
        _jobs.update { it - novelId }
        tasks.remove(novelId)
        contentChanged()
    }

    private companion object {
        const val CONCURRENCY = 4
    }
}
