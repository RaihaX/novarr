package studio.visns.novarr.ui.connect

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import java.util.Date
import studio.visns.novarr.app.SignInFlow
import studio.visns.novarr.domain.PairingRules
import studio.visns.novarr.domain.PairingState
import studio.visns.novarr.ui.theme.BrandFonts
import studio.visns.novarr.ui.theme.LocalEink

/**
 * Link with a code: the code to type on the website while the app polls
 * (in the app's scope, so rotating never asks for a second code). In
 * e-ink mode nothing moves while waiting: the code is drawn once.
 */
@Composable
internal fun LinkCodeScreen(state: PairingState, server: String, flow: SignInFlow) {
    val eink = LocalEink.current
    BackHandler { flow.cancelPairing() }

    SignInFrame(title = "Link with a code", intro = null) {
        Spacer(Modifier.height(24.dp))
        when (state) {
            PairingState.Starting, PairingState.Linked -> Waiting("Getting a code…", eink)
            is PairingState.Waiting -> {
                Text(
                    state.code,
                    style = MaterialTheme.typography.displayMedium.copy(fontFamily = BrandFonts.GeistMono, letterSpacing = 4.sp),
                    textAlign = TextAlign.Center,
                    modifier = Modifier.fillMaxWidth().semantics { contentDescription = "Code: " + PairingRules.spoken(state.code) },
                )
                Spacer(Modifier.height(20.dp))
                Text(PairingRules.instructions(server), style = MaterialTheme.typography.bodyLarge, textAlign = TextAlign.Center)
                Spacer(Modifier.height(12.dp))
                Text(
                    "This code works until ${clockTime(state.expiresAt)}.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                )
                Spacer(Modifier.height(20.dp))
                Waiting("Waiting for the code to be entered…", eink)
            }
            PairingState.Expired -> {
                Text("This code has expired.", style = MaterialTheme.typography.bodyLarge, textAlign = TextAlign.Center)
                Spacer(Modifier.height(20.dp))
                Button(onClick = { flow.startPairing(server) }, modifier = Modifier.fillMaxWidth().height(52.dp)) { Text("Get a new code") }
            }
            is PairingState.Failed -> {
                ErrorLine(state.message)
                Spacer(Modifier.height(20.dp))
                Button(onClick = { flow.startPairing(server) }, modifier = Modifier.fillMaxWidth().height(52.dp)) { Text("Try again") }
            }
        }
        Spacer(Modifier.height(12.dp))
        OutlinedButton(onClick = { flow.cancelPairing() }, modifier = Modifier.fillMaxWidth().heightIn(min = 52.dp)) { Text("Cancel") }
    }
}

/** A still line in e-ink mode; elsewhere with a small spinner. */
@Composable
private fun Waiting(text: String, eink: Boolean) {
    Row(
        Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(10.dp, Alignment.CenterHorizontally),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (!eink) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp)
        Text(text, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}

/** The device's own clock format (12 or 24 hours). */
@Composable
private fun clockTime(at: Long): String {
    val context = LocalContext.current
    return remember(at) { android.text.format.DateFormat.getTimeFormat(context).format(Date(at)) }
}
