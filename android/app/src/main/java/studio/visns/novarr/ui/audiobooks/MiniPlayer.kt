package studio.visns.novarr.ui.audiobooks

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Pause
import androidx.compose.material.icons.filled.PlayArrow
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import studio.visns.novarr.playback.NowPlaying
import studio.visns.novarr.ui.components.Cover
import studio.visns.novarr.ui.components.LocalApp

/** The bar above the tabs while an audiobook is loaded: tap for the full player, play/pause, close. */
@Composable
fun MiniPlayer(now: NowPlaying, onOpen: () -> Unit, modifier: Modifier = Modifier) {
    val listening = LocalApp.current.listening
    val total = now.timeline.totalMs
    Surface(color = MaterialTheme.colorScheme.surfaceContainer, modifier = modifier.fillMaxWidth()) {
        Column {
            LinearProgressIndicator(
                progress = { if (total > 0) (now.positionMs.toFloat() / total).coerceIn(0f, 1f) else 0f },
                modifier = Modifier.fillMaxWidth(),
                drawStopIndicator = {},
            )
            Row(
                Modifier.clickable(onClickLabel = "Open the player", role = Role.Button, onClick = onOpen).padding(start = 12.dp, end = 4.dp, top = 6.dp, bottom = 6.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Cover(now.detail.summary.coverUrl, now.detail.summary.title, Modifier.width(36.dp), corner = 4.dp)
                Column(Modifier.weight(1f).padding(horizontal = 12.dp).semantics(mergeDescendants = true) {}) {
                    Text(now.detail.summary.title, style = MaterialTheme.typography.titleSmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    now.chapterTitle?.let {
                        Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
                IconButton(onClick = listening::togglePlay) {
                    when {
                        now.buffering -> CircularProgressIndicator(Modifier.size(22.dp), strokeWidth = 2.dp)
                        now.playing -> Icon(Icons.Filled.Pause, contentDescription = "Pause")
                        else -> Icon(Icons.Filled.PlayArrow, contentDescription = "Play")
                    }
                }
                if (!now.playing) {
                    IconButton(onClick = listening::close) { Icon(Icons.Outlined.Close, contentDescription = "Close the player") }
                }
            }
            HorizontalDivider()
        }
    }
}
