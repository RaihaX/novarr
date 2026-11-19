package studio.visns.novarr

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.CompositionLocalProvider
import studio.visns.novarr.ui.Root
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
}
