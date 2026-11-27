package studio.visns.novarr.ui.audiobooks

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyGridItemSpanScope
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Clear
import androidx.compose.material.icons.outlined.Headphones
import androidx.compose.material.icons.outlined.Search
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
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.api.AudiobooksPage
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.ui.PlayerRoute
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.EmptyState
import studio.visns.novarr.ui.components.LoadFailed
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.OfflinePill
import studio.visns.novarr.ui.components.isWide

/** Every audiobook (paged, searchable) and "Continue listening"; the first page is saved for offline. */
class AudiobooksViewModel(private val app: AppModel) : ViewModel() {
    var items by mutableStateOf<List<AudiobookSummary>>(emptyList())
        private set
    var continueItems by mutableStateOf<List<AudiobookSummary>>(emptyList())
        private set
    var loaded by mutableStateOf(false)
        private set
    var hasMore by mutableStateOf(false)
        private set
    var loadingMore by mutableStateOf(false)
        private set
    var failure by mutableStateOf<String?>(null)
        private set
    var fromCache by mutableStateOf(false)
        private set
    var refreshing by mutableStateOf(false)
        private set
    var search by mutableStateOf("")
        private set
    private var page = 1
    private var searchJob: Job? = null

    fun load(pull: Boolean = false) = viewModelScope.launch {
        if (pull) refreshing = true
        val query = search.trim()
        if (!loaded && query.isEmpty()) {
            app.cached(OfflineStore.Key.audiobooks)?.let { show(it); fromCache = true }
            app.cached(OfflineStore.Key.continueListening)?.let { continueItems = it }
        }
        if (query.isEmpty()) {
            val result = app.load(OfflineStore.Key.audiobooks) { it.audiobooks(page = 1) }
            result.value?.let(::show)
            fromCache = result.fromCache || (result.error != null && loaded)
            failure = if (!loaded) result.error?.userMessage ?: "Couldn't load audiobooks." else null
            app.load(OfflineStore.Key.continueListening) { it.continueListening() }.value?.let { continueItems = it }
        } else {
            val session = app.active
            try {
                session?.client?.audiobooks(page = 1, search = query)?.let(::show)
                failure = null
                fromCache = false
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                failure = studio.visns.novarr.api.ApiError.from(e).userMessage
            }
        }
        refreshing = false
    }

    private fun show(result: AudiobooksPage) {
        items = result.audiobooks
        hasMore = result.hasMore
        page = result.page.coerceAtLeast(1)
        loaded = true
    }

    fun loadMore() {
        if (loadingMore || !hasMore) return
        loadingMore = true
        viewModelScope.launch {
            try {
                val next = app.active?.client?.audiobooks(page = page + 1, search = search.trim().ifEmpty { null })
                if (next != null) {
                    items = (items + next.audiobooks).distinctBy { it.id }
                    hasMore = next.hasMore
                    page = next.page.coerceAtLeast(page + 1)
                }
            } catch (e: Exception) {
                e.rethrowIfCancellation()
            }
            loadingMore = false
        }
    }

    /** Searches as the reader types (a short pause first). */
    fun updateSearch(text: String) {
        search = text
        searchJob?.cancel()
        searchJob = viewModelScope.launch {
            delay(350)
            load()
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AudiobooksScreen(nav: NavController) {
    val app = LocalApp.current
    val model = viewModel { AudiobooksViewModel(app) }
    val wide = isWide()
    LaunchedEffect(Unit) { model.load() }

    Scaffold(topBar = { TopAppBar(title = { Text("Audiobooks") }) }) { padding ->
        PullToRefreshBox(
            isRefreshing = model.refreshing,
            onRefresh = { model.load(pull = true) },
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
                item(span = full) {
                    OutlinedTextField(
                        value = model.search,
                        onValueChange = model::updateSearch,
                        placeholder = { Text("Search audiobooks") },
                        leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
                        trailingIcon = {
                            if (model.search.isNotEmpty()) {
                                IconButton(onClick = { model.updateSearch("") }) { Icon(Icons.Outlined.Clear, contentDescription = "Clear search") }
                            }
                        },
                        singleLine = true,
                        shape = CircleShape,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
                if (model.fromCache) {
                    item(span = full) { Box(contentAlignment = Alignment.Center) { OfflinePill() } }
                }
                if (model.search.isBlank() && model.continueItems.isNotEmpty()) {
                    item(span = full) {
                        Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                            Text("Continue listening", style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
                            ContinueListeningRow(model.continueItems, wide) { nav.navigate(PlayerRoute(it.id)) }
                        }
                    }
                    item(span = full) {
                        Text("All audiobooks", style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
                    }
                }
                when {
                    !model.loaded && model.failure != null -> item(span = full) {
                        LoadFailed("Audiobooks unavailable", model.failure.orEmpty()) { model.load() }
                    }
                    !model.loaded -> item(span = full) { CenteredProgress() }
                    model.items.isEmpty() -> item(span = full) {
                        EmptyState(
                            if (model.search.isBlank()) "No audiobooks yet" else "No audiobooks match",
                            if (model.search.isBlank()) "Audiobooks your server's owner adds show up here, and on the book's page."
                            else "Try another title, author or narrator.",
                            Icons.Outlined.Headphones,
                        )
                    }
                    else -> {
                        items(model.items, key = { it.id }) { item ->
                            AudiobookTile(item) { nav.navigate(PlayerRoute(item.id)) }
                        }
                        if (model.hasMore) {
                            item(span = full) {
                                LaunchedEffect(model.items.size) { model.loadMore() }
                                Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                                    if (model.loadingMore) CenteredProgress() else TextButton(onClick = model::loadMore) { Text("Show more") }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
