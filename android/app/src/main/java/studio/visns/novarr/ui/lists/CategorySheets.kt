package studio.visns.novarr.ui.lists

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.selection.triStateToggleable
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TriStateCheckbox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.state.ToggleableState
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.api.SearchResult
import studio.visns.novarr.domain.Categories
import studio.visns.novarr.domain.Categories.Tri
import studio.visns.novarr.domain.ReadingLists
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.theme.BrandFonts

/**
 * "Set categories…" for a multi-select: every category (the built-ins
 * too, hidden on Home or not) with a box that is ticked when all the
 * selected series are in it, clear when none are, and indeterminate when
 * some are. A new category can be made inline (ticked). Apply sends one
 * memberships call with only what changed; [onDone] gets the message for
 * the screen's snackbar (null: saved).
 */
@Composable
fun SetCategoriesSheet(novelIds: List<Int>, onDismiss: () -> Unit, onDone: (String?) -> Unit) {
    val app = LocalApp.current
    val state by app.lists.state.collectAsStateWithLifecycle()
    val loaded by app.lists.loaded.collectAsStateWithLifecycle()
    val reachable by app.isReachable.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()
    LaunchedEffect(Unit) { if (!loaded) app.lists.refresh() }
    // How things were when the sheet opened (what Apply compares against), then the reader's boxes.
    val initial = remember(loaded) { if (loaded) Categories.initial(state.lists, novelIds) else emptyMap() }
    val current = remember(loaded) { mutableStateMapOf<Int, Tri>().apply { putAll(initial) } }
    var sending by remember { mutableStateOf(false) }
    val diff = Categories.diff(initial, current)

    SheetFrame(onDismiss, snackbar) {
        Text("Set categories", style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        Text(
            if (novelIds.size == 1) "1 series" else "${novelIds.size} series",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        if (!reachable) OfflineNote()
        val lists = ReadingLists.sorted(state.lists)
        when {
            !loaded -> Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            lists.isEmpty() -> Text("No categories yet: name your first one below.", style = MaterialTheme.typography.bodyMedium)
            else -> Column {
                lists.forEach { list ->
                    val started = initial[list.id] ?: Tri.OFF
                    val box = current[list.id] ?: started
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .triStateToggleable(
                                state = box.toggleable(),
                                enabled = reachable && !sending,
                                role = Role.Checkbox,
                                onClick = { current[list.id] = Categories.next(box, started) },
                            )
                            .heightIn(min = 52.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        TriStateCheckbox(state = box.toggleable(), onClick = null, enabled = reachable && !sending)
                        Spacer(Modifier.width(12.dp))
                        Text(list.name, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(categoryNote(list), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
        if (loaded) {
            HorizontalDivider()
            NewListField(state.lists, enabled = reachable && !sending, label = "New category") { name ->
                val made = app.lists.create(name)
                // Made empty, then ticked here: Apply puts the selection in it with the rest.
                made.getOrNull()?.let { current[it.id] = Tri.ON }
                made.exceptionOrNull()?.message
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onDismiss, enabled = !sending) { Text("Cancel") }
            Spacer(Modifier.width(8.dp))
            Button(
                enabled = reachable && !sending && !diff.isEmpty,
                onClick = {
                    sending = true
                    scope.launch {
                        val failure = app.lists.setMemberships(novelIds, diff.add, diff.remove)
                        sending = false
                        if (failure == null) onDone(null) else snackbar.showSnackbar(failure)
                    }
                },
            ) {
                if (sending) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) else Text("Apply")
            }
        }
    }
}

private fun Tri.toggleable(): ToggleableState = when (this) {
    Tri.ON -> ToggleableState.On
    Tri.OFF -> ToggleableState.Off
    Tri.MIXED -> ToggleableState.Indeterminate
}

/** "12 series", with "hidden on Home" for a category Home leaves out. */
private fun categoryNote(list: ReadingList): String =
    seriesCount(list.count) + if (list.hiddenOnHome) " · hidden on Home" else ""

/**
 * Browse / Discover's long-press: "Add to category" for a series the
 * library may not have yet. Boxes start ticked where it already is (or
 * where the reader's request will put it); [onConfirm] gets the chosen
 * set. Not in the library, confirming requests it (or joins, or updates
 * the reader's own request) with those categories.
 */
@Composable
fun AddToCategorySheet(result: SearchResult, sending: Boolean, onDismiss: () -> Unit, onConfirm: (Set<Int>) -> Unit) {
    val app = LocalApp.current
    val state by app.lists.state.collectAsStateWithLifecycle()
    val loaded by app.lists.loaded.collectAsStateWithLifecycle()
    val reachable by app.isReachable.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }
    LaunchedEffect(Unit) { if (!loaded) app.lists.refresh() }
    val chosen = remember(result.key) { mutableStateMapOf<Int, Boolean>().apply { Categories.preTicked(result).forEach { put(it, true) } } }
    val picked = chosen.filterValues { it }.keys
    val action = Categories.browseAction(result, picked)
    val inLibrary = result.inLibrary || result.novelId != null

    SheetFrame(onDismiss, snackbar) {
        Text("Add to category", style = MaterialTheme.typography.titleLarge, modifier = Modifier.semantics { heading() })
        Text(
            result.title,
            style = MaterialTheme.typography.bodyMedium.copy(fontFamily = BrandFonts.Literata),
            color = MaterialTheme.colorScheme.onSurfaceVariant,
            maxLines = 2,
            overflow = TextOverflow.Ellipsis,
        )
        if (!inLibrary) {
            Text(
                if (result.requested?.byMe == true) "You've asked for it: it goes into these categories once it's in the library."
                else "Not in the library yet: this asks the owner for it, and it goes into these categories once it's here.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
        if (!reachable) OfflineNote()
        val lists = ReadingLists.sorted(state.lists)
        when {
            !loaded -> Box(Modifier.fillMaxWidth().padding(24.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
            lists.isEmpty() -> Text("No categories yet: name your first one below.", style = MaterialTheme.typography.bodyMedium)
            else -> Column {
                lists.forEach { list ->
                    val checked = chosen[list.id] == true
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .toggleable(value = checked, enabled = reachable && !sending, role = Role.Checkbox, onValueChange = { chosen[list.id] = it })
                            .heightIn(min = 52.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Checkbox(checked = checked, onCheckedChange = null, enabled = reachable && !sending)
                        Spacer(Modifier.width(12.dp))
                        Text(list.name, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(seriesCount(list.count), style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
            }
        }
        if (loaded) {
            HorizontalDivider()
            NewListField(state.lists, enabled = reachable && !sending, label = "New category") { name ->
                val made = app.lists.create(name)
                made.getOrNull()?.let { chosen[it.id] = true }
                made.exceptionOrNull()?.message
            }
        }
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
            TextButton(onClick = onDismiss, enabled = !sending) { Text("Cancel") }
            Spacer(Modifier.width(8.dp))
            Button(enabled = reachable && !sending && action != Categories.BrowseAction.Nothing, onClick = { onConfirm(picked.toSet()) }) {
                if (sending) {
                    CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                } else {
                    Text(
                        when {
                            inLibrary -> "Save"
                            result.requested?.byMe == true -> "Save"
                            result.requested != null -> "Me too & add"
                            else -> "Request & add"
                        },
                    )
                }
            }
        }
    }
}
