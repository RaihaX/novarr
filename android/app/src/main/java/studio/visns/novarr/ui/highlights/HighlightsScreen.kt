package studio.visns.novarr.ui.highlights

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.Notes
import androidx.compose.material.icons.outlined.Bookmarks
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
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
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import android.content.ClipData
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.platform.ClipEntry
import androidx.compose.ui.platform.LocalClipboard
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter
import java.time.format.FormatStyle
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.Highlight
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.ui.HighlightsRoute
import studio.visns.novarr.ui.ReaderRoute
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.EmptyState
import studio.visns.novarr.ui.components.LoadFailed
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.readableWidth

class HighlightsViewModel(private val app: AppModel, private val novelId: Int?) : ViewModel() {
    var highlights by mutableStateOf<List<Highlight>>(emptyList())
        private set
    var loaded by mutableStateOf(false)
        private set
    var failure by mutableStateOf<String?>(null)
        private set
    var refreshing by mutableStateOf(false)
        private set
    var hasMore by mutableStateOf(false)
        private set
    var loadingMore by mutableStateOf(false)
        private set
    var actionError by mutableStateOf<String?>(null)
    private var page = 1
    private var generation = 0

    init { load() }

    fun load(pull: Boolean = false) = viewModelScope.launch {
        val client = app.active?.client ?: return@launch
        val current = ++generation
        if (pull) refreshing = true
        try {
            val answer = client.highlights(novelId, 1, PER_PAGE)
            if (current != generation) return@launch
            app.noteSuccess()
            highlights = answer.highlights
            hasMore = answer.hasMore
            page = 1
            failure = null
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            if (current != generation) return@launch
            val error = ApiError.from(e)
            app.noteFailure(error)
            if (loaded) actionError = message(error) else failure = message(error)
        } finally {
            loaded = true
            refreshing = false
        }
    }

    fun loadMore() {
        if (!hasMore || loadingMore) return
        val client = app.active?.client ?: return
        val current = generation
        loadingMore = true
        viewModelScope.launch {
            try {
                val answer = client.highlights(novelId, page + 1, PER_PAGE)
                if (current != generation) return@launch
                page += 1
                highlights = (highlights + answer.highlights).distinctBy { it.id }
                hasMore = answer.hasMore
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                actionError = message(ApiError.from(e))
            } finally {
                loadingMore = false
            }
        }
    }

    /** Removes it straight away and puts it back if the server refuses. */
    fun delete(highlight: Highlight) {
        val client = app.active?.client ?: return
        val index = highlights.indexOfFirst { it.id == highlight.id }.takeIf { it >= 0 } ?: return
        highlights = highlights.filterNot { it.id == highlight.id }
        viewModelScope.launch {
            try {
                client.deleteHighlight(highlight.id)
                app.noteSuccess()
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                val error = ApiError.from(e)
                app.noteFailure(error)
                highlights = highlights.toMutableList().apply { add(index.coerceAtMost(size), highlight) }
                actionError = if (error.isConnectivity) {
                    "Couldn't delete the highlight: the server can't be reached. Try again when you're back online."
                } else {
                    "Couldn't delete the highlight. ${error.userMessage}"
                }
            }
        }
    }

    private fun message(error: ApiError) =
        if (error is ApiError.Http && error.status == 404) "This Novarr server doesn't offer highlights yet. Update the server to see them here."
        else error.userMessage

    companion object {
        const val PER_PAGE = 50
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HighlightsScreen(nav: NavController, route: HighlightsRoute) {
    val app = LocalApp.current
    val novelId = route.novelId.takeIf { it > 0 }
    val model = viewModel { HighlightsViewModel(app, novelId) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text("Highlights")
                        if (route.novelName.isNotBlank()) {
                            Text(route.novelName, style = MaterialTheme.typography.labelMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                    }
                },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                },
            )
        },
    ) { padding ->
        PullToRefreshBox(model.refreshing, onRefresh = { model.load(pull = true) }, modifier = Modifier.fillMaxSize().padding(padding)) {
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(bottom = 24.dp)) {
                when {
                    model.highlights.isEmpty() && model.failure != null -> item {
                        LoadFailed("Couldn't load highlights", model.failure.orEmpty()) { model.load() }
                    }
                    model.highlights.isEmpty() && model.loaded -> item {
                        EmptyState(
                            "No highlights yet",
                            "Passages you highlight while reading, here, on your Novarr website or on your e-reader, show up here, newest first.",
                            Icons.Outlined.Bookmarks,
                        )
                    }
                    model.highlights.isEmpty() -> item { CenteredProgress() }
                    else -> {
                        items(model.highlights, key = { it.id }) { highlight ->
                            LaunchedEffect(highlight.id) { if (highlight.id == model.highlights.last().id) model.loadMore() }
                            HighlightRow(
                                highlight,
                                showsNovel = novelId == null,
                                modifier = Modifier.readableWidth(),
                                onClick = { nav.navigate(ReaderRoute(highlight.novelId, highlight.chapterId, lookupExcerpt = highlight.excerpt)) },
                                onDelete = { model.delete(highlight) },
                            )
                            HorizontalDivider(Modifier.readableWidth().padding(horizontal = 16.dp))
                        }
                        if (model.loadingMore) item { CenteredProgress() }
                    }
                }
            }
        }
    }

    model.actionError?.let { message ->
        AlertDialog(
            onDismissRequest = { model.actionError = null },
            title = { Text("Highlight") },
            text = { Text(message) },
            confirmButton = { TextButton(onClick = { model.actionError = null }) { Text("OK") } },
        )
    }
}

/** An excerpt with its accent bar, optional note and "Series · Chapter · date" caption. */
@Composable
fun HighlightRow(highlight: Highlight, showsNovel: Boolean, modifier: Modifier = Modifier, onClick: () -> Unit, onDelete: (() -> Unit)? = null) {
    var menu by remember { mutableStateOf(false) }
    val clipboard = LocalClipboard.current
    val scope = rememberCoroutineScope()
    Row(
        modifier
            .clickable(role = Role.Button, onClickLabel = "Open at this passage", onClick = onClick)
            .padding(start = 16.dp, end = 4.dp, top = 12.dp, bottom = 12.dp),
    ) {
        Column(Modifier.weight(1f).semantics(mergeDescendants = true) {}, verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(Modifier.height(IntrinsicSize.Min)) {
                Box(Modifier.width(3.dp).fillMaxHeight().background(MaterialTheme.colorScheme.primary))
                Text(
                    highlight.excerpt,
                    style = MaterialTheme.typography.bodyLarge.copy(fontFamily = studio.visns.novarr.ui.theme.BrandFonts.Literata),
                    maxLines = 6,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.padding(start = 10.dp),
                )
            }
            highlight.noteOrNull?.let { note ->
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    Icon(Icons.AutoMirrored.Outlined.Notes, contentDescription = "Note", modifier = Modifier.width(16.dp))
                    Text(note, style = MaterialTheme.typography.bodySmall, maxLines = 4, overflow = TextOverflow.Ellipsis)
                }
            }
            Text(caption(highlight, showsNovel), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 2)
        }
        if (onDelete != null) {
            Box {
                IconButton(onClick = { menu = true }) { Icon(Icons.Outlined.MoreVert, contentDescription = "Highlight options") }
                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                    DropdownMenuItem(text = { Text("Copy excerpt") }, onClick = {
                        menu = false
                        scope.launch { clipboard.setClipEntry(ClipEntry(ClipData.newPlainText("Highlight", highlight.excerpt))) }
                    })
                    DropdownMenuItem(text = { Text("Delete", color = MaterialTheme.colorScheme.error) }, onClick = { menu = false; onDelete() })
                }
            }
        }
    }
}

private fun caption(highlight: Highlight, showsNovel: Boolean): String = buildList {
    if (showsNovel && highlight.novelName.isNotBlank()) add(highlight.novelName)
    add(highlight.chapterLabel)
    highlight.createdAt?.let { raw ->
        runCatching { OffsetDateTime.parse(raw).format(DateTimeFormatter.ofLocalizedDate(FormatStyle.MEDIUM)) }.getOrNull()?.let(::add)
    }
}.joinToString(" · ")
