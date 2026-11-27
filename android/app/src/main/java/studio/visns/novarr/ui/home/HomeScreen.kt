package studio.visns.novarr.ui.home

import studio.visns.novarr.ui.theme.LocalEink
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.outlined.MenuBook
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.CollectionsBookmark
import androidx.compose.material.icons.outlined.CreateNewFolder
import androidx.compose.material.icons.outlined.Folder
import androidx.compose.material.icons.outlined.Headphones
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.runtime.remember
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.input.ImeAction
import coil3.compose.AsyncImage
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.domain.AutoSearch
import studio.visns.novarr.domain.HomeShelves
import studio.visns.novarr.store.HomeLayout
import studio.visns.novarr.ui.FolderRoute
import studio.visns.novarr.ui.LibraryRoute
import studio.visns.novarr.ui.audiobooks.caption
import studio.visns.novarr.ui.lists.LibraryNovels
import studio.visns.novarr.ui.lists.NewFolderDialog
import studio.visns.novarr.ui.lists.seriesCount
import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.ContinueItem
import studio.visns.novarr.api.HomeFeed
import studio.visns.novarr.api.NewChaptersItem
import studio.visns.novarr.api.WantToReadItem
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.NewChapters
import studio.visns.novarr.domain.ReadingLists
import studio.visns.novarr.domain.ResumeRule
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.ui.AudiobooksRoute
import studio.visns.novarr.ui.NovelRoute
import studio.visns.novarr.ui.PlayerRoute
import studio.visns.novarr.ui.audiobooks.ContinueListeningRow
import studio.visns.novarr.ui.ReaderRoute
import studio.visns.novarr.ui.RequestsRoute
import studio.visns.novarr.ui.StatsRoute
import studio.visns.novarr.ui.stats.HomeStatsCard
import studio.visns.novarr.ui.stats.StatsCardViewModel
import studio.visns.novarr.ui.WantToReadRoute
import studio.visns.novarr.ui.components.RequestsIcon
import studio.visns.novarr.ui.components.isWide
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.Cover
import studio.visns.novarr.ui.components.LoadFailed
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.OfflinePill
import studio.visns.novarr.ui.components.OriginBadge
import studio.visns.novarr.ui.components.ProgressLine
import studio.visns.novarr.ui.components.readableWidth
import studio.visns.novarr.ui.components.UpdateBanner
import studio.visns.novarr.ui.components.rememberNotificationPermissionRequest

class HomeViewModel(private val app: AppModel) : ViewModel() {
    var feed by mutableStateOf<HomeFeed?>(null)
        private set
    var failure by mutableStateOf<String?>(null)
        private set
    var fromCache by mutableStateOf(false)
        private set
    var refreshing by mutableStateOf(false)
        private set

    /**
     * The feed's Continue items, each adjusted for reading on this device not
     * sent yet (offline) from the cached chapter list ([ResumeRule.adjusted]);
     * the server's choice otherwise.
     */
    var continueItems by mutableStateOf<List<ContinueItem>>(emptyList())
        private set

    /** "Continue listening" (audiobooks started and not finished); empty without audiobooks on the server. */
    var listening by mutableStateOf<List<AudiobookSummary>>(emptyList())
        private set
    private var loadedRevision = -1
    private var loadedAt = 0L

    /** The library by id (Favourites' and folders' covers, the search): the saved copy, refreshed with Home. */
    val library = LibraryNovels(app)

    fun refreshLibrary() = viewModelScope.launch { library.load(fetch = true) }

    // Search: the library on this device at once, the server's audiobooks after a pause in typing.

    var query by mutableStateOf("")
        private set
    var seriesResults by mutableStateOf<List<NovelSummary>>(emptyList())
        private set
    var audiobookResults by mutableStateOf<List<AudiobookSummary>>(emptyList())
        private set
    var searchingAudiobooks by mutableStateOf(false)
        private set
    private var audiobookSearch: Job? = null

    fun search(text: String) {
        query = text
        seriesResults = HomeShelves.search(text, library.novels.values)
        audiobookSearch?.cancel()
        if (text.isBlank() || !app.audiobooksOffered.value) {
            audiobookResults = emptyList()
            searchingAudiobooks = false
            return
        }
        searchingAudiobooks = true
        audiobookSearch = viewModelScope.launch {
            delay(400)
            val found = runCatching { app.active?.client?.audiobooks(search = text.trim())?.audiobooks }
                .onFailure { it.rethrowIfCancellation() }
                .getOrNull()
            // Offline: what this device has seen (Continue listening, the saved first page).
            audiobookResults = found ?: (listening + app.cached(OfflineStore.Key.audiobooks)?.audiobooks.orEmpty())
                .distinctBy { it.id }
                .filter { AutoSearch.normalize("${it.title} ${it.author.orEmpty()} ${it.narrator.orEmpty()}").contains(AutoSearch.normalize(text)) }
            searchingAudiobooks = false
        }
    }

    /** Reloads when read state changed elsewhere or the data is a minute old. */
    fun refreshIfStale() {
        val stale = System.currentTimeMillis() - loadedAt > 60_000
        if (stale || loadedRevision != app.readStateRevision.value) load()
    }

    fun load(pull: Boolean = false) = viewModelScope.launch {
        if (pull) refreshing = true
        if (feed == null) {
            app.cached(OfflineStore.Key.home)?.let { feed = it; continueItems = it.continueReading; fromCache = true }
            applyUnsentReading()
        }
        loadedRevision = app.readStateRevision.value
        val result = app.load(OfflineStore.Key.home) { it.home() }
        result.value?.let { feed = it; continueItems = it.continueReading }
        if (!result.fromCache && result.value != null) loadedAt = System.currentTimeMillis()
        failure = if (feed == null) result.error?.userMessage ?: "Couldn't load Home." else null
        fromCache = result.fromCache || (result.error != null && feed != null)
        applyUnsentReading()
        refreshing = false
        loadListening()
        // The library copy: fetched with a pull, or when none is saved yet.
        library.load(fetch = pull || !library.loaded)
    }

    fun refreshListening() = viewModelScope.launch { loadListening() }

    private suspend fun loadListening() {
        if (!app.audiobooksOffered.value) {
            listening = emptyList()
            return
        }
        if (listening.isEmpty()) app.cached(OfflineStore.Key.continueListening)?.let { listening = it }
        app.load(OfflineStore.Key.continueListening) { it.continueListening() }.value?.let { listening = it }
    }

    /** Recomputes [continueItems] from the feed and the write queue (no network). */
    fun applyUnsent() = viewModelScope.launch { applyUnsentReading() }

    private suspend fun applyUnsentReading() {
        val source = feed
        val items = source?.continueReading.orEmpty()
        if (app.pendingWrites.value == 0) {
            continueItems = items
            return
        }
        val adjusted = items.map { item ->
            val unsent = app.unsentWrites(item.novel.id)
            if (unsent.isEmpty()) return@map item
            val chapters = app.cached(OfflineStore.Key.chapters(item.novel.id)) ?: return@map item
            ResumeRule.adjusted(item, chapters, unsent)
        }
        // A newer feed arrived meanwhile: its own pass sets the items.
        if (feed === source) continueItems = adjusted
    }
}

private fun readerRoute(item: ContinueItem) =
    ReaderRoute(item.novel.id, item.chapter.id, manga = item.novel.isManga, offerLatest = true)

/**
 * Home is "my stuff" across books, manga, web novels and audiobooks: a
 * search over everything, a compact strip to pick up where you left off
 * (each half can be hidden in Settings), Favourites, Want to read, what is in
 * progress, the reader's folders (their own reading lists) and a way into
 * the full Library.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(nav: NavController) {
    val app = LocalApp.current
    val model = viewModel { HomeViewModel(app) }
    val revision by app.readStateRevision.collectAsStateWithLifecycle()
    LaunchedEffect(revision) { model.refreshIfStale() }
    // "Your stats": today's figures and the streak, from the 30-day answer (saved copy, then fresh).
    val statsCard = viewModel { StatsCardViewModel(app) }
    LaunchedEffect(revision) { statsCard.refreshIfStale() }
    // Reading queued (or sent) since: Continue follows it without a reload, offline too.
    val queueRevision by app.queueRevision.collectAsStateWithLifecycle()
    LaunchedEffect(queueRevision) { model.applyUnsent() }
    LaunchedEffect(Unit) { app.refreshMetaIfDue() }
    val update by app.updateBanner.collectAsStateWithLifecycle(null)
    // On screen now: the notification about it goes, and won't come back for this build.
    LaunchedEffect(update) { update?.let(app::updateShown) }
    // Android 13+: asks once for notifications, here (connected, signed in) rather than at a cold start.
    // Denied, Settings explains how to turn them on.
    val askNotifications = rememberNotificationPermissionRequest()
    LaunchedEffect(Unit) { if (app.takeNotificationPermissionPrompt()) askNotifications() }
    val wide = isWide()
    val scroll = TopAppBarDefaults.pinnedScrollBehavior()
    val requestsOffered by app.requestsOffered.collectAsStateWithLifecycle()
    val audiobooksOffered by app.audiobooksOffered.collectAsStateWithLifecycle()
    // Listening moves "Continue listening" along: reload it when a book is closed or another opened.
    val playingId = app.listening.nowPlaying.collectAsStateWithLifecycle().value?.id
    LaunchedEffect(audiobooksOffered, playingId) { if (audiobooksOffered) model.refreshListening() }
    val unseenRequests by app.requestsUnseen.collectAsStateWithLifecycle(0)
    val layout by app.prefs.homeLayout.collectAsStateWithLifecycle(HomeLayout())
    // The lists: "Want to read" (the feed's shelf, kept in step with the local list), Favourites and the folders.
    val listsOffered by app.listsOffered.collectAsStateWithLifecycle()
    val favouritesOffered by app.favouritesOffered.collectAsStateWithLifecycle()
    val listsState by app.lists.state.collectAsStateWithLifecycle()
    val listsLoaded by app.lists.loaded.collectAsStateWithLifecycle()
    val wantList = if (listsOffered) ReadingLists.wantToRead(listsState.lists) else null
    val favouritesList = if (listsOffered && favouritesOffered) ReadingLists.favourites(listsState.lists) else null
    LaunchedEffect(listsOffered) { if (listsOffered && !app.lists.loaded.value) app.lists.refresh() }
    LaunchedEffect(wantList?.novelIds) {
        val feed = model.feed
        if (feed != null && ReadingLists.needsRefetch(feed.wantToRead, wantList, WANT_TO_READ_SHELF)) model.load()
    }
    // Favourites and folders show covers from the library: fetched again when a list holds a series it doesn't know.
    val allListIds = listsState.lists.flatMap { it.novelIds }
    LaunchedEffect(allListIds, model.library.loaded) {
        if (listsOffered && model.library.loaded && allListIds.any { it !in model.library.novels }) model.refreshLibrary()
    }
    var newFolder by remember { mutableStateOf(false) }
    val hPad = if (wide) 28.dp else 16.dp

    Scaffold(
        modifier = Modifier.nestedScroll(scroll.nestedScrollConnection),
        topBar = {
            TopAppBar(
                title = { Text("Home") },
                actions = {
                    // Phones: Requests lives here; tablets have it in the rail.
                    if (requestsOffered && !wide) {
                        IconButton(onClick = { nav.navigate(RequestsRoute()) }) { RequestsIcon(unseenRequests) }
                    }
                },
                scrollBehavior = scroll,
            )
        },
    ) { padding ->
        PullToRefreshBox(
            isRefreshing = model.refreshing,
            onRefresh = { model.load(pull = true) },
            modifier = Modifier.fillMaxSize().padding(padding),
        ) {
            val feed = model.feed
            LazyColumn(
                Modifier.fillMaxSize(),
                contentPadding = PaddingValues(horizontal = hPad, vertical = 8.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                update?.let { release ->
                    item(key = "update") {
                        UpdateBanner(release, onDismiss = { app.dismissUpdate(release) }, modifier = Modifier.readableWidth(1000.dp).padding(bottom = 12.dp))
                    }
                }
                if (model.fromCache) item { OfflinePill(modifier = Modifier.padding(bottom = 12.dp)) }
                // Search everything: the library (this device's copy) and, online, the server's audiobooks.
                item(key = "search") {
                    HomeSearchField(model.query, onChange = model::search, modifier = Modifier.readableWidth(1000.dp).padding(bottom = 12.dp))
                }
                if (model.query.isNotBlank()) {
                    searchResults(model, nav)
                    return@LazyColumn
                }
                // Phones: a labelled entry besides the top bar's icon, which is easy to miss. Tablets have the labelled rail item.
                if (requestsOffered && !wide) {
                    // No key: the list then stays at the top when the update banner arrives above it.
                    item {
                        RequestsEntry(unseenRequests, Modifier.readableWidth(1000.dp).padding(bottom = 12.dp)) { nav.navigate(RequestsRoute()) }
                    }
                }
                when {
                    feed != null -> {
                        val listening = if (audiobooksOffered) model.listening else emptyList()
                        // 1. Pick up where you left off: compact, one row (Settings › Home can hide either half).
                        val quick = HomeShelves.quickResume(model.continueItems, listening, layout.continueReading, layout.continueListening)
                        if (quick.isNotEmpty()) {
                            item(key = "quick") {
                                QuickResumeStrip(quick, Modifier.readableWidth(1000.dp)) { open(nav, it) }
                            }
                        }
                        // Today's stats and the streak; hidden on servers without stats or with nothing read yet.
                        statsCard.stats?.takeIf { !it.isEmpty }?.let { stats ->
                            item(key = "stats") {
                                HomeStatsCard(stats, Modifier.readableWidth(1000.dp).padding(top = 12.dp)) { nav.navigate(StatsRoute) }
                            }
                        }
                        // 2. Favourites (servers with the built-in list).
                        if (favouritesList != null) {
                            item(key = "favourites") {
                                CoverShelf(
                                    title = ReadingLists.FAVOURITES,
                                    novels = HomeShelves.shelf(favouritesList, model.library.novels).take(SHELF_MAX),
                                    total = favouritesList.count,
                                    wide = wide,
                                    empty = "Tap the heart at the top of any book, web novel or manga and it lives here.",
                                    onSeeAll = { nav.navigate(FolderRoute(favouritesList.id)) },
                                    onOpen = { nav.navigate(NovelRoute(it)) },
                                )
                            }
                        }
                        // 3. Want to read (shown once the server has the list; an older server sends neither).
                        if (wantList != null || feed.wantToRead.isNotEmpty()) {
                            item(key = "want-to-read") {
                                WantToReadSection(
                                    ReadingLists.shown(feed.wantToRead, wantList),
                                    total = wantList?.count ?: feed.wantToReadCount,
                                    wide = wide,
                                    onSeeAll = { nav.navigate(WantToReadRoute) },
                                    onOpen = { nav.navigate(NovelRoute(it)) },
                                )
                            }
                        }
                        // 4. Everything in progress, reading and listening, as covers (beyond what the strip shows).
                        val progress = HomeShelves.inProgress(model.continueItems, listening).filterNot { p -> quick.any { it.key == p.key } }
                        if (progress.isNotEmpty()) {
                            item(key = "in-progress") {
                                InProgressShelf(progress, wide, audiobooksOffered, onAllAudiobooks = { nav.navigate(AudiobooksRoute) }) { open(nav, it) }
                            }
                        } else if (quick.isEmpty() && model.continueItems.isEmpty()) {
                            item(key = "nothing") {
                                Text(
                                    "Nothing in progress. Pick something from your Library to start.",
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    modifier = Modifier.readableWidth(1000.dp).padding(top = 20.dp),
                                )
                            }
                        }
                        // 5. The reader's folders (their own lists), with "New folder".
                        if (listsOffered && listsLoaded) {
                            item(key = "folders") {
                                FoldersSection(
                                    HomeShelves.folders(listsState.lists, model.library.novels),
                                    wide = wide,
                                    onOpen = { nav.navigate(FolderRoute(it)) },
                                    onNew = { newFolder = true },
                                )
                            }
                        }
                        val newChapters = NewChapters.shown(feed.newChapters)
                        if (newChapters.isNotEmpty()) {
                            item { SectionTitle("New chapters", Modifier.readableWidth(1000.dp).padding(top = 28.dp, bottom = 8.dp)) }
                            items(newChapters, key = { "new-${it.novel.id}" }) { item ->
                                NewChaptersRow(item, Modifier.readableWidth(1000.dp)) {
                                    val first = item.firstChapter
                                    if (first != null) {
                                        nav.navigate(ReaderRoute(item.novel.id, first.id, manga = item.novel.isManga, offerLatest = true))
                                    } else {
                                        nav.navigate(NovelRoute(item.novel.id))
                                    }
                                }
                                if (item != newChapters.last()) HorizontalDivider(Modifier.readableWidth(1000.dp).padding(start = 64.dp))
                            }
                        }
                        // 6. The full Library.
                        item(key = "library") {
                            FilledTonalButton(
                                onClick = { nav.navigate(LibraryRoute) { launchSingleTop = true } },
                                modifier = Modifier.readableWidth(1000.dp).padding(top = 28.dp).heightIn(min = 48.dp),
                            ) {
                                Icon(Icons.Outlined.CollectionsBookmark, contentDescription = null, modifier = Modifier.padding(end = 8.dp))
                                Text("Open the full Library")
                            }
                        }
                        feed.stats?.let { stats ->
                            item {
                                val queued = if (stats.chaptersQueued > 0) " · ${"%,d".format(stats.chaptersQueued)} queued" else ""
                                Text(
                                    "${stats.novels} series · ${"%,d".format(stats.chaptersDownloaded)} chapters on the server$queued",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                    textAlign = TextAlign.Center,
                                    modifier = Modifier.fillMaxWidth().padding(vertical = 24.dp),
                                )
                            }
                        }
                    }
                    model.failure != null -> item {
                        LoadFailed("Can't reach your server", model.failure.orEmpty(), Modifier.padding(top = 60.dp)) { model.load() }
                    }
                    else -> item { CenteredProgress() }
                }
            }
        }
    }
    if (newFolder) NewFolderDialog { newFolder = false }
}

/** Covers on Home's Favourites row. */
private const val SHELF_MAX = 12

private fun open(nav: NavController, item: HomeShelves.InProgress) = when (item) {
    is HomeShelves.InProgress.Reading -> nav.navigate(readerRoute(item.item))
    is HomeShelves.InProgress.Listening -> nav.navigate(PlayerRoute(item.book.id))
}

@Composable
private fun SectionTitle(text: String, modifier: Modifier = Modifier) {
    Text(text, style = MaterialTheme.typography.titleLarge.copy(fontSize = 20.sp), modifier = modifier.semantics { heading() })
}

@Composable
private fun HomeSearchField(query: String, onChange: (String) -> Unit, modifier: Modifier = Modifier) {
    OutlinedTextField(
        value = query,
        onValueChange = onChange,
        placeholder = { Text("Search books, manga, web novels, audiobooks") },
        leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
        trailingIcon = {
            if (query.isNotEmpty()) IconButton(onClick = { onChange("") }) { Icon(Icons.Outlined.Close, contentDescription = "Clear search") }
        },
        singleLine = true,
        shape = RoundedCornerShape(28.dp),
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
        modifier = modifier.fillMaxWidth(),
    )
}

private fun androidx.compose.foundation.lazy.LazyListScope.searchResults(model: HomeViewModel, nav: NavController) {
    val series = model.seriesResults
    val audiobooks = model.audiobookResults
    if (series.isEmpty() && audiobooks.isEmpty()) {
        item(key = "no-results") {
            Text(
                if (model.searchingAudiobooks) "Searching…" else "Nothing matches “${model.query.trim()}”.",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.readableWidth(1000.dp).padding(vertical = 16.dp),
            )
        }
        return
    }
    if (series.isNotEmpty()) {
        item(key = "results-series") { SectionTitle("Series", Modifier.readableWidth(1000.dp).padding(top = 8.dp, bottom = 4.dp)) }
        items(series, key = { "s-${it.id}" }) { novel ->
            ResultRow(novel.coverUrl, novel.name, listOfNotNull(novel.authorOrNull, mediumLabel(novel)).joinToString(" · "), Modifier.readableWidth(1000.dp)) {
                nav.navigate(NovelRoute(novel.id))
            }
        }
    }
    if (audiobooks.isNotEmpty()) {
        item(key = "results-audio") { SectionTitle("Audiobooks", Modifier.readableWidth(1000.dp).padding(top = 16.dp, bottom = 4.dp)) }
        items(audiobooks, key = { "a-${it.id}" }) { book ->
            ResultRow(book.coverUrl, book.title, listOfNotNull(book.authorOrNull, caption(book)).joinToString(" · "), Modifier.readableWidth(1000.dp)) {
                nav.navigate(PlayerRoute(book.id))
            }
        }
    }
}

private fun mediumLabel(novel: studio.visns.novarr.api.NovelSummary) = when {
    novel.isManga -> "Manga"
    novel.isBook -> "Book"
    else -> "Web novel"
}

@Composable
private fun ResultRow(cover: String?, title: String, subtitle: String, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier
            .clickable(role = Role.Button, onClick = onClick)
            .heightIn(min = 56.dp)
            .padding(vertical = 6.dp)
            .semantics(mergeDescendants = true) {},
        horizontalArrangement = Arrangement.spacedBy(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Cover(cover, title, Modifier.width(40.dp), corner = 4.dp)
        Column(Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.bodyLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (subtitle.isNotBlank()) {
                Text(subtitle, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

/**
 * The compact Continue strip: one small card each for the latest read and the
 * latest listen — a 60 dp cover, the title, where you are, a thin progress
 * line and a 48 dp play button — side by side, scrolling sideways on narrow
 * screens.
 */
@Composable
private fun QuickResumeStrip(items: List<HomeShelves.InProgress>, modifier: Modifier, onOpen: (HomeShelves.InProgress) -> Unit) {
    Column(modifier.padding(top = 4.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        SectionTitle("Continue")
        LazyRow(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
            items(items, key = { it.key }) { item -> QuickResumeCard(item, Modifier.width(300.dp)) { onOpen(item) } }
        }
    }
}

@Composable
private fun QuickResumeCard(item: HomeShelves.InProgress, modifier: Modifier, onClick: () -> Unit) {
    val eink = LocalEink.current
    val (detail, action, icon) = when (item) {
        is HomeShelves.InProgress.Reading -> Triple(item.item.chapter.title, "Continue reading", Icons.AutoMirrored.Outlined.MenuBook)
        is HomeShelves.InProgress.Listening -> Triple(caption(item.book), "Continue listening", Icons.Filled.PlayArrow)
    }
    Surface(
        onClick = onClick,
        shape = RoundedCornerShape(12.dp),
        color = MaterialTheme.colorScheme.surfaceContainerLow,
        border = if (eink) androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outline) else null,
        modifier = modifier.semantics(mergeDescendants = true) { contentDescription = "$action: ${item.title}" },
    ) {
        Row(Modifier.padding(8.dp), verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Cover(item.coverUrl, item.title, Modifier.width(40.dp), corner = 4.dp)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(item.title, style = MaterialTheme.typography.titleSmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(detail, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                ProgressLine(item.fraction)
            }
            Surface(shape = CircleShape, color = MaterialTheme.colorScheme.primary, modifier = Modifier.size(48.dp)) {
                Box(contentAlignment = Alignment.Center) { Icon(icon, contentDescription = null) }
            }
        }
    }
}

/** A titled row of covers with "See all" (Favourites). */
@Composable
private fun CoverShelf(
    title: String,
    novels: List<studio.visns.novarr.api.NovelSummary>,
    total: Int,
    wide: Boolean,
    empty: String,
    onSeeAll: () -> Unit,
    onOpen: (Int) -> Unit,
) {
    Column(Modifier.readableWidth(1000.dp).padding(top = 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            SectionTitle(title, Modifier.weight(1f))
            if (total > 0) {
                TextButton(onClick = onSeeAll, modifier = Modifier.semantics { contentDescription = "See all $total in $title" }) { Text("See all") }
            }
        }
        if (novels.isEmpty()) {
            Text(empty, color = MaterialTheme.colorScheme.onSurfaceVariant)
            return@Column
        }
        LazyRow(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            items(novels, key = { it.id }) { novel ->
                Column(
                    Modifier.width(if (wide) 120.dp else 96.dp)
                        .clickable(onClickLabel = "Open the series", role = Role.Button) { onOpen(novel.id) }
                        .semantics(mergeDescendants = true) {},
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Cover(novel.coverUrl, novel.name)
                    Text(novel.name, style = MaterialTheme.typography.labelLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    Text(
                        listOfNotNull(mediumLabel(novel).uppercase(), if (novel.audiobook != null) "AUDIO" else null).joinToString(" · "),
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}

/** "Currently reading & listening": every series and audiobook in progress, as covers with a progress line. */
@Composable
private fun InProgressShelf(
    items: List<HomeShelves.InProgress>,
    wide: Boolean,
    audiobooks: Boolean,
    onAllAudiobooks: () -> Unit,
    onOpen: (HomeShelves.InProgress) -> Unit,
) {
    Column(Modifier.readableWidth(1000.dp).padding(top = 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            SectionTitle(if (audiobooks) "Reading & listening" else "Reading", Modifier.weight(1f))
            if (audiobooks) TextButton(onClick = onAllAudiobooks) { Text("All audiobooks") }
        }
        LazyRow(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            items(items, key = { it.key }) { item ->
                Column(
                    Modifier.width(if (wide) 120.dp else 96.dp)
                        .clickable(onClickLabel = if (item is HomeShelves.InProgress.Listening) "Listen" else "Open the reader", role = Role.Button) { onOpen(item) }
                        .semantics(mergeDescendants = true) {},
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Cover(item.coverUrl, item.title) {
                        Box(Modifier.align(Alignment.BottomCenter).padding(6.dp)) {
                            ProgressLine(item.fraction, color = Color.White, track = Color.Black.copy(alpha = 0.35f))
                        }
                        if (item is HomeShelves.InProgress.Listening) {
                            Surface(shape = CircleShape, color = Color.Black.copy(alpha = 0.55f), modifier = Modifier.align(Alignment.TopEnd).padding(4.dp)) {
                                Icon(Icons.Outlined.Headphones, contentDescription = null, tint = Color.White, modifier = Modifier.padding(4.dp).size(14.dp))
                            }
                        }
                    }
                    Text(item.title, style = MaterialTheme.typography.labelLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    Text(
                        when (item) {
                            is HomeShelves.InProgress.Reading -> item.item.chapter.title
                            is HomeShelves.InProgress.Listening -> caption(item.book)
                        },
                        style = MaterialTheme.typography.labelSmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        }
    }
}

/** The reader's folders as tiles (a 2×2 collage of covers, the name and count), then "New folder". */
@Composable
private fun FoldersSection(folders: List<HomeShelves.Folder>, wide: Boolean, onOpen: (Int) -> Unit, onNew: () -> Unit) {
    Column(Modifier.readableWidth(1000.dp).padding(top = 24.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            SectionTitle("Folders", Modifier.weight(1f))
            TextButton(onClick = onNew) { Text("New folder") }
        }
        if (folders.isEmpty()) {
            Text(
                "Make folders for anything — “Manga – Action”, “Comfort reads” — then add series from their page (Lists, at the top).",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        val columns = if (wide) 4 else 2
        (folders.map<HomeShelves.Folder, HomeShelves.Folder?> { it } + null).chunked(columns).forEach { row ->
            Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                row.forEach { folder ->
                    if (folder == null) NewFolderTile(Modifier.weight(1f), onNew) else FolderTile(folder, Modifier.weight(1f)) { onOpen(folder.id) }
                }
                repeat(columns - row.size) { Spacer(Modifier.weight(1f)) }
            }
        }
    }
}

@Composable
private fun FolderTile(folder: HomeShelves.Folder, modifier: Modifier, onClick: () -> Unit) {
    Column(
        modifier
            .clickable(onClickLabel = "Open the folder", role = Role.Button, onClick = onClick)
            .semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Surface(shape = RoundedCornerShape(12.dp), color = MaterialTheme.colorScheme.surfaceContainerHigh, modifier = Modifier.fillMaxWidth().aspectRatio(1f)) {
            if (folder.covers.isEmpty()) {
                Box(contentAlignment = Alignment.Center) {
                    Icon(Icons.Outlined.Folder, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(40.dp))
                }
            } else {
                Column(Modifier.padding(6.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    folder.covers.chunked(2).take(2).let { rows -> if (rows.size == 1) rows + listOf(emptyList()) else rows }.forEach { pair ->
                        Row(Modifier.weight(1f), horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                            pair.forEach { url ->
                                AsyncImage(
                                    model = url,
                                    contentDescription = null,
                                    contentScale = ContentScale.Crop,
                                    modifier = Modifier.weight(1f).fillMaxHeight().clip(RoundedCornerShape(6.dp)),
                                )
                            }
                            repeat(2 - pair.size) { Spacer(Modifier.weight(1f)) }
                        }
                    }
                }
            }
        }
        Text(folder.name, style = MaterialTheme.typography.labelLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Text(seriesCount(folder.count), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun NewFolderTile(modifier: Modifier, onClick: () -> Unit) {
    Column(
        modifier.clickable(onClickLabel = "Make a new folder", role = Role.Button, onClick = onClick).semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Surface(
            shape = RoundedCornerShape(12.dp),
            color = MaterialTheme.colorScheme.surface,
            border = androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.outlineVariant),
            modifier = Modifier.fillMaxWidth().aspectRatio(1f),
        ) {
            Box(contentAlignment = Alignment.Center) {
                Icon(Icons.Outlined.CreateNewFolder, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(36.dp))
            }
        }
        Text("New folder", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.primary)
    }
}

/** Covers on Home's "Want to read" row (the server sends as many). */
private const val WANT_TO_READ_SHELF = 12

@Composable
private fun WantToReadSection(items: List<WantToReadItem>, total: Int, wide: Boolean, onSeeAll: () -> Unit, onOpen: (Int) -> Unit) {
    Column(Modifier.readableWidth(1000.dp).padding(top = 28.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            SectionTitle(ReadingLists.WANT_TO_READ, Modifier.weight(1f))
            if (total > 0) {
                TextButton(onClick = onSeeAll, modifier = Modifier.semantics { contentDescription = "See all $total in Want to read" }) { Text("See all") }
            }
        }
        if (items.isEmpty()) {
            Text(
                "Shortlist what to read next: tap the bookmark at the top of a series and it waits here.",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            return@Column
        }
        LazyRow(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            items(items, key = { it.novel.id }) { item ->
                WantToReadTile(item, Modifier.width(if (wide) 130.dp else 108.dp)) { onOpen(item.novel.id) }
            }
        }
    }
}

@Composable
private fun WantToReadTile(item: WantToReadItem, modifier: Modifier, onClick: () -> Unit) {
    val started = ReadingLists.started(item)
    Column(
        modifier
            .clickable(onClickLabel = "Open the series", role = Role.Button, onClick = onClick)
            .semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Cover(item.novel.coverUrl, item.novel.name)
        Text(item.novel.name, style = MaterialTheme.typography.labelLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
        Text(
            when {
                started -> "STARTED"
                item.novel.isManga -> "MANGA"
                item.novel.isBook -> "BOOK"
                else -> "WEB NOVEL"
            },
            style = MaterialTheme.typography.labelSmall,
            color = if (started) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun NewChaptersRow(item: NewChaptersItem, modifier: Modifier, onClick: () -> Unit) {
    Row(
        modifier
            .clickable(role = Role.Button, onClick = onClick)
            .padding(vertical = 8.dp)
            .semantics(mergeDescendants = true) {},
        horizontalArrangement = Arrangement.spacedBy(14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Cover(item.novel.coverUrl, item.novel.name, Modifier.width(50.dp), corner = 4.dp)
        Column(Modifier.weight(1f)) {
            Text(item.novel.name, style = MaterialTheme.typography.bodyLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
            item.firstChapter?.let {
                Text("From ${it.title}", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
        // The row's badges, stacked so the origin never takes width from the title.
        Column(horizontalAlignment = Alignment.End, verticalArrangement = Arrangement.spacedBy(4.dp)) {
            NewChapters.badge(item.newCount)?.let { badge ->
                val eink = LocalEink.current
                Surface(
                    shape = CircleShape,
                    color = if (eink) MaterialTheme.colorScheme.surface else MaterialTheme.colorScheme.primary.copy(alpha = 0.12f),
                    border = if (eink) androidx.compose.foundation.BorderStroke(1.dp, MaterialTheme.colorScheme.primary) else null,
                ) {
                    Text(
                        badge,
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.primary,
                        modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                    )
                }
            }
            OriginBadge(item.novel)
        }
    }
}

/** Home's labelled way into Requests (phones), with the same "new activity" dot as the icon. */
@Composable
private fun RequestsEntry(unseen: Int, modifier: Modifier = Modifier, onClick: () -> Unit) {
    Surface(
        onClick = onClick,
        shape = RoundedCornerShape(14.dp),
        color = MaterialTheme.colorScheme.surfaceContainerLow,
        modifier = modifier.fillMaxWidth(),
    ) {
        // Read as one button: "Requests, New activity on your requests".
        Row(Modifier.padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.clearAndSetSemantics { }) { RequestsIcon(unseen) }
            Column(Modifier.weight(1f).padding(start = 16.dp)) {
                Text("Requests", style = MaterialTheme.typography.titleSmall)
                Text(
                    if (unseen > 0) "New activity on your requests" else "Ask for a book, web novel or manga",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}
