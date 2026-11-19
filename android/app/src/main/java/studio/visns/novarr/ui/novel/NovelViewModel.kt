package studio.visns.novarr.ui.novel

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlin.math.floor
import kotlinx.coroutines.async
import kotlinx.coroutines.launch
import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.api.ChapterListItem
import studio.visns.novarr.api.Highlight
import studio.visns.novarr.api.NovelDetail
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.LibraryFilter
import studio.visns.novarr.store.OfflineStore
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
    val isManga: Boolean get() = summary?.isManga == true

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

    val readCount: Int get() = if (chapters.isEmpty()) summary?.chaptersRead ?: 0 else chapters.count { it.read }

    fun load(pull: Boolean = false) = viewModelScope.launch {
        if (pull) refreshing = true
        if (!loaded) {
            app.cached(OfflineStore.Key.novel(novelId))?.let { detail = it }
            app.cached(OfflineStore.Key.chapters(novelId))?.let { chapters = it }
        }
        refreshCachedIds()
        val d = async { app.load(OfflineStore.Key.novel(novelId)) { it.novel(novelId) } }
        val c = async { app.load(OfflineStore.Key.chapters(novelId)) { it.chapters(novelId) } }
        val (dr, cr) = d.await() to c.await()
        dr.value?.let { detail = it }
        cr.value?.let { chapters = it }
        fromCache = dr.error != null || cr.error != null
        failure = if (detail == null && chapters.isEmpty()) (dr.error ?: cr.error)?.userMessage ?: "Couldn't load this series." else null
        loaded = true
        refreshing = false
        if (dr.error == null) loadHighlights()
    }

    /** Best effort: a server without highlights simply shows no section. */
    private suspend fun loadHighlights() {
        if (isManga) return
        val client = app.active?.client ?: return
        runCatching { client.highlights(novelId, 1, HIGHLIGHT_PREVIEW) }.getOrNull()?.let {
            highlights = it.highlights
            hasMoreHighlights = it.hasMore
        }
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
        val ids = chapters.filter { it.downloaded && it.id !in cachedIds && (!unreadOnly || !it.read) }.map { it.id }
        app.downloads.start(novelId, ids, manga = isManga)
    }

    fun removeDownloads() = viewModelScope.launch {
        val store = app.active?.store ?: return@launch
        app.downloads.remove(novelId, store)
        refreshCachedIds()
    }

    companion object {
        const val HIGHLIGHT_PREVIEW = 3
    }
}
