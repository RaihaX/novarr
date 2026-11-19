package studio.visns.novarr

import android.os.Bundle
import android.view.KeyEvent
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.CompositionLocalProvider
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
        setContent {
            NovarrTheme {
                CompositionLocalProvider(LocalApp provides app) { Root() }
            }
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
