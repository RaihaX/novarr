package studio.visns.novarr.ui.reader

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.outlined.TextDecrease
import androidx.compose.material.icons.outlined.TextIncrease
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalIconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavController
import kotlinx.coroutines.launch
import studio.visns.novarr.domain.Eink
import studio.visns.novarr.domain.TextReadingMode
import studio.visns.novarr.ui.theme.LocalEink
import studio.visns.novarr.store.ReaderMargins
import studio.visns.novarr.store.ReaderSettings
import studio.visns.novarr.store.ReaderSpacing
import studio.visns.novarr.store.ReaderTheme
import studio.visns.novarr.store.ReaderWidth
import studio.visns.novarr.store.ReaderFont
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.readableWidth

/** Reader appearance controls, used in the reader's sheet and in Settings. */
@Composable
fun ReaderSettingsForm(modifier: Modifier = Modifier) {
    val app = LocalApp.current
    val scope = rememberCoroutineScope()
    val settings by app.prefs.readerSettings.collectAsStateWithLifecycle(ReaderSettings())
    fun update(transform: (ReaderSettings) -> ReaderSettings) = scope.launch { app.prefs.updateReader(transform) }

    Column(modifier, verticalArrangement = Arrangement.spacedBy(18.dp)) {
        Label("Reading mode")
        Segments(TextReadingMode.entries, settings.mode, { it.title }, groupLabel = "Reading mode") { value -> update { it.copy(mode = value) } }
        Text(
            if (settings.mode == TextReadingMode.PAGES) "Tap the right or left side, swipe, or use the page or volume keys to turn pages."
            else "Scroll through each chapter continuously.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )

        Label("Theme")
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceBetween) {
            ReaderTheme.entries.forEach { theme ->
                ThemeSwatch(theme, settings.theme == theme) { update { it.copy(theme = theme) } }
            }
        }

        Label("Text size")
        Row(verticalAlignment = Alignment.CenterVertically) {
            FilledTonalIconButton(
                onClick = { update { it.copy(fontSize = (it.fontSize - 1).coerceAtLeast(ReaderSettings.MIN_SIZE)) } },
                enabled = settings.fontSize > ReaderSettings.MIN_SIZE,
            ) { Icon(Icons.Outlined.TextDecrease, contentDescription = "Smaller text") }
            Text("${settings.fontSize.toInt()} sp", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
            FilledTonalIconButton(
                onClick = { update { it.copy(fontSize = (it.fontSize + 1).coerceAtMost(ReaderSettings.MAX_SIZE)) } },
                enabled = settings.fontSize < ReaderSettings.MAX_SIZE,
            ) { Icon(Icons.Outlined.TextIncrease, contentDescription = "Larger text") }
        }

        Label("Font")
        Segments(ReaderFont.entries, settings.font, { it.title }) { value -> update { it.copy(font = value) } }
        Label("Line spacing")
        Segments(ReaderSpacing.entries, settings.spacing, { it.title }) { value -> update { it.copy(spacing = value) } }
        Label("Margins")
        Segments(ReaderMargins.entries, settings.margins, { it.title }) { value -> update { it.copy(margins = value) } }
        Label("Column width")
        Segments(ReaderWidth.entries, settings.width, { it.title }) { value -> update { it.copy(width = value) } }
        Text(
            "Column width caps how wide lines get on tablets and in landscape.",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )

        SwitchRow("Justify text", settings.justify) { value -> update { it.copy(justify = value) } }
        // Untouched, it follows e-ink mode (on there, off elsewhere).
        SwitchRow("Bolder text", Eink.bolderText(settings.bolder, LocalEink.current)) { value -> update { it.copy(bolder = value) } }
        SwitchRow("Keep screen on while reading", settings.keepScreenOn) { value -> update { it.copy(keepScreenOn = value) } }
    }
}

@Composable
private fun Label(text: String) {
    Text(text, style = MaterialTheme.typography.titleSmall, modifier = Modifier.semantics { heading() })
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun <T> Segments(options: List<T>, selected: T, title: (T) -> String, groupLabel: String? = null, onSelect: (T) -> Unit) {
    SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth().then(if (groupLabel != null) Modifier.semantics { contentDescription = groupLabel } else Modifier)) {
        options.forEachIndexed { index, option ->
            SegmentedButton(
                selected = option == selected,
                onClick = { onSelect(option) },
                shape = SegmentedButtonDefaults.itemShape(index, options.size),
                modifier = Modifier.heightIn(min = 48.dp),
                label = { Text(title(option), maxLines = 1, overflow = TextOverflow.Ellipsis) },
            )
        }
    }
}

@Composable
private fun SwitchRow(title: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .heightIn(min = 48.dp)
            .toggleable(value = checked, role = Role.Switch, onValueChange = onChange),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(title, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = null)
    }
}

/** Settings → Reader appearance. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ReaderSettingsScreen(nav: NavController) {
    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Reader defaults") },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                },
            )
        },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState())) {
            ReaderSettingsForm(Modifier.readableWidth(640.dp).padding(16.dp))
        }
    }
}
