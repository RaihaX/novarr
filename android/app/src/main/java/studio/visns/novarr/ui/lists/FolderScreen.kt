package studio.visns.novarr.ui.lists

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyGridItemSpanScope
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.outlined.FavoriteBorder
import androidx.compose.material.icons.outlined.Folder
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import kotlinx.coroutines.launch
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.Categories
import studio.visns.novarr.domain.HomeShelves
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import studio.visns.novarr.domain.ReadingLists
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.ui.NovelRoute
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.EmptyState
import studio.visns.novarr.ui.components.LoadFailed
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.OfflinePill
import studio.visns.novarr.ui.components.isWide
import studio.visns.novarr.ui.library.LibraryCell

/** The library's series by id, from the saved copy then the server (Home, folders). */
class LibraryNovels(private val app: AppModel) {
    var novels by mutableStateOf<Map<Int, NovelSummary>>(emptyMap())
        private set
    var loaded by mutableStateOf(false)
        private set
    var fromCache by mutableStateOf(false)
        private set
    var failure by mutableStateOf<String?>(null)
        private set

    /** The saved copy at once; the server's when [fetch] (or when nothing is saved). */
    suspend fun load(fetch: Boolean) {
        if (!loaded) {
            app.cached(OfflineStore.Key.novels)?.let { novels = it.associateBy { n -> n.id }; loaded = true; fromCache = true }
        }
        if (!fetch && loaded) return
        val result = app.load(OfflineStore.Key.novels) { it.novels() }
        result.value?.let { novels = it.associateBy { n -> n.id }; loaded = true }
        fromCache = result.fromCache || (result.error != null && loaded)
        failure = if (!loaded) result.error?.userMessage ?: "Couldn't load your library." else null
    }

    /** Shows [ids] marked read or unread at once (a multi-select's mark); answers how to put them back. */
    fun mark(ids: Set<Int>, read: Boolean): () -> Unit {
        val before = novels.filterKeys { it in ids }
        novels = novels.mapValues { (id, novel) -> if (id in ids) Categories.marked(novel, read) else novel }
        return { novels = novels.mapValues { (id, novel) -> before[id] ?: novel } }
    }
}

class FolderViewModel(app: AppModel) : ViewModel() {
    val library = LibraryNovels(app)
    var refreshing by mutableStateOf(false)
        private set

    /** Long-press multi-select inside the category. */
    val selection = SeriesSelection(app, viewModelScope)

    fun markRead(read: Boolean) = selection.markRead(read) { ids, value -> library.mark(ids, value) }

    fun load(fetch: Boolean, pull: Boolean = false) = viewModelScope.launch {
        if (pull) refreshing = true
        library.load(fetch)
        refreshing = false
    }
}

/**
 * One category (a reading list; Home's "See all"): its series as covers,
 * newest first for the built-ins, with the same long-press multi-select as
 * the Library. The reader's own categories can be renamed and deleted here.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun FolderScreen(nav: NavController, listId: Int) {
    val app = LocalApp.current
    val model = viewModel { FolderViewModel(app) }
    val listsState by app.lists.state.collectAsStateWithLifecycle()
    val loadedLists by app.lists.loaded.collectAsStateWithLifecycle()
    val reachable by app.isReachable.collectAsStateWithLifecycle()
    val list = listsState.lists.firstOrNull { it.id == listId }
    val wide = isWide()
    var menu by remember { mutableStateOf(false) }
    var renaming by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) {
        model.load(fetch = false)
        if (!app.lists.loaded.value) app.lists.refresh()
    }
    // A series the library copy doesn't know yet (added elsewhere): the library is fetched again.
    LaunchedEffect(list?.novelIds, model.library.loaded) {
        if (model.library.loaded && list != null && list.novelIds.any { it !in model.library.novels }) model.load(fetch = true)
    }
    // Read state changed elsewhere (a mark sent, reading): the covers follow.
    val revision by app.readStateRevision.collectAsStateWithLifecycle()
    LaunchedEffect(revision) { if (model.library.loaded) model.load(fetch = true) }
    val snackbar = remember { SnackbarHostState() }
    val selection = model.selection
    SelectionEffects(selection, snackbar)
    val longPress = rememberLongPress(selection::longPress)
    val shown = HomeShelves.shelf(list, model.library.novels)
    // Taken out of this category (here or elsewhere): no longer selected.
    LaunchedEffect(shown) { selection.retain(shown.map { it.id }) }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            if (selection.active) {
                val chosen = shown.filter { it.id in selection.selection }
                SelectionTopBar(
                    count = selection.selection.count,
                    allSelected = selection.selection.allSelected(shown.map { it.id }),
                    onClose = selection::exit,
                    onSelectAll = { selection.selectAll(shown.map { it.id }) },
                    categorise = { selection.openCategories() },
                    markRead = model::markRead,
                    download = { selection.download(chosen) },
                    removeDownload = { selection.removeDownloads(chosen.map { it.id }) },
                    enabled = !selection.working,
                )
                return@Scaffold
            }
            TopAppBar(
                title = { Text(list?.name ?: "Category") },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                },
                actions = {
                    // The built-ins are never renamed or deleted.
                    if (list != null && !list.isBuiltIn) {
                        Box {
                            IconButton(onClick = { menu = true }) { Icon(Icons.Outlined.MoreVert, contentDescription = "Category options") }
                            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                                DropdownMenuItem(text = { Text("Rename") }, enabled = reachable, onClick = { menu = false; renaming = true })
                                DropdownMenuItem(
                                    text = { Text("Delete", color = if (reachable) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant) },
                                    enabled = reachable,
                                    onClick = { menu = false; deleting = true },
                                )
                            }
                        }
                    }
                },
            )
        },
    ) { padding ->
        PullToRefreshBox(
            isRefreshing = model.refreshing,
            onRefresh = {
                model.load(fetch = true, pull = true)
            },
            modifier = Modifier.fillMaxSize().padding(padding),
        ) {
            LazyVerticalGrid(
                columns = GridCells.Adaptive(if (wide) 150.dp else 104.dp),
                contentPadding = PaddingValues(horizontal = if (wide) 24.dp else 16.dp, vertical = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(if (wide) 22.dp else 14.dp),
                verticalArrangement = Arrangement.spacedBy(20.dp),
                modifier = Modifier.fillMaxSize(),
            ) {
                val full: LazyGridItemSpanScope.() -> GridItemSpan = { GridItemSpan(maxLineSpan) }
                if (model.library.fromCache) item(span = full) { Box(contentAlignment = Alignment.Center) { OfflinePill() } }
                when {
                    list == null && loadedLists -> item(span = full) {
                        EmptyState("This category is gone", "It may have been deleted on another device.", Icons.Outlined.Folder)
                    }
                    list == null || (!model.library.loaded && model.library.failure == null) -> item(span = full) { CenteredProgress() }
                    !model.library.loaded -> item(span = full) {
                        LoadFailed("Category unavailable", model.library.failure.orEmpty()) { model.load(fetch = true) }
                    }
                    shown.isEmpty() -> item(span = full) {
                        EmptyState(
                            "“${list.name}” is empty",
                            when {
                                list.isFavourites -> "Open a book, web novel or manga and tap the heart at the top to keep it here."
                                list.isWantToRead -> "Open a series and tap the bookmark at the top to add it here."
                                else -> "Long-press series in the Library (or tap Categories on a series page) to put them here."
                            },
                            if (list.isFavourites) Icons.Outlined.FavoriteBorder else Icons.Outlined.Folder,
                        )
                    }
                    else -> items(shown, key = { it.id }) { novel ->
                        Column {
                            LibraryCell(novel, selected = novel.id in selection.selection, onLongClick = { longPress(novel.id) }) {
                                if (!selection.tap(novel.id)) nav.navigate(NovelRoute(novel.id))
                            }
                        }
                    }
                }
            }
        }
    }
    selection.categoriesFor?.let { ids ->
        SetCategoriesSheet(
            ids,
            onDismiss = { selection.categoriesFor = null },
            onDone = { failure ->
                selection.categoriesFor = null
                selection.exit()
                selection.notice = failure ?: "Categories saved."
            },
        )
    }
    if (renaming && list != null) RenameListDialog(list, listsState.lists) { renaming = false }
    if (deleting && list != null) DeleteListDialog(list, onDeleted = { nav.popBackStack() }) { deleting = false }
}

/** "New folder": a name checked as it is typed; made on the server (lists are never changed offline). */
@Composable
fun NewFolderDialog(onDone: () -> Unit) {
    val app = LocalApp.current
    val scope = rememberCoroutineScope()
    val lists = app.lists.state.collectAsStateWithLifecycle().value.lists
    var name by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var sending by remember { mutableStateOf(false) }
    val problem = if (name.isBlank()) null else ReadingLists.nameProblem(name, lists)
    val canCreate = !sending && name.isNotBlank() && problem == null
    fun create() {
        if (!canCreate) return
        sending = true
        scope.launch {
            val failure = app.lists.create(name).exceptionOrNull()?.message
            sending = false
            if (failure == null) onDone() else error = failure
        }
    }
    AlertDialog(
        onDismissRequest = { if (!sending) onDone() },
        title = { Text("New category") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    "Group anything — books, manga, web novels — like “Manga – Action”. Then long-press series in the Library to put them in it.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it; error = null },
                    label = { Text("Name") },
                    singleLine = true,
                    isError = (problem ?: error) != null,
                    supportingText = {
                        val message = problem ?: error
                        Text(
                            message ?: "${ReadingLists.length(name)} / ${ReadingLists.NAME_MAX}",
                            modifier = if (message != null) Modifier.semantics { liveRegion = LiveRegionMode.Polite } else Modifier,
                        )
                    },
                    keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, imeAction = ImeAction.Done),
                    keyboardActions = KeyboardActions(onDone = { create() }),
                )
            }
        },
        confirmButton = { TextButton(onClick = { create() }, enabled = canCreate) { Text("Create") } },
        dismissButton = { TextButton(onClick = onDone, enabled = !sending) { Text("Cancel") } },
    )
}
