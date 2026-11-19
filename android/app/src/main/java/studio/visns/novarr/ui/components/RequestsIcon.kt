package studio.visns.novarr.ui.components

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.PlaylistAdd
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import studio.visns.novarr.ui.theme.brandAmber

/**
 * The Requests entry's icon (Home's top bar, the tablet rail), with a dot
 * while the request feed has news not seen in the app yet. TalkBack reads
 * "Requests, new activity" then.
 */
@Composable
fun RequestsIcon(unseen: Int, modifier: Modifier = Modifier) {
    val label = if (unseen > 0) "Requests, new activity" else "Requests"
    BadgedBox(
        // The brand's amber, as for other marks that want attention.
        badge = { if (unseen > 0) Badge(containerColor = MaterialTheme.colorScheme.brandAmber) },
        modifier = modifier.semantics { contentDescription = label },
    ) {
        Icon(Icons.AutoMirrored.Outlined.PlaylistAdd, contentDescription = null)
    }
}
