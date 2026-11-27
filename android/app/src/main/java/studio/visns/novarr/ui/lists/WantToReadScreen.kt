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
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.outlined.BookmarkBorder
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import kotlinx.coroutines.launch
import studio.visns.novarr.api.WantToReadItem
import studio.visns.novarr.app.AppModel
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

/** The whole "Want to read" (GET /want-to-read): every medium, newest first; saved for offline. */
class WantToReadViewModel(private val app: AppModel) : ViewModel() {
    var items by mutableStateOf<List<WantToReadItem>>(emptyList())
        private set
    var loaded by mutableStateOf(false)
        private set
    var failure by mutableStateOf<String?>(null)
        private set
    var fromCache by mutableStateOf(false)
        private set
    var refreshing by mutableStateOf(false)
        private set

    fun load(pull: Boolean = false) = viewModelScope.launch {
        if (pull) refreshing = true
        if (!loaded) {
            app.cached(OfflineStore.Key.wantToRead)?.let { items = it.items; loaded = true; fromCache = true }
        }
        val result = app.load(OfflineStore.Key.wantToRead) { it.wantToRead() }
        result.value?.let { items = it.items; loaded = true }
        failure = if (!loaded) result.error?.userMessage ?: "Couldn't load Want to read." else null
        fromCache = result.fromCache || (result.error != null && loaded)
        refreshing = false
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun WantToReadScreen(nav: NavController) {
    val app = LocalApp.current
    val model = viewModel { WantToReadViewModel(app) }
    val listsState by app.lists.state.collectAsStateWithLifecycle()
    val list = ReadingLists.wantToRead(listsState.lists)
    val wide = isWide()
    LaunchedEffect(Unit) {
        model.load()
        if (!app.lists.loaded.value) app.lists.refresh()
    }
    // A series added elsewhere since (the series screen's bookmark): fetch again to show it.
    LaunchedEffect(list?.novelIds) {
        if (model.loaded && ReadingLists.needsRefetch(model.items, list)) model.load()
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(ReadingLists.WANT_TO_READ) },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                },
            )
        },
    ) { padding ->
        PullToRefreshBox(
            isRefreshing = model.refreshing,
            onRefresh = { model.load(pull = true) },
            modifier = Modifier.fillMaxSize().padding(padding),
        ) {
            // Taken off since (here or elsewhere): gone at once.
            val shown = ReadingLists.shown(model.items, list)
            LazyVerticalGrid(
                columns = GridCells.Adaptive(if (wide) 150.dp else 104.dp),
                contentPadding = PaddingValues(horizontal = if (wide) 24.dp else 16.dp, vertical = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(if (wide) 22.dp else 14.dp),
                verticalArrangement = Arrangement.spacedBy(20.dp),
                modifier = Modifier.fillMaxSize(),
            ) {
                val full: LazyGridItemSpanScope.() -> GridItemSpan = { GridItemSpan(maxLineSpan) }
                if (model.fromCache) {
                    item(span = full) { Box(contentAlignment = Alignment.Center) { OfflinePill() } }
                }
                when {
                    !model.loaded && model.failure != null -> item(span = full) {
                        LoadFailed("Want to read unavailable", model.failure.orEmpty()) { model.load() }
                    }
                    !model.loaded -> item(span = full) { CenteredProgress() }
                    shown.isEmpty() -> item(span = full) {
                        EmptyState(
                            "Nothing to read next yet",
                            "Open a novel, manga or book and tap the bookmark at the top: it waits here, and on Home, until you get to it.",
                            Icons.Outlined.BookmarkBorder,
                        )
                    }
                    else -> items(shown, key = { it.novel.id }) { item ->
                        Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            LibraryCell(item.novel) { nav.navigate(NovelRoute(item.novel.id)) }
                            if (ReadingLists.started(item)) {
                                Text("STARTED", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.primary)
                            }
                        }
                    }
                }
            }
        }
    }
}
