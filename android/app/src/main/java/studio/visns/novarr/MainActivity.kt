package studio.visns.novarr

import android.content.Intent
import android.os.Bundle
import android.view.KeyEvent
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.CompositionLocalProvider
import studio.visns.novarr.app.Notifications
import studio.visns.novarr.domain.RequestFeed
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.ui.Root
import studio.visns.novarr.ui.reader.VolumeKeys
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.theme.NovarrTheme

/** The single activity: everything else is Compose navigation. */
class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        val app = (application as NovarrApplication).model
        // Not after a configuration change: the same intent would open Home again.
        if (savedInstanceState == null) handle(intent)
        setContent {
            NovarrTheme {
                CompositionLocalProvider(LocalApp provides app) { Root() }
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        handle(intent)
    }

    /**
     * A tapped notification opens Home (an update, several series with new
     * chapters), one series (new chapters in it) or the Requests screen (a
     * request: its part, or the series), also when the app was on another screen.
     */
    private fun handle(intent: Intent?) {
        val action = intent?.action ?: return
        // Reopened from Recents, the old intent comes back: not a new tap.
        if (intent.flags and Intent.FLAG_ACTIVITY_LAUNCHED_FROM_HISTORY != 0) return
        val model = (application as NovarrApplication).model
        if (intent.getBooleanExtra(Notifications.EXTRA_FROM_NEW_CHAPTERS, false)) model.clearNewChapters()
        when (action) {
            Notifications.ACTION_OPEN_HOME -> model.requestHome()
            Notifications.ACTION_OPEN_SERIES ->
                intent.getIntExtra(Notifications.EXTRA_NOVEL_ID, 0).takeIf { it > 0 }?.let(model::openSeries) ?: model.requestHome()
            Notifications.ACTION_OPEN_REQUESTS -> model.openRequests(
                RequestFeed.Target(
                    Requests.Section.from(intent.getStringExtra(Notifications.EXTRA_SECTION)),
                    intent.getIntExtra(Notifications.EXTRA_NOVEL_ID, 0).takeIf { it > 0 },
                ),
            )
        }
    }

    /** Volume keys turn pages while a paged reader asks for them (down = next). */
    override fun onKeyDown(keyCode: Int, event: KeyEvent): Boolean {
        val forward = volumeDirection(keyCode)
        val handler = VolumeKeys.handler
        if (forward != null && handler != null) {
            // One turn per press; held repeats are swallowed so the volume doesn't change either.
            if (event.repeatCount == 0) handler(forward)
            return true
        }
        return super.onKeyDown(keyCode, event)
    }

    override fun onKeyUp(keyCode: Int, event: KeyEvent): Boolean {
        if (volumeDirection(keyCode) != null && VolumeKeys.handler != null) return true
        return super.onKeyUp(keyCode, event)
    }

    private fun volumeDirection(keyCode: Int): Boolean? = when (keyCode) {
        KeyEvent.KEYCODE_VOLUME_DOWN -> true
        KeyEvent.KEYCODE_VOLUME_UP -> false
        else -> null
    }
}
