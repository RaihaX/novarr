package studio.visns.novarr.ui.reader

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.unit.dp
import studio.visns.novarr.domain.Highlights
import studio.visns.novarr.ui.theme.BrandFonts

/**
 * Saving a highlight: the passage (selectable, all of it selected to start
 * with, so the reader can narrow it down to a sentence) and an optional
 * note. [onSave] gets the chosen part of [text] and the note, both trimmed
 * but not yet clipped to the server's limits.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun HighlightSheet(text: String, onDismiss: () -> Unit, onSave: (excerpt: String, note: String) -> Unit) {
    var passage by rememberSaveable(text, stateSaver = TextFieldValue.Saver) {
        mutableStateOf(TextFieldValue(text, selection = TextRange(0, text.length)))
    }
    var note by rememberSaveable { mutableStateOf("") }
    val selection = passage.selection
    val chosen = (if (selection.collapsed) text else text.substring(selection.min, selection.max)).trim()

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(
            Modifier.verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("Highlight", style = MaterialTheme.typography.titleLarge)
            // Read-only: only the selection can change, and it is what gets saved.
            OutlinedTextField(
                value = passage,
                onValueChange = { passage = it.copy(text = text) },
                readOnly = true,
                label = { Text("Passage") },
                supportingText = {
                    Text(
                        when {
                            chosen.length > Highlights.MAX_LENGTH -> "Only the first 2,000 characters are saved."
                            selection.collapsed || chosen.length == text.trim().length -> "Select part of it to highlight less."
                            else -> "${chosen.length} characters selected."
                        },
                    )
                },
                textStyle = MaterialTheme.typography.bodyLarge.copy(fontFamily = BrandFonts.Literata),
                modifier = Modifier.fillMaxWidth().heightIn(max = 320.dp),
            )
            OutlinedTextField(
                value = note,
                onValueChange = { note = it.take(Highlights.MAX_LENGTH) },
                label = { Text("Note (optional)") },
                minLines = 2,
                maxLines = 6,
                supportingText = if (note.length > Highlights.MAX_LENGTH - 200) {
                    { Text("${note.length} / ${Highlights.MAX_LENGTH}") }
                } else null,
                modifier = Modifier.fillMaxWidth(),
            )
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp, androidx.compose.ui.Alignment.End)) {
                TextButton(onClick = onDismiss) { Text("Cancel") }
                Button(onClick = { onSave(chosen, note.trim()) }, enabled = chosen.isNotEmpty()) { Text("Save") }
            }
        }
    }
}
