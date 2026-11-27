package studio.visns.novarr.ui.lists

import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.detectDragGesturesAfterLongPress
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.outlined.DragHandle
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material.icons.outlined.Visibility
import androidx.compose.material.icons.outlined.VisibilityOff
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.zIndex
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavController
import kotlin.math.roundToInt
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.domain.ReadingLists
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.readableWidth

/** A row's height in Manage categories: fixed, so a drag can tell how many rows it has passed. */
private val ROW_HEIGHT = 64.dp

/**
 * Manage categories (Home's link, the Library's overflow, Settings): make
 * one, rename and delete the reader's own, drag to reorder (long-press the
 * handle; saved when dropped), and hide any of them — the built-ins too —
 * from Home's sections. Read-only offline.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ManageCategoriesScreen(nav: NavController) {
    val app = LocalApp.current
    val state by app.lists.state.collectAsStateWithLifecycle()
    val loaded by app.lists.loaded.collectAsStateWithLifecycle()
    val reachable by app.isReachable.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()
    val haptics = LocalHapticFeedback.current
    var renaming by remember { mutableStateOf<ReadingList?>(null) }
    var deleting by remember { mutableStateOf<ReadingList?>(null) }
    LaunchedEffect(Unit) { if (!loaded) app.lists.refresh() }

    fun report(message: String?) {
        if (message != null) scope.launch { snackbar.showSnackbar(message) }
    }

    val saved = ReadingLists.sorted(state.lists).map { it.id }
    // One state for the order as shown (the drag gestures hold on to it): it follows the
    // saved order except while a drag is moving things; the drop saves it.
    var order by remember { mutableStateOf(saved) }
    var dragging by remember { mutableStateOf<Int?>(null) }
    var dragOffset by remember { mutableFloatStateOf(0f) }
    LaunchedEffect(saved) { if (dragging == null) order = saved }
    val savedNow by rememberUpdatedState(saved)
    val rowPx = with(LocalDensity.current) { ROW_HEIGHT.toPx() }

    fun save(next: List<Int>) {
        if (next == savedNow) return
        scope.launch { report(app.lists.reorder(next)) }
    }

    fun move(id: Int, by: Int) {
        val from = order.indexOf(id)
        val to = (from + by).coerceIn(0, order.lastIndex)
        if (from < 0 || from == to) return
        order = order.toMutableList().apply { removeAt(from); add(to, id) }
    }

    Scaffold(
        snackbarHost = { SnackbarHost(snackbar) },
        topBar = {
            TopAppBar(
                title = { Text("Categories") },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                },
            )
        },
    ) { padding ->
        Column(
            Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState(), enabled = dragging == null),
        ) {
            Column(Modifier.readableWidth(720.dp).padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                if (!reachable) OfflineNote()
                Text(
                    "Home shows each category that has something in it, in this order. Long-press the handle to drag one; the eye hides it from Home (it stays in the Library).",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
                if (loaded) {
                    NewListField(state.lists, enabled = reachable, label = "New category") { name -> app.lists.create(name).exceptionOrNull()?.message }
                }
                HorizontalDivider()
            }
            val byId = state.lists.associateBy { it.id }
            Column(Modifier.readableWidth(720.dp)) {
                // Keyed by id, so a row (and its drag) moves with its category as the order changes.
                order.mapNotNull { byId[it] }.forEach { list -> key(list.id) {
                    val isDragged = dragging == list.id
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .height(ROW_HEIGHT)
                            .zIndex(if (isDragged) 1f else 0f)
                            .graphicsLayer { translationY = if (isDragged) dragOffset else 0f; shadowElevation = if (isDragged) 8f else 0f }
                            .background(if (isDragged) MaterialTheme.colorScheme.surfaceContainerHigh else MaterialTheme.colorScheme.surface)
                            .padding(horizontal = 8.dp)
                            .semantics(mergeDescendants = true) {
                                // The drag, for screen readers.
                                customActions = listOf(
                                    CustomAccessibilityAction("Move up") { move(list.id, -1); save(order); true },
                                    CustomAccessibilityAction("Move down") { move(list.id, 1); save(order); true },
                                )
                            },
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(
                            Modifier
                                .size(48.dp)
                                .pointerInput(list.id, reachable) {
                                    if (!reachable) return@pointerInput
                                    detectDragGesturesAfterLongPress(
                                        onDragStart = {
                                            haptics.performHapticFeedback(HapticFeedbackType.LongPress)
                                            dragging = list.id
                                            dragOffset = 0f
                                        },
                                        onDrag = { change, amount ->
                                            change.consume()
                                            dragOffset += amount.y
                                            // Past half a row: the row swaps places, and the offset follows it.
                                            val steps = (dragOffset / rowPx).roundToInt()
                                            if (steps != 0) {
                                                val before = order.indexOf(list.id)
                                                move(list.id, steps)
                                                dragOffset -= (order.indexOf(list.id) - before) * rowPx
                                            }
                                        },
                                        onDragEnd = { dragging = null; dragOffset = 0f; save(order) },
                                        onDragCancel = { dragging = null; dragOffset = 0f; save(order) },
                                    )
                                },
                            contentAlignment = Alignment.Center,
                        ) {
                            Icon(Icons.Outlined.DragHandle, contentDescription = "Drag to reorder", tint = MaterialTheme.colorScheme.onSurfaceVariant)
                        }
                        Column(Modifier.weight(1f).padding(horizontal = 8.dp)) {
                            Text(
                                list.name,
                                style = MaterialTheme.typography.bodyLarge,
                                color = if (list.hiddenOnHome) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.onSurface,
                                maxLines = 1,
                                overflow = TextOverflow.Ellipsis,
                            )
                            Text(
                                listOfNotNull(seriesCount(list.count), "built in".takeIf { list.isBuiltIn }, "hidden on Home".takeIf { list.hiddenOnHome }).joinToString(" · "),
                                style = MaterialTheme.typography.bodySmall,
                                color = MaterialTheme.colorScheme.onSurfaceVariant,
                            )
                        }
                        IconButton(
                            onClick = { scope.launch { report(app.lists.setHiddenOnHome(list.id, !list.hiddenOnHome)) } },
                            enabled = reachable,
                        ) {
                            Icon(
                                if (list.hiddenOnHome) Icons.Outlined.VisibilityOff else Icons.Outlined.Visibility,
                                contentDescription = if (list.hiddenOnHome) "Show ${list.name} on Home" else "Hide ${list.name} on Home",
                            )
                        }
                        // The built-ins are never renamed or deleted.
                        if (!list.isBuiltIn) {
                            var menu by remember { mutableStateOf(false) }
                            Box {
                                IconButton(onClick = { menu = true }) { Icon(Icons.Outlined.MoreVert, contentDescription = "Options for ${list.name}") }
                                DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                                    DropdownMenuItem(text = { Text("Rename") }, enabled = reachable, onClick = { menu = false; renaming = list })
                                    DropdownMenuItem(
                                        text = { Text("Delete", color = if (reachable) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant) },
                                        enabled = reachable,
                                        onClick = { menu = false; deleting = list },
                                    )
                                }
                            }
                        } else {
                            Box(Modifier.size(48.dp))
                        }
                    }
                } }
                if (loaded && state.lists.isEmpty()) {
                    Text(
                        "No categories yet. Name one above, then long-press series in the Library to put them in it.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.padding(16.dp).semantics { heading() },
                    )
                }
            }
        }
    }

    renaming?.let { list -> RenameListDialog(list, state.lists) { renaming = null } }
    deleting?.let { list -> DeleteListDialog(list, onDeleted = {}) { deleting = null } }
}
