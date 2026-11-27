package studio.visns.novarr.ui.settings

import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.Spacer
import studio.visns.novarr.ui.theme.BrandFonts
import androidx.compose.ui.platform.LocalContext
import studio.visns.novarr.ui.components.BrandLockup
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.toggleable
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
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Switch
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
import android.os.Build
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LifecycleResumeEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavController
import kotlinx.coroutines.launch
import studio.visns.novarr.BuildConfig
import studio.visns.novarr.app.Notifications
import studio.visns.novarr.app.Session
import studio.visns.novarr.domain.Eink
import studio.visns.novarr.domain.EinkMode
import studio.visns.novarr.store.ListeningPrefs
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.ui.ChangeServerRoute
import studio.visns.novarr.ui.DownloadsRoute
import studio.visns.novarr.ui.ReaderSettingsRoute
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.UpdateRow
import studio.visns.novarr.ui.components.formatBytes
import studio.visns.novarr.ui.components.openNotificationSettings
import studio.visns.novarr.ui.components.rememberNotificationPermissionRequest
import studio.visns.novarr.ui.components.readableWidth
import studio.visns.novarr.ui.theme.LocalEink
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
    var showFonts by remember { mutableStateOf(false) }
    var confirmClear by remember { mutableStateOf(false) }
    var confirmSignOut by remember { mutableStateOf(false) }
    var signingOut by remember { mutableStateOf(false) }
    var syncing by remember { mutableStateOf(false) }

    suspend fun measure() {
        // Offline chapters and downloaded audiobooks (both listed on Downloads).
        offlineBytes = (active?.store?.offlineChaptersSize() ?: 0) + (active?.store?.audiobookDownloads()?.sumOf { it.bytes } ?: 0)
        val images = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) { OfflineStore.sizeOf(app.coverCacheDir()) }
        imageBytes = images
        // Everything this account keeps on the device: lists, chapters and images.
        totalBytes = (active?.store?.totalSize() ?: 0) + images
    }
    val update by app.availableUpdate.collectAsStateWithLifecycle(null)
    LaunchedEffect(update) { update?.let(app::updateShown) }
    val context = LocalContext.current
    val notifyUpdates by app.prefs.updateNotifications.collectAsStateWithLifecycle(true)
    val notifyRequests by app.prefs.requestNotifications.collectAsStateWithLifecycle(true)
    val requestsOffered by app.requestsOffered.collectAsStateWithLifecycle()
    val notifyNewChapters by app.prefs.newChapterNotifications.collectAsStateWithLifecycle(true)
    val updatesOffered by app.updatesOffered.collectAsStateWithLifecycle()
    val einkMode by app.prefs.einkMode.collectAsStateWithLifecycle(EinkMode.AUTO)
    val eink = LocalEink.current
    val volumeKeys by app.prefs.volumeKeys.collectAsStateWithLifecycle(null)
    val audiobooksOffered by app.audiobooksOffered.collectAsStateWithLifecycle()
    val listeningPrefs by app.prefs.listening.collectAsStateWithLifecycle(ListeningPrefs())
    val audiobookRevision by app.listening.downloadsRevision.collectAsStateWithLifecycle()
    // Re-read on every return: the user may have changed them in Android's settings (each channel can be muted).
    var notificationsAllowed by remember { mutableStateOf(Notifications.canPost(context, Notifications.NotifyChannel.APP_UPDATES)) }
    var requestNotificationsAllowed by remember { mutableStateOf(Notifications.canPost(context, Notifications.NotifyChannel.REQUESTS)) }
    var newChaptersAllowed by remember { mutableStateOf(Notifications.canPost(context, Notifications.NotifyChannel.NEW_CHAPTERS)) }
    fun recheckNotifications() {
        notificationsAllowed = Notifications.canPost(context, Notifications.NotifyChannel.APP_UPDATES)
        requestNotificationsAllowed = Notifications.canPost(context, Notifications.NotifyChannel.REQUESTS)
        newChaptersAllowed = Notifications.canPost(context, Notifications.NotifyChannel.NEW_CHAPTERS)
    }
    LifecycleResumeEffect(Unit) {
        recheckNotifications()
        onPauseOrDispose {}
    }
    // One permission prompt for every switch.
    val askNotifications = rememberNotificationPermissionRequest { recheckNotifications() }
    // Always on opening Settings: server version, sign-in switch and a newer app build.
    LaunchedEffect(Unit) { app.refreshMeta() }
    LaunchedEffect(downloadRevision, audiobookRevision) { measure() }

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
                // Untouched, it shows on: the volume keys turn pages in the novel Pages mode (everywhere on e-ink).
                SwitchRow("Volume keys turn pages", volumeKeys ?: true) { on -> scope.launch { app.prefs.setVolumeKeys(on) } }
                Note(
                    when {
                        volumeKeys == false -> "The volume keys change the volume, also in the reader. Page, arrow and space keys still turn pages."
                        volumeKeys == null && !eink -> "In the Pages mode for web novels; switch off and on again to use them in every reading mode. Page, arrow and space keys always turn pages."
                        else -> "Volume down goes to the next page and volume up to the previous one, in every reading mode. Page, arrow and space keys always turn pages."
                    },
                )

                Section("Display")
                Text("E-ink mode", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp))
                SingleChoiceSegmentedButtonRow(
                    Modifier.fillMaxWidth().padding(horizontal = 16.dp).semantics { contentDescription = "E-ink mode" },
                ) {
                    EinkMode.entries.forEachIndexed { index, mode ->
                        SegmentedButton(
                            selected = mode == einkMode,
                            onClick = { scope.launch { app.prefs.setEinkMode(mode) } },
                            shape = SegmentedButtonDefaults.itemShape(index, EinkMode.entries.size),
                            modifier = Modifier.heightIn(min = 48.dp),
                            label = { Text(mode.title) },
                        )
                    }
                }
                val onyx = Eink.isOnyx(Build.MANUFACTURER, Build.BRAND)
                Note(
                    "Black on white, no animations, bolder text and page-button support, for e-ink screens. " +
                        "Auto turns it on for Onyx Boox devices." + if (onyx) " Onyx Boox detected." else "",
                )

                Section("Notifications")
                // Shows off while Android blocks them, whatever the saved choice.
                SwitchRow("Notify me about app updates", notifyUpdates && notificationsAllowed) { on ->
                    app.setUpdateNotifications(on)
                    if (on && !notificationsAllowed) askNotifications()
                }
                if (notifyUpdates && !notificationsAllowed) {
                    Note("Notifications from Novarr are blocked in Android's settings, so new versions only show here and on Home.")
                    Link("Open notification settings") { openNotificationSettings(context) }
                } else {
                    Note("Checks your server for a newer Novarr about twice a day, even while the app is closed.")
                }
                // Only on servers that take requests.
                if (requestsOffered) {
                    SwitchRow("Notify me about requests", notifyRequests && requestNotificationsAllowed) { on ->
                        app.setRequestNotifications(on)
                        if (on && !requestNotificationsAllowed) askNotifications()
                    }
                    if (notifyRequests && !requestNotificationsAllowed) {
                        Note("Request notifications are blocked in Android's settings, so news about requests only shows as a dot on Requests.")
                        if (!(notifyUpdates && !notificationsAllowed)) Link("Open notification settings") { openNotificationSettings(context) }
                    } else {
                        Note("New requests to review, and the owner's decisions on yours. Checked about once an hour while the app is closed.")
                    }
                }
                // Only on servers that send new chapters for it (meta `updates`).
                if (updatesOffered) {
                    SwitchRow("New chapter notifications", notifyNewChapters && newChaptersAllowed) { on ->
                        app.setNewChapterNotifications(on)
                        if (on && !newChaptersAllowed) askNotifications()
                    }
                    if (notifyNewChapters && !newChaptersAllowed) {
                        Note("New chapter notifications are blocked in Android's settings (or their “New chapters” category is off), so new chapters only show on Home.")
                        Link("Open notification settings") { openNotificationSettings(context) }
                    } else {
                        Note("One notification when series you'd caught up on get new chapters. Checked about every 2 hours while the app is closed; never makes a sound more than once in 6 hours, or between 10 pm and 7 am.")
                    }
                }

                // Only on servers with audiobooks (meta `audiobooks`).
                if (audiobooksOffered) {
                    Section("Listening")
                    SkipChoice("Skip back", listeningPrefs.skipBackSeconds) { s -> scope.launch { app.prefs.updateListening { it.copy(skipBackSeconds = s) } } }
                    SkipChoice("Skip forward", listeningPrefs.skipForwardSeconds) { s -> scope.launch { app.prefs.updateListening { it.copy(skipForwardSeconds = s) } } }
                    SwitchRow("Skip silence", listeningPrefs.skipSilence) { on -> app.listening.setSkipSilence(on) }
                    SwitchRow("Volume boost", listeningPrefs.boost) { on -> app.listening.setBoost(on) }
                    SwitchRow("Download audiobooks on Wi-Fi only", listeningPrefs.wifiOnlyDownloads) { on ->
                        scope.launch { app.prefs.updateListening { it.copy(wifiOnlyDownloads = on) } }
                    }
                    Note("Audiobooks are large: on Wi-Fi only, a download waits for Wi-Fi. Applies to downloads started from now on.")
                    SwitchRow("Upload audiobooks on Wi-Fi only", listeningPrefs.wifiOnlyUploads) { on ->
                        scope.launch { app.prefs.updateListening { it.copy(wifiOnlyUploads = on) } }
                    }
                    Note("An audiobook you upload waits for Wi-Fi. Applies to uploads started from now on.")
                }

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
                BrandLockup(28.dp, Modifier.padding(horizontal = 16.dp, vertical = 12.dp))
                Value("App version", "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})")
                update?.let { UpdateRow(it) }
                Value("Server version", meta?.let { it.serverVersion ?: "Unknown" } ?: if (reachable) "…" else "Unavailable offline")
                meta?.let { Value("API version", "v${it.apiVersion}") }
                Link("Fonts", trailing = "Geist, Geist Mono, Literata", chevron = true) { showFonts = true }
                Note("Fonts: Geist, Geist Mono, Literata — SIL Open Font License.")
            }
        }
    }

    if (showFonts) FontLicences { showFonts = false }

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
private fun SwitchRow(title: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .toggleable(value = checked, role = Role.Switch, onValueChange = onChange)
            .heightIn(min = 48.dp)
            .padding(horizontal = 16.dp, vertical = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(title, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
        Switch(checked = checked, onCheckedChange = null)
    }
}

/** A skip interval: 10, 15, 30, 45 or 60 seconds. */
@Composable
private fun SkipChoice(title: String, seconds: Int, onChange: (Int) -> Unit) {
    Text(title, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(horizontal = 16.dp, vertical = 8.dp))
    SingleChoiceSegmentedButtonRow(Modifier.fillMaxWidth().padding(horizontal = 16.dp).semantics { contentDescription = "$title seconds" }) {
        val choices = ListeningPrefs.SKIP_CHOICES
        choices.forEachIndexed { index, choice ->
            SegmentedButton(
                selected = choice == seconds,
                onClick = { onChange(choice) },
                shape = SegmentedButtonDefaults.itemShape(index, choices.size),
                modifier = Modifier.heightIn(min = 48.dp),
                label = { Text("${choice}s") },
            )
        }
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

/** The bundled fonts' licences (assets/licenses), as the SIL OFL asks. */
@Composable
private fun FontLicences(onDismiss: () -> Unit) {
    val context = LocalContext.current
    val text = remember {
        listOf("OFL-Geist.txt", "OFL-Literata.txt").joinToString("\n\n") { name ->
            runCatching { context.assets.open("licenses/$name").bufferedReader().use { it.readText() } }.getOrDefault("")
        }
    }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Fonts") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Text("Geist and Geist Mono (Vercel) and Literata (TypeTogether for Google) are used under the SIL Open Font License 1.1.", style = MaterialTheme.typography.bodyMedium)
                Spacer(Modifier.height(12.dp))
                Text(text, style = MaterialTheme.typography.bodySmall.copy(fontFamily = BrandFonts.GeistMono), color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Done") } },
    )
}
