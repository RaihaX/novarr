package studio.visns.novarr

import java.util.concurrent.AbstractExecutorService
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.Dispatcher
import okhttp3.OkHttpClient
import okhttp3.Request
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AuthCodes
import studio.visns.novarr.api.AuthTokenResponse
import studio.visns.novarr.api.DeviceIdentity
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.app.SignInFlow
import studio.visns.novarr.domain.PairingState
import studio.visns.novarr.domain.SecondStepMessages
import studio.visns.novarr.domain.SecondStepMethod

/** Runs OkHttp's callbacks on the calling thread, so a fake answer arrives before enqueue returns. */
private object InlineExecutor : AbstractExecutorService() {
    override fun execute(command: Runnable) = command.run()
    override fun shutdown() = Unit
    override fun shutdownNow(): MutableList<Runnable> = mutableListOf()
    override fun isShutdown() = false
    override fun isTerminated() = false
    override fun awaitTermination(timeout: Long, unit: TimeUnit) = true
}

/**
 * The sign-in steps end to end against a fake server (the real one is
 * written in parallel against the same contract).
 */
@OptIn(ExperimentalCoroutinesApi::class)
class SignInFlowTest {
    private val device = DeviceIdentity("a_TESTDEVICE123", "Pixel 8")
    private val challenge = "ab".repeat(32)
    private val token = """{"token":"tok","user":{"id":2,"name":"Reader","username":"reader","role":"reader"}}"""

    private fun required(methods: String = """["totp","email","recovery"]""") =
        """{"code":"second_step_required","message":"Enter your sign-in code to finish signing in.","challenge":"$challenge",
           "expires_in":600,"methods":$methods,"email_hint":"r•••@a•••.com.au"}"""

    private class Harness(scope: TestScope, val server: FakeServer) {
        val completed = mutableListOf<Triple<String, AuthTokenResponse, String>>()
        val http: OkHttpClient = OkHttpClient.Builder().addInterceptor(server).dispatcher(Dispatcher(InlineExecutor)).build()
        val flow = SignInFlow(
            scope = scope.backgroundScope,
            client = { s, t -> ApiClient(s, http, DeviceIdentity("a_TESTDEVICE123", "Pixel 8"), t) },
            device = { DeviceIdentity("a_TESTDEVICE123", "Pixel 8") },
            complete = { s, r, u -> completed += Triple(s, r, u) },
            clock = { scope.testScheduler.currentTime },
        )
    }

    private fun path(r: Request) = r.url.encodedPath.removePrefix("/api/v1/")

    private fun body(server: FakeServer, i: Int) = NovarrJson.parseToJsonElement(server.bodies[i]).jsonObject

    @Test
    fun homeNetworkSignsInWithThePasswordAlone() = runTest {
        val h = Harness(this, FakeServer { 200 to token })
        h.flow.signIn("http://host", "reader", "pw")
        assertEquals("tok", h.completed.single().second.token)
        assertEquals("reader", h.completed.single().third)
        assertNull(h.flow.secondStep.value)
    }

    @Test
    fun passwordThenAuthenticatorCode() = runTest {
        val h = Harness(this, FakeServer { r -> if (path(r) == "auth/token") 401 to required() else 200 to token })
        h.flow.signIn("http://host", "reader", "pw")
        val state = h.flow.secondStep.value!!
        assertEquals(SecondStepMethod.TOTP, state.method)
        assertTrue(h.completed.isEmpty())

        h.flow.updateCode("123 456 7")
        assertEquals("123456", h.flow.code)
        h.flow.submitCode()
        runCurrent()
        assertEquals("auth/token/second-step", path(h.server.requests[1]))
        assertEquals("""{"challenge":"$challenge","method":"totp","code":"123456"}""", h.server.bodies[1])
        // The same completion as a password sign-in, with the name typed on the form.
        assertEquals("tok", h.completed.single().second.token)
        assertEquals("reader", h.completed.single().third)
        assertNull(h.flow.secondStep.value)
        assertEquals("", h.flow.code)
        // The password went only to auth/token.
        assertTrue(h.server.bodies.drop(1).none { it.contains("pw") })
    }

    @Test
    fun emailCodeIsSentOnlyWhenAskedAndSendAgainWaits() = runTest {
        val h = Harness(this, FakeServer { r ->
            when (path(r)) {
                "auth/token" -> 401 to required("""["email","recovery"]""")
                "auth/token/email-code" -> 204 to ""
                else -> 200 to token
            }
        })
        h.flow.signIn("http://host", "reader", "pw")
        assertEquals(SecondStepMethod.EMAIL, h.flow.secondStep.value!!.method)
        assertTrue(h.flow.secondStep.value!!.awaitingEmail)
        assertEquals(1, h.server.requests.size)

        h.flow.sendEmailCode()
        runCurrent()
        assertEquals("auth/token/email-code", path(h.server.requests[1]))
        assertEquals(challenge, body(h.server, 1)["challenge"]?.jsonPrimitive?.content)
        val sent = h.flow.secondStep.value!!
        assertNotNull(sent.emailSentAt)
        // Too soon for "Send again".
        h.flow.sendEmailCode()
        runCurrent()
        assertEquals(2, h.server.requests.size)
        advanceTimeBy(30_001)
        h.flow.sendEmailCode()
        runCurrent()
        assertEquals(3, h.server.requests.size)

        h.flow.updateCode("654321")
        h.flow.submitCode()
        runCurrent()
        assertEquals("email", body(h.server, 3)["method"]?.jsonPrimitive?.content)
        assertEquals(1, h.completed.size)
    }

    @Test
    fun recoveryCodeIsSentWithItsHyphen() = runTest {
        val h = Harness(this, FakeServer { r -> if (path(r) == "auth/token") 401 to required() else 200 to token })
        h.flow.signIn("http://host", "reader", "pw")
        h.flow.chooseMethod(SecondStepMethod.RECOVERY)
        h.flow.updateCode("ABCDE-FGHIJ")
        assertEquals("abcdefghij", h.flow.code)
        h.flow.submitCode()
        runCurrent()
        assertEquals("""{"challenge":"$challenge","method":"recovery","code":"abcde-fghij"}""", h.server.bodies[1])
    }

    @Test
    fun wrongCodeStaysWithTriesLeft() = runTest {
        val h = Harness(this, FakeServer { r ->
            if (path(r) == "auth/token") 401 to required()
            else 422 to """{"code":"wrong_code","message":"That code is not right. Check it and try again.","tries_left":4}"""
        })
        h.flow.signIn("http://host", "reader", "pw")
        h.flow.updateCode("000000")
        h.flow.submitCode()
        runCurrent()
        val state = h.flow.secondStep.value!!
        assertEquals("That code is not right. Check it and try again. 4 tries left.", state.error)
        assertEquals(false, state.submitting)
        assertEquals("", h.flow.code)
        assertTrue(h.completed.isEmpty())
    }

    @Test
    fun expiredChallengeGoesBackToThePasswordForm() = runTest {
        val h = Harness(this, FakeServer { r ->
            if (path(r) == "auth/token") 401 to required()
            else 404 to """{"code":"challenge_expired","message":"That took too long. Sign in again."}"""
        })
        h.flow.signIn("http://host", "reader", "pw")
        h.flow.updateCode("123456")
        h.flow.submitCode()
        runCurrent()
        assertNull(h.flow.secondStep.value)
        assertEquals("That took too long. Sign in again.", h.flow.formMessage.value)
        // The next password attempt clears it.
        h.server.respond = { 422 to """{"message":"Wrong name or password."}""" }
        runCatching { h.flow.signIn("http://host", "reader", "pw") }
        assertNull(h.flow.formMessage.value)
    }

    @Test
    fun fifthWrongCodeShowsTheServersMessageOnTheForm() = runTest {
        val h = Harness(this, FakeServer { r ->
            if (path(r) == "auth/token") 401 to required()
            else 404 to """{"code":"challenge_expired","message":"Too many wrong codes. Sign in again."}"""
        })
        h.flow.signIn("http://host", "reader", "pw")
        h.flow.updateCode("999999")
        h.flow.submitCode()
        runCurrent()
        assertNull(h.flow.secondStep.value)
        assertEquals("Too many wrong codes. Sign in again.", h.flow.formMessage.value)
    }

    @Test
    fun throttledEmailShowsTheServersMessage() = runTest {
        val h = Harness(this, FakeServer { r ->
            if (path(r) == "auth/token") 401 to required("""["email"]""")
            else 429 to """{"code":"throttled","message":"Too many codes. Try again in 5 minutes.","retry_after":300}"""
        })
        h.flow.signIn("http://host", "reader", "pw")
        h.flow.sendEmailCode()
        runCurrent()
        val state = h.flow.secondStep.value!!
        assertEquals("Too many codes. Try again in 5 minutes.", state.error)
        assertTrue(state.awaitingEmail)
        assertEquals(false, state.sending)
    }

    @Test
    fun passkeyOnlyAccountGets403WithTheServersMessage() = runTest {
        val message = "This account signs in with a passkey. Link this device with a code instead: on a phone or computer where you are signed in, open Account → Link a device."
        val h = Harness(this, FakeServer { 403 to """{"code":"second_step_unavailable","message":"$message"}""" })
        val error = runCatching { h.flow.signIn("http://host", "reader", "pw") }.exceptionOrNull() as ApiError
        assertTrue(studio.visns.novarr.ui.connect.SignInMessages.isSecondStepUnavailable(error))
        assertEquals(message, studio.visns.novarr.ui.connect.SignInMessages.message(error))
        assertNull(h.flow.secondStep.value)
    }

    @Test
    fun locallyExpiredChallengeIsNotEvenSent() = runTest {
        val h = Harness(this, FakeServer { 401 to required() })
        h.flow.signIn("http://host", "reader", "pw")
        advanceTimeBy(600_001)
        h.flow.updateCode("123456")
        h.flow.submitCode()
        runCurrent()
        assertEquals(1, h.server.requests.size)
        assertEquals(SecondStepMessages.EXPIRED, h.flow.formMessage.value)
    }

    @Test
    fun offlineStaysOnTheCodeScreen() = runTest {
        val h = Harness(this, FakeServer { r ->
            if (path(r) == "auth/token") 401 to required() else throw java.io.IOException("offline")
        })
        h.flow.signIn("http://host", "reader", "pw")
        h.flow.updateCode("123456")
        h.flow.submitCode()
        runCurrent()
        assertEquals("No connection. Try again.", h.flow.secondStep.value?.error)
        // The code is kept for another try.
        assertEquals("123456", h.flow.code)
    }

    @Test
    fun throttledUsesTheUsualWording() = runTest {
        val h = Harness(this, FakeServer { r -> if (path(r) == "auth/token") 401 to required() else 429 to "{}" })
        h.flow.signIn("http://host", "reader", "pw")
        h.flow.updateCode("123456")
        h.flow.submitCode()
        runCurrent()
        assertEquals("Too many sign-in attempts. Wait a minute and try again.", h.flow.secondStep.value?.error)
    }

    @Test
    fun secondStepUnavailableThrowsForTheForm() = runTest {
        val h = Harness(this, FakeServer { 403 to """{"code":"second_step_unavailable","message":"This account needs a second sign-in step…"}""" })
        try {
            h.flow.signIn("http://host", "reader", "pw")
            fail("expected 403")
        } catch (e: ApiError.Http) {
            assertEquals(AuthCodes.SECOND_STEP_UNAVAILABLE, e.code)
        }
        assertNull(h.flow.secondStep.value)
    }

    @Test
    fun onlyAPasskeyIsLikeUnavailable() = runTest {
        val h = Harness(this, FakeServer { 401 to required("""["passkey"]""") })
        val error = runCatching { h.flow.signIn("http://host", "reader", "pw") }.exceptionOrNull() as ApiError.Http
        assertEquals(AuthCodes.SECOND_STEP_UNAVAILABLE, error.code)
        assertEquals(SecondStepMessages.NO_APP_METHOD, error.serverMessage)
    }

    @Test
    fun cancelGoesBackAndForgetsTheChallenge() = runTest {
        val h = Harness(this, FakeServer { 401 to required() })
        h.flow.signIn("http://host", "reader", "pw")
        h.flow.updateCode("12")
        h.flow.cancelSecondStep()
        assertNull(h.flow.secondStep.value)
        assertEquals("", h.flow.code)
        assertNull(h.flow.formMessage.value)
    }

    // Link with a code

    @Test
    fun pairingStartsOnceEvenIfAskedAgainAndLinks() = runTest {
        var polls = 0
        val h = Harness(this, FakeServer { r ->
            when {
                r.method == "POST" -> 201 to """{"code":"K7M4QX","secret":"s3cret","expires_in":600,"interval":3}"""
                ++polls < 3 -> 200 to """{"status":"pending"}"""
                else -> 200 to """{"status":"linked","token":"nvr_tok","user":{"id":4,"username":"rey","role":"admin"}}"""
            }
        })
        h.flow.startPairing("http://host")
        runCurrent()
        assertEquals(PairingState.Waiting("K7M 4QX", 600_000), h.flow.pairing.value)
        // Rotation, a second tap: the code on screen is kept, no second POST.
        h.flow.startPairing("http://host")
        runCurrent()
        assertEquals(1, h.server.requests.count { it.method == "POST" })
        advanceTimeBy(9_001)
        runCurrent()
        assertEquals("nvr_tok", h.completed.single().second.token)
        assertEquals("rey", h.completed.single().third)
        assertNull(h.flow.pairing.value)
        assertEquals("/api/v1/auth/pairings/s3cret", h.server.requests.last().url.encodedPath)
    }

    @Test
    fun linkedWithoutAUserAsksAuthMeWithTheToken() = runTest {
        val h = Harness(this, FakeServer { r ->
            when {
                r.method == "POST" -> 201 to """{"code":"K7M4QX","secret":"s3cret","interval":2}"""
                path(r) == "auth/me" -> 200 to """{"user":{"id":9,"username":"kid","role":"reader"}}"""
                else -> 200 to """{"status":"linked","token":"nvr_t"}"""
            }
        })
        h.flow.startPairing("http://host")
        advanceTimeBy(2_001)
        runCurrent()
        assertEquals("Bearer nvr_t", h.server.requests.last().header("Authorization"))
        assertEquals(9, h.completed.single().second.user.id)
    }

    @Test
    fun cancelPairingStopsPolling() = runTest {
        val h = Harness(this, FakeServer { r ->
            if (r.method == "POST") 201 to """{"code":"K7M4QX","secret":"s3cret","interval":3}""" else 200 to """{"status":"pending"}"""
        })
        h.flow.startPairing("http://host")
        advanceTimeBy(3_001)
        runCurrent()
        val sent = h.server.requests.size
        h.flow.cancelPairing()
        advanceTimeBy(60_000)
        runCurrent()
        assertEquals(sent, h.server.requests.size)
        assertNull(h.flow.pairing.value)
        // After a cancel, a new code may be asked for.
        h.flow.startPairing("http://host")
        runCurrent()
        assertEquals(2, h.server.requests.count { it.method == "POST" })
    }

    @Test
    fun expiredCodeOffersANewOne() = runTest {
        val h = Harness(this, FakeServer { r ->
            if (r.method == "POST") 201 to """{"code":"K7M4QX","secret":"s3cret","interval":3}""" else 404 to "{}"
        })
        h.flow.startPairing("http://host")
        advanceTimeBy(3_001)
        runCurrent()
        assertEquals(PairingState.Expired, h.flow.pairing.value)
        h.flow.startPairing("http://host")
        runCurrent()
        assertTrue(h.flow.pairing.value is PairingState.Waiting)
    }

    @Test
    fun resetDropsEverything() = runTest {
        val h = Harness(this, FakeServer { r ->
            when {
                path(r) == "auth/token" -> 401 to required()
                r.method == "POST" -> 201 to """{"code":"K7M4QX","secret":"s3cret"}"""
                else -> 200 to """{"status":"pending"}"""
            }
        })
        h.flow.signIn("http://host", "reader", "pw")
        h.flow.reset()
        assertNull(h.flow.secondStep.value)
        h.flow.startPairing("http://host")
        runCurrent()
        h.flow.reset()
        val sent = h.server.requests.size
        advanceTimeBy(60_000)
        runCurrent()
        assertEquals(sent, h.server.requests.size)
        assertNull(h.flow.pairing.value)
    }
}
