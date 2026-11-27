package studio.visns.novarr

import android.annotation.SuppressLint
import android.content.Intent
import android.os.Build
import android.os.Bundle
import android.view.KeyEvent
import androidx.activity.ComponentActivity
import androidx.compose.ui.platform.ComposeView
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import studio.visns.novarr.app.Notifications
import studio.visns.novarr.domain.Eink
import studio.visns.novarr.domain.EinkMode
import studio.visns.novarr.domain.RequestFeed
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.ui.Root
import studio.visns.novarr.ui.reader.PageKeyFrame
import studio.visns.novarr.ui.reader.PageKeys
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.theme.LocalEink
import studio.visns.novarr.ui.theme.NovarrTheme

/** The single activity: everything else is Compose navigation. */
class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        val app = (application as NovarrApplication).model
        // Not after a configuration change: the same intent would open Home again.
        if (savedInstanceState == null) handle(intent)
        // Page keys are taken in PageKeyFrame, around the Compose content.
        val content = ComposeView(this)
        setContentView(PageKeyFrame(this).apply { addView(content) })
        content.setContent {
            // Null until DataStore answers, so a stored choice isn't applied as Auto first.
            val einkMode by app.prefs.einkMode.collectAsStateWithLifecycle(null)
            val eink = Eink.resolve(einkMode ?: EinkMode.AUTO, Build.MANUFACTURER, Build.BRAND)
            LaunchedEffect(einkMode, eink) { if (einkMode != null && eink) app.prefs.applyEinkDefaultsOnce() }
            CompositionLocalProvider(LocalEink provides eink) {
                NovarrTheme(eink = eink) {
                    CompositionLocalProvider(LocalApp provides app) { Root() }
                }
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
            Notifications.ACTION_OPEN_PLAYER -> model.openPlayer()
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

    /**
     * Page keys turn pages while a reader is on screen. PageKeyFrame normally
     * takes them first; this catches any that reach the activity without it
     * (no view focused). Text fields only live in sheets and dialogs, which are
     * their own windows, so the reader window has none to break.
     *
     * RestrictedApi is a lint false positive: this overrides the public
     * android.app.Activity.dispatchKeyEvent, which androidx.core's
     * ComponentActivity re-declares with @RestrictTo, so lint flags the super call.
     */
    @SuppressLint("RestrictedApi")
    override fun dispatchKeyEvent(event: KeyEvent): Boolean = PageKeys.handle(event) || super.dispatchKeyEvent(event)
}
