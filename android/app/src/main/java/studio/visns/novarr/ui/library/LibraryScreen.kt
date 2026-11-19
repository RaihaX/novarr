package studio.visns.novarr.ui.library

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Sort
import androidx.compose.material.icons.outlined.Bookmarks
import androidx.compose.material.icons.outlined.Check
import androidx.compose.material.icons.outlined.Clear
import androidx.compose.material.icons.outlined.CollectionsBookmark
import androidx.compose.material.icons.outlined.FilterList
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.PrimaryTabRow
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Tab
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import kotlinx.coroutines.launch
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.LibraryFilter
import studio.visns.novarr.domain.LibraryFilter.Filter
import studio.visns.novarr.domain.LibraryFilter.Shelf
import studio.visns.novarr.domain.LibraryFilter.Sort
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.ui.HighlightsRoute
import studio.visns.novarr.ui.NovelRoute
import studio.visns.novarr.ui.components.isWide
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.Cover
import studio.visns.novarr.ui.components.EmptyState
import studio.visns.novarr.ui.components.LoadFailed
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.OfflinePill
import studio.visns.novarr.ui.components.ProgressLine

class LibraryViewModel(private val app: AppModel) : ViewModel() {
    var novels by mutableStateOf<List<NovelSummary>>(emptyList())
        private set
    var loaded by mutableStateOf(false)
        private set
    var failure by mutableStateOf<String?>(null)
        private set
    var fromCache by mutableStateOf(false)
        private set
    var refreshing by mutableStateOf(false)
        private set
    var search by mutableStateOf("")
    var shelf by mutableStateOf(Shelf.NOVELS)
        private set
    var filter by mutableStateOf(Filter.ALL)
        private set
    var sort by mutableStateOf(Sort.LAST_READ)
        private set
    private var loadedRevision = -1
    private var loadedAt = 0L

    init {
        viewModelScope.launch {
            app.prefs.string("library.shelf")?.let { v -> Shelf.entries.firstOrNull { it.name == v } }?.let { shelf = it }
            app.prefs.string("library.filter")?.let { v -> Filter.entries.firstOrNull { it.name == v } }?.let { filter = it }
            app.prefs.string("library.sort")?.let { v -> Sort.entries.firstOrNull { it.name == v } }?.let { sort = it }
        }
    }

    fun select(value: Shelf) { shelf = value; save("library.shelf", value.name) }
    fun select(value: Filter) { filter = value; save("library.filter", value.name) }
    fun select(value: Sort) { sort = value; save("library.sort", value.name) }
    private fun save(key: String, value: String) = viewModelScope.launch { app.prefs.setString(key, value) }

    val onShelf: List<NovelSummary> get() = LibraryFilter.shelf(novels, shelf)
    val visible: List<NovelSummary> get() = LibraryFilter.apply(onShelf, filter, sort, search)
    fun count(filter: Filter) = onShelf.count(filter::matches)
    fun count(shelf: Shelf) = LibraryFilter.shelf(novels, shelf).size

    fun refreshIfStale() {
        val stale = System.currentTimeMillis() - loadedAt > 60_000
        if (stale || loadedRevision != app.readStateRevision.value) load()
    }

    fun load(pull: Boolean = false) = viewModelScope.launch {
        if (pull) refreshing = true
        if (novels.isEmpty()) {
            // Show the saved copy straight away while the network request runs.
            app.cached(OfflineStore.Key.novels)?.let { novels = it; fromCache = true }
        }
        loadedRevision = app.readStateRevision.value
        val result = app.load(OfflineStore.Key.novels) { it.novels() }
        result.value?.let { novels = it; loaded = true }
        if (!result.fromCache && result.value != null) loadedAt = System.currentTimeMillis()
        failure = if (novels.isEmpty() && result.value == null) result.error?.userMessage ?: "Couldn't load the library." else null
        if (failure == null) loaded = true
        fromCache = result.fromCache || (result.error != null && novels.isNotEmpty())
        refreshing = false
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LibraryScreen(nav: NavController) {
    val app = LocalApp.current
    val model = viewModel { LibraryViewModel(app) }
    val revision by app.readStateRevision.collectAsStateWithLifecycle()
    LaunchedEffect(revision) { model.refreshIfStale() }
    val wide = isWide()
    var sortMenu by remember { mutableStateOf(false) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Library") },
                actions = {
                    IconButton(onClick = { nav.navigate(HighlightsRoute()) }) {
                        Icon(Icons.Outlined.Bookmarks, contentDescription = "Highlights")
                    }
                    Box {
                        IconButton(onClick = { sortMenu = true }) {
                            Icon(Icons.AutoMirrored.Outlined.Sort, contentDescription = "Sort by ${model.sort.title}")
                        }
                        DropdownMenu(expanded = sortMenu, onDismissRequest = { sortMenu = false }) {
                            Sort.entries.forEach { sort ->
                                DropdownMenuItem(
                                    text = { Text(sort.title) },
                                    onClick = { model.select(sort); sortMenu = false },
                                    leadingIcon = {
                                        if (sort == model.sort) Icon(Icons.Outlined.Check, contentDescription = "Selected") else Box(Modifier.size(24.dp))
                                    },
                                )
                            }
                        }
                    }
                },
            )
        },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            PrimaryTabRow(selectedTabIndex = model.shelf.ordinal) {
                Shelf.entries.forEach { shelf ->
                    Tab(
                        selected = model.shelf == shelf,
                        onClick = { model.select(shelf) },
                        text = { Text(if (model.novels.isEmpty()) shelf.title else "${shelf.title} (${model.count(shelf)})") },
                    )
                }
            }
            OutlinedTextField(
                value = model.search,
                onValueChange = { model.search = it },
                placeholder = { Text("Title or author") },
                leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
                trailingIcon = {
                    if (model.search.isNotEmpty()) {
                        IconButton(onClick = { model.search = "" }) { Icon(Icons.Outlined.Clear, contentDescription = "Clear search") }
                    }
                },
                singleLine = true,
                shape = CircleShape,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 8.dp),
            )
            LazyRow(
                contentPadding = PaddingValues(horizontal = 16.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(Filter.entries) { filter ->
                    FilterChip(
                        selected = model.filter == filter,
                        onClick = { model.select(filter) },
                        label = { Text(if (model.novels.isEmpty()) filter.title else "${filter.title} ${model.count(filter)}") },
                    )
                }
            }
            PullToRefreshBox(
                isRefreshing = model.refreshing,
                onRefresh = { model.load(pull = true) },
                modifier = Modifier.fillMaxSize(),
            ) {
                val visible = model.visible
                LazyVerticalGrid(
                    columns = GridCells.Adaptive(if (wide) 150.dp else 104.dp),
                    contentPadding = PaddingValues(horizontal = if (wide) 24.dp else 16.dp, vertical = 12.dp),
                    horizontalArrangement = Arrangement.spacedBy(if (wide) 22.dp else 14.dp),
                    verticalArrangement = Arrangement.spacedBy(20.dp),
                    modifier = Modifier.fillMaxSize(),
                ) {
                    val full: (androidx.compose.foundation.lazy.grid.LazyGridItemSpanScope.() -> GridItemSpan) = { GridItemSpan(maxLineSpan) }
                    if (model.fromCache) {
                        item(span = full) { Box(contentAlignment = Alignment.Center) { OfflinePill() } }
                    }
                    when {
                        model.novels.isEmpty() && model.failure != null -> item(span = full) {
                            LoadFailed("Library unavailable", model.failure.orEmpty()) { model.load() }
                        }
                        model.novels.isEmpty() && !model.loaded -> item(span = full) { CenteredProgress() }
                        visible.isEmpty() -> item(span = full) { LibraryEmpty(model) }
                        else -> items(visible, key = { it.id }) { novel ->
                            LibraryCell(novel) { nav.navigate(NovelRoute(novel.id)) }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun LibraryEmpty(model: LibraryViewModel) {
    when {
        model.search.isNotBlank() -> EmptyState("No results", "Nothing on this shelf matches “${model.search}”.", Icons.Outlined.Search)
        model.onShelf.isEmpty() -> EmptyState(
            if (model.shelf == Shelf.MANGA) "No manga yet" else "No novels yet",
            "Add series on your Novarr server and they'll show up here.",
            Icons.Outlined.CollectionsBookmark,
        )
        else -> EmptyState("Nothing here", "Nothing matches “${model.filter.title}”.", Icons.Outlined.FilterList)
    }
}

@Composable
fun LibraryCell(novel: NovelSummary, onClick: () -> Unit) {
    Column(
        Modifier
            .clickable(role = Role.Button, onClick = onClick)
            .semantics(mergeDescendants = true) {},
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Cover(novel.coverUrl, novel.name) {
            if (novel.chaptersRead > 0 && !novel.finished) {
                Box(Modifier.align(Alignment.BottomCenter).padding(6.dp)) {
                    ProgressLine(novel.readFraction, color = Color.White, track = Color.Black.copy(alpha = 0.35f))
                }
            }
            if (novel.chaptersNew > 0) {
                Surface(
                    shape = CircleShape,
                    color = MaterialTheme.colorScheme.primary,
                    border = androidx.compose.foundation.BorderStroke(1.5.dp, Color.White),
                    modifier = Modifier.align(Alignment.TopEnd).padding(6.dp).size(12.dp),
                ) {}
            }
        }
        Text(novel.name, style = MaterialTheme.typography.labelLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
        Text(
            novel.caption,
            style = MaterialTheme.typography.labelSmall,
            color = if (novel.chaptersNew > 0) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}
