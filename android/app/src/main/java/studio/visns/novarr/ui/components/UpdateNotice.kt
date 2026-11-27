package studio.visns.novarr.ui.components

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.widget.Toast
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.SystemUpdate
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import studio.visns.novarr.api.AndroidRelease
import studio.visns.novarr.app.AppUpdater
import studio.visns.novarr.domain.AppUpdate
import studio.visns.novarr.domain.UpdateFailure
import studio.visns.novarr.domain.UpdateMachine
import studio.visns.novarr.domain.UpdateState

/**
 * Opens the server's APK download page in the browser, which downloads the
 * file; Android then offers to install it over this app. The fallback when
 * the in-app install fails. Without a browser the user is told so.
 */
fun openDownload(context: Context, url: String) {
    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    try {
        context.startActivity(intent)
    } catch (_: ActivityNotFoundException) {
        Toast.makeText(context, "No browser is installed to download the update. Open $url on this device.", Toast.LENGTH_LONG).show()
    } catch (_: SecurityException) {
        Toast.makeText(context, "Couldn't open the download link.", Toast.LENGTH_LONG).show()
    }
}

/** Android's "Install unknown apps" switch for Novarr. */
private fun openInstallPermission(context: Context, updater: AppUpdater) {
    try {
        context.startActivity(updater.permissionSettingsIntent().addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    } catch (_: ActivityNotFoundException) {
        Toast.makeText(context, "Open Settings › Apps › Special app access › Install unknown apps, and allow Novarr.", Toast.LENGTH_LONG).show()
    }
}

/** The line under the title for [state]; null in Idle (each place words that itself). */
private fun statusText(state: UpdateState): String? = when (state) {
    UpdateState.Idle -> null
    is UpdateState.Downloading -> state.percent?.let { "Downloading… $it%" } ?: "Downloading…"
    UpdateState.Verifying -> "Checking the download…"
    UpdateState.NeedsPermission -> "Allow Novarr to install apps, then come back here to finish the update."
    UpdateState.AwaitingConfirmation -> "Confirm the install in Android's dialog."
    is UpdateState.Failed -> state.reason.message
}

/**
 * What the notice shows for [release]: the updater's state as
 * [UpdateMachine.shown] words it, and whether the Play Protect hint goes
 * under it (before the first in-app install of this build).
 */
@Composable
private fun rememberShownState(release: AndroidRelease, updater: AppUpdater): Pair<UpdateState, Boolean> {
    val state by updater.state.collectAsStateWithLifecycle()
    val builds by updater.builds.collectAsStateWithLifecycle()
    val shown = UpdateMachine.shown(state, builds.current, release.build, builds.unfinished)
    return shown to (builds.loaded && UpdateMachine.showsInstallHint(shown, release.build, builds.attempted))
}

/** The one-line hint before the first install: Android (Play Protect) may warn about a sideloaded app. */
@Composable
private fun InstallHint(modifier: Modifier = Modifier) {
    Text(UpdateMachine.INSTALL_HINT, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = modifier)
}

/** The buttons for [state]: Update / Cancel / Allow / Install / Try again + Open in browser. */
@Composable
private fun UpdateActions(release: AndroidRelease, state: UpdateState, updater: AppUpdater) {
    val context = LocalContext.current
    when (state) {
        UpdateState.Idle -> TextButton(onClick = { updater.start(release) }) { Text("Update") }
        is UpdateState.Downloading, UpdateState.Verifying -> TextButton(onClick = updater::cancel) { Text("Cancel") }
        UpdateState.NeedsPermission -> {
            TextButton(onClick = updater::cancel) { Text("Cancel") }
            TextButton(onClick = { openInstallPermission(context, updater) }) { Text("Allow") }
        }
        UpdateState.AwaitingConfirmation -> {
            TextButton(onClick = updater::cancel) { Text("Cancel") }
            TextButton(onClick = { updater.showConfirmation() }) { Text("Install") }
        }
        // Didn't finish (cancelled, or Play Protect): Update again reuses the checked file.
        is UpdateState.Failed -> if (state.reason == UpdateFailure.Unfinished) {
            TextButton(onClick = { openDownload(context, release.url) }) { Text("Download in browser") }
            TextButton(onClick = { updater.start(release) }) { Text("Update") }
        } else {
            TextButton(onClick = { openDownload(context, release.url) }) { Text("Open in browser") }
            TextButton(onClick = { updater.start(release) }) { Text("Try again") }
        }
    }
}

/** "What's new" and the release's notes as a bullet list; nothing when the server sent none. */
@Composable
private fun WhatsNew(release: AndroidRelease, modifier: Modifier = Modifier) {
    val notes = AppUpdate.notes(release)
    if (notes.isEmpty()) return
    Column(modifier.semantics(mergeDescendants = true) {}, verticalArrangement = Arrangement.spacedBy(2.dp)) {
        Text("What's new", style = MaterialTheme.typography.labelLarge)
        notes.forEach { note ->
            Row {
                Text("•", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(end = 6.dp))
                Text(note, style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}

@Composable
private fun DownloadProgress(state: UpdateState, modifier: Modifier = Modifier) {
    when (state) {
        is UpdateState.Downloading -> {
            val percent = state.percent
            if (percent != null) LinearProgressIndicator(progress = { percent / 100f }, modifier = modifier.fillMaxWidth())
            else LinearProgressIndicator(modifier = modifier.fillMaxWidth())
        }
        UpdateState.Verifying -> LinearProgressIndicator(modifier = modifier.fillMaxWidth())
        else -> Unit
    }
}

/** Home's "Version 1.3 is available" notice: Update downloads and installs it in the app. */
@Composable
fun UpdateBanner(release: AndroidRelease, onDismiss: () -> Unit, modifier: Modifier = Modifier) {
    val updater = LocalApp.current.updater
    val (state, hint) = rememberShownState(release, updater)
    Surface(
        color = MaterialTheme.colorScheme.secondaryContainer,
        contentColor = MaterialTheme.colorScheme.onSecondaryContainer,
        shape = RoundedCornerShape(14.dp),
        modifier = modifier.fillMaxWidth(),
    ) {
        Column(Modifier.padding(start = 16.dp, top = 6.dp, bottom = 6.dp, end = 4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Outlined.SystemUpdate, contentDescription = null, modifier = Modifier.size(20.dp))
                Column(Modifier.weight(1f).padding(start = 12.dp), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                    Text(
                        if (release.version.isNullOrBlank()) "A newer version is available" else "Version ${release.version} is available",
                        style = MaterialTheme.typography.titleSmall,
                    )
                    Text(
                        statusText(state) ?: "Download and install it from your server.",
                        style = MaterialTheme.typography.bodySmall,
                        // A failure is read out when it appears.
                        modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite },
                    )
                    if (hint) InstallHint(Modifier.padding(top = 2.dp))
                    if (state == UpdateState.Idle) WhatsNew(release, Modifier.padding(top = 4.dp))
                }
                // While an update runs the notice stays; Cancel stops it instead.
                if (!state.busy) IconButton(onClick = onDismiss) { Icon(Icons.Outlined.Close, contentDescription = "Dismiss update notice") }
            }
            DownloadProgress(state, Modifier.padding(start = 32.dp, end = 12.dp, top = 6.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { UpdateActions(release, state, updater) }
        }
    }
}

/** Settings' persistent "Update available: 1.3" row with the same actions. */
@Composable
fun UpdateRow(release: AndroidRelease) {
    val updater = LocalApp.current.updater
    val (state, hint) = rememberShownState(release, updater)
    Column(Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                "Update available: ${AppUpdate.label(release)}",
                style = MaterialTheme.typography.bodyLarge,
                color = MaterialTheme.colorScheme.primary,
                modifier = Modifier.weight(1f),
            )
            if (state == UpdateState.Idle) UpdateActions(release, state, updater)
        }
        if (hint) InstallHint(Modifier.padding(end = 8.dp, bottom = 6.dp))
        if (state == UpdateState.Idle) WhatsNew(release, Modifier.padding(end = 8.dp, bottom = 6.dp))
        if (state != UpdateState.Idle) {
            statusText(state)?.let {
                Text(it, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(end = 8.dp).semantics { liveRegion = LiveRegionMode.Polite })
            }
            DownloadProgress(state, Modifier.padding(top = 6.dp, end = 8.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) { UpdateActions(release, state, updater) }
        }
    }
}
