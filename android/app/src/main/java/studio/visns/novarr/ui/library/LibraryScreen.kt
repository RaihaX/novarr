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
import androidx.compose.material.icons.automirrored.outlined.List
import androidx.compose.material.icons.automirrored.outlined.Sort
import androidx.compose.material.icons.outlined.ArrowDropDown
import androidx.compose.material.icons.outlined.Bookmarks
import androidx.compose.material.icons.outlined.Check
import androidx.compose.material.icons.outlined.Clear
import androidx.compose.material.icons.outlined.CollectionsBookmark
import androidx.compose.material.icons.outlined.FilterList
import androidx.compose.material.icons.outlined.Public
import androidx.compose.material.icons.outlined.Search
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.RadioButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.ui.semantics.heading
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
import androidx.compose.ui.semantics.contentDescription
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
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.domain.ReadingLists
import studio.visns.novarr.ui.lists.LibraryListsSheet
import studio.visns.novarr.ui.lists.seriesCount
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
import studio.visns.novarr.ui.components.OriginBadge
import studio.visns.novarr.ui.components.readableWidth
import studio.visns.novarr.domain.Origin
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
    /** The chosen shelf, remembered; see [shelf] for the one in effect. */
    var shelfChoice by mutableStateOf(Shelf.NOVELS)
        private set
    var filter by mutableStateOf(Filter.ALL)
        private set
    var sort by mutableStateOf(Sort.LAST_READ)
        private set

    /** The chosen reading list (Library's list filter); null: all series. Remembered like the other filters. */
    var listId by mutableStateOf<Int?>(null)
        private set

    /** The chosen Origin option, remembered like the other filters; ignored while the filter is hidden. */
    var originChoice by mutableStateOf(Origin.Filter.ALL)
        private set
    private var loadedRevision = -1
    private var loadedAt = 0L

    init {
        viewModelScope.launch {
            app.prefs.string("library.shelf")?.let { v -> Shelf.entries.firstOrNull { it.name == v } }?.let { shelfChoice = it }
            app.prefs.string("library.filter")?.let { v -> Filter.entries.firstOrNull { it.name == v } }?.let { filter = it }
            app.prefs.string("library.sort")?.let { v -> Sort.entries.firstOrNull { it.name == v } }?.let { sort = it }
            app.prefs.string("library.list")?.toIntOrNull()?.let { listId = it }
            app.prefs.string("library.origin")?.let { v -> Origin.Filter.entries.firstOrNull { it.name == v } }?.let { originChoice = it }
        }
    }

    fun select(value: Shelf) { shelfChoice = value; save("library.shelf", value.name) }
    fun select(value: Filter) { filter = value; save("library.filter", value.name) }
    fun select(value: Sort) { sort = value; save("library.sort", value.name) }
    fun select(value: Origin.Filter) { originChoice = value; save("library.origin", value.name) }
    fun select(value: ReadingList?) { listId = value?.id; save("library.list", value?.id?.toString().orEmpty()) }
    private fun save(key: String, value: String) = viewModelScope.launch { app.prefs.setString(key, value) }

    /** Books appears only when the library has a book. */
    val shelves: List<Shelf> get() = LibraryFilter.shelves(novels)

    /** The shelf shown: a remembered Books shelf with no books left falls back to Novels (the choice is kept). */
    val shelf: Shelf get() = LibraryFilter.effectiveShelf(shelfChoice, novels)
    val onShelf: List<NovelSummary> get() = LibraryFilter.shelf(novels, shelf)

    /**
     * Offered only when some series in the loaded library has an origin (an
     * older server sends none), and never on Books (books have no origin).
     */
    val originOffered: Boolean get() = shelf != Shelf.BOOKS && Origin.offered(novels)
    val origin: Origin.Filter get() = if (originOffered) Origin.effective(originChoice, novels) else Origin.Filter.ALL
    fun visible(list: ReadingList?): List<NovelSummary> = LibraryFilter.apply(onShelf, filter, sort, search, list, origin)
    fun count(filter: Filter, list: ReadingList?) = LibraryFilter.count(onShelf, list, filter, origin)
    fun count(origin: Origin.Filter, list: ReadingList?) = LibraryFilter.count(onShelf, list, filter, origin)
    fun count(shelf: Shelf, list: ReadingList?) = ReadingLists.filter(LibraryFilter.shelf(novels, shelf), list).size

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
        // Lists come with the library refresh (and are saved for offline with it).
        val lists = launch { app.lists.refresh() }
        val result = app.load(OfflineStore.Key.novels) { it.novels() }
        result.value?.let { novels = it; loaded = true }
        if (!result.fromCache && result.value != null) loadedAt = System.currentTimeMillis()
        failure = if (novels.isEmpty() && result.value == null) result.error?.userMessage ?: "Couldn't load the library." else null
        if (failure == null) loaded = true
        fromCache = result.fromCache || (result.error != null && novels.isNotEmpty())
        lists.join()
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
    var listSheet by remember { mutableStateOf(false) }
    var originSheet by remember { mutableStateOf(false) }
    val listsOffered by app.listsOffered.collectAsStateWithLifecycle()
    val listsState by app.lists.state.collectAsStateWithLifecycle()
    val listsLoaded by app.lists.loaded.collectAsStateWithLifecycle()
    // Without lists on the server (or the chosen one deleted elsewhere): all series.
    val list = if (listsOffered) ReadingLists.selected(listsState.lists, model.listId) else null
    LaunchedEffect(listsLoaded, list, model.listId) {
        if (listsLoaded && listsOffered && model.listId != null && list == null) model.select(null as ReadingList?)
    }

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
            val shelves = model.shelves
            PrimaryTabRow(selectedTabIndex = shelves.indexOf(model.shelf).coerceAtLeast(0)) {
                shelves.forEach { shelf ->
                    Tab(
                        selected = model.shelf == shelf,
                        onClick = { model.select(shelf) },
                        unselectedContentColor = MaterialTheme.colorScheme.onSurfaceVariant,
                        text = { Text(if (model.novels.isEmpty()) shelf.title else "${shelf.title} (${model.count(shelf, list)})") },
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
                if (listsOffered) {
                    item(key = "lists") {
                        FilterChip(
                            selected = list != null,
                            onClick = { listSheet = true },
                            label = { Text(list?.name ?: "Lists", maxLines = 1, overflow = TextOverflow.Ellipsis) },
                            leadingIcon = { Icon(Icons.AutoMirrored.Outlined.List, contentDescription = null, modifier = Modifier.size(18.dp)) },
                            trailingIcon = { Icon(Icons.Outlined.ArrowDropDown, contentDescription = null, modifier = Modifier.size(18.dp)) },
                            modifier = Modifier.semantics { contentDescription = if (list != null) "List: ${list.name}. Change or manage lists" else "Filter by list, or manage lists" },
                        )
                    }
                }
                if (model.originOffered) {
                    item(key = "origin") {
                        val origin = model.origin
                        FilterChip(
                            selected = origin != Origin.Filter.ALL,
                            onClick = { originSheet = true },
                            label = { Text(if (origin == Origin.Filter.ALL) "Origin" else origin.title, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                            leadingIcon = { Icon(Icons.Outlined.Public, contentDescription = null, modifier = Modifier.size(18.dp)) },
                            trailingIcon = { Icon(Icons.Outlined.ArrowDropDown, contentDescription = null, modifier = Modifier.size(18.dp)) },
                            modifier = Modifier.semantics {
                                contentDescription = if (origin == Origin.Filter.ALL) "Filter by origin" else "Origin: ${origin.title}. Change origin"
                            },
                        )
                    }
                }
                items(Filter.entries) { filter ->
                    FilterChip(
                        selected = model.filter == filter,
                        onClick = { model.select(filter) },
                        label = { Text(if (model.novels.isEmpty()) filter.title else "${filter.title} ${model.count(filter, list)}") },
                    )
                }
            }
            PullToRefreshBox(
                isRefreshing = model.refreshing,
                onRefresh = { model.load(pull = true) },
                modifier = Modifier.fillMaxSize(),
            ) {
                val visible = model.visible(list)
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
                        visible.isEmpty() -> item(span = full) { LibraryEmpty(model, list) }
                        else -> items(visible, key = { it.id }) { novel ->
                            LibraryCell(novel) { nav.navigate(NovelRoute(novel.id)) }
                        }
                    }
                }
            }
        }
    }

    if (originSheet && model.originOffered) {
        OriginSheet(
            selected = model.origin,
            count = { model.count(it, list) },
            onSelect = { model.select(it); originSheet = false },
            onDismiss = { originSheet = false },
        )
    }

    if (listSheet && listsOffered) {
        LibraryListsSheet(
            selected = list?.id,
            onSelect = { chosen -> model.select(chosen); listSheet = false },
            onDismiss = { listSheet = false },
        )
    }
}

@Composable
private fun LibraryEmpty(model: LibraryViewModel, list: ReadingList?) {
    val listReason = list?.let { ReadingLists.emptyReason(it, model.novels, model.onShelf) }
    when {
        model.search.isNotBlank() -> EmptyState(
            "No results",
            if (model.filter == Filter.ALL && model.origin == Origin.Filter.ALL) {
                "Nothing on this shelf matches “${model.search}”."
            } else {
                // A series may match the search but not the filters: say which ones are on.
                "Nothing on this shelf matches “${model.search}” with “${filters(model)}”."
            },
            Icons.Outlined.Search,
        )
        list != null && listReason == ReadingLists.Empty.NoSeries -> EmptyState(
            "“${list.name}” is empty",
            if (list.isWantToRead) "Open a series and tap the bookmark at the top to add it here." else "Open a series and tap Lists at the top to add it here.",
            Icons.AutoMirrored.Outlined.List,
        )
        list != null && listReason is ReadingLists.Empty.OtherShelf -> EmptyState(
            "No ${model.shelf.noun} in “${list.name}”",
            "Its ${seriesCount(listReason.count)} ${if (listReason.count == 1) "is" else "are"} on ${otherShelves(model, list)}.",
            Icons.AutoMirrored.Outlined.List,
        )
        model.onShelf.isEmpty() -> EmptyState(
            "No ${model.shelf.noun} yet",
            "Add series on your Novarr server and they'll show up here.",
            Icons.Outlined.CollectionsBookmark,
        )
        list != null -> EmptyState("Nothing here", "Nothing in “${list.name}” matches “${filters(model)}”.", Icons.Outlined.FilterList)
        else -> EmptyState("Nothing here", "Nothing matches “${filters(model)}”.", Icons.Outlined.FilterList)
    }
}

/** "the Manga tab", or "the Manga and Books tabs": the other shelves holding [list]'s series. */
private fun otherShelves(model: LibraryViewModel, list: ReadingList): String {
    val others = model.shelves.filter { it != model.shelf && model.count(it, list) > 0 }.map { it.title }.ifEmpty { listOf("other") }
    return if (others.size == 1) "the ${others[0]} tab" else "the ${others.dropLast(1).joinToString(", ")} and ${others.last()} tabs"
}

/** "Reading", "Korean", or "Reading · Korean": the filters in effect, for the empty state. */
private fun filters(model: LibraryViewModel): String = listOfNotNull(
    model.filter.title.takeIf { model.filter != Filter.ALL || model.origin == Origin.Filter.ALL },
    model.origin.takeIf { it != Origin.Filter.ALL }?.let { if (it == Origin.Filter.UNKNOWN) "Unknown origin" else it.title },
).joinToString(" · ")

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OriginSheet(selected: Origin.Filter, count: (Origin.Filter) -> Int, onSelect: (Origin.Filter) -> Unit, onDismiss: () -> Unit) {
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(
            Modifier
                .readableWidth(640.dp)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp)
                .padding(bottom = 16.dp)
                .navigationBarsPadding()
                .selectableGroup(),
            verticalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            Text("Origin", style = MaterialTheme.typography.titleLarge, modifier = Modifier.padding(bottom = 8.dp).semantics { heading() })
            Origin.Filter.entries.forEach { option ->
                Row(
                    Modifier
                        .fillMaxWidth()
                        .selectable(selected = option == selected, role = Role.RadioButton, onClick = { onSelect(option) })
                        .heightIn(min = 52.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    RadioButton(selected = option == selected, onClick = null)
                    Spacer(Modifier.width(12.dp))
                    Text(option.title, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                    Text(seriesCount(count(option)), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        }
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
        Box {
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
            // On the cover, so it never adds a line under it: bottom-right, clear of the progress line,
            // the new-chapters dot (top-right) and a placeholder's title (top-left) and mark (bottom-left).
            // Outside Cover, whose semantics are cleared, so the tile still reads "Korean" etc.
            OriginBadge(novel, Modifier.align(Alignment.BottomEnd).padding(end = 6.dp, bottom = 16.dp), onCover = true)
        }
        Text(novel.name, style = MaterialTheme.typography.labelLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
        Text(
            novel.caption,
            style = MaterialTheme.typography.labelSmall,
            color = if (novel.chaptersNew > 0) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}
