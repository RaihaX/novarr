package studio.visns.novarr.ui.connect

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Visibility
import androidx.compose.material.icons.outlined.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LocalTextStyle
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.autofill.ContentType
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.semantics.contentType
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AuthCodes
import studio.visns.novarr.app.Session
import studio.visns.novarr.domain.SecondStepMessages
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.theme.BrandFonts

/** What the sign-in form says when a sign-in fails. */
object SignInMessages {
    const val UNAVAILABLE_HERE = "This server is not accepting sign-ins at this address."
    const val UNAVAILABLE_ACCOUNT =
        "This account needs a second sign-in step before it can be used here. Set one up from your home network, or ask the person who runs this Novarr."

    fun message(error: ApiError): String = when {
        error is ApiError.Http && error.status == 422 -> error.serverMessage?.takeIf { it.isNotBlank() } ?: "Wrong name or password."
        error is ApiError.Http && error.status == 429 ->
            error.serverMessage?.takeIf { it.isNotBlank() } ?: SecondStepMessages.THROTTLED
        isSecondStepUnavailable(error) -> (error as ApiError.Http).serverMessage?.takeIf { it.isNotBlank() } ?: UNAVAILABLE_ACCOUNT
        error is ApiError.Http && error.status == 404 -> "This server doesn't support signing in from the app yet. Update the server."
        error.isConnectivity -> "Couldn't reach the server. Check your connection and try again."
        else -> error.userMessage
    }

    /** 403 `second_step_unavailable`: the password was right but no code can be entered here; offer the link code. */
    fun isSecondStepUnavailable(error: ApiError): Boolean =
        error is ApiError.Http && error.status == 403 && error.code == AuthCodes.SECOND_STEP_UNAVAILABLE

    /** The form's intro; [asksForCode]: meta says this address asks for a sign-in code. */
    fun intro(asksForCode: Boolean): String =
        "This Novarr server has accounts. Sign in with the name or email and password you were given." +
            if (asksForCode) " After your password, you'll be asked for a sign-in code." else " You may also be asked for a sign-in code."
}

/**
 * Sign-in for a server with sign-in on: the password form, then (when the
 * server asks) the sign-in code screen, or linking with a code instead.
 * Shown after the server step, and again whenever the session ends (401 or
 * sign-out), with the server and last name prefilled. The code and link-code
 * steps live in the app model ([studio.visns.novarr.app.SignInFlow]), so
 * rotating the device keeps them.
 */
@Composable
fun SignInScreen(session: Session.SignIn) {
    val app = LocalApp.current
    val flow = app.signInFlow
    val secondStep by flow.secondStep.collectAsStateWithLifecycle()
    val pairing by flow.pairing.collectAsStateWithLifecycle()
    var changingServer by rememberSaveable { mutableStateOf(false) }
    // Never saved: the password goes with the screen, and is cleared after the call.
    var password by remember { mutableStateOf("") }

    when {
        changingServer -> {
            BackHandler { changingServer = false }
            ConnectScreen(initialSetup = false, onDone = { changingServer = false })
        }
        pairing != null -> LinkCodeScreen(pairing!!, session.server, flow)
        secondStep != null -> SecondStepScreen(secondStep!!, flow)
        else -> PasswordForm(session, password, { password = it }, onChangeServer = { changingServer = true })
    }
}

@Composable
private fun PasswordForm(session: Session.SignIn, password: String, setPassword: (String) -> Unit, onChangeServer: () -> Unit) {
    val app = LocalApp.current
    val flow = app.signInFlow
    val scope = rememberCoroutineScope()
    val meta by app.serverMeta.collectAsStateWithLifecycle()
    val formMessage by flow.formMessage.collectAsStateWithLifecycle()
    var username by rememberSaveable(session.server) { mutableStateOf(session.lastUsername.orEmpty()) }
    var showPassword by remember { mutableStateOf(false) }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    // The server said this account can't pass the second step here: offer the link code even if meta didn't.
    var suggestLink by rememberSaveable(session.server) { mutableStateOf(false) }
    var retryingMeta by remember { mutableStateOf(false) }
    val passwordFocus = remember { FocusRequester() }

    val unavailable = meta?.signInUnavailable == true
    val canSubmit = !busy && username.isNotBlank() && password.isNotEmpty()
    fun submit() {
        if (!canSubmit) return
        error = null
        busy = true
        scope.launch {
            try {
                app.signIn(username.trim(), password)
                // Signed in, or the code screen is up: the password is not kept.
                setPassword("")
            } catch (e: Exception) {
                if (e is kotlinx.coroutines.CancellationException) throw e
                val apiError = ApiError.from(e)
                error = SignInMessages.message(apiError)
                if (SignInMessages.isSecondStepUnavailable(apiError)) {
                    // The password was right but can't be used here: no reason to keep it.
                    suggestLink = true
                    setPassword("")
                }
                runCatching { passwordFocus.requestFocus() }
            } finally {
                busy = false
            }
        }
    }

    SignInFrame(title = "Sign in", intro = if (unavailable) null else SignInMessages.intro(meta?.asksForSecondStep == true)) {
        session.notice?.takeIf { !unavailable }?.let { notice ->
            Spacer(Modifier.height(20.dp))
            NoticeCard(notice)
        }

        Spacer(Modifier.height(24.dp))
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Server", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
                Text(session.server, style = MaterialTheme.typography.bodyMedium, maxLines = 1, overflow = TextOverflow.MiddleEllipsis)
            }
            TextButton(onClick = onChangeServer, enabled = !busy) { Text("Change") }
        }

        if (unavailable) {
            // Sign-in is on but switched off at this address: no form, nothing to type.
            Spacer(Modifier.height(20.dp))
            NoticeCard(SignInMessages.UNAVAILABLE_HERE)
            Spacer(Modifier.height(20.dp))
            BusyButton("Try again", "Checking…", busy = retryingMeta, enabled = true) {
                retryingMeta = true
                scope.launch {
                    try {
                        app.refreshMeta()
                    } finally {
                        retryingMeta = false
                    }
                }
            }
            return@SignInFrame
        }

        Spacer(Modifier.height(12.dp))
        OutlinedTextField(
            value = username,
            onValueChange = { username = it },
            label = { Text("Name or email") },
            textStyle = LocalTextStyle.current.copy(fontFamily = BrandFonts.GeistMono),
            singleLine = true,
            enabled = !busy,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next, autoCorrectEnabled = false),
            keyboardActions = KeyboardActions(onNext = { runCatching { passwordFocus.requestFocus() } }),
            modifier = Modifier.fillMaxWidth().semantics { contentType = ContentType.Username },
        )
        Spacer(Modifier.height(12.dp))
        OutlinedTextField(
            value = password,
            onValueChange = setPassword,
            label = { Text("Password") },
            singleLine = true,
            enabled = !busy,
            visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Go, autoCorrectEnabled = false),
            keyboardActions = KeyboardActions(onGo = { submit() }),
            trailingIcon = {
                IconButton(onClick = { showPassword = !showPassword }) {
                    Icon(
                        if (showPassword) Icons.Outlined.VisibilityOff else Icons.Outlined.Visibility,
                        contentDescription = if (showPassword) "Hide password" else "Show password",
                    )
                }
            },
            modifier = Modifier.fillMaxWidth().focusRequester(passwordFocus).semantics { contentType = ContentType.Password },
        )
        (error ?: formMessage)?.let { ErrorLine(it) }
        Spacer(Modifier.height(20.dp))
        BusyButton("Sign in", "Signing in…", busy = busy, enabled = canSubmit) { submit() }
        if (meta?.offersPairing == true || suggestLink) {
            Spacer(Modifier.height(8.dp))
            TextButton(
                onClick = { flow.startPairing(session.server) },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
            ) { Text("Link with a code instead") }
        }
    }
}
