package studio.visns.novarr.ui.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.AutoAwesome
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material.icons.outlined.WarningAmber
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import studio.visns.novarr.domain.CheckDisplay
import studio.visns.novarr.ui.theme.warning

/** One line describing a check: spinner while running, then the outcome. */
@Composable
fun CheckStatusLabel(display: CheckDisplay, tintOverride: Color? = null) {
    val scheme = MaterialTheme.colorScheme
    val tint = tintOverride ?: when (display) {
        is CheckDisplay.Found -> scheme.primary
        is CheckDisplay.Failed -> scheme.warning
        else -> scheme.onSurfaceVariant
    }
    Row(
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(6.dp),
        modifier = Modifier.semantics(mergeDescendants = true) { liveRegion = LiveRegionMode.Polite },
    ) {
        if (display.isChecking) {
            CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
        } else {
            val icon = when (display) {
                is CheckDisplay.Found -> Icons.Outlined.AutoAwesome
                is CheckDisplay.Failed -> Icons.Outlined.WarningAmber
                CheckDisplay.StillRunning -> Icons.Outlined.Schedule
                else -> Icons.Outlined.CheckCircle
            }
            Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(18.dp))
        }
        Text(display.text, style = MaterialTheme.typography.bodySmall, color = tint)
    }
}

/** "Check for new chapters" button plus its status, for the series page and the reader's "caught up" footer. */
@Composable
fun CheckForChaptersControl(novelId: Int, compact: Boolean = false, tint: Color? = null) {
    val app = LocalApp.current
    val displays by app.checks.displays.collectAsStateWithLifecycle()
    val running by app.checks.running.collectAsStateWithLifecycle()
    val display = displays[novelId]
    val isRunning = novelId in running
    val button: @Composable () -> Unit = {
        TextButton(onClick = { app.startCheck(novelId) }) {
            Icon(Icons.Outlined.Refresh, contentDescription = null, modifier = Modifier.size(18.dp), tint = tint ?: MaterialTheme.colorScheme.primary)
            Text(
                if (display == null) " Check for new chapters" else " Check again",
                color = tint ?: MaterialTheme.colorScheme.primary,
            )
        }
    }
    if (compact) {
        Column(Modifier.fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            display?.let { CheckStatusLabel(it, tint) }
            if (!isRunning) button()
        }
    } else {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) { display?.let { CheckStatusLabel(it) } }
            if (!isRunning) button()
        }
    }
}

/** Text that wraps and centres; used in footers. */
@Composable
fun CenteredNote(text: String, color: Color) {
    Text(text, style = MaterialTheme.typography.bodySmall, color = color, textAlign = TextAlign.Center, modifier = Modifier.fillMaxWidth())
}
