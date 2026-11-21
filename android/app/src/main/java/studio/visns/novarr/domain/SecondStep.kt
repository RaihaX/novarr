package studio.visns.novarr.domain

import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AuthCodes
import studio.visns.novarr.api.SecondStepRequired
import studio.visns.novarr.api.SignInErrorBody
import studio.visns.novarr.api.nonBlank

/**
 * A way to pass the second sign-in step in the app (the web's passkey is not
 * one: an account with only a passkey links with a code instead). [wire] is
 * the contract's `method` value, [title] the chooser's label.
 */
enum class SecondStepMethod(val wire: String, val title: String) {
    TOTP("totp", "Authenticator app"),
    EMAIL("email", "Email me a code"),
    RECOVERY("recovery", "Use a recovery code");

    /** A 6-digit code (numeric keyboard); else a recovery code `xxxxx-xxxxx`. */
    val numeric: Boolean get() = this != RECOVERY

    companion object {
        /** The methods of a challenge the app can use, in the app's order; unknown ones are left out. */
        fun usable(raw: List<String>): List<SecondStepMethod> =
            entries.filter { method -> raw.any { it.trim().equals(method.wire, ignoreCase = true) } }

        /** The authenticator when offered, else the emailed code, else whatever there is. */
        fun default(methods: List<SecondStepMethod>): SecondStepMethod? = when {
            TOTP in methods -> TOTP
            EMAIL in methods -> EMAIL
            else -> methods.firstOrNull()
        }
    }
}

/**
 * Typing and sending sign-in codes. The field keeps only what can be part of
 * a code (digits; a recovery code's letters and digits, without its hyphen),
 * so pasting "123 456" or "ABCDE-FGHIJ" just works; the recovery code's
 * hyphen is drawn by the field (the offsets below) and added when sent.
 */
object SignInCode {
    const val DIGITS = 6
    const val RECOVERY_PART = 5
    const val RECOVERY_LENGTH = 2 * RECOVERY_PART

    /** What the field keeps of [raw] for [method]. */
    fun input(method: SecondStepMethod, raw: String): String =
        if (method.numeric) {
            raw.filter { it in '0'..'9' }.take(DIGITS)
        } else {
            raw.lowercase().filter { it in 'a'..'z' || it in '0'..'9' }.take(RECOVERY_LENGTH)
        }

    fun isComplete(method: SecondStepMethod, input: String): Boolean {
        val kept = input(method, input)
        return kept.length == if (method.numeric) DIGITS else RECOVERY_LENGTH
    }

    /** The `code` sent to the server: "123456", or "abcde-fghij" for a recovery code. */
    fun wire(method: SecondStepMethod, input: String): String {
        val kept = input(method, input)
        return if (method.numeric || kept.length <= RECOVERY_PART) kept else kept.take(RECOVERY_PART) + "-" + kept.drop(RECOVERY_PART)
    }

    /** A recovery code as shown while typing: the hyphen appears after the fifth character. */
    fun recoveryDisplay(input: String): String =
        if (input.length <= RECOVERY_PART) input else input.take(RECOVERY_PART) + "-" + input.drop(RECOVERY_PART)

    /** Cursor offsets between the kept text and [recoveryDisplay] (the field's OffsetMapping). */
    fun recoveryToDisplay(offset: Int, length: Int): Int = if (offset > RECOVERY_PART && length > RECOVERY_PART) offset + 1 else offset

    fun recoveryFromDisplay(offset: Int, length: Int): Int =
        (if (offset > RECOVERY_PART && length > RECOVERY_PART) offset - 1 else offset).coerceIn(0, length)
}

/**
 * The second step of one sign-in, between the password and the token. Pure
 * (time comes in as `now`, epoch ms): the app model keeps it in memory only,
 * so it survives rotation but never process death, and it never holds the
 * password.
 */
data class SecondStepState(
    val server: String,
    /** The name typed on the password form (kept for the next sign-in's prefill). */
    val username: String,
    val challenge: String,
    val methods: List<SecondStepMethod>,
    val emailHint: String?,
    /** The server's sentence ("Enter your sign-in code to finish signing in."). */
    val message: String,
    val expiresAt: Long,
    val method: SecondStepMethod,
    /** When the last code was emailed for this challenge; null before the first. */
    val emailSentAt: Long? = null,
    val sending: Boolean = false,
    val submitting: Boolean = false,
    val error: String? = null,
) {
    val busy: Boolean get() = sending || submitting

    fun expired(now: Long): Boolean = now >= expiresAt

    /** The emailed-code method before any code was sent: a button, no field yet. */
    val awaitingEmail: Boolean get() = method == SecondStepMethod.EMAIL && emailSentAt == null

    /** When "Send again" may be pressed; null before the first send. */
    val sendAgainAt: Long? get() = emailSentAt?.let { it + RESEND_AFTER_MS }

    fun canSendAgain(now: Long): Boolean = sendAgainAt?.let { now >= it } ?: true

    fun choose(next: SecondStepMethod): SecondStepState =
        if (next in methods && next != method) copy(method = next, error = null) else this

    fun canSubmit(code: String): Boolean = !busy && !awaitingEmail && SignInCode.isComplete(method, code)

    companion object {
        const val RESEND_AFTER_MS = 30_000L
        const val DEFAULT_MESSAGE = "Enter your sign-in code to finish signing in."

        /** From the 401 body; null when the app can use none of its methods. */
        fun start(server: String, username: String, required: SecondStepRequired, now: Long): SecondStepState? {
            val methods = SecondStepMethod.usable(required.methods)
            val method = SecondStepMethod.default(methods) ?: return null
            val lifetime = required.expiresIn.takeIf { it > 0 } ?: SecondStepRequired.DEFAULT_EXPIRES_IN
            return SecondStepState(
                server = server,
                username = username,
                challenge = required.challenge,
                methods = methods,
                emailHint = required.emailHint.nonBlank()?.takeIf { SecondStepMethod.EMAIL in methods },
                message = required.message.nonBlank() ?: DEFAULT_MESSAGE,
                expiresAt = now + lifetime * 1000,
                method = method,
            )
        }
    }
}

/** What a failed second-step call means for the screen. */
sealed interface SecondStepOutcome {
    /** Stay on the code screen with [message]; [clearCode]: the typed code was wrong. */
    data class Retry(val message: String, val clearCode: Boolean = false) : SecondStepOutcome

    /**
     * The challenge is gone: back to the password form with [message] (the
     * server's: "Too many wrong codes. Sign in again." after the 5th wrong
     * code, "That took too long. Sign in again." on timeout).
     */
    data class Expired(val message: String = SecondStepMessages.EXPIRED) : SecondStepOutcome
}

/** What the code screen says when something fails. */
object SecondStepMessages {
    const val EXPIRED = "That took too long. Sign in again."
    const val OFFLINE = "No connection. Try again."
    const val WRONG_CODE = "That code is not right. Check it and try again."

    /** The sign-in form's wording for 429 (SignInMessages uses it too). */
    const val THROTTLED = "Too many sign-in attempts. Wait a minute and try again."
    const val EMAIL_THROTTLED = "Too many codes were sent. Wait a while and try again."

    /**
     * A 401 challenge with no method the app has. The v1.1 contract answers a
     * passkey-only account with 403 `second_step_unavailable` instead, so this
     * is only a fallback for an odd server.
     */
    const val NO_APP_METHOD = "This account's second sign-in step can't be done in the app. Use Link with a code instead."

    fun triesLeft(n: Int): String = when {
        n <= 0 -> "No tries left."
        n == 1 -> "1 try left."
        else -> "$n tries left."
    }

    /** The server's message for 404 `challenge_expired`; anything else (a bare 404, a 401) says [EXPIRED]. */
    private fun expired(error: ApiError.Http): SecondStepOutcome.Expired =
        SecondStepOutcome.Expired(error.serverMessage.nonBlank()?.takeIf { error.status == 404 && error.code == AuthCodes.CHALLENGE_EXPIRED } ?: EXPIRED)

    /** `POST auth/token/second-step` failed. */
    fun submitFailed(error: ApiError): SecondStepOutcome = when {
        // 404 challenge_expired (also after 5 wrong codes); a 401 can only mean the challenge is no good either.
        error is ApiError.Http && (error.status == 404 || error.status == 401) -> expired(error)
        error is ApiError.Http && error.status == 422 -> {
            val body = SignInErrorBody.from(error.body)
            val message = error.serverMessage.nonBlank() ?: WRONG_CODE
            val tries = body?.triesLeft
            SecondStepOutcome.Retry(if (tries != null) "$message ${triesLeft(tries)}" else message, clearCode = true)
        }
        error is ApiError.Http && error.status == 429 -> SecondStepOutcome.Retry(error.serverMessage.nonBlank() ?: THROTTLED)
        error.isConnectivity -> SecondStepOutcome.Retry(OFFLINE)
        else -> SecondStepOutcome.Retry(error.userMessage)
    }

    /** `POST auth/token/email-code` failed. */
    fun emailFailed(error: ApiError): SecondStepOutcome = when {
        error is ApiError.Http && (error.status == 404 || error.status == 401) -> expired(error)
        error is ApiError.Http && error.status == 429 -> SecondStepOutcome.Retry(error.serverMessage.nonBlank() ?: EMAIL_THROTTLED)
        error.isConnectivity -> SecondStepOutcome.Retry(OFFLINE)
        else -> SecondStepOutcome.Retry(error.userMessage)
    }
}
