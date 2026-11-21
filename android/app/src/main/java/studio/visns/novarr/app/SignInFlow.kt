package studio.visns.novarr.app

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AuthCodes
import studio.visns.novarr.api.AuthTokenResponse
import studio.visns.novarr.api.DeviceIdentity
import studio.visns.novarr.api.SignInAnswer
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.domain.PairingRun
import studio.visns.novarr.domain.PairingState
import studio.visns.novarr.domain.SecondStepMessages
import studio.visns.novarr.domain.SecondStepMethod
import studio.visns.novarr.domain.SecondStepOutcome
import studio.visns.novarr.domain.SecondStepState
import studio.visns.novarr.domain.SignInCode

/**
 * The sign-in steps after the password form: the second step (a sign-in
 * code) and linking with a code. Held by the app model, so rotation keeps
 * the challenge, the typed code and a running link code; nothing of it is
 * saved, so process death starts over at the password form. The password
 * itself is never kept.
 *
 * Calls run in [scope] (the app's): leaving or rotating the screen never
 * cuts one short or starts a second link code.
 */
class SignInFlow(
    private val scope: CoroutineScope,
    /** A client for the server; with [token] only to read auth/me after a link without a user. */
    private val client: (server: String, token: String?) -> ApiClient,
    private val device: () -> DeviceIdentity,
    /** Same completion as a password sign-in: save the credential and start the session. */
    private val complete: suspend (server: String, response: AuthTokenResponse, username: String) -> Unit,
    private val clock: () -> Long = System::currentTimeMillis,
) {
    private val _secondStep = MutableStateFlow<SecondStepState?>(null)

    /** Non-null while the code screen is up. */
    val secondStep: StateFlow<SecondStepState?> = _secondStep.asStateFlow()

    /** The code being typed (Compose state, so the field never lags). Never saved. */
    var code by mutableStateOf("")

    private val _formMessage = MutableStateFlow<String?>(null)

    /** A message for the password form after the code screen sent it back ("That took too long…"). */
    val formMessage: StateFlow<String?> = _formMessage.asStateFlow()

    private val _pairing = MutableStateFlow<PairingState?>(null)

    /** Non-null while the link-code screen is up. */
    val pairing: StateFlow<PairingState?> = _pairing.asStateFlow()

    private var stepJob: Job? = null
    private var pairingJob: Job? = null

    /**
     * The password form's call. Signed in, or the code screen comes up.
     * Throws ApiError for the form (422, 429, 403 `second_step_unavailable`,
     * connectivity).
     */
    suspend fun signIn(server: String, username: String, password: String) {
        _formMessage.value = null
        when (val answer = client(server, null).signIn(username, password, device())) {
            is SignInAnswer.Signed -> complete(server, answer.response, username)
            is SignInAnswer.SecondStep -> {
                val state = SecondStepState.start(server, username, answer.required, clock())
                    // Only methods the app can't do (a passkey): the same as the server's 403.
                    ?: throw ApiError.Http(403, SecondStepMessages.NO_APP_METHOD, code = AuthCodes.SECOND_STEP_UNAVAILABLE)
                code = ""
                _secondStep.value = state
            }
        }
    }

    fun chooseMethod(method: SecondStepMethod) {
        val state = _secondStep.value ?: return
        if (state.busy) return
        val next = state.choose(method)
        if (next !== state) code = ""
        _secondStep.value = next
    }

    fun updateCode(raw: String) {
        val state = _secondStep.value ?: return
        code = SignInCode.input(state.method, raw)
    }

    /** "Email me a code" and "Send again". */
    fun sendEmailCode() {
        val state = _secondStep.value ?: return
        if (state.busy || !state.canSendAgain(clock())) return
        if (state.expired(clock())) return restart(SecondStepMessages.EXPIRED)
        _secondStep.value = state.copy(sending = true, error = null)
        stepJob = scope.launch {
            try {
                client(state.server, null).sendSignInCode(state.challenge, device())
                updateIfSame(state) { it.copy(sending = false, emailSentAt = clock(), error = null) }
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                fail(state, SecondStepMessages.emailFailed(ApiError.from(e)))
            }
        }
    }

    fun submitCode() {
        val state = _secondStep.value ?: return
        val typed = code
        if (!state.canSubmit(typed)) return
        if (state.expired(clock())) return restart(SecondStepMessages.EXPIRED)
        _secondStep.value = state.copy(submitting = true, error = null)
        stepJob = scope.launch {
            val response = try {
                client(state.server, null).finishSignIn(state.challenge, state.method.wire, SignInCode.wire(state.method, typed), device())
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                fail(state, SecondStepMessages.submitFailed(ApiError.from(e)))
                return@launch
            }
            // Cancelled meanwhile (another server, sign-in turned off): nothing to finish.
            if (_secondStep.value?.challenge != state.challenge) return@launch
            // Never cut short half-way through starting the session.
            withContext(NonCancellable) {
                complete(state.server, response, state.username)
                if (_secondStep.value?.challenge == state.challenge) {
                    _secondStep.value = null
                    code = ""
                }
            }
        }
    }

    /** Back to the password form (Cancel, Back). */
    fun cancelSecondStep() {
        stepJob?.cancel()
        stepJob = null
        _secondStep.value = null
        code = ""
    }

    /** The challenge is gone: back to the password form with [message]. */
    private fun restart(message: String) {
        cancelSecondStep()
        _formMessage.value = message
    }

    /** A call failed (only one runs at a time: [SecondStepState.busy] blocks the others). */
    private fun fail(state: SecondStepState, outcome: SecondStepOutcome) {
        if (_secondStep.value?.challenge != state.challenge) return
        when (outcome) {
            is SecondStepOutcome.Expired -> restart(outcome.message)
            is SecondStepOutcome.Retry -> {
                if (outcome.clearCode) code = ""
                updateIfSame(state) { it.copy(sending = false, submitting = false, error = outcome.message) }
            }
        }
    }

    private inline fun updateIfSame(state: SecondStepState, change: (SecondStepState) -> SecondStepState) {
        _secondStep.update { current -> if (current?.challenge == state.challenge) change(current) else current }
    }

    // Link with a code

    /**
     * Shows the link-code screen and asks for a code. A code already being
     * shown (or asked for) is kept: the server allows only 10 an hour.
     */
    fun startPairing(server: String) {
        if (pairingJob?.isActive == true) return
        cancelSecondStep()
        val run = PairingRun(
            start = { client(server, null).startPairing(device()) },
            poll = { secret -> client(server, null).pairingStatus(secret) },
            onLinked = { token, user ->
                withContext(NonCancellable) {
                    val account = user ?: client(server, token).me()
                    complete(server, AuthTokenResponse(token, account), account.username)
                }
            },
            now = clock,
            onState = { state ->
                // Linked: the session has started, the screen goes.
                _pairing.value = if (state == PairingState.Linked) null else state
            },
        )
        _pairing.value = PairingState.Starting
        pairingJob = scope.launch { run.run() }
    }

    /** Cancel: stops polling (nothing more is sent) and goes back to the password form. */
    fun cancelPairing() {
        pairingJob?.cancel()
        pairingJob = null
        _pairing.value = null
    }

    /** Another server, or sign-in turned off: everything goes. */
    fun reset() {
        cancelSecondStep()
        cancelPairing()
        _formMessage.value = null
    }

    /** The password form was submitted again or edited: its old message goes. */
    fun clearFormMessage() {
        _formMessage.value = null
    }
}
