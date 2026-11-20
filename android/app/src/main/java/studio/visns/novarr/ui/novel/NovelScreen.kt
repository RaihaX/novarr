package studio.visns.novarr.ui.novel

import studio.visns.novarr.ui.theme.LocalEink
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.PlaylistAdd
import androidx.compose.material.icons.automirrored.outlined.PlaylistAddCheck
import androidx.compose.material.icons.outlined.Clear
import androidx.compose.material.icons.outlined.CloudDownload
import androidx.compose.material.icons.outlined.DownloadDone
import androidx.compose.material.icons.outlined.DownloadForOffline
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.WarningAmber
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
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
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import kotlinx.coroutines.launch
import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.api.ChapterListItem
import studio.visns.novarr.api.NovelDetail
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.ui.HighlightsRoute
import studio.visns.novarr.ui.ReaderRoute
import studio.visns.novarr.ui.components.isWide
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.CheckForChaptersControl
import studio.visns.novarr.ui.components.Cover
import studio.visns.novarr.ui.components.LoadFailed
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.OfflinePill
import studio.visns.novarr.ui.components.ProgressLine
import studio.visns.novarr.ui.components.StateBadge
import studio.visns.novarr.ui.components.OriginBadge
import studio.visns.novarr.ui.components.readableWidth
import studio.visns.novarr.ui.highlights.HighlightRow
import studio.visns.novarr.ui.lists.SeriesListsSheet
import studio.visns.novarr.ui.theme.warning

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NovelScreen(nav: NavController, novelId: Int) {
    val app = LocalApp.current
    val model = viewModel { NovelViewModel(app, novelId) }
    val reachable by app.isReachable.collectAsStateWithLifecycle()
    val downloadRevision by app.downloads.revision.collectAsStateWithLifecycle()
    val checkRevisions by app.checks.revisions.collectAsStateWithLifecycle()
    val running by app.checks.running.collectAsStateWithLifecycle()
    val readRevision by app.readStateRevision.collectAsStateWithLifecycle()
    val list = rememberLazyListState()
    val eink = LocalEink.current
    val scope = rememberCoroutineScope()
    var menu by remember { mutableStateOf(false) }
    var searching by rememberSaveable { mutableStateOf(false) }
    var confirmBulk by remember { mutableStateOf<BulkReadRequest?>(null) }
    var confirmRemove by remember { mutableStateOf(false) }
    var showLists by rememberSaveable { mutableStateOf(false) }
    val listsOffered by app.listsOffered.collectAsStateWithLifecycle()
    val listsState by app.lists.state.collectAsStateWithLifecycle()
    val inAnyList = listsState.lists.any { novelId in it }
    // Opened from Home before Library ever loaded: the icon shows whether it is in a list.
    LaunchedEffect(listsOffered) { if (listsOffered && !app.lists.loaded.value) app.lists.refresh() }
    val wide = isWide()

    // Load on open, after a check changed the chapters, and when coming back from the reader.
    LaunchedEffect(checkRevisions[novelId], readRevision) { model.load() }
    LaunchedEffect(downloadRevision) { model.refreshCachedIds() }
    // Reading queued here (sent or not yet) moves Continue at once, offline too.
    val queueRevision by app.queueRevision.collectAsStateWithLifecycle()
    LaunchedEffect(queueRevision) { model.refreshUnsentWrites() }

    fun open(chapterId: Int, offerLatest: Boolean = true) =
        nav.navigate(ReaderRoute(novelId, chapterId, manga = model.isManga, offerLatest = offerLatest))

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(model.summary?.name.orEmpty(), style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis) },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                },
                actions = {
                    // Only on servers with reading lists (meta `lists`).
                    if (listsOffered) {
                        IconButton(onClick = { showLists = true }) {
                            Icon(
                                if (inAnyList) Icons.AutoMirrored.Outlined.PlaylistAddCheck else Icons.AutoMirrored.Outlined.PlaylistAdd,
                                contentDescription = if (inAnyList) "Lists (in ${listsState.lists.count { novelId in it }})" else "Lists",
                            )
                        }
                    }
                    IconButton(onClick = { searching = !searching; if (!searching) model.chapterSearch = "" }) {
                        Icon(Icons.Outlined.Search, contentDescription = if (searching) "Close chapter search" else "Search chapters")
                    }
                    Box {
                        IconButton(onClick = { menu = true }) { Icon(Icons.Outlined.MoreVert, contentDescription = "More") }
                        DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                            model.firstUnread?.let { unread ->
                                DropdownMenuItem(text = { Text("Jump to first unread") }, onClick = {
                                    menu = false
                                    model.chapterSearch = ""
                                    val index = model.chapters.indexOf(unread)
                                    scope.launch { if (eink) list.scrollToItem(headerItems(model) + index) else list.animateScrollToItem(headerItems(model) + index) }
                                })
                            }
                            DropdownMenuItem(
                                text = { Text("Check for new chapters") },
                                enabled = novelId !in running,
                                onClick = { menu = false; app.startCheck(novelId) },
                            )
                            HorizontalDivider()
                            DropdownMenuItem(text = { Text("Mark all read") }, onClick = { menu = false; confirmBulk = BulkReadRequest.all(novelId, true) })
                            DropdownMenuItem(text = { Text("Mark all unread") }, onClick = { menu = false; confirmBulk = BulkReadRequest.all(novelId, false) })
                            HorizontalDivider()
                            val canDownload = model.chapters.isNotEmpty() && reachable
                            DropdownMenuItem(text = { Text("Download all chapters") }, enabled = canDownload, onClick = { menu = false; model.download(false) })
                            DropdownMenuItem(text = { Text("Download unread chapters") }, enabled = canDownload, onClick = { menu = false; model.download(true) })
                            if (model.cachedIds.isNotEmpty()) {
                                DropdownMenuItem(text = { Text("Remove downloads") }, onClick = { menu = false; confirmRemove = true })
                            }
                        }
                    }
                },
            )
        },
    ) { padding ->
        PullToRefreshBox(
            isRefreshing = model.refreshing,
            onRefresh = { model.load(pull = true) },
            modifier = Modifier.fillMaxSize().padding(padding),
        ) {
            LazyColumn(state = list, modifier = Modifier.fillMaxSize(), contentPadding = PaddingValues(bottom = 24.dp)) {
                val summary = model.summary
                when {
                    summary != null -> {
                        item(key = "header") {
                            Column(Modifier.readableWidth().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
                                if (model.fromCache && model.loaded) OfflinePill(modifier = Modifier.align(Alignment.CenterHorizontally))
                                NovelHeader(summary, model.detail, wide)
                                ProgressBlock(summary)
                                PrimaryButton(summary, model.resumeChapterId, model.hasStarted) { id -> open(id) }
                                CheckForChaptersControl(novelId)
                                DownloadBlock(model, reachable, onDownload = { model.download(it) }, onRemove = { confirmRemove = true })
                                model.detail?.extras?.sourceUnavailable?.takeIf { it.count > 0 }?.let { unavailable ->
                                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                        Icon(Icons.Outlined.WarningAmber, contentDescription = null, tint = MaterialTheme.colorScheme.warning)
                                        Text(
                                            unavailable.message.ifBlank { "${unavailable.count} chapters can't be fetched from the source." },
                                            style = MaterialTheme.typography.bodySmall,
                                            color = MaterialTheme.colorScheme.warning,
                                        )
                                    }
                                }
                            }
                        }
                    }
                    model.failure != null -> item { LoadFailed("Couldn't open this series", model.failure.orEmpty()) { model.load() } }
                    else -> item { CenteredProgress() }
                }

                if (model.highlights.isNotEmpty() && model.chapterSearch.isEmpty()) {
                    item(key = "highlights-title") { SectionHeader("Highlights") }
                    items(model.highlights, key = { "h${it.id}" }) { highlight ->
                        HighlightRow(
                            highlight,
                            showsNovel = false,
                            modifier = Modifier.readableWidth(),
                            onClick = { nav.navigate(ReaderRoute(novelId, highlight.chapterId, lookupExcerpt = highlight.excerpt)) },
                        )
                    }
                    if (model.hasMoreHighlights) {
                        item(key = "highlights-all") {
                            TextButton(
                                onClick = { nav.navigate(HighlightsRoute(novelId, model.summary?.name.orEmpty())) },
                                modifier = Modifier.readableWidth().padding(horizontal = 8.dp),
                            ) { Text("All highlights") }
                        }
                    }
                }

                item(key = "chapters-title") {
                    Column(Modifier.readableWidth()) {
                        SectionHeader(
                            "Chapters",
                            trailing = if (model.chapters.isNotEmpty()) "${model.readCount} of ${model.chapters.size} read" else null,
                        )
                        if (searching) {
                            OutlinedTextField(
                                value = model.chapterSearch,
                                onValueChange = { model.chapterSearch = it },
                                placeholder = { Text("Search chapters") },
                                leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
                                trailingIcon = {
                                    if (model.chapterSearch.isNotEmpty()) {
                                        IconButton(onClick = { model.chapterSearch = "" }) { Icon(Icons.Outlined.Clear, contentDescription = "Clear search") }
                                    }
                                },
                                singleLine = true,
                                shape = CircleShape,
                                modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 4.dp),
                            )
                        }
                    }
                }
                val visible = model.visibleChapters
                if (model.loaded && model.chapters.isEmpty()) {
                    item { EmptyNote("No chapters yet.") }
                } else if (visible.isEmpty() && model.chapterSearch.isNotBlank()) {
                    item { EmptyNote("No chapters match “${model.chapterSearch}”.") }
                }
                items(visible, key = { it.id }) { chapter ->
                    ChapterRow(
                        chapter = chapter,
                        offline = chapter.id in model.cachedIds,
                        modifier = Modifier.readableWidth(),
                        onOpen = { open(chapter.id) },
                        onToggleRead = { model.setRead(chapter, !chapter.read) },
                        onReadUpTo = { model.bulk(BulkReadRequest.upTo(chapter.id, novelId, true)) },
                        onUnreadFrom = { model.bulk(BulkReadRequest.from(chapter.id, novelId, false)) },
                    )
                }
            }
        }
    }

    if (showLists && listsOffered) {
        SeriesListsSheet(novelId, model.summary?.name.orEmpty()) { showLists = false }
    }
    confirmBulk?.let { request ->
        AlertDialog(
            onDismissRequest = { confirmBulk = null },
            title = { Text(if (request.read) "Mark every chapter as read?" else "Mark every chapter as unread?") },
            confirmButton = {
                TextButton(onClick = { model.bulk(request); confirmBulk = null }) { Text(if (request.read) "Mark read" else "Mark unread") }
            },
            dismissButton = { TextButton(onClick = { confirmBulk = null }) { Text("Cancel") } },
        )
    }
    if (confirmRemove) {
        AlertDialog(
            onDismissRequest = { confirmRemove = false },
            title = { Text("Remove offline chapters?") },
            text = { Text("Chapters stay on your server; only this device's copies are removed.") },
            confirmButton = {
                TextButton(onClick = { model.removeDownloads(); confirmRemove = false }) {
                    Text("Remove", color = MaterialTheme.colorScheme.error)
                }
            },
            dismissButton = { TextButton(onClick = { confirmRemove = false }) { Text("Cancel") } },
        )
    }
}

/** List items above the first chapter row (for "jump to first unread"). */
private fun headerItems(model: NovelViewModel): Int {
    var count = 2 // header (or its placeholder) + chapters title
    if (model.highlights.isNotEmpty() && model.chapterSearch.isEmpty()) {
        count += 1 + model.highlights.size + if (model.hasMoreHighlights) 1 else 0
    }
    return count
}

@Composable
private fun SectionHeader(title: String, trailing: String? = null) {
    Row(Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 24.dp, bottom = 8.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(title, style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f).semantics { heading() })
        trailing?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
    }
}

@Composable
private fun EmptyNote(text: String) {
    Text(text, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.readableWidth().padding(16.dp))
}

@Composable
private fun NovelHeader(summary: NovelSummary, detail: NovelDetail?, wide: Boolean) {
    var expanded by rememberSaveable { mutableStateOf(false) }
    Column(verticalArrangement = Arrangement.spacedBy(14.dp), modifier = Modifier.padding(top = 8.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(16.dp)) {
            Cover(summary.coverLargeUrl ?: summary.coverUrl, summary.name, Modifier.width(if (wide) 180.dp else 120.dp), corner = 8.dp)
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(
                    summary.name,
                    style = if (wide) MaterialTheme.typography.headlineMedium else MaterialTheme.typography.headlineSmall,
                    modifier = Modifier.semantics { heading() },
                )
                summary.authorOrNull?.let { Text(it, style = MaterialTheme.typography.titleSmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
                // Wraps rather than clips when the badges and the origin caption don't fit (narrow screens, large text).
                FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    StateBadge(summary.state, summary.stateLabel)
                    OriginBadge(summary, Modifier.align(Alignment.CenterVertically))
                    summary.origin?.let {
                        Text(
                            it,
                            style = MaterialTheme.typography.labelSmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                            maxLines = 1,
                            modifier = Modifier.align(Alignment.CenterVertically),
                        )
                    }
                }
                if (summary.isManga) {
                    Text("Manga", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        }
        if (summary.tags.isNotEmpty()) {
            FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                summary.tags.forEach { tag ->
                    Text(
                        tag.name,
                        style = MaterialTheme.typography.labelMedium,
                        modifier = Modifier
                            .background(MaterialTheme.colorScheme.surfaceContainerHigh, CircleShape)
                            .padding(horizontal = 10.dp, vertical = 4.dp),
                    )
                }
            }
        }
        detail?.description?.let { synopsis ->
            Column {
                Text(
                    synopsis,
                    style = MaterialTheme.typography.bodyMedium.copy(fontFamily = studio.visns.novarr.ui.theme.BrandFonts.Literata),
                    maxLines = if (expanded) Int.MAX_VALUE else 4,
                    overflow = TextOverflow.Ellipsis,
                )
                TextButton(onClick = { expanded = !expanded }, contentPadding = PaddingValues(0.dp)) { Text(if (expanded) "Less" else "More") }
            }
        }
    }
}

@Composable
private fun ProgressBlock(summary: NovelSummary) {
    Column(verticalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.semantics(mergeDescendants = true) {}) {
        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row {
                Text("Read", style = MaterialTheme.typography.labelLarge, modifier = Modifier.weight(1f))
                Text(
                    "${summary.chaptersRead} / ${summary.chaptersTotal} · ${summary.readPercent}%",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            ProgressLine(summary.readFraction)
        }
        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row {
                Text("On server", style = MaterialTheme.typography.labelLarge, modifier = Modifier.weight(1f))
                Text(
                    "${summary.chaptersDownloaded} / ${summary.chaptersTotal} · ${summary.downloadPercent}%",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            ProgressLine(summary.downloadPercent / 100f, color = MaterialTheme.colorScheme.secondary)
        }
    }
}

@Composable
private fun PrimaryButton(summary: NovelSummary, resume: Int?, started: Boolean, onOpen: (Int) -> Unit) {
    if (resume != null) {
        Button(onClick = { onOpen(resume) }, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) {
            Text(if (started) "Continue reading" else "Start reading", style = MaterialTheme.typography.titleSmall)
        }
    } else {
        Text(
            if (summary.finished) "You've read everything here." else "No chapters downloaded on the server yet.",
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.fillMaxWidth(),
        )
    }
}

@Composable
private fun DownloadBlock(model: NovelViewModel, reachable: Boolean, onDownload: (unreadOnly: Boolean) -> Unit, onRemove: () -> Unit) {
    val app = LocalApp.current
    val jobs by app.downloads.jobs.collectAsStateWithLifecycle()
    val messages by app.downloads.messages.collectAsStateWithLifecycle()
    val job = jobs[model.novelId]
    var menu by remember { mutableStateOf(false) }
    if (job != null) {
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Saving for offline… ${job.done + job.failed} of ${job.total}", style = MaterialTheme.typography.bodySmall, modifier = Modifier.weight(1f))
                TextButton(onClick = { app.downloads.cancel(model.novelId) }) { Text("Cancel") }
            }
            LinearProgressIndicator(progress = { job.fraction }, modifier = Modifier.fillMaxWidth())
        }
        return
    }
    val offline = model.cachedIds.size
    Column {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(
                if (offline == 0) Icons.Outlined.CloudDownload else Icons.Outlined.DownloadDone,
                contentDescription = null,
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.size(18.dp),
            )
            Text(
                if (offline == 0) "  Not available offline" else "  $offline chapters on this device",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.weight(1f),
            )
            Box {
                TextButton(onClick = { menu = true }, enabled = model.chapters.isNotEmpty() && reachable) {
                    Icon(Icons.Outlined.DownloadForOffline, contentDescription = null, modifier = Modifier.size(18.dp))
                    Text(" Download")
                }
                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                    DropdownMenuItem(text = { Text("Download all chapters") }, onClick = { menu = false; onDownload(false) })
                    DropdownMenuItem(text = { Text("Download unread chapters") }, onClick = { menu = false; onDownload(true) })
                    if (offline > 0) DropdownMenuItem(text = { Text("Remove downloads") }, onClick = { menu = false; onRemove() })
                }
            }
        }
        messages[model.novelId]?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun ChapterRow(
    chapter: ChapterListItem,
    offline: Boolean,
    modifier: Modifier,
    onOpen: () -> Unit,
    onToggleRead: () -> Unit,
    onReadUpTo: () -> Unit,
    onUnreadFrom: () -> Unit,
) {
    var menu by remember { mutableStateOf(false) }
    val toggleLabel = if (chapter.read) "Mark as unread" else "Mark as read"
    Row(
        modifier
            .combinedClickable(role = Role.Button, onLongClick = { menu = true }, onLongClickLabel = "Chapter options", onClick = onOpen)
            .heightIn(min = 56.dp)
            .padding(start = 16.dp, end = 4.dp, top = 6.dp, bottom = 6.dp)
            .semantics(mergeDescendants = true) {
                stateDescription = if (chapter.read) "Read" else "Unread"
                customActions = listOf(
                    CustomAccessibilityAction(toggleLabel) { onToggleRead(); true },
                    CustomAccessibilityAction("Mark read up to here") { onReadUpTo(); true },
                    CustomAccessibilityAction("Mark unread from here") { onUnreadFrom(); true },
                )
            },
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(
            Modifier
                .size(8.dp)
                .background(if (chapter.read || !chapter.downloaded) androidx.compose.ui.graphics.Color.Transparent else MaterialTheme.colorScheme.primary, CircleShape),
        )
        Spacer(Modifier.width(10.dp))
        Column(Modifier.weight(1f)) {
            Text(
                chapter.title,
                style = MaterialTheme.typography.bodyLarge,
                color = if (chapter.read) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            subtitle(chapter)?.let {
                Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
        }
        chapter.readProgress?.takeIf { it in 1..99 && !chapter.read }?.let {
            Text("$it%", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        if (offline) {
            Icon(
                Icons.Outlined.DownloadDone,
                contentDescription = "Available offline",
                tint = MaterialTheme.colorScheme.onSurfaceVariant,
                modifier = Modifier.padding(start = 6.dp).size(18.dp),
            )
        }
        Box {
            IconButton(onClick = { menu = true }) { Icon(Icons.Outlined.MoreVert, contentDescription = "Options for ${chapter.title}") }
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                DropdownMenuItem(text = { Text(toggleLabel) }, onClick = { menu = false; onToggleRead() })
                DropdownMenuItem(text = { Text("Mark read up to here") }, onClick = { menu = false; onReadUpTo() })
                DropdownMenuItem(text = { Text("Mark unread from here") }, onClick = { menu = false; onUnreadFrom() })
            }
        }
    }
}

private fun subtitle(chapter: ChapterListItem): String? {
    val parts = buildList {
        if (chapter.note) add("Note")
        if (!chapter.downloaded) add(chapter.reason?.takeIf { it.isNotBlank() } ?: "Not downloaded yet")
        chapter.wordCount?.takeIf { it > 0 }?.let { add("%,d words".format(it)) }
        chapter.pageCount?.takeIf { it > 0 }?.let { add("$it pages") }
    }
    return parts.takeIf { it.isNotEmpty() }?.joinToString(" · ")
}
