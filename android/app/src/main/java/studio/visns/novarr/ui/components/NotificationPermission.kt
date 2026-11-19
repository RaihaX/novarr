package studio.visns.novarr.ui.components

import android.Manifest
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.os.Build
import android.widget.Toast
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import studio.visns.novarr.app.Notifications

/**
 * Shows Android 13+'s "Allow Novarr to send you notifications?" prompt when
 * called; [onResult] gets whether it was granted. Before 13 there is no
 * prompt (the permission is granted at install) and this answers at once.
 * Android itself stops showing the prompt after it was denied twice; the
 * answer is then an immediate false.
 */
@Composable
fun rememberNotificationPermissionRequest(onResult: (Boolean) -> Unit = {}): () -> Unit {
    val callback = rememberUpdatedState(onResult)
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { callback.value(it) }
    return remember(launcher) {
        {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                launcher.launch(Manifest.permission.POST_NOTIFICATIONS)
            } else {
                callback.value(true)
            }
        }
    }
}

/** Android's notification settings for Novarr. */
fun openNotificationSettings(context: Context) {
    try {
        context.startActivity(Notifications.settingsIntent(context).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    } catch (_: ActivityNotFoundException) {
        Toast.makeText(context, "Open Settings › Apps › Novarr › Notifications.", Toast.LENGTH_LONG).show()
    }
}
