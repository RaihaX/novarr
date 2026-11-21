package studio.visns.novarr.ui.connect

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.LocalTextStyle
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.autofill.ContentType
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.contentType
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.OffsetMapping
import androidx.compose.ui.text.input.TransformedText
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import kotlinx.coroutines.delay
import studio.visns.novarr.app.SignInFlow
import studio.visns.novarr.domain.SecondStepMethod
import studio.visns.novarr.domain.SecondStepState
import studio.visns.novarr.domain.SignInCode
import studio.visns.novarr.ui.theme.BrandFonts

/** What the code screen says for each method. */
object SecondStepTexts {
    const val TITLE = "Enter your sign-in code"
    const val TOTP = "Open your authenticator app and enter the 6-digit code it shows for Novarr."
    const val RECOVERY = "Enter one of the recovery codes you saved. Each one works once."

    fun beforeEmail(hint: String?): String =
        if (hint != null) "We'll email a 6-digit code to $hint." else "We'll email you a 6-digit code."

    fun emailSent(hint: String?): String = if (hint != null) "We sent a code to $hint." else "We sent you a code by email."
}

/**
 * The second step after a correct password: a method chooser built only
 * from the methods the server offered, and the code. All state is in
 * [SignInFlow] (the app model), so rotation keeps it.
 */
@Composable
internal fun SecondStepScreen(state: SecondStepState, flow: SignInFlow) {
    val focus = remember { FocusRequester() }
    BackHandler(enabled = !state.submitting) { flow.cancelSecondStep() }

    // "Send again" wakes up once, 30 s after a send: no ticking countdown (e-ink redraws every change).
    var now by remember { mutableLongStateOf(System.currentTimeMillis()) }
    LaunchedEffect(state.sendAgainAt) {
        val at = state.sendAgainAt ?: return@LaunchedEffect
        val wait = at - System.currentTimeMillis()
        if (wait > 0) delay(wait)
        now = System.currentTimeMillis()
    }
    LaunchedEffect(state.method, state.awaitingEmail) {
        if (!state.awaitingEmail) runCatching { focus.requestFocus() }
    }

    SignInFrame(title = SecondStepTexts.TITLE, intro = state.message) {
        Spacer(Modifier.height(8.dp))
        Text(
            "Signing in as ${state.username}",
            style = MaterialTheme.typography.labelLarge,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )

        if (state.methods.size > 1) {
            Spacer(Modifier.height(20.dp))
            Column(Modifier.fillMaxWidth().selectableGroup().semantics { contentDescription = "How to get your code" }) {
                state.methods.forEach { method ->
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .heightIn(min = 56.dp)
                            .selectable(
                                selected = method == state.method,
                                enabled = !state.busy,
                                role = Role.RadioButton,
                                onClick = { flow.chooseMethod(method) },
                            )
                            .padding(horizontal = 4.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        // The row is the touch target; the radio only shows the choice.
                        RadioButton(selected = method == state.method, onClick = null, enabled = !state.busy)
                        Text(method.title, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(start = 16.dp))
                    }
                }
            }
        }

        Spacer(Modifier.height(20.dp))
        Text(
            when {
                state.method == SecondStepMethod.TOTP -> SecondStepTexts.TOTP
                state.method == SecondStepMethod.RECOVERY -> SecondStepTexts.RECOVERY
                state.awaitingEmail -> SecondStepTexts.beforeEmail(state.emailHint)
                else -> SecondStepTexts.emailSent(state.emailHint)
            },
            style = MaterialTheme.typography.bodyMedium,
            modifier = Modifier.fillMaxWidth(),
        )

        if (state.awaitingEmail) {
            // Never sent by itself: the person chooses to get an email.
            state.error?.let { ErrorLine(it) }
            Spacer(Modifier.height(20.dp))
            BusyButton("Email me a code", "Sending…", busy = state.sending, enabled = true) { flow.sendEmailCode() }
        } else {
            Spacer(Modifier.height(12.dp))
            CodeField(state, flow, focus)
            state.error?.let { ErrorLine(it) }
            Spacer(Modifier.height(20.dp))
            BusyButton("Continue", "Checking…", busy = state.submitting, enabled = state.canSubmit(flow.code)) { flow.submitCode() }
            if (state.method == SecondStepMethod.EMAIL) {
                TextButton(
                    onClick = { flow.sendEmailCode() },
                    enabled = !state.busy && state.canSendAgain(now),
                    modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
                ) { Text(if (state.sending) "Sending…" else "Send again") }
            }
        }

        Spacer(Modifier.height(8.dp))
        TextButton(
            onClick = { flow.cancelSecondStep() },
            enabled = !state.submitting,
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
        ) { Text("Cancel") }
    }
}

@Composable
private fun CodeField(state: SecondStepState, flow: SignInFlow, focus: FocusRequester) {
    val numeric = state.method.numeric
    OutlinedTextField(
        value = flow.code,
        onValueChange = { flow.updateCode(it) },
        label = { Text(if (numeric) "Sign-in code" else "Recovery code") },
        placeholder = { Text(if (numeric) "123456" else "xxxxx-xxxxx") },
        textStyle = LocalTextStyle.current.copy(fontFamily = BrandFonts.GeistMono, fontSize = 22.sp, letterSpacing = 2.sp),
        singleLine = true,
        enabled = !state.submitting,
        visualTransformation = if (numeric) VisualTransformation.None else RecoveryCodeTransformation,
        keyboardOptions = KeyboardOptions(
            keyboardType = if (numeric) KeyboardType.Number else KeyboardType.Ascii,
            capitalization = KeyboardCapitalization.None,
            imeAction = ImeAction.Done,
            autoCorrectEnabled = false,
        ),
        keyboardActions = KeyboardActions(onDone = { flow.submitCode() }),
        modifier = Modifier
            .fillMaxWidth()
            .focusRequester(focus)
            // One-time codes: the keyboard / autofill can offer the code (an emailed one, too).
            .semantics { if (numeric) contentType = ContentType.SmsOtpCode },
    )
}

/** Draws the recovery code's hyphen after the fifth character; the field keeps only letters and digits. */
private object RecoveryCodeTransformation : VisualTransformation {
    override fun filter(text: AnnotatedString): TransformedText {
        val length = text.text.length
        return TransformedText(
            AnnotatedString(SignInCode.recoveryDisplay(text.text)),
            object : OffsetMapping {
                override fun originalToTransformed(offset: Int): Int = SignInCode.recoveryToDisplay(offset, length)
                override fun transformedToOriginal(offset: Int): Int = SignInCode.recoveryFromDisplay(offset, length)
            },
        )
    }
}
