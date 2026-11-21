package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.SecondStepRequired
import studio.visns.novarr.domain.SecondStepMessages
import studio.visns.novarr.domain.SecondStepMethod
import studio.visns.novarr.domain.SecondStepMethod.EMAIL
import studio.visns.novarr.domain.SecondStepMethod.RECOVERY
import studio.visns.novarr.domain.SecondStepMethod.TOTP
import studio.visns.novarr.domain.SecondStepOutcome
import studio.visns.novarr.domain.SecondStepState
import studio.visns.novarr.domain.SignInCode
import studio.visns.novarr.ui.connect.SignInMessages

class SecondStepTest {
    private fun required(methods: List<String>, hint: String? = "r•••@a•••.com.au", expiresIn: Long = 600) =
        SecondStepRequired("second_step_required", "Enter your sign-in code to finish signing in.", "c".repeat(64), expiresIn, methods, hint)

    // Methods

    @Test
    fun methodsKeepOnlyWhatTheAppCanDoInItsOwnOrder() {
        assertEquals(listOf(TOTP, EMAIL, RECOVERY), SecondStepMethod.usable(listOf("recovery", "email", "totp")))
        assertEquals(listOf(EMAIL), SecondStepMethod.usable(listOf("passkey", "EMAIL", "sms")))
        assertEquals(emptyList<SecondStepMethod>(), SecondStepMethod.usable(listOf("passkey")))
    }

    @Test
    fun defaultIsTheAuthenticatorThenEmail() {
        assertEquals(TOTP, SecondStepMethod.default(listOf(TOTP, EMAIL, RECOVERY)))
        assertEquals(EMAIL, SecondStepMethod.default(listOf(EMAIL, RECOVERY)))
        assertEquals(RECOVERY, SecondStepMethod.default(listOf(RECOVERY)))
        assertNull(SecondStepMethod.default(emptyList()))
    }

    @Test
    fun labels() {
        assertEquals("Authenticator app", TOTP.title)
        assertEquals("Email me a code", EMAIL.title)
        assertEquals("Use a recovery code", RECOVERY.title)
        assertEquals(listOf("totp", "email", "recovery"), SecondStepMethod.entries.map { it.wire })
    }

    // Typing codes

    @Test
    fun sixDigitCodes() {
        assertEquals("123456", SignInCode.input(TOTP, "123 456"))
        assertEquals("123456", SignInCode.input(EMAIL, "12-34-56-78"))
        assertEquals("12", SignInCode.input(TOTP, "1a2"))
        assertFalse(SignInCode.isComplete(TOTP, "12345"))
        assertTrue(SignInCode.isComplete(TOTP, "123456"))
        assertEquals("012345", SignInCode.wire(EMAIL, " 012345 "))
    }

    @Test
    fun recoveryCodes() {
        assertEquals("abcdefghij", SignInCode.input(RECOVERY, "ABCDE-FGHIJ"))
        assertEquals("abcdefghij", SignInCode.input(RECOVERY, " abcde fghij k"))
        assertEquals("abcde-fghij", SignInCode.wire(RECOVERY, "abcdefghij"))
        assertEquals("abcde-fghij", SignInCode.wire(RECOVERY, "abcde-fghij"))
        assertTrue(SignInCode.isComplete(RECOVERY, "ABCDE-FGHIJ"))
        assertFalse(SignInCode.isComplete(RECOVERY, "abcde-fghi"))
        assertEquals("abc", SignInCode.recoveryDisplay("abc"))
        assertEquals("abcde", SignInCode.recoveryDisplay("abcde"))
        assertEquals("abcde-f", SignInCode.recoveryDisplay("abcdef"))
    }

    @Test
    fun recoveryCursorMapping() {
        // "abcdef" is drawn "abcde-f".
        assertEquals(5, SignInCode.recoveryToDisplay(5, 6))
        assertEquals(7, SignInCode.recoveryToDisplay(6, 6))
        assertEquals(5, SignInCode.recoveryFromDisplay(6, 6))
        assertEquals(6, SignInCode.recoveryFromDisplay(7, 6))
        // No hyphen drawn yet.
        assertEquals(5, SignInCode.recoveryToDisplay(5, 5))
        assertEquals(5, SignInCode.recoveryFromDisplay(5, 5))
        for (length in 0..10) {
            for (offset in 0..length) {
                val shown = SignInCode.recoveryToDisplay(offset, length)
                assertTrue(shown <= SignInCode.recoveryDisplay("x".repeat(length)).length)
                assertEquals(offset, SignInCode.recoveryFromDisplay(shown, length))
            }
        }
    }

    // The state

    @Test
    fun startFromTheChallenge() {
        val s = SecondStepState.start("http://h", "reader", required(listOf("email", "totp", "recovery")), now = 1_000)!!
        assertEquals(TOTP, s.method)
        assertEquals(listOf(TOTP, EMAIL, RECOVERY), s.methods)
        assertEquals(1_000 + 600_000L, s.expiresAt)
        assertEquals("r•••@a•••.com.au", s.emailHint)
        assertEquals("Enter your sign-in code to finish signing in.", s.message)
        assertFalse(s.expired(600_999))
        assertTrue(s.expired(601_000))
    }

    @Test
    fun startWithoutUsableMethodsIsNull() {
        assertNull(SecondStepState.start("http://h", "r", required(listOf("passkey")), 0))
        assertNull(SecondStepState.start("http://h", "r", required(emptyList()), 0))
    }

    @Test
    fun emailHintOnlyWithEmailAndDefaultsForMissingNumbers() {
        val s = SecondStepState.start("http://h", "r", required(listOf("totp"), hint = "x•••@y"), 0)!!
        assertNull(s.emailHint)
        val zero = SecondStepState.start("http://h", "r", required(listOf("totp"), expiresIn = 0), 0)!!
        assertEquals(600_000L, zero.expiresAt)
        val noMessage = SecondStepState.start("http://h", "r", required(listOf("totp")).copy(message = " "), 0)!!
        assertEquals(SecondStepState.DEFAULT_MESSAGE, noMessage.message)
    }

    @Test
    fun emailIsNeverSentByItselfAndSendAgainWaits30Seconds() {
        val s = SecondStepState.start("http://h", "r", required(listOf("email", "recovery")), 0)!!
        assertEquals(EMAIL, s.method)
        assertTrue(s.awaitingEmail)
        assertFalse(s.canSubmit("123456"))
        assertTrue(s.canSendAgain(0))
        val sent = s.copy(emailSentAt = 10_000)
        assertFalse(sent.awaitingEmail)
        assertTrue(sent.canSubmit("123456"))
        assertFalse(sent.canSendAgain(39_999))
        assertTrue(sent.canSendAgain(40_000))
        assertEquals(40_000L, sent.sendAgainAt)
        // Switching away and back keeps "We sent a code".
        assertFalse(sent.choose(RECOVERY).choose(EMAIL).awaitingEmail)
    }

    @Test
    fun chooseOnlyOfferedMethodsAndClearTheError() {
        val s = SecondStepState.start("http://h", "r", required(listOf("totp", "recovery")), 0)!!.copy(error = "x")
        assertSame(s, s.choose(EMAIL))
        val r = s.choose(RECOVERY)
        assertEquals(RECOVERY, r.method)
        assertNull(r.error)
        assertFalse(r.canSubmit("123456"))
        assertTrue(r.canSubmit("abcdefghij"))
        assertFalse(r.copy(submitting = true).canSubmit("abcdefghij"))
    }

    // Messages

    private fun http(status: Int, message: String? = null, code: String? = null, tries: Int? = null) = ApiError.Http(
        status,
        message,
        code = code,
        body = buildJsonObject {
            code?.let { put("code", JsonPrimitive(it)) }
            tries?.let { put("tries_left", JsonPrimitive(it)) }
        },
    )

    @Test
    fun wrongCodeShowsTheServersMessageAndTriesLeft() {
        val out = SecondStepMessages.submitFailed(http(422, "That code is not right. Check it and try again.", "wrong_code", 3))
        assertEquals(SecondStepOutcome.Retry("That code is not right. Check it and try again. 3 tries left.", clearCode = true), out)
        val one = SecondStepMessages.submitFailed(http(422, null, "wrong_code", 1)) as SecondStepOutcome.Retry
        assertEquals("That code is not right. Check it and try again. 1 try left.", one.message)
        val none = SecondStepMessages.submitFailed(http(422, "Nope.", "wrong_code")) as SecondStepOutcome.Retry
        assertEquals("Nope.", none.message)
    }

    @Test
    fun expiredGoesBackToThePasswordForm() {
        // v1.1: the server's message for 404 challenge_expired (timeout, or the 5th wrong code).
        assertEquals(
            SecondStepOutcome.Expired("That took too long. Sign in again."),
            SecondStepMessages.submitFailed(http(404, "That took too long. Sign in again.", "challenge_expired")),
        )
        assertEquals(
            SecondStepOutcome.Expired("Too many wrong codes. Sign in again."),
            SecondStepMessages.submitFailed(http(404, "Too many wrong codes. Sign in again.", "challenge_expired")),
        )
        // No message, a bare 404 or a 401: the fallback.
        assertEquals(SecondStepOutcome.Expired("That took too long. Sign in again."), SecondStepMessages.submitFailed(http(404, code = "challenge_expired")))
        assertEquals(SecondStepOutcome.Expired(SecondStepMessages.EXPIRED), SecondStepMessages.submitFailed(http(404, "Not found.")))
        assertEquals(SecondStepOutcome.Expired(SecondStepMessages.EXPIRED), SecondStepMessages.submitFailed(http(401, "Unauthenticated.")))
        assertEquals(
            SecondStepOutcome.Expired("Too many wrong codes. Sign in again."),
            SecondStepMessages.emailFailed(http(404, "Too many wrong codes. Sign in again.", "challenge_expired")),
        )
        assertEquals("That took too long. Sign in again.", SecondStepMessages.EXPIRED)
    }

    @Test
    fun throttledOfflineAndOthersStay() {
        assertEquals(SecondStepOutcome.Retry("Too many sign-in attempts. Wait a minute and try again."), SecondStepMessages.submitFailed(http(429)))
        assertEquals(SecondStepOutcome.Retry("Slow down."), SecondStepMessages.submitFailed(http(429, "Slow down.")))
        assertEquals(SecondStepOutcome.Retry("No connection. Try again."), SecondStepMessages.submitFailed(ApiError.Unreachable("x")))
        assertEquals(SecondStepOutcome.Retry("No connection. Try again."), SecondStepMessages.submitFailed(ApiError.TimedOut))
        assertEquals(SecondStepOutcome.Retry("Too many codes were sent. Wait a while and try again."), SecondStepMessages.emailFailed(http(429, code = "throttled")))
        // v1.1: every 429 carries {code: throttled, message, retry_after}; the message is shown.
        assertEquals(
            SecondStepOutcome.Retry("Too many codes. Try again in 5 minutes."),
            SecondStepMessages.emailFailed(http(429, "Too many codes. Try again in 5 minutes.", "throttled")),
        )
        assertEquals(
            SecondStepOutcome.Retry("Too many attempts. Try again in 60 seconds."),
            SecondStepMessages.submitFailed(http(429, "Too many attempts. Try again in 60 seconds.", "throttled")),
        )
        assertEquals(SecondStepOutcome.Retry("No connection. Try again."), SecondStepMessages.emailFailed(ApiError.Unreachable("x")))
        val server = SecondStepMessages.submitFailed(http(500)) as SecondStepOutcome.Retry
        assertEquals("The server answered with an error (HTTP 500).", server.message)
    }

    @Test
    fun passwordFormMessages() {
        val unavailable = http(403, "This account needs a second sign-in step before it can be used here.", "second_step_unavailable")
        assertTrue(SignInMessages.isSecondStepUnavailable(unavailable))
        assertEquals("This account needs a second sign-in step before it can be used here.", SignInMessages.message(unavailable))
        assertEquals(SignInMessages.UNAVAILABLE_ACCOUNT, SignInMessages.message(http(403, null, "second_step_unavailable")))
        assertFalse(SignInMessages.isSecondStepUnavailable(http(403, "Readers can't.", "forbidden")))
        // v1.1: a passkey-only account gets 403 with a message pointing to the link code.
        val passkey = "This account signs in with a passkey. Link this device with a code instead: on a phone or computer where you are signed in, open Account → Link a device."
        val passkeyOnly = http(403, passkey, "second_step_unavailable")
        assertTrue(SignInMessages.isSecondStepUnavailable(passkeyOnly))
        assertEquals(passkey, SignInMessages.message(passkeyOnly))
        assertEquals("Too many attempts. Try again in 60 seconds.", SignInMessages.message(http(429, "Too many attempts. Try again in 60 seconds.", "throttled")))
        assertEquals("Wrong name or password.", SignInMessages.message(http(422)))
        assertTrue(SignInMessages.intro(true).endsWith("After your password, you'll be asked for a sign-in code."))
        assertTrue(SignInMessages.intro(false).endsWith("You may also be asked for a sign-in code."))
    }
}
