package studio.visns.novarr.ui.novel

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlin.math.floor
import android.os.SystemClock
import kotlinx.coroutines.Job
import kotlinx.coroutines.async
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.domain.SendToKindle
import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.api.ChapterListItem
import studio.visns.novarr.api.Highlight
import studio.visns.novarr.api.NovelDetail
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.LibraryFilter
import studio.visns.novarr.domain.ResumeRule
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.store.PendingWrite
import studio.visns.novarr.store.WriteKind

/** A series page: detail, chapter list, read marks and offline downloads (the iOS NovelModel). */
class NovelViewModel(private val app: AppModel, val novelId: Int) : ViewModel() {
    var detail by mutableStateOf<NovelDetail?>(null)
        private set
    var chapters by mutableStateOf<List<ChapterListItem>>(emptyList())
        private set
    var cachedIds by mutableStateOf<Set<Int>>(emptySet())
        private set
    var failure by mutableStateOf<String?>(null)
        private set
    var loaded by mutableStateOf(false)
        private set
    var fromCache by mutableStateOf(false)
        private set
    var refreshing by mutableStateOf(false)
        private set

    /** This series' writes not sent yet, oldest first (the local resume rule). */
    var unsentWrites by mutableStateOf<List<PendingWrite>>(emptyList())
        private set

    /** The newest few highlights here (empty when none, or the server has no highlights API). */
    var highlights by mutableStateOf<List<Highlight>>(emptyList())
        private set
    var hasMoreHighlights by mutableStateOf(false)
        private set
    var chapterSearch by mutableStateOf("")

    init {
        // Seed from the library listing so the header shows at once.
        viewModelScope.launch {
            if (detail == null) app.cached(OfflineStore.Key.novels)?.firstOrNull { it.id == novelId }?.let { detail = NovelDetail(it) }
        }
    }

    val summary: NovelSummary? get() = detail?.summary
    /** Page images (manga or a comic): the page reader, page downloads, no highlights or ePub. */
    val isPaged: Boolean get() = summary?.isPaged == true
    val isBook: Boolean get() = summary?.isBook == true

    val visibleChapters: List<ChapterListItem>
        get() {
            val query = chapterSearch.trim()
            if (query.isEmpty()) return chapters
            // A bare number matches the chapter number exactly, as well as the label text.
            val number = query.toDoubleOrNull()
            return chapters.filter { (number != null && it.chapter == number) || LibraryFilter.matches(it.title, query) }
        }

    val firstUnread: ChapterListItem?
        get() = chapters.firstOrNull { !it.read && it.downloaded && !it.note } ?: chapters.firstOrNull { !it.read }

    /**
     * Where "Continue" opens: the server's choice plus this device's unsent
     * reading, by the rule every device shares ([ResumeRule]). With nothing
     * unsent, the server's `resume_chapter_id` as is.
     */
    val resumeChapterId: Int?
        get() {
            val server = summary?.resumeChapterId
            return if (unsentWrites.isEmpty()) server else ResumeRule.resolve(chapters, server, unsentWrites)
        }

    /** "Continue reading" rather than "Start reading": something is read or part-read, here or on the server. */
    val hasStarted: Boolean
        get() = ResumeRule.hasStarted(chapters, summary?.chaptersRead ?: 0, unsentWrites)

    val readCount: Int get() = if (chapters.isEmpty()) summary?.chaptersRead ?: 0 else chapters.count { it.read }

    fun load(pull: Boolean = false) = viewModelScope.launch {
        if (pull) refreshing = true
        if (!loaded) {
            app.cached(OfflineStore.Key.novel(novelId))?.let { detail = it }
            app.cached(OfflineStore.Key.chapters(novelId))?.let { chapters = it }
        }
        refreshCachedIds()
        refreshUnsentWrites()
        val d = async { app.load(OfflineStore.Key.novel(novelId)) { it.novel(novelId) } }
        val c = async { app.load(OfflineStore.Key.chapters(novelId)) { it.chapters(novelId) } }
        val (dr, cr) = d.await() to c.await()
        dr.value?.let { detail = it }
        cr.value?.let { chapters = it }
        fromCache = dr.error != null || cr.error != null
        failure = if (detail == null && chapters.isEmpty()) (dr.error ?: cr.error)?.userMessage ?: "Couldn't load this series." else null
        refreshUnsentWrites()
        loaded = true
        refreshing = false
        if (dr.error == null) loadHighlights()
    }

    /** Best effort: a server without highlights simply shows no section. */
    private suspend fun loadHighlights() {
        if (isPaged) return
        val client = app.active?.client ?: return
        runCatching { client.highlights(novelId, 1, HIGHLIGHT_PREVIEW) }.getOrNull()?.let {
            highlights = it.highlights
            hasMoreHighlights = it.hasMore
        }
    }

    suspend fun refreshUnsentWrites() {
        unsentWrites = app.unsentWrites(novelId)
    }

    suspend fun refreshCachedIds() {
        cachedIds = app.active?.store?.cachedChapterIds(novelId).orEmpty()
    }

    // Read state

    fun setRead(chapter: ChapterListItem, read: Boolean) = viewModelScope.launch {
        apply(read) { it.id == chapter.id }
        persistLocalState()
        app.record(WriteKind.SetRead(chapter.id, read), novelId)
        app.active?.store?.updateChapterState(chapter.id, novelId, if (read) 100 else 0, read)
        refreshDetailIfOnline()
    }

    fun bulk(request: BulkReadRequest) = viewModelScope.launch {
        when (request.scope) {
            BulkReadRequest.ALL -> apply(request.read) { true }
            BulkReadRequest.UP_TO, BulkReadRequest.FROM -> {
                val index = chapters.indexOfFirst { it.id == request.anchorId }
                if (index >= 0) {
                    val range = if (request.scope == BulkReadRequest.UP_TO) 0..index else index..chapters.lastIndex
                    val ids = chapters.slice(range).map { it.id }.toSet()
                    apply(request.read) { it.id in ids }
                }
            }
            else -> {
                val ids = request.ids.orEmpty().toSet()
                apply(request.read) { it.id in ids }
            }
        }
        persistLocalState()
        app.record(WriteKind.BulkRead(request), novelId)
        refreshDetailIfOnline()
    }

    private fun apply(read: Boolean, predicate: (ChapterListItem) -> Boolean) {
        chapters = chapters.map { if (predicate(it)) it.copy(read = read, readProgress = if (read) 100 else null) else it }
        // Keep the header counts honest until the server's numbers arrive.
        detail?.let { d ->
            val count = chapters.count { it.read }
            val s = d.summary
            val finished = s.chaptersTotal > 0 && count >= s.chaptersTotal
            detail = d.copy(
                summary = s.copy(
                    chaptersRead = count,
                    readPercent = if (s.chaptersTotal > 0) floor(count * 100.0 / s.chaptersTotal).toInt() else s.readPercent,
                    finished = finished,
                    chaptersNew = if (finished || !read) 0 else s.chaptersNew,
                ),
            )
        }
    }

    private suspend fun persistLocalState() {
        val store = app.active?.store ?: return
        store.save(chapters, OfflineStore.Key.chapters(novelId))
        detail?.let { store.save(it, OfflineStore.Key.novel(novelId)) }
    }

    private suspend fun refreshDetailIfOnline() {
        if (!app.isReachable.value || app.pendingWrites.value > 0) return
        val result = app.load(OfflineStore.Key.novel(novelId)) { it.novel(novelId) }
        if (!result.fromCache) result.value?.let { detail = it }
    }

    // Offline downloads

    fun download(unreadOnly: Boolean) = viewModelScope.launch {
        refreshCachedIds()
        refreshUnsentWrites()
        // The chapter Continue opens is wanted even when it is marked read
        // but not finished (the web reader marks read on open).
        val resume = chapters.firstOrNull { it.id == resumeChapterId }
            ?.takeIf { ResumeRule.isUnfinished(it.read, it.readProgress) }?.id
        val ids = chapters.filter { it.downloaded && it.id !in cachedIds && (!unreadOnly || !it.read || it.id == resume) }.map { it.id }
        app.downloads.start(novelId, ids, manga = isPaged)
    }

    fun removeDownloads() = viewModelScope.launch {
        val store = app.active?.store ?: return@launch
        app.downloads.remove(novelId, store)
        refreshCachedIds()
    }

    // Send to my Kindle / email

    /** The send dialog's state; null when it is closed. */
    var send by mutableStateOf<SendToKindle.UiState?>(null)
        private set
    private var sendJob: Job? = null

    /** POST then poll GET until sent / failed (or [SendToKindle.GIVE_UP_MS]); never queued offline. */
    fun sendToKindle() {
        val session = app.active ?: return
        if (send is SendToKindle.UiState.Running) return
        sendJob?.cancel()
        send = SendToKindle.UiState.Running(null)
        sendJob = viewModelScope.launch {
            val client = session.client
            val started = try {
                client.startSend(novelId).also { app.noteSuccess() }
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                app.noteFailure(ApiError.from(e))
                send = SendToKindle.refusal(e, client.baseUrl)
                return@launch
            }
            send = SendToKindle.UiState.Running(started.to)
            val began = SystemClock.elapsedRealtime()
            var state = started
            while (isActive) {
                val elapsed = SystemClock.elapsedRealtime() - began
                // The 202 answer itself may already be the end (a send that was running).
                if (SendToKindle.phase(state) != SendToKindle.Phase.RUNNING || elapsed >= SendToKindle.GIVE_UP_MS) {
                    send = SendToKindle.next(state, elapsed)
                    break
                }
                delay(SendToKindle.POLL_MS)
                state = try {
                    client.sendState(novelId)
                } catch (e: Exception) {
                    e.rethrowIfCancellation()
                    val error = ApiError.from(e)
                    // Offline for a moment: the send goes on on the server; keep asking.
                    if (error.isConnectivity) continue
                    send = SendToKindle.UiState.Failed(Requests.message(error))
                    break
                }
            }
        }
    }

    fun dismissSend() {
        sendJob?.cancel()
        send = null
    }

    companion object {
        const val HIGHLIGHT_PREVIEW = 3
    }
}
