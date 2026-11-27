package studio.visns.novarr.playback

import android.content.Context
import android.os.Bundle
import android.os.SystemClock
import androidx.annotation.OptIn
import androidx.media3.common.MediaItem
import androidx.media3.common.MediaMetadata
import androidx.media3.common.util.UnstableApi
import androidx.media3.session.MediaConstants
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.app.Session
import studio.visns.novarr.domain.AutoBrowse
import studio.visns.novarr.domain.AutoSearch
import studio.visns.novarr.domain.ListeningSync
import studio.visns.novarr.store.OfflineStore

/**
 * Gathers what the car's browse tree shows ([AutoBrowse.Catalog]) from the
 * server — quickly, with short timeouts, since a car's network comes and
 * goes — and falls back to the offline cache, so Downloaded (and whatever
 * was seen last) always answers. Progress is the newer of this device's and
 * the server's. Runs on the main thread (AppModel's scope).
 */
@OptIn(UnstableApi::class)
class AutoLibrary(private val context: Context, private val app: AppModel) {
    private var catalog: AutoBrowse.Catalog? = null
    private var catalogSession: String? = null
    private var catalogAt = 0L

    /** "All audiobooks", as far as fetched: the server's pages, for this session. */
    private val all = mutableListOf<AutoBrowse.Book>()
    private var allSession: String? = null
    private var allNextPage = 1
    private var allMore = true

    /** The last search's results, for onGetSearchResult. */
    private var lastSearch: Pair<String, List<AutoBrowse.Book>>? = null

    /** Forgets what was gathered (a download finished, a list changed): the next browse asks again. */
    fun invalidate() {
        catalog = null
    }

    suspend fun catalog(): AutoBrowse.Catalog {
        val session = app.active ?: return AutoBrowse.Catalog()
        val cached = catalog
        if (cached != null && catalogSession == session.id && SystemClock.elapsedRealtime() - catalogAt < CATALOG_TTL_MS) return cached
        val built = build(session)
        if (app.active?.id == session.id) {
            catalog = built
            catalogSession = session.id
            catalogAt = SystemClock.elapsedRealtime()
        }
        return built
    }

    private suspend fun build(session: Session.Active): AutoBrowse.Catalog {
        if (!app.audiobooksOffered.value && app.metaKnown) return AutoBrowse.Catalog()
        val store = session.store
        val downloads = store.audiobookDownloads()
        val downloadedIds = downloads.filter { d -> store.audiobookFiles(d.audiobookId).size == d.files.size && d.files.isNotEmpty() }
            .map { it.audiobookId }.toSet()

        suspend fun book(summary: AudiobookSummary): AutoBrowse.Book {
            val resolved = ListeningSync.resolve(app.listening.localRecord(summary.id), summary.progress)
            return AutoBrowse.book(summary, resolved.positionMs, resolved.finished, summary.id in downloadedIds)
        }

        // Continue listening: the server's, else the saved copy.
        val continueSummaries = quick { it.continueListening() }
            ?.also { store.save(it, OfflineStore.Key.continueListening) }
            ?: store.load(OfflineStore.Key.continueListening).orEmpty()

        // What we know about each audiobook (by id): details of downloads, then lists' summaries.
        val known = LinkedHashMap<Int, AutoBrowse.Book>()
        val downloaded = downloads.mapNotNull { download ->
            val detail = store.load(OfflineStore.Key.audiobook(download.audiobookId))
            val summary = detail?.summary ?: AudiobookSummary(id = download.audiobookId, title = download.title)
            book(summary).copy(downloaded = download.audiobookId in downloadedIds)
        }.filter { it.downloaded }
        downloaded.forEach { known[it.audiobookId] = it }
        val continuing = continueSummaries.map { summary -> book(summary).also { known.putIfAbsent(it.audiobookId, it) } }
        store.load(OfflineStore.Key.audiobooks)?.audiobooks?.forEach { summary -> if (summary.id !in known) known[summary.id] = book(summary) }

        // Lists: the shared model's (fresh if this session fetched them), else the saved copy.
        val listsOn = app.listsOffered.value
        var lists: List<ReadingList> = emptyList()
        if (listsOn) {
            if (!app.lists.loaded.value) withTimeoutOrNull(QUICK_MS) { app.lists.refresh() }
            lists = app.lists.lists.ifEmpty { store.load(OfflineStore.Key.lists).orEmpty() }
        }
        val novels: Map<Int, NovelSummary> = if (lists.isNotEmpty()) {
            (store.load(OfflineStore.Key.novels) ?: quick { it.novels() }?.also { store.save(it, OfflineStore.Key.novels) }).orEmpty().associateBy { it.id }
        } else {
            emptyMap()
        }
        fun withProgress(books: List<AutoBrowse.Book>) = books.map { b -> known[b.audiobookId] ?: b.copy(downloaded = b.audiobookId in downloadedIds) }

        val favouritesList = lists.firstOrNull { it.isFavourites }?.takeIf { app.favouritesOffered.value }
        val wantList = lists.firstOrNull { it.isWantToRead }
        val catalog = AutoBrowse.Catalog(
            continueListening = continuing,
            downloaded = downloaded,
            favourites = favouritesList?.let { withProgress(AutoBrowse.booksFor(it.novelIds, novels, known)) },
            wantToRead = wantList?.let { withProgress(AutoBrowse.booksFor(it.novelIds, novels, known)) },
            lists = AutoBrowse.shelves(lists, novels, known).map { it.copy(books = withProgress(it.books)) },
        )
        // Covers of what plays offline are kept ahead, so the car shows them without a network.
        app.scope.launch { Artwork.prefetch(context, downloaded.map { it.coverUrl } + continuing.take(6).map { it.coverUrl }) }
        return catalog
    }

    /** Children of a folder (paged as Media3 asks; page from 0). Null: no such folder. */
    suspend fun children(parentId: String, page: Int, pageSize: Int): List<MediaItem>? {
        if (parentId == AutoBrowse.ALL) return allPage(page, pageSize).map { toItem(AutoBrowse.bookNode(it)) }
        if (parentId == AutoBrowse.RECENT_ROOT) return listOfNotNull(recent()).map(::toItem)
        val nodes = AutoBrowse.children(parentId, catalog()) ?: return null
        return AutoBrowse.page(nodes, page, pageSize).map(::toItem)
    }

    /** One item by id (a folder or a book). */
    suspend fun item(mediaId: String): MediaItem? {
        AutoBrowse.parseBook(mediaId)?.let { id -> return findBook(id)?.let { item(AutoBrowse.bookNode(it)) } }
        if (mediaId == AutoBrowse.ROOT || mediaId == AutoBrowse.RECENT_ROOT) return item(AutoBrowse.rootNode(mediaId == AutoBrowse.RECENT_ROOT))
        val catalog = catalog()
        val folder = (AutoBrowse.root(catalog) + AutoBrowse.browse(catalog)).firstOrNull { it.id == mediaId } ?: return null
        return item(folder)
    }

    /** The system's "recent" media: the last audiobook. */
    private suspend fun recent(): AutoBrowse.Node? {
        val now = app.listening.nowPlaying.value
        val id = now?.id ?: app.listening.lastAudiobookId() ?: return null
        return findBook(id)?.let(AutoBrowse::bookNode)
    }

    private suspend fun findBook(id: Int): AutoBrowse.Book? {
        val catalog = catalog()
        (catalog.continueListening + catalog.downloaded + catalog.favourites.orEmpty() + catalog.wantToRead.orEmpty() + catalog.lists.flatMap { it.books } + all)
            .firstOrNull { it.audiobookId == id }?.let { return it }
        val session = app.active ?: return null
        val summary = session.store.load(OfflineStore.Key.audiobook(id))?.summary ?: return null
        val resolved = ListeningSync.resolve(app.listening.localRecord(id), summary.progress)
        return AutoBrowse.book(summary, resolved.positionMs, resolved.finished, downloaded = false)
    }

    private suspend fun allPage(page: Int, pageSize: Int): List<AutoBrowse.Book> {
        val session = app.active ?: return emptyList()
        if (allSession != session.id) {
            all.clear()
            allSession = session.id
            allNextPage = 1
            allMore = true
        }
        val wanted = if (pageSize > 0) (page + 1).toLong() * pageSize else Long.MAX_VALUE
        val known = catalog().let { c -> (c.downloaded + c.continueListening).associateBy { it.audiobookId } }
        while (allMore && all.size < wanted && all.size < ALL_MAX) {
            val result = quick { it.audiobooks(page = allNextPage) }
            if (result == null) {
                // Offline: the saved first page (and nothing more) when nothing came yet.
                if (all.isEmpty()) {
                    session.store.load(OfflineStore.Key.audiobooks)?.audiobooks?.forEach { all += toBook(it, known) }
                }
                break
            }
            if (allNextPage == 1) session.store.save(result, OfflineStore.Key.audiobooks)
            result.audiobooks.forEach { summary -> if (all.none { it.audiobookId == summary.id }) all += toBook(summary, known) }
            allMore = result.hasMore && result.audiobooks.isNotEmpty()
            allNextPage++
        }
        return AutoBrowse.page(all.toList(), page, pageSize)
    }

    private suspend fun toBook(summary: AudiobookSummary, known: Map<Int, AutoBrowse.Book>): AutoBrowse.Book {
        known[summary.id]?.let { return it }
        val resolved = ListeningSync.resolve(app.listening.localRecord(summary.id), summary.progress)
        return AutoBrowse.book(summary, resolved.positionMs, resolved.finished, downloaded = false)
    }

    // Search ("Hey Google, play … on Novarr", the car's search box)

    /** The best matches for [query], best first: what this device knows, then the server's own search. */
    suspend fun search(query: String): List<AutoBrowse.Book> {
        lastSearch?.takeIf { it.first == query }?.let { return it.second }
        val catalog = catalog()
        val local = catalog.continueListening + catalog.downloaded + catalog.favourites.orEmpty() +
            catalog.wantToRead.orEmpty() + catalog.lists.flatMap { it.books } + all
        val known = local.associateBy { it.audiobookId }
        val remote = quick { it.audiobooks(search = query) }?.audiobooks.orEmpty().map { toBook(it, known) }
        val ranked = AutoSearch.match(query, local + remote)
        // The server may match on more than titles and names (a description, a series): its answers follow.
        val results = (ranked + remote).distinctBy { it.audiobookId }
        lastSearch = query to results
        return results
    }

    suspend fun searchItems(query: String, page: Int, pageSize: Int): List<MediaItem> =
        AutoBrowse.page(search(query), page, pageSize).map { item(AutoBrowse.bookNode(it)) }

    /** A server call that gives up quickly (null: offline, slow or refused). */
    private suspend fun <T> quick(call: suspend (studio.visns.novarr.api.ApiClient) -> T): T? {
        val session = app.active ?: return null
        return try {
            withTimeoutOrNull(QUICK_MS) { call(session.client) }
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            null
        }
    }

    /** A tree node as a Media3 item, with the car's hints (layout, progress, downloaded). */
    fun item(node: AutoBrowse.Node): MediaItem = toItem(node)

    private fun toItem(node: AutoBrowse.Node): MediaItem {
        val extras = Bundle()
        node.browsableStyle?.let { extras.putInt(MediaConstants.EXTRAS_KEY_CONTENT_STYLE_BROWSABLE, style(it)) }
        node.playableStyle?.let { extras.putInt(MediaConstants.EXTRAS_KEY_CONTENT_STYLE_PLAYABLE, style(it)) }
        node.completion?.let {
            extras.putInt(
                MediaConstants.EXTRAS_KEY_COMPLETION_STATUS,
                when (it) {
                    AutoBrowse.Completion.NOT_PLAYED -> MediaConstants.EXTRAS_VALUE_COMPLETION_STATUS_NOT_PLAYED
                    AutoBrowse.Completion.PARTLY_PLAYED -> MediaConstants.EXTRAS_VALUE_COMPLETION_STATUS_PARTIALLY_PLAYED
                    AutoBrowse.Completion.FULLY_PLAYED -> MediaConstants.EXTRAS_VALUE_COMPLETION_STATUS_FULLY_PLAYED
                },
            )
        }
        node.fraction?.takeIf { node.completion == AutoBrowse.Completion.PARTLY_PLAYED }?.let {
            extras.putDouble(MediaConstants.EXTRAS_KEY_COMPLETION_PERCENTAGE, it)
        }
        if (node.downloaded) extras.putLong(MediaConstants.EXTRAS_KEY_DOWNLOAD_STATUS, MediaConstants.EXTRAS_VALUE_STATUS_DOWNLOADED)
        val metadata = MediaMetadata.Builder()
            .setTitle(node.title)
            .setDisplayTitle(node.title)
            .setSubtitle(node.subtitle)
            .setArtist(if (node.playable) node.subtitle else null)
            .setArtworkUri(Artwork.uri(node.artUrl))
            .setIsBrowsable(node.browsable)
            .setIsPlayable(node.playable)
            .setMediaType(if (node.playable) MediaMetadata.MEDIA_TYPE_AUDIO_BOOK else MediaMetadata.MEDIA_TYPE_FOLDER_AUDIO_BOOKS)
            .setExtras(extras)
            .build()
        return MediaItem.Builder().setMediaId(node.id).setMediaMetadata(metadata).build()
    }

    private fun style(style: AutoBrowse.Style) = when (style) {
        AutoBrowse.Style.LIST -> MediaConstants.EXTRAS_VALUE_CONTENT_STYLE_LIST_ITEM
        AutoBrowse.Style.GRID -> MediaConstants.EXTRAS_VALUE_CONTENT_STYLE_GRID_ITEM
    }

    companion object {
        /** A browse reuses what was gathered this long (Auto asks for each tab in turn). */
        const val CATALOG_TTL_MS = 30_000L

        /** How long a server call may take before the cache answers instead. */
        const val QUICK_MS = 5_000L

        /** "All audiobooks" stops here (a car list is not for scrolling thousands). */
        const val ALL_MAX = 500
    }
}
