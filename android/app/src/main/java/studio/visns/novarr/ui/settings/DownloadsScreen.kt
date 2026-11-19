package studio.visns.novarr.ui.settings

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.outlined.DeleteOutline
import androidx.compose.material.icons.outlined.DownloadForOffline
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavController
import kotlinx.coroutines.launch
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.ui.NovelRoute
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.EmptyState
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.formatBytes
import studio.visns.novarr.ui.components.readableWidth

/** Series with chapters saved on this device, with the space they use. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DownloadsScreen(nav: NavController) {
    val app = LocalApp.current
    val scope = rememberCoroutineScope()
    val revision by app.downloads.revision.collectAsStateWithLifecycle()
    var items by remember { mutableStateOf<List<OfflineStore.NovelDownloads>?>(null) }
    LaunchedEffect(revision) { items = app.active?.store?.downloads().orEmpty() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Downloads") },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                },
            )
        },
    ) { padding ->
        val list = items
        LazyColumn(Modifier.fillMaxSize().padding(padding), contentPadding = PaddingValues(bottom = 24.dp)) {
            when {
                list == null -> item { CenteredProgress() }
                list.isEmpty() -> item {
                    EmptyState("No downloads", "Use “Download” on a series to read it without a connection.", Icons.Outlined.DownloadForOffline)
                }
                else -> {
                    items(list, key = { it.novelId }) { item ->
                        Row(
                            Modifier.readableWidth()
                                .clickable(role = Role.Button) { nav.navigate(NovelRoute(item.novelId)) }
                                .padding(start = 16.dp, end = 4.dp, top = 8.dp, bottom = 8.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Column(Modifier.weight(1f).semantics(mergeDescendants = true) {}) {
                                Text(item.name, style = MaterialTheme.typography.bodyLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
                                Text(
                                    "${item.chapterCount} chapters · ${formatBytes(item.bytes)}",
                                    style = MaterialTheme.typography.bodySmall,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                            IconButton(onClick = {
                                scope.launch {
                                    val store = app.active?.store ?: return@launch
                                    app.downloads.remove(item.novelId, store)
                                }
                            }) { Icon(Icons.Outlined.DeleteOutline, contentDescription = "Remove ${item.name} downloads") }
                        }
                        HorizontalDivider(Modifier.readableWidth().padding(horizontal = 16.dp))
                    }
                    item {
                        Text(
                            "Total ${formatBytes(list.sumOf { it.bytes })}.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            modifier = Modifier.readableWidth().padding(16.dp),
                        )
                    }
                }
            }
        }
    }
}
