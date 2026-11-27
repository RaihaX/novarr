package studio.visns.novarr.ui.audiobooks

import android.os.SystemClock
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.List
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.filled.SkipNext
import androidx.compose.material.icons.filled.SkipPrevious
import androidx.compose.material.icons.outlined.Bedtime
import androidx.compose.material.icons.outlined.BookmarkAdd
import androidx.compose.material.icons.outlined.Bookmarks
import androidx.compose.material.icons.outlined.DeleteOutline
import androidx.compose.material.icons.outlined.DownloadDone
import androidx.compose.material.icons.outlined.DownloadForOffline
import androidx.compose.material.icons.outlined.Forward30
import androidx.compose.material.icons.outlined.KeyboardArrowDown
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material.icons.outlined.Replay
import androidx.compose.material.icons.outlined.Forward10
import androidx.compose.material.icons.outlined.Replay10
import androidx.compose.material.icons.outlined.Replay30
import androidx.compose.material.icons.outlined.Speed
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledIconButton
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Slider
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavController
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import studio.visns.novarr.app.Notifications
import studio.visns.novarr.domain.ListeningSync
import studio.visns.novarr.domain.SleepTimer
import studio.visns.novarr.playback.AudiobookDownloadState
import studio.visns.novarr.playback.NowPlaying
import studio.visns.novarr.store.ListeningPrefs
import studio.visns.novarr.ui.NovelRoute
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.Cover
import studio.visns.novarr.ui.components.LoadFailed
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.formatBytes
import studio.visns.novarr.ui.components.rememberNotificationPermissionRequest
import studio.visns.novarr.ui.components.readableWidth

private enum class PlayerSheet { SPEED, SLEEP, CHAPTERS, BOOKMARKS }

/** The full-screen player for audiobook [audiobookId]; it opens it (paused, at its resume position) if another is loaded. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun PlayerScreen(nav: NavController, audiobookId: Int) {
    val app = LocalApp.current
    val listening = app.listening
    val nowPlaying by listening.nowPlaying.collectAsStateWithLifecycle()
    val openError by listening.lastError.collectAsStateWithLifecycle()
    val prefs by app.prefs.listening.collectAsStateWithLifecycle(ListeningPrefs())
    val scope = rememberCoroutineScope()
    val snackbar = remember { SnackbarHostState() }
    var sheet by remember { mutableStateOf<PlayerSheet?>(null) }
    var menu by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }
    val context = androidx.compose.ui.platform.LocalContext.current
    val askNotifications = rememberNotificationPermissionRequest { listening.play() }
    val askForDownload = rememberNotificationPermissionRequest()

    LaunchedEffect(audiobookId) { if (listening.nowPlaying.value?.id != audiobookId) listening.open(audiobookId, play = false) }
    val now = nowPlaying?.takeIf { it.id == audiobookId }
    val download by remember(audiobookId) { app.audiobookDownloads.state(audiobookId) }.collectAsStateWithLifecycle(AudiobookDownloadState.None)

    /** Play, asking once (Android 13+) for notifications first: the download and playback notifications need them. */
    fun play() {
        if (now?.playing == true) return listening.pause()
        scope.launch {
            if (Notifications.needsPermission(context) && !app.prefs.listeningNotifyAsked()) {
                app.prefs.setListeningNotifyAsked()
                askNotifications()
            } else {
                listening.play()
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Now playing", style = MaterialTheme.typography.titleMedium) },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.Outlined.KeyboardArrowDown, contentDescription = "Close player") }
                },
                actions = {
                    if (now != null) {
                        DownloadAction(download, onDownload = {
                            scope.launch {
                                // The download's progress notification needs the permission; it starts regardless.
                                if (Notifications.needsPermission(context) && !app.prefs.listeningNotifyAsked()) {
                                    app.prefs.setListeningNotifyAsked()
                                    askForDownload()
                                }
                                app.audiobookDownloads.start(audiobookId, now.detail.summary.title)
                            }
                        }, onCancel = { app.audiobookDownloads.cancel(audiobookId) }, onDelete = { confirmDelete = true })
                        androidx.compose.foundation.layout.Box {
                            IconButton(onClick = { menu = true }) { Icon(Icons.Outlined.MoreVert, contentDescription = "More") }
                            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                                DropdownMenuItem(
                                    text = { Text("Skip silence") },
                                    trailingIcon = { Switch(checked = prefs.skipSilence, onCheckedChange = null) },
                                    onClick = { listening.setSkipSilence(!prefs.skipSilence) },
                                )
                                DropdownMenuItem(
                                    text = { Text("Volume boost") },
                                    trailingIcon = { Switch(checked = prefs.boost, onCheckedChange = null) },
                                    onClick = { listening.setBoost(!prefs.boost) },
                                )
                                now.detail.summary.novelId?.let { novelId ->
                                    HorizontalDivider()
                                    DropdownMenuItem(text = { Text("Open the book") }, onClick = { menu = false; nav.navigate(NovelRoute(novelId)) })
                                }
                            }
                        }
                    }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        when {
            now != null -> PlayerBody(
                now = now,
                prefs = prefs,
                download = download,
                modifier = Modifier.fillMaxSize().padding(padding),
                onPlay = ::play,
                onSheet = { sheet = it },
                onBookmark = {
                    scope.launch { listening.addBookmark(null)?.let { snackbar.showSnackbar(it) } ?: snackbar.showSnackbar("Bookmark added.") }
                },
            )
            openError != null -> LoadFailed("Couldn't open this audiobook", openError.orEmpty(), Modifier.padding(padding).padding(top = 60.dp)) {
                listening.open(audiobookId, play = false)
            }
            else -> CenteredProgress(Modifier.padding(padding))
        }
    }

    if (now != null) {
        when (sheet) {
            PlayerSheet.SPEED -> SpeedSheet(now.speed, onSpeed = listening::setSpeed) { sheet = null }
            PlayerSheet.SLEEP -> SleepSheet(now, onDismiss = { sheet = null })
            PlayerSheet.CHAPTERS -> ChaptersSheet(now, onDismiss = { sheet = null })
            PlayerSheet.BOOKMARKS -> BookmarksSheet(now, snackbar, onDismiss = { sheet = null })
            null -> Unit
        }
    }
    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text("Remove the download?") },
            text = { Text("The audiobook stays on your server; only this device's copy is removed.") },
            confirmButton = {
                TextButton(onClick = { confirmDelete = false; scope.launch { app.audiobookDownloads.remove(audiobookId) } }) { Text("Remove") }
            },
            dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text("Cancel") } },
        )
    }
}

@Composable
private fun DownloadAction(state: AudiobookDownloadState, onDownload: () -> Unit, onCancel: () -> Unit, onDelete: () -> Unit) {
    when (state) {
        is AudiobookDownloadState.Done -> IconButton(onClick = onDelete) {
            Icon(Icons.Outlined.DownloadDone, contentDescription = "Downloaded (${formatBytes(state.bytes)}). Remove download")
        }
        is AudiobookDownloadState.Running -> IconButton(onClick = onCancel, modifier = Modifier.semantics { contentDescription = "Downloading, ${(state.fraction * 100).toInt()}%. Cancel" }) {
            if (state.total > 0) CircularProgressIndicator(progress = { state.fraction }, modifier = Modifier.size(22.dp), strokeWidth = 2.dp)
            else CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp)
        }
        is AudiobookDownloadState.Waiting -> TextButton(onClick = onCancel) { Text(if (state.wifiOnly) "Waiting for Wi-Fi" else "Waiting…") }
        else -> IconButton(onClick = onDownload) { Icon(Icons.Outlined.DownloadForOffline, contentDescription = "Download for offline") }
    }
}

@Composable
private fun PlayerBody(
    now: NowPlaying,
    prefs: ListeningPrefs,
    download: AudiobookDownloadState,
    modifier: Modifier,
    onPlay: () -> Unit,
    onSheet: (PlayerSheet) -> Unit,
    onBookmark: () -> Unit,
) {
    val listening = LocalApp.current.listening
    val summary = now.detail.summary
    val timeline = now.timeline
    // While a thumb is dragged the slider shows where it will go; the player moves on release.
    var dragging by remember { mutableStateOf<Pair<Boolean, Float>?>(null) }
    val position = dragging?.let { (book, value) -> if (book) value.toLong() else (timeline.chapterAt(now.positionMs)?.startMs ?: 0) + value.toLong() } ?: now.positionMs
    val (chapterElapsed, chapterLength) = timeline.chapterProgress(position)

    Column(
        modifier.verticalScroll(rememberScrollState()).padding(horizontal = 20.dp, vertical = 8.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Cover(summary.coverUrl, summary.title, Modifier.widthIn(max = 280.dp).fillMaxWidth(0.72f), corner = 12.dp)
        Spacer(Modifier.height(4.dp))
        Text(summary.title, style = MaterialTheme.typography.titleLarge, textAlign = TextAlign.Center, maxLines = 2, overflow = TextOverflow.Ellipsis)
        listOfNotNull(summary.authorOrNull, summary.narratorOrNull?.let { "read by $it" }).joinToString(" · ").takeIf { it.isNotEmpty() }?.let {
            Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center, maxLines = 2)
        }
        timeline.chapterAt(position)?.let { chapter ->
            Text(
                chapter.label,
                style = MaterialTheme.typography.titleSmall,
                color = MaterialTheme.colorScheme.primary,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.clickable { onSheet(PlayerSheet.CHAPTERS) },
            )
        }
        now.error?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall, textAlign = TextAlign.Center) }

        Column(Modifier.readableWidth(560.dp)) {
            // The chapter scrubber.
            Slider(
                value = chapterElapsed.toFloat(),
                onValueChange = { dragging = false to it },
                onValueChangeFinished = {
                    dragging?.let { (_, value) -> listening.seekTo((timeline.chapterAt(now.positionMs)?.startMs ?: 0) + value.toLong()) }
                    dragging = null
                },
                valueRange = 0f..chapterLength.coerceAtLeast(1).toFloat(),
                modifier = Modifier.semantics { contentDescription = "Position in chapter"; stateDescription = "${ListeningSync.clock(chapterElapsed)} of ${ListeningSync.clock(chapterLength)}" },
            )
            Row {
                Text(ListeningSync.clock(chapterElapsed), style = MaterialTheme.typography.labelMedium, modifier = Modifier.weight(1f))
                Text("-" + ListeningSync.clock(chapterLength - chapterElapsed), style = MaterialTheme.typography.labelMedium)
            }
        }

        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            IconButton(onClick = listening::previousChapter) { Icon(Icons.Filled.SkipPrevious, contentDescription = "Previous chapter") }
            SkipButton(skipIcon(prefs.skipBackSeconds, back = true), "Back ${prefs.skipBackSeconds} seconds", prefs.skipBackSeconds, forward = false, onClick = listening::skipBack)
            FilledIconButton(onClick = onPlay, modifier = Modifier.size(72.dp)) {
                if (now.buffering) {
                    CircularProgressIndicator(Modifier.size(28.dp), color = MaterialTheme.colorScheme.onPrimary, strokeWidth = 3.dp)
                } else {
                    Icon(
                        if (now.playing) Icons.Filled.Pause else Icons.Filled.PlayArrow,
                        contentDescription = if (now.playing) "Pause" else "Play",
                        modifier = Modifier.size(40.dp),
                    )
                }
            }
            SkipButton(skipIcon(prefs.skipForwardSeconds, back = false), "Forward ${prefs.skipForwardSeconds} seconds", prefs.skipForwardSeconds, forward = true, onClick = listening::skipForward)
            IconButton(onClick = listening::nextChapter, enabled = timeline.nextChapterTarget(now.positionMs) != null) {
                Icon(Icons.Filled.SkipNext, contentDescription = "Next chapter")
            }
        }

        // The whole book.
        Column(Modifier.readableWidth(560.dp)) {
            Slider(
                value = position.toFloat(),
                onValueChange = { dragging = true to it },
                onValueChangeFinished = {
                    dragging?.let { (_, value) -> listening.seekTo(value.toLong()) }
                    dragging = null
                },
                valueRange = 0f..timeline.totalMs.coerceAtLeast(1).toFloat(),
                modifier = Modifier.semantics { contentDescription = "Position in book"; stateDescription = "${ListeningSync.clock(position)} of ${ListeningSync.clock(timeline.totalMs)}" },
            )
            Row {
                Text(ListeningSync.clock(position), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.weight(1f))
                val left = ((timeline.totalMs - position) / now.speed.coerceAtLeast(0.5f)).toLong()
                Text(
                    "${ListeningSync.length(left)} left" + if (now.speed != 1f) " at ${speedLabel(now.speed)}" else "",
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }

        FlowRow(horizontalArrangement = Arrangement.spacedBy(4.dp, Alignment.CenterHorizontally), modifier = Modifier.fillMaxWidth()) {
            ToolButton(Icons.Outlined.Speed, speedLabel(now.speed), "Playback speed ${speedLabel(now.speed)}") { onSheet(PlayerSheet.SPEED) }
            SleepToolButton(now) { onSheet(PlayerSheet.SLEEP) }
            ToolButton(Icons.AutoMirrored.Outlined.List, "Chapters", "Chapters") { onSheet(PlayerSheet.CHAPTERS) }
            ToolButton(Icons.Outlined.BookmarkAdd, "Bookmark", "Add a bookmark here", onBookmark)
            ToolButton(Icons.Outlined.Bookmarks, "Bookmarks", "Bookmarks (${now.bookmarks.size})") { onSheet(PlayerSheet.BOOKMARKS) }
        }
        when (download) {
            is AudiobookDownloadState.Running -> Column(Modifier.readableWidth(560.dp)) {
                Text("Downloading for offline…", style = MaterialTheme.typography.bodySmall)
                LinearProgressIndicator(progress = { download.fraction }, modifier = Modifier.fillMaxWidth())
            }
            is AudiobookDownloadState.Failed -> Text(download.message, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error)
            else -> if (now.local) Text("Playing from this device", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Spacer(Modifier.height(16.dp))
    }
}

/** Numbered icons exist for 10 and 30 s; other intervals show the plain arrow with the number under it. */
private fun skipIcon(seconds: Int, back: Boolean): ImageVector = when {
    back && seconds == 10 -> Icons.Outlined.Replay10
    back && seconds == 30 -> Icons.Outlined.Replay30
    !back && seconds == 10 -> Icons.Outlined.Forward10
    !back && seconds == 30 -> Icons.Outlined.Forward30
    else -> Icons.Outlined.Replay
}

@Composable
private fun SkipButton(icon: ImageVector, description: String, seconds: Int, forward: Boolean, onClick: () -> Unit) {
    IconButton(onClick = onClick, modifier = Modifier.size(56.dp)) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            val numbered = seconds == 10 || seconds == 30
            // Forward without a numbered icon: the replay arrow, mirrored.
            val mirror = forward && !numbered
            Icon(icon, contentDescription = description, modifier = Modifier.size(30.dp).graphicsLayer { if (mirror) scaleX = -1f })
            if (!numbered) Text("$seconds", style = MaterialTheme.typography.labelSmall)
        }
    }
}

@Composable
private fun ToolButton(icon: ImageVector, label: String, description: String, onClick: () -> Unit) {
    TextButton(onClick = onClick, modifier = Modifier.semantics { contentDescription = description }, contentPadding = PaddingValues(horizontal = 10.dp)) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Icon(icon, contentDescription = null)
            Text(label, style = MaterialTheme.typography.labelSmall, maxLines = 1)
        }
    }
}

@Composable
private fun SleepToolButton(now: NowPlaying, onClick: () -> Unit) {
    // The countdown ticks on its own clock (the player's position may not change while paused).
    var clock by remember { mutableLongStateOf(SystemClock.elapsedRealtime()) }
    LaunchedEffect(now.sleep) {
        while (now.sleep is SleepTimer.Duration) {
            clock = SystemClock.elapsedRealtime()
            delay(1000)
        }
    }
    val label = SleepTimer.label(now.sleep, clock)
    ToolButton(Icons.Outlined.Bedtime, label ?: "Sleep", if (label != null) "Sleep timer: $label" else "Sleep timer", onClick)
}

fun speedLabel(speed: Float): String {
    val text = "%.2f".format(java.util.Locale.ROOT, speed).trimEnd('0').trimEnd('.')
    return "$text×"
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SpeedSheet(speed: Float, onSpeed: (Float) -> Unit, onDismiss: () -> Unit) {
    var value by remember { mutableFloatStateOf(speed) }
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(Modifier.padding(horizontal = 20.dp).padding(bottom = 28.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Text("Playback speed", style = MaterialTheme.typography.titleMedium)
            Text(speedLabel(value), style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.SemiBold, modifier = Modifier.align(Alignment.CenterHorizontally))
            Row(verticalAlignment = Alignment.CenterVertically) {
                TextButton(onClick = { value = ListeningPrefs.validSpeed(value - ListeningPrefs.SPEED_STEP); onSpeed(value) }) { Text("−") }
                Slider(
                    value = value,
                    onValueChange = { value = ListeningPrefs.validSpeed(it) },
                    onValueChangeFinished = { onSpeed(value) },
                    valueRange = ListeningPrefs.MIN_SPEED..ListeningPrefs.MAX_SPEED,
                    // 0.05 steps: 50 intervals between 0.5 and 3.0.
                    steps = 49,
                    modifier = Modifier.weight(1f).semantics { contentDescription = "Playback speed" },
                )
                TextButton(onClick = { value = ListeningPrefs.validSpeed(value + ListeningPrefs.SPEED_STEP); onSpeed(value) }) { Text("+") }
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                ListeningPrefs.SPEED_PRESETS.forEach { preset ->
                    FilterChip(selected = value == preset, onClick = { value = preset; onSpeed(preset) }, label = { Text(speedLabel(preset)) })
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SleepSheet(now: NowPlaying, onDismiss: () -> Unit) {
    val listening = LocalApp.current.listening
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(Modifier.padding(bottom = 28.dp)) {
            Text("Sleep timer", style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(horizontal = 20.dp, vertical = 8.dp))
            if (now.sleep != SleepTimer.Off) {
                SheetRow("Turn off") { listening.cancelSleep(); onDismiss() }
                if (now.sleep is SleepTimer.Duration) SheetRow("5 more minutes") { listening.extendSleep(5); onDismiss() }
                HorizontalDivider()
            }
            SheetRow("End of chapter", selected = now.sleep is SleepTimer.EndOfChapter) { listening.sleepAtChapterEnd(); onDismiss() }
            SleepTimer.PRESET_MINUTES.forEach { minutes ->
                SheetRow("$minutes minutes", selected = (now.sleep as? SleepTimer.Duration)?.minutes == minutes) { listening.sleepIn(minutes); onDismiss() }
            }
        }
    }
}

@Composable
private fun SheetRow(text: String, selected: Boolean = false, onClick: () -> Unit) {
    Text(
        text,
        style = MaterialTheme.typography.bodyLarge,
        color = if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface,
        fontWeight = if (selected) FontWeight.SemiBold else null,
        modifier = Modifier.fillMaxWidth().clickable(onClick = onClick).padding(horizontal = 20.dp, vertical = 14.dp),
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ChaptersSheet(now: NowPlaying, onDismiss: () -> Unit) {
    val listening = LocalApp.current.listening
    val current = now.chapterIndex
    val list = rememberLazyListState(initialFirstVisibleItemIndex = (current - 2).coerceAtLeast(0))
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Text("Chapters", style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(horizontal = 20.dp, vertical = 8.dp))
        LazyColumn(state = list, contentPadding = PaddingValues(bottom = 28.dp)) {
            items(now.timeline.chapters, key = { it.index }) { chapter ->
                val playing = chapter.index == current
                Row(
                    Modifier.fillMaxWidth().clickable { listening.seekTo(chapter.startMs); onDismiss() }.padding(horizontal = 20.dp, vertical = 12.dp)
                        .semantics(mergeDescendants = true) { if (playing) stateDescription = "Playing" },
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(
                        chapter.label,
                        style = MaterialTheme.typography.bodyLarge,
                        color = if (playing) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurface,
                        fontWeight = if (playing) FontWeight.SemiBold else null,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f),
                    )
                    Text(ListeningSync.clock(chapter.endMs - chapter.startMs), style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun BookmarksSheet(now: NowPlaying, snackbar: SnackbarHostState, onDismiss: () -> Unit) {
    val listening = LocalApp.current.listening
    val scope = rememberCoroutineScope()
    var note by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    ModalBottomSheet(onDismissRequest = onDismiss) {
        Column(Modifier.padding(horizontal = 20.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("Bookmarks", style = MaterialTheme.typography.titleMedium)
            OutlinedTextField(
                value = note,
                onValueChange = { note = it.take(500) },
                label = { Text("Note (optional)") },
                modifier = Modifier.fillMaxWidth(),
            )
            Button(
                onClick = {
                    busy = true
                    scope.launch {
                        error = listening.addBookmark(note)
                        if (error == null) note = ""
                        busy = false
                    }
                },
                enabled = !busy,
            ) { Text("Bookmark ${ListeningSync.clock(now.positionMs)}") }
            error?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
        }
        LazyColumn(contentPadding = PaddingValues(bottom = 28.dp, top = 8.dp)) {
            if (now.bookmarks.isEmpty()) {
                item {
                    Text(
                        "No bookmarks yet.",
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(horizontal = 20.dp, vertical = 12.dp),
                    )
                }
            }
            items(now.bookmarks.sortedBy { it.positionMs }, key = { it.id }) { bookmark ->
                Row(
                    Modifier.fillMaxWidth().clickable { listening.seekTo(bookmark.positionMs); onDismiss() }.padding(start = 20.dp, end = 4.dp, top = 6.dp, bottom = 6.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Column(Modifier.weight(1f).semantics(mergeDescendants = true) {}) {
                        val chapter = now.timeline.chapterAt(bookmark.positionMs)?.label
                        Text(ListeningSync.clock(bookmark.positionMs) + (chapter?.let { " · $it" } ?: ""), style = MaterialTheme.typography.bodyLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        bookmark.noteOrNull?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 3) }
                    }
                    IconButton(onClick = { scope.launch { listening.deleteBookmark(bookmark.id)?.let { snackbar.showSnackbar(it) } } }) {
                        Icon(Icons.Outlined.DeleteOutline, contentDescription = "Delete bookmark")
                    }
                }
            }
        }
    }
}
