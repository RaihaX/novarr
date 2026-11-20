package studio.visns.novarr.ui.lists

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.CloudOff
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.AssistChip
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
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
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.domain.ReadingLists
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.readableWidth
import studio.visns.novarr.ui.theme.BrandFonts

private const val OFFLINE_NOTE = "You're offline. Your lists show as last saved; they can be changed once the server is reachable."

/**
 * The series screen's "Lists": a checkbox per list (each tap saves at once;
 * a failure is undone and explained in a snackbar), a field for a new list
 * (made with this series in it) and, with no lists yet, three one-tap
 * starters. Read-only offline.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SeriesListsSheet(novelId: Int, novelName: String, onDismiss: () -> Unit) {
    val app = LocalApp.current
    val state by app.lists.state.collectAsStateWithLifecycle()
    val loaded by app.lists.loaded.collectAsStateWithLifecycle()
    val reachable by app.isReachable.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()
    var creating by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { if (!loaded) app.lists.refresh() }

    fun report(message: String?) {
        if (message != null) scope.launch { snackbar.showSnackbar(message) }
    }

    SheetFrame(onDismiss, snackbar) {
        Text("Lists", style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        Text(
            novelName,
            style = MaterialTheme.typography.bodyMedium.copy(fontFamily = BrandFonts.Literata),
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        if (!reachable) OfflineNote()
        val lists = ReadingLists.sorted(state.lists)
        when {
            !loaded -> Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            lists.isEmpty() -> {
                Text("Keep series together: start with one of these, or name your own below.", style = MaterialTheme.typography.bodyMedium)
                FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    ReadingLists.starters(lists).forEach { name ->
                        AssistChip(
                            onClick = {
                                creating = true
                                scope.launch {
                                    report(app.lists.create(name, addNovel = novelId).exceptionOrNull()?.message)
                                    creating = false
                                }
                            },
                            enabled = reachable && !creating,
                            label = { Text(name) },
                            leadingIcon = { Icon(Icons.Outlined.Add, contentDescription = null, modifier = Modifier.size(18.dp)) },
                        )
                    }
                }
            }
            else -> Column {
                lists.forEach { list ->
                    val checked = novelId in list
                    val busy = state.busy(list.id, novelId)
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .toggleable(
                                value = checked,
                                enabled = reachable && !busy,
                                role = Role.Checkbox,
                                onValueChange = { add -> scope.launch { report(app.lists.toggle(list.id, novelId, add)) } },
                            )
                            .heightIn(min = 52.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Checkbox(checked = checked, onCheckedChange = null, enabled = reachable && !busy)
                        Spacer(Modifier.width(12.dp))
                        Text(list.name, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(seriesCount(list.count), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
        if (loaded) {
            HorizontalDivider()
            NewListField(state.lists, enabled = reachable, label = "New list with this series") { name ->
                app.lists.create(name, addNovel = novelId).exceptionOrNull()?.message
            }
        }
    }
}

/**
 * Library's list filter, and where lists are managed: "All series" or one
 * list (remembered like the other filters), each list's rename and delete,
 * and a new (empty) list. Read-only offline.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun LibraryListsSheet(selected: Int?, onSelect: (ReadingList?) -> Unit, onDismiss: () -> Unit) {
    val app = LocalApp.current
    val state by app.lists.state.collectAsStateWithLifecycle()
    val loaded by app.lists.loaded.collectAsStateWithLifecycle()
    val reachable by app.isReachable.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }
    var renaming by remember { mutableStateOf<ReadingList?>(null) }
    var deleting by remember { mutableStateOf<ReadingList?>(null) }
    LaunchedEffect(Unit) { if (!loaded) app.lists.refresh() }

    SheetFrame(onDismiss, snackbar) {
        Text("Lists", style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        if (!reachable) OfflineNote()
        val lists = ReadingLists.sorted(state.lists)
        Column {
            ChoiceRow("All series", null, selected == null, onClick = { onSelect(null) })
            lists.forEach { list ->
                ChoiceRow(list.name, seriesCount(list.count), selected == list.id, onClick = { onSelect(list) }) {
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
                }
            }
        }
        if (loaded && lists.isEmpty()) {
            Text(
                "No lists yet. Make one here, or add a series to a list from its page (Lists, at the top).",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        if (loaded) {
            HorizontalDivider()
            NewListField(state.lists, enabled = reachable, label = "New list") { name -> app.lists.create(name).exceptionOrNull()?.message }
        }
    }

    renaming?.let { list -> RenameListDialog(list, state.lists) { renaming = null } }
    deleting?.let { list ->
        DeleteListDialog(list, onDeleted = { if (selected == list.id) onSelect(null) }) { deleting = null }
    }
}

@Composable
private fun ChoiceRow(title: String, trailing: String?, selected: Boolean, onClick: () -> Unit, actions: @Composable () -> Unit = {}) {
    Row(
        Modifier
            .fillMaxWidth()
            .selectable(selected = selected, role = Role.RadioButton, onClick = onClick)
            .heightIn(min = 52.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        RadioButton(selected = selected, onClick = null)
        Spacer(Modifier.width(12.dp))
        Text(title, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
        trailing?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        actions()
    }
}

/**
 * A name field checked as it is typed (the server's limits); [onCreate]
 * answers null when made, else the message to show under the field.
 */
@Composable
private fun NewListField(lists: List<ReadingList>, enabled: Boolean, label: String, onCreate: suspend (String) -> String?) {
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    var sending by remember { mutableStateOf(false) }
    val problem = if (name.isBlank()) null else ReadingLists.nameProblem(name, lists)
    val canCreate = enabled && !sending && name.isNotBlank() && problem == null
    fun create() {
        if (!canCreate) return
        sending = true
        scope.launch {
            val failure = onCreate(name)
            sending = false
            if (failure == null) name = "" else error = failure
        }
    }
    Row(verticalAlignment = Alignment.Top, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        OutlinedTextField(
            value = name,
            onValueChange = { name = it; error = null },
            label = { Text(label) },
            enabled = enabled,
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
            modifier = Modifier.weight(1f),
        )
        FilledTonalButton(onClick = { create() }, enabled = canCreate, modifier = Modifier.padding(top = 8.dp)) {
            if (sending) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) else Text("Add")
        }
    }
}

@Composable
private fun RenameListDialog(list: ReadingList, lists: List<ReadingList>, onDone: () -> Unit) {
    val app = LocalApp.current
    val scope = rememberCoroutineScope()
    var name by remember { mutableStateOf(list.name) }
    var error by remember { mutableStateOf<String?>(null) }
    var sending by remember { mutableStateOf(false) }
    val problem = ReadingLists.nameProblem(name, lists, renaming = list.id)
    val unchanged = ReadingLists.clean(name) == list.name
    AlertDialog(
        onDismissRequest = { if (!sending) onDone() },
        title = { Text("Rename list") },
        text = {
            OutlinedTextField(
                value = name,
                onValueChange = { name = it; error = null },
                singleLine = true,
                isError = (problem ?: error) != null,
                supportingText = { Text(problem ?: error ?: "${ReadingLists.length(name)} / ${ReadingLists.NAME_MAX}") },
                keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences),
            )
        },
        confirmButton = {
            TextButton(
                enabled = !sending && problem == null && !unchanged,
                onClick = {
                    sending = true
                    scope.launch {
                        val failure = app.lists.rename(list.id, name)
                        sending = false
                        if (failure == null) onDone() else error = failure
                    }
                },
            ) { Text("Save") }
        },
        dismissButton = { TextButton(onClick = onDone, enabled = !sending) { Text("Cancel") } },
    )
}

@Composable
private fun DeleteListDialog(list: ReadingList, onDeleted: () -> Unit, onDone: () -> Unit) {
    val app = LocalApp.current
    val scope = rememberCoroutineScope()
    var error by remember { mutableStateOf<String?>(null) }
    var sending by remember { mutableStateOf(false) }
    AlertDialog(
        onDismissRequest = { if (!sending) onDone() },
        title = { Text("Delete “${list.name}”?") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(if (list.count == 0) "The list is empty." else "The ${seriesCount(list.count)} in it stay in your library; only the list goes.")
                error?.let { Text(it, color = MaterialTheme.colorScheme.error, modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }) }
            }
        },
        confirmButton = {
            TextButton(
                enabled = !sending,
                onClick = {
                    sending = true
                    scope.launch {
                        val failure = app.lists.delete(list.id)
                        sending = false
                        if (failure == null) {
                            onDeleted()
                            onDone()
                        } else {
                            error = failure
                        }
                    }
                },
            ) { Text("Delete", color = MaterialTheme.colorScheme.error) }
        },
        dismissButton = { TextButton(onClick = onDone, enabled = !sending) { Text("Cancel") } },
    )
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SheetFrame(onDismiss: () -> Unit, snackbar: SnackbarHostState, content: @Composable () -> Unit) {
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Box {
            Column(
                Modifier
                    .readableWidth(640.dp)
                    .verticalScroll(rememberScrollState())
                    .padding(horizontal = 20.dp)
                    .padding(bottom = 16.dp)
                    .navigationBarsPadding()
                    .imePadding(),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) { content() }
            // The sheet covers the screen's own snackbar, so it has its own.
            SnackbarHost(snackbar, Modifier.align(Alignment.BottomCenter).navigationBarsPadding())
        }
    }
}

@Composable
private fun OfflineNote() {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(Icons.Outlined.CloudOff, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.size(18.dp))
        Text(OFFLINE_NOTE, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

fun seriesCount(count: Int): String = if (count == 1) "1 series" else "$count series"
