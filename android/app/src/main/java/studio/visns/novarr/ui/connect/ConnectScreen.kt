package studio.visns.novarr.ui.connect

import androidx.compose.material3.LocalTextStyle
import studio.visns.novarr.ui.theme.BrandFonts
import studio.visns.novarr.ui.components.BrandLockup
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.ServerUrl
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.readableWidth

/** Enter and verify the server address. Shown on first launch and from Settings / sign-in. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ConnectScreen(initialSetup: Boolean, onDone: () -> Unit) {
    val app = LocalApp.current
    val scope = rememberCoroutineScope()
    var address by rememberSaveable { mutableStateOf(app.currentServer ?: studio.visns.novarr.BuildConfig.DEFAULT_SERVER) }
    var checking by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val focus = remember { FocusRequester() }

    fun verify() {
        if (checking) return
        error = null
        val url = ServerUrl.normalize(address)
        if (url == null) {
            error = ApiError.InvalidServerUrl.userMessage
            return
        }
        address = url
        checking = true
        scope.launch {
            try {
                val meta = ApiClient(url, app.http).meta()
                app.connect(url, meta).join()
                onDone()
            } catch (e: Exception) {
                if (e is kotlinx.coroutines.CancellationException) throw e
                error = ApiError.from(e).userMessage
            } finally {
                checking = false
            }
        }
    }

    LaunchedEffect(Unit) { if (initialSetup) runCatching { focus.requestFocus() } }

    Scaffold(
        topBar = {
            if (!initialSetup) {
                TopAppBar(
                    title = { Text("Server") },
                    navigationIcon = {
                        IconButton(onClick = onDone) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                    },
                )
            }
        },
    ) { padding ->
        Column(
            Modifier
                .fillMaxSize()
                .padding(padding)
                .imePadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 24.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Column(Modifier.readableWidth(520.dp), horizontalAlignment = Alignment.CenterHorizontally) {
                if (initialSetup) {
                    Spacer(Modifier.height(64.dp))
                    BrandLockup(40.dp, Modifier.semantics { heading() })
                    Spacer(Modifier.height(8.dp))
                    Text(
                        "Connect to the Novarr server you run at home to read your library here.",
                        style = MaterialTheme.typography.bodyLarge,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                    )
                }
                Spacer(Modifier.height(32.dp))
                OutlinedTextField(
                    value = address,
                    onValueChange = { address = it; error = null },
                    label = { Text("Server address") },
                    textStyle = LocalTextStyle.current.copy(fontFamily = BrandFonts.GeistMono),
                    placeholder = { Text("http://192.168.0.10:8080") },
                    singleLine = true,
                    enabled = !checking,
                    isError = error != null,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, imeAction = ImeAction.Go, autoCorrectEnabled = false),
                    keyboardActions = KeyboardActions(onGo = { verify() }),
                    supportingText = {
                        Text("Your server's LAN address, or its address on Tailscale. “http://” is added if you leave it out.")
                    },
                    modifier = Modifier.fillMaxWidth().focusRequester(focus),
                )
                error?.let {
                    Row(
                        Modifier.fillMaxWidth().padding(top = 8.dp).semantics { liveRegion = LiveRegionMode.Polite },
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Icon(Icons.Outlined.ErrorOutline, contentDescription = null, tint = MaterialTheme.colorScheme.error)
                        Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
                    }
                }
                Spacer(Modifier.height(20.dp))
                Button(
                    onClick = { verify() },
                    enabled = !checking && address.isNotBlank(),
                    modifier = Modifier.fillMaxWidth().height(52.dp),
                ) {
                    if (checking) {
                        CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                        Spacer(Modifier.width(10.dp))
                        Text("Checking…")
                    } else {
                        Text("Connect")
                    }
                }
                Spacer(Modifier.height(32.dp))
            }
        }
    }
}
