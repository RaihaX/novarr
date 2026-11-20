package studio.visns.novarr.ui.home

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
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ContinueItem
import studio.visns.novarr.api.HomeFeed
import studio.visns.novarr.api.NewChaptersItem
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.NewChapters
import studio.visns.novarr.domain.ResumeRule
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.ui.NovelRoute
import studio.visns.novarr.ui.ReaderRoute
import studio.visns.novarr.ui.RequestsRoute
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
    private var loadedRevision = -1
    private var loadedAt = 0L

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

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HomeScreen(nav: NavController) {
    val app = LocalApp.current
    val model = viewModel { HomeViewModel(app) }
    val revision by app.readStateRevision.collectAsStateWithLifecycle()
    LaunchedEffect(revision) { model.refreshIfStale() }
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
    val unseenRequests by app.requestsUnseen.collectAsStateWithLifecycle(0)

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
                contentPadding = PaddingValues(horizontal = if (wide) 28.dp else 16.dp, vertical = 8.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                update?.let { release ->
                    item(key = "update") {
                        UpdateBanner(release, onDismiss = { app.dismissUpdate(release) }, modifier = Modifier.readableWidth(1000.dp).padding(bottom = 12.dp))
                    }
                }
                if (model.fromCache) item { OfflinePill(modifier = Modifier.padding(bottom = 12.dp)) }
                // Phones: a labelled entry besides the top bar's icon, which is easy to miss. Tablets have the labelled rail item.
                if (requestsOffered && !wide) {
                    // No key: the list then stays at the top when the update banner arrives above it.
                    item {
                        RequestsEntry(unseenRequests, Modifier.readableWidth(1000.dp).padding(bottom = 12.dp)) { nav.navigate(RequestsRoute()) }
                    }
                }
                when {
                    feed != null -> {
                        item { ContinueSection(model.continueItems, wide, nav) }
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
}

@Composable
private fun SectionTitle(text: String, modifier: Modifier = Modifier) {
    Text(text, style = MaterialTheme.typography.titleLarge.copy(fontSize = 20.sp), modifier = modifier.semantics { heading() })
}

@Composable
private fun ContinueSection(items: List<ContinueItem>, wide: Boolean, nav: NavController) {
    Column(Modifier.readableWidth(1000.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
        SectionTitle("Continue reading", Modifier.padding(top = 8.dp))
        val first = items.firstOrNull()
        if (first == null) {
            Text(
                "Nothing in progress. Pick something from your Library to start reading.",
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            return@Column
        }
        HeroCard(first, wide) { nav.navigate(readerRoute(first)) }
        if (items.size > 1) {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                items(items.drop(1), key = { it.novel.id }) { item ->
                    ShelfItem(item, Modifier.width(if (wide) 130.dp else 108.dp)) { nav.navigate(readerRoute(item)) }
                }
            }
        }
    }
}

@Composable
private fun HeroCard(item: ContinueItem, large: Boolean, onClick: () -> Unit) {
    Surface(
        onClick = onClick,
        shape = RoundedCornerShape(16.dp),
        color = MaterialTheme.colorScheme.surfaceContainerLow,
        tonalElevation = 1.dp,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(Modifier.padding(14.dp), horizontalArrangement = Arrangement.spacedBy(16.dp)) {
            Cover(item.novel.coverLargeUrl ?: item.novel.coverUrl, item.novel.name, Modifier.width(if (large) 170.dp else 116.dp), corner = 8.dp)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(
                    item.novel.name,
                    style = if (large) MaterialTheme.typography.headlineSmall else MaterialTheme.typography.titleLarge,
                    maxLines = 3,
                    overflow = TextOverflow.Ellipsis,
                )
                item.novel.authorOrNull?.let {
                    Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Text(item.chapter.title, style = MaterialTheme.typography.bodyMedium, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Spacer(Modifier.height(4.dp))
                ProgressLine(item.novel.readFraction)
                Text(
                    "${item.novel.chaptersRead} of ${item.novel.chaptersTotal} chapters · ${item.novel.readPercent}%",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                Spacer(Modifier.height(4.dp))
                Surface(shape = CircleShape, color = MaterialTheme.colorScheme.primary) {
                    Row(Modifier.padding(horizontal = 14.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                        Icon(Icons.AutoMirrored.Outlined.MenuBook, contentDescription = null, modifier = Modifier.padding(end = 6.dp))
                        Text("Continue", style = MaterialTheme.typography.labelLarge)
                    }
                }
            }
        }
    }
}

@Composable
private fun ShelfItem(item: ContinueItem, modifier: Modifier, onClick: () -> Unit) {
    Column(
        modifier
            .clickable(onClickLabel = "Open the reader", role = Role.Button, onClick = onClick)
            .semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Cover(item.novel.coverUrl, item.novel.name) {
            Box(Modifier.align(Alignment.BottomCenter).padding(6.dp)) {
                ProgressLine(item.novel.readFraction, color = Color.White, track = Color.Black.copy(alpha = 0.35f))
            }
        }
        Text(item.novel.name, style = MaterialTheme.typography.labelLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
        Text(item.chapter.title, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
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
                Surface(shape = CircleShape, color = MaterialTheme.colorScheme.primary.copy(alpha = 0.12f)) {
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
                    if (unseen > 0) "New activity on your requests" else "Ask for a novel or manga",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            Icon(Icons.AutoMirrored.Outlined.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}
