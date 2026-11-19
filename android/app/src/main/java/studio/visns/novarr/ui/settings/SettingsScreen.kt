package studio.visns.novarr.ui.settings

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.CloudOff
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavController
import kotlinx.coroutines.launch
import studio.visns.novarr.BuildConfig
import studio.visns.novarr.app.Session
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.ui.ChangeServerRoute
import studio.visns.novarr.ui.DownloadsRoute
import studio.visns.novarr.ui.ReaderSettingsRoute
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.formatBytes
import studio.visns.novarr.ui.components.readableWidth
import studio.visns.novarr.ui.theme.success
import studio.visns.novarr.ui.theme.warning

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SettingsScreen(nav: NavController) {
    val app = LocalApp.current
    val scope = rememberCoroutineScope()
    val session by app.session.collectAsStateWithLifecycle()
    val meta by app.serverMeta.collectAsStateWithLifecycle()
    val reachable by app.isReachable.collectAsStateWithLifecycle()
    val pending by app.pendingWrites.collectAsStateWithLifecycle()
    val downloadRevision by app.downloads.revision.collectAsStateWithLifecycle()
    val active = session as? Session.Active
    var offlineBytes by remember { mutableStateOf<Long?>(null) }
    var imageBytes by remember { mutableStateOf<Long?>(null) }
    var totalBytes by remember { mutableStateOf<Long?>(null) }
    var confirmClear by remember { mutableStateOf(false) }
    var confirmSignOut by remember { mutableStateOf(false) }
    var signingOut by remember { mutableStateOf(false) }
    var syncing by remember { mutableStateOf(false) }

    suspend fun measure() {
        offlineBytes = active?.store?.offlineChaptersSize() ?: 0
        val images = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) { OfflineStore.sizeOf(app.coverCacheDir()) }
        imageBytes = images
        // Everything this account keeps on the device: lists, chapters and images.
        totalBytes = (active?.store?.totalSize() ?: 0) + images
    }
    LaunchedEffect(Unit) { if (meta == null) app.refreshMeta() }
    LaunchedEffect(downloadRevision) { measure() }

    Scaffold(topBar = { TopAppBar(title = { Text("Settings") }) }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState())) {
            Column(Modifier.readableWidth(720.dp)) {
                Section("Server")
                Value("Address", active?.server ?: "Not set")
                Row(Modifier.fillMaxWidth().heightIn(min = 48.dp).padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text("Status", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                    val color = if (reachable) MaterialTheme.colorScheme.success else MaterialTheme.colorScheme.warning
                    Icon(if (reachable) Icons.Outlined.CheckCircle else Icons.Outlined.CloudOff, contentDescription = null, tint = color, modifier = Modifier.size(18.dp))
                    Text(if (reachable) "  Connected" else "  Unreachable", color = color, style = MaterialTheme.typography.bodyMedium)
                }
                Link("Change server…") { nav.navigate(ChangeServerRoute) }

                // Only on servers with sign-in on; with it off there is no account.
                active?.account?.user?.let { user ->
                    Section("Account")
                    Value("Signed in as", "${user.displayName} (@${user.username})")
                    Value("Role", user.roleLabel)
                    if (signingOut) {
                        Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                            CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                            Text("  Signing out…")
                        }
                    } else {
                        Link("Sign out", color = MaterialTheme.colorScheme.error) { confirmSignOut = true }
                    }
                }

                Section("Reading")
                Link("Reader defaults", chevron = true) { nav.navigate(ReaderSettingsRoute) }

                Section("Offline")
                Link("Downloads", trailing = offlineBytes?.let(::formatBytes) ?: "…", chevron = true) { nav.navigate(DownloadsRoute) }
                Value("Cover and page images", imageBytes?.let(::formatBytes) ?: "…")
                Value("All saved data", totalBytes?.let(::formatBytes) ?: "…")
                if (pending > 0) {
                    Row(Modifier.fillMaxWidth().heightIn(min = 48.dp).padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text("$pending reading updates waiting to sync", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f))
                        if (syncing) {
                            CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
                        } else {
                            TextButton(onClick = { app.scope.launch { syncing = true; app.flushWrites(); syncing = false } }) { Text("Sync now") }
                        }
                    }
                }
                Link("Clear cache…", color = MaterialTheme.colorScheme.error) { confirmClear = true }
                Note(
                    "Clearing removes saved lists, offline chapters and images from this device. Nothing on your server changes, and unsynced reading progress is kept.",
                )

                Section("About")
                Value("App version", "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})")
                Value("Server version", meta?.let { it.serverVersion ?: "Unknown" } ?: if (reachable) "…" else "Unavailable offline")
                meta?.let { Value("API version", "v${it.apiVersion}") }
            }
        }
    }

    if (confirmClear) {
        AlertDialog(
            onDismissRequest = { confirmClear = false },
            title = { Text("Clear all cached data?") },
            text = { Text("Offline chapters will need to be downloaded again.") },
            confirmButton = {
                TextButton(onClick = {
                    confirmClear = false
                    scope.launch { app.clearCaches(); measure() }
                }) { Text("Clear cache", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmClear = false }) { Text("Cancel") } },
        )
    }
    if (confirmSignOut) {
        AlertDialog(
            onDismissRequest = { confirmSignOut = false },
            title = { Text("Sign out of this server?") },
            text = {
                Text(
                    if (pending > 0) "$pending reading updates haven't synced yet. They stay on this device and are sent the next time you sign in to this account."
                    else "Your offline chapters for this account stay on this device.",
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    confirmSignOut = false
                    scope.launch { signingOut = true; app.signOut(); signingOut = false }
                }) { Text("Sign out", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmSignOut = false }) { Text("Cancel") } },
        )
    }
}

@Composable
private fun Section(title: String) {
    Column {
        HorizontalDivider(Modifier.padding(top = 12.dp))
        Text(
            title,
            style = MaterialTheme.typography.titleSmall,
            color = MaterialTheme.colorScheme.primary,
            modifier = Modifier.padding(start = 16.dp, top = 16.dp, bottom = 4.dp).semantics { heading() },
        )
    }
}

@Composable
private fun Value(label: String, value: String) {
    Row(
        Modifier.fillMaxWidth().heightIn(min = 48.dp).padding(horizontal = 16.dp, vertical = 8.dp).semantics(mergeDescendants = true) {},
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Text(value, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(start = 12.dp))
    }
}

@Composable
private fun Link(title: String, trailing: String? = null, chevron: Boolean = false, color: Color = MaterialTheme.colorScheme.primary, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .clickable(role = Role.Button, onClick = onClick)
            .heightIn(min = 48.dp)
            .padding(horizontal = 16.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(title, style = MaterialTheme.typography.bodyLarge, color = if (chevron) MaterialTheme.colorScheme.onSurface else color, modifier = Modifier.weight(1f))
        trailing?.let { Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        if (chevron) Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null, tint = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

@Composable
private fun Note(text: String) {
    Text(
        text,
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.padding(horizontal = 16.dp, vertical = 4.dp),
    )
}
