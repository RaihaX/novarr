package studio.visns.novarr.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.key
import androidx.compose.ui.Modifier
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import studio.visns.novarr.app.Session
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.connect.ConnectScreen
import studio.visns.novarr.ui.connect.SignInScreen

/** Connect, sign in, or the app proper, following the session. */
@Composable
fun Root() {
    val app = LocalApp.current
    val session by app.session.collectAsStateWithLifecycle()
    when (val s = session) {
        Session.Starting -> Box(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background))
        Session.NoServer -> ConnectScreen(initialSetup = true, onDone = {})
        is Session.SignIn -> SignInScreen(s)
        // Recreate navigation (and every screen's model) when the server or account changes.
        is Session.Active -> key(s.id) { MainNavigation() }
    }
}
