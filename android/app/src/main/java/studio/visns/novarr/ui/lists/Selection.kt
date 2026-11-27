package studio.visns.novarr.ui.lists

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.Label
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.DoneAll
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material.icons.outlined.RemoveDone
import androidx.compose.material.icons.outlined.SelectAll
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.SnackbarResult
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import kotlinx.coroutines.withTimeoutOrNull
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.app.BulkActions
import studio.visns.novarr.domain.Categories

/**
 * A screen's long-press multi-select over series ids (Library, a category,
 * Listen) and the actions on it, kept in its ViewModel so it survives
 * rotation. The screen passes how to show a mark at once ([markRead]'s
 * apply / revert), since each screen holds its own copy of the series.
 */
class SeriesSelection(private val app: AppModel, private val scope: CoroutineScope) {
    var selection by mutableStateOf(Categories.Selection<Int>())
        private set

    /** The series "Set categories" is open for (null: closed). */
    var categoriesFor by mutableStateOf<List<Int>?>(null)

    /** A line for the snackbar; the screen clears it once shown. */
    var notice by mutableStateOf<String?>(null)

    /** The latest mark, while Undo is still possible: the screen shows it with an Undo button. */
    var undoable by mutableStateOf<Undoable?>(null)
        private set

    /** A download or removal is being set up. */
    var working by mutableStateOf(false)
        private set

    private val bulk = BulkActions(app)

    class Undoable internal constructor(val message: String, internal val mark: BulkActions.PendingMark, internal val revert: () -> Unit)

    val active: Boolean get() = selection.active
    val ids: List<Int> get() = selection.keys.toList()

    fun longPress(id: Int) { selection = selection.longPress(id) }

    /** A tap: true when it toggled (selecting), false when the item should open as usual. */
    fun tap(id: Int): Boolean {
        val next = selection.tap(id) ?: return false
        selection = next
        return true
    }

    fun selectAll(shown: Collection<Int>) { selection = selection.selectAll(shown) }
    fun retain(available: Collection<Int>) { selection = selection.retain(available) }
    fun exit() { selection = selection.exit() }

    /** "Set categories…" for the selection ([series]: when the keys aren't series ids, as in Listen). */
    fun openCategories(series: List<Int> = ids) { categoriesFor = series }

    /**
     * Marks the selection read or unread: [apply] shows it at once and
     * answers how to put it back (on Undo or a refusal); the call waits for
     * Undo ([Categories.UNDO_MS]). A mark still waiting is sent first.
     * [series]: the series ids when the selection's keys are something else
     * (Listen selects audiobooks).
     */
    fun markRead(read: Boolean, series: Set<Int> = selection.keys, apply: (ids: Set<Int>, read: Boolean) -> () -> Unit = { _, _ -> {} }) {
        val chosen = series
        if (chosen.isEmpty()) return
        undoable?.let { previous -> bulk.flush(previous.mark, previous.revert) { notice = it } }
        val revert = apply(chosen, read)
        exit()
        val mark = bulk.markRead(chosen.toList(), read, revert) { message -> notice = message }
        undoable = Undoable(Categories.markedLabel(chosen.size, read), mark, revert)
    }

    /** Undo on the snackbar: called off in time, so the screen goes back as it was. */
    fun undo(item: Undoable) {
        if (bulk.undo(item.mark)) item.revert() else notice = "Too late to undo: it was already saved."
        if (undoable === item) undoable = null
    }

    /** The snackbar went without Undo. */
    fun undoShown(item: Undoable) {
        if (undoable === item) undoable = null
    }

    /** Download (offline) for [novels]: their unread chapters. */
    fun download(novels: List<studio.visns.novarr.api.NovelSummary>) {
        exit()
        working = true
        scope.launch {
            notice = bulk.download(novels)
            working = false
        }
    }

    fun removeDownloads(ids: List<Int>) {
        exit()
        scope.launch { notice = bulk.removeDownloads(ids) }
    }

    fun downloadAudiobooks(books: List<studio.visns.novarr.api.AudiobookSummary>) {
        exit()
        notice = bulk.downloadAudiobooks(books)
    }

    fun removeAudiobookDownloads(books: List<studio.visns.novarr.api.AudiobookSummary>) {
        exit()
        scope.launch { notice = bulk.removeAudiobookDownloads(books) }
    }
}

/**
 * Shows [selection]'s notices and its Undo snackbar ("Marked 3 series as
 * read · Undo") for as long as Undo is possible; back ends selecting.
 */
@Composable
fun SelectionEffects(selection: SeriesSelection, snackbar: SnackbarHostState) {
    BackHandler(enabled = selection.active) { selection.exit() }
    LaunchedEffect(selection.notice) {
        selection.notice?.let { snackbar.showSnackbar(it) }
        selection.notice = null
    }
    val undoable = selection.undoable
    LaunchedEffect(undoable) {
        if (undoable == null) return@LaunchedEffect
        // Gone when the mark is sent: Undo can't call it off after that.
        val result = withTimeoutOrNull(Categories.UNDO_MS) {
            snackbar.showSnackbar(undoable.message, actionLabel = "Undo", duration = SnackbarDuration.Indefinite)
        }
        if (result == SnackbarResult.ActionPerformed) selection.undo(undoable) else selection.undoShown(undoable)
    }
}

/** A long-press with the haptic tick (every selection entry point uses it). */
@Composable
fun rememberLongPress(onLongPress: (Int) -> Unit): (Int) -> Unit {
    val haptics = LocalHapticFeedback.current
    return remember(onLongPress, haptics) {
        { id: Int ->
            haptics.performHapticFeedback(HapticFeedbackType.LongPress)
            onLongPress(id)
        }
    }
}

/**
 * The selection's top bar (in place of the screen's own): close, the
 * count, Select all, then Set categories, Mark read and Mark unread, with
 * Download and Remove download in the overflow. [categorise] / [markRead]
 * null: not offered (e.g. audiobooks without a matched book).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SelectionTopBar(
    count: Int,
    allSelected: Boolean,
    onClose: () -> Unit,
    onSelectAll: () -> Unit,
    categorise: (() -> Unit)?,
    markRead: ((Boolean) -> Unit)?,
    download: (() -> Unit)?,
    removeDownload: (() -> Unit)?,
    enabled: Boolean = true,
) {
    var menu by remember { mutableStateOf(false) }
    TopAppBar(
        title = { Text("$count selected") },
        navigationIcon = { IconButton(onClick = onClose) { Icon(Icons.Outlined.Close, contentDescription = "Stop selecting") } },
        colors = TopAppBarDefaults.topAppBarColors(containerColor = MaterialTheme.colorScheme.surfaceContainerHigh),
        actions = {
            if (!allSelected) IconButton(onClick = onSelectAll) { Icon(Icons.Outlined.SelectAll, contentDescription = "Select all") }
            categorise?.let { IconButton(onClick = it, enabled = enabled) { Icon(Icons.AutoMirrored.Outlined.Label, contentDescription = "Set categories") } }
            markRead?.let { mark ->
                IconButton(onClick = { mark(true) }, enabled = enabled) { Icon(Icons.Outlined.DoneAll, contentDescription = "Mark as read") }
                IconButton(onClick = { mark(false) }, enabled = enabled) { Icon(Icons.Outlined.RemoveDone, contentDescription = "Mark as unread") }
            }
            if (download != null || removeDownload != null) {
                Box {
                    IconButton(onClick = { menu = true }) { Icon(Icons.Outlined.MoreVert, contentDescription = "More") }
                    DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                        download?.let { DropdownMenuItem(text = { Text("Download") }, enabled = enabled, onClick = { menu = false; it() }) }
                        removeDownload?.let { DropdownMenuItem(text = { Text("Remove download") }, onClick = { menu = false; it() }) }
                    }
                }
            }
        },
    )
}

/** On a selected cover: a tint, an outline and a tick in the corner. */
@Composable
fun BoxScope.SelectedMark(selected: Boolean) {
    if (!selected) return
    Surface(
        color = MaterialTheme.colorScheme.primary.copy(alpha = 0.28f),
        shape = RoundedCornerShape(8.dp),
        border = BorderStroke(3.dp, MaterialTheme.colorScheme.primary),
        modifier = Modifier.matchParentSize(),
    ) {}
    Surface(shape = CircleShape, color = Color.White, modifier = Modifier.align(Alignment.TopStart).padding(6.dp).size(24.dp)) {
        Icon(Icons.Filled.CheckCircle, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.fillMaxSize())
    }
}
