package studio.visns.novarr.app

import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.domain.Categories
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.store.WriteKind

/**
 * The multi-select's actions on many series at once (Library, a category,
 * Listen): mark read / unread with Undo, and offline downloads. The work
 * runs on the app's scope, so leaving the screen during the Undo wait still
 * sends the mark.
 */
class BulkActions(private val app: AppModel) {
    /** A mark waiting out its Undo time; [undo] calls it off. */
    class PendingMark internal constructor(val ids: List<Int>, val read: Boolean) {
        internal var job: Job? = null

        /** Sent (or being sent): too late for Undo. */
        @Volatile internal var sending = false
    }

    /**
     * Marks every chapter of [ids] read ([read]) or unread after
     * [Categories.UNDO_MS], unless [undo] comes first. The screen shows the
     * change itself straight away; [revert] puts it back if the server
     * refuses, and [onDone] gets the message for a snackbar (null: fine).
     * Offline (or on a server without the bulk call) each series is queued
     * on this device instead and sent later, as the series page does.
     */
    fun markRead(ids: List<Int>, read: Boolean, revert: () -> Unit, onDone: (String?) -> Unit): PendingMark {
        val mark = PendingMark(ids.distinct(), read)
        mark.job = app.scope.launch {
            delay(Categories.UNDO_MS)
            mark.sending = true
            onDone(send(mark.ids, read, revert))
        }
        return mark
    }

    /** True when the mark was called off in time (nothing was sent). */
    fun undo(mark: PendingMark): Boolean {
        if (mark.sending) return false
        mark.job?.cancel()
        return true
    }

    /** Sends a waiting mark now (another mark came along, so the first one isn't kept waiting behind it). */
    fun flush(mark: PendingMark, revert: () -> Unit, onDone: (String?) -> Unit) {
        if (mark.sending) return
        mark.job?.cancel()
        mark.sending = true
        app.scope.launch { onDone(send(mark.ids, mark.read, revert)) }
    }

    private suspend fun send(ids: List<Int>, read: Boolean, revert: () -> Unit): String? {
        val session = app.active ?: return null
        return try {
            ids.chunked(Categories.BULK_MAX).forEach { session.client.bulkReadNovels(it, read) }
            app.noteSuccess()
            // Every screen showing read state reloads (Home, Library, the series pages).
            app.libraryChanged()
            null
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            val error = ApiError.from(e)
            app.noteFailure(error)
            val older = error is ApiError.Http && (error.status == 404 || error.status == 405)
            if (error.isConnectivity || older) {
                // Kept on this device and sent when the server is reachable: one "mark all" per series.
                ids.forEach { id -> app.record(WriteKind.BulkRead(BulkReadRequest.all(id, read)), id) }
                if (older) null else "You're offline: saved on this device, sent once the server is reachable."
            } else {
                revert()
                "Couldn't mark them as ${if (read) "read" else "unread"}. ${error.userMessage}"
            }
        }
    }

    /**
     * Saves the unread chapters of each series for offline reading (what
     * the server has and this device doesn't yet); a message for the snackbar.
     */
    suspend fun download(novels: List<NovelSummary>): String {
        val session = app.active ?: return "Not connected."
        var started = 0
        var failed = 0
        for (novel in novels) {
            val chapters = app.load(OfflineStore.Key.chapters(novel.id)) { it.chapters(novel.id) }.value
            if (chapters == null) {
                failed++
                continue
            }
            val cached = session.store.cachedChapterIds(novel.id)
            val ids = chapters.filter { it.downloaded && !it.read && it.id !in cached }.map { it.id }
            if (ids.isNotEmpty()) {
                app.downloads.start(novel.id, ids, manga = novel.isPaged)
                started++
            }
        }
        return when {
            started == 0 && failed > 0 -> "Couldn't fetch the chapter lists. Try again when the server is reachable."
            started == 0 -> "Their unread chapters are already on this device."
            failed > 0 -> "Downloading ${count(started)}; ${count(failed)} couldn't be checked."
            else -> "Downloading the unread chapters of ${count(started)}."
        }
    }

    /** Deletes what [ids] have on this device. */
    suspend fun removeDownloads(ids: List<Int>): String {
        val store = app.active?.store ?: return "Not connected."
        ids.forEach { app.downloads.remove(it, store) }
        return "Removed the downloads of ${count(ids.size)}."
    }

    /** Listen: each audiobook downloaded (Wi-Fi only if Settings says so). */
    fun downloadAudiobooks(books: List<AudiobookSummary>): String {
        books.forEach { app.audiobookDownloads.start(it.id, it.title) }
        return if (books.size == 1) "Downloading 1 audiobook." else "Downloading ${books.size} audiobooks."
    }

    suspend fun removeAudiobookDownloads(books: List<AudiobookSummary>): String {
        books.forEach { app.audiobookDownloads.remove(it.id) }
        return if (books.size == 1) "Removed 1 audiobook download." else "Removed ${books.size} audiobook downloads."
    }

    private fun count(n: Int) = if (n == 1) "1 series" else "$n series"
}
