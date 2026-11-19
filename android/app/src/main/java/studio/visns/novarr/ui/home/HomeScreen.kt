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
import androidx.compose.material.icons.automirrored.outlined.MenuBook
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
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
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
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
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.ui.NovelRoute
import studio.visns.novarr.ui.ReaderRoute
import studio.visns.novarr.ui.components.isWide
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.Cover
import studio.visns.novarr.ui.components.LoadFailed
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.OfflinePill
import studio.visns.novarr.ui.components.ProgressLine
import studio.visns.novarr.ui.components.readableWidth

class HomeViewModel(private val app: AppModel) : ViewModel() {
    var feed by mutableStateOf<HomeFeed?>(null)
        private set
    var failure by mutableStateOf<String?>(null)
        private set
    var fromCache by mutableStateOf(false)
        private set
    var refreshing by mutableStateOf(false)
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
            app.cached(OfflineStore.Key.home)?.let { feed = it; fromCache = true }
        }
        loadedRevision = app.readStateRevision.value
        val result = app.load(OfflineStore.Key.home) { it.home() }
        result.value?.let { feed = it }
        if (!result.fromCache && result.value != null) loadedAt = System.currentTimeMillis()
        failure = if (feed == null) result.error?.userMessage ?: "Couldn't load Home." else null
        fromCache = result.fromCache || (result.error != null && feed != null)
        refreshing = false
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
    val wide = isWide()
    val scroll = TopAppBarDefaults.pinnedScrollBehavior()

    Scaffold(
        modifier = Modifier.nestedScroll(scroll.nestedScrollConnection),
        topBar = { TopAppBar(title = { Text("Home") }, scrollBehavior = scroll) },
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
                if (model.fromCache) item { OfflinePill(modifier = Modifier.padding(bottom = 12.dp)) }
                when {
                    feed != null -> {
                        item { ContinueSection(feed.continueReading, wide, nav) }
                        if (feed.newChapters.isNotEmpty()) {
                            item { SectionTitle("New chapters", Modifier.readableWidth(1000.dp).padding(top = 28.dp, bottom = 8.dp)) }
                            items(feed.newChapters, key = { "new-${it.novel.id}" }) { item ->
                                NewChaptersRow(item, Modifier.readableWidth(1000.dp)) {
                                    val first = item.firstChapter
                                    if (first != null) {
                                        nav.navigate(ReaderRoute(item.novel.id, first.id, manga = item.novel.isManga, offerLatest = true))
                                    } else {
                                        nav.navigate(NovelRoute(item.novel.id))
                                    }
                                }
                                if (item != feed.newChapters.last()) HorizontalDivider(Modifier.readableWidth(1000.dp).padding(start = 64.dp))
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
    Text(text, style = MaterialTheme.typography.titleLarge, modifier = modifier.semantics { heading() })
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
        Surface(shape = CircleShape, color = MaterialTheme.colorScheme.primary.copy(alpha = 0.12f)) {
            Text(
                "${item.newCount} NEW",
                style = MaterialTheme.typography.labelMedium,
                color = MaterialTheme.colorScheme.primary,
                modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
            )
        }
    }
}
