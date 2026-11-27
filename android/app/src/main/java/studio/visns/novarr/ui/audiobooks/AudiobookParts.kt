package studio.visns.novarr.ui.audiobooks

import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.ui.semantics.stateDescription
import studio.visns.novarr.ui.lists.SelectedMark
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import studio.visns.novarr.api.AudiobookSummary
import studio.visns.novarr.domain.ListeningSync
import studio.visns.novarr.ui.components.Cover
import studio.visns.novarr.ui.components.ProgressLine

/**
 * A cover with the title, the length or time left, and progress along its
 * bottom. With [onLongClick] it takes part in Listen's multi-select.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun AudiobookTile(
    item: AudiobookSummary,
    modifier: Modifier = Modifier,
    selected: Boolean = false,
    onLongClick: (() -> Unit)? = null,
    onClick: () -> Unit,
) {
    Column(
        modifier
            .then(
                if (onLongClick != null) {
                    Modifier.combinedClickable(onClickLabel = "Listen", role = Role.Button, onClick = onClick, onLongClick = onLongClick, onLongClickLabel = "Select")
                } else {
                    Modifier.clickable(onClickLabel = "Listen", role = Role.Button, onClick = onClick)
                },
            )
            .semantics(mergeDescendants = true) { if (onLongClick != null) stateDescription = if (selected) "Selected" else "" },
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        Box {
            Cover(item.coverUrl, item.title) {
                if (item.started && item.progress?.finished != true) {
                    Box(Modifier.align(Alignment.BottomCenter).padding(6.dp)) {
                        ProgressLine(item.fraction, color = Color.White, track = Color.Black.copy(alpha = 0.35f))
                    }
                }
            }
            SelectedMark(selected)
        }
        Text(item.title, style = MaterialTheme.typography.labelLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
        item.seriesLabel?.let {
            Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        Text(
            caption(item),
            style = MaterialTheme.typography.labelSmall,
            color = if (item.progress?.finished == true) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.onSurfaceVariant,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

/** "FINISHED", "3 h 12 min left" or the book's length. */
fun caption(item: AudiobookSummary): String {
    val progress = item.progress
    return when {
        progress?.finished == true -> "FINISHED"
        item.started && item.durationMs > 0 -> "${ListeningSync.length(item.durationMs - progress!!.positionMs)} left"
        item.durationMs > 0 -> ListeningSync.length(item.durationMs)
        else -> "AUDIOBOOK"
    }
}

/** Home's and the Audiobooks screen's "Continue listening" row. */
@Composable
fun ContinueListeningRow(items: List<AudiobookSummary>, wide: Boolean, onOpen: (AudiobookSummary) -> Unit) {
    LazyRow(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
        items(items, key = { it.id }) { item ->
            AudiobookTile(item, Modifier.width(if (wide) 130.dp else 108.dp)) { onOpen(item) }
        }
    }
}
