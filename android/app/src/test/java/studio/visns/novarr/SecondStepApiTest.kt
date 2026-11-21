package studio.visns.novarr

import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AuthCodes
import studio.visns.novarr.api.DeviceIdentity
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.SignInAnswer

/** The second-step and link-code calls: paths, bodies, no token, and no 401 ever reported as a lost session. */
class SecondStepApiTest {
    private val device = DeviceIdentity("a_TESTDEVICE123", "Pixel 8")
    private val challenge = "ab".repeat(32)

    private val secondStepRequired = """{"code":"second_step_required","message":"Enter your sign-in code to finish signing in.",
        "challenge":"$challenge","expires_in":600,"methods":["totp","email","recovery"],"email_hint":"r•••@a•••.com.au"}"""

    private fun body(server: FakeServer, i: Int) = NovarrJson.parseToJsonElement(server.bodies[i]).jsonObject

    @Test
    fun passwordThen401SecondStepRequiredIsAChallengeNotASignOut() = runTest {
        val server = FakeServer { 401 to secondStepRequired }
        var reported = 0
        val client = ApiClient("http://host", server.client, device, token = "old") { reported++ }
        val answer = client.signIn("reader", "pw", device)
        assertTrue(answer is SignInAnswer.SecondStep)
        val required = (answer as SignInAnswer.SecondStep).required
        assertEquals(challenge, required.challenge)
        assertEquals(listOf("totp", "email", "recovery"), required.methods)
        assertEquals("r•••@a•••.com.au", required.emailHint)
        assertEquals(600L, required.expiresIn)
        assertEquals(0, reported)
        assertNull(server.requests.single().header("Authorization"))
        assertEquals("/api/v1/auth/token", server.requests.single().url.encodedPath)
    }

    @Test
    fun secondStepRequiredWithoutAChallengeIsABadAnswer() = runTest {
        val server = FakeServer { 401 to """{"code":"second_step_required","message":"x"}""" }
        val error = runCatching { ApiClient("http://host", server.client, device).signIn("a", "b", device) }.exceptionOrNull()
        assertTrue(error is ApiError.Decoding)
    }

    @Test
    fun otherAuthTokenErrorsStillThrowWithTheirCode() = runTest {
        val server = FakeServer {
            403 to """{"code":"second_step_unavailable","message":"This account needs a second sign-in step before it can be used here."}"""
        }
        try {
            ApiClient("http://host", server.client, device).signIn("a", "b", device)
            fail("expected 403")
        } catch (e: ApiError.Http) {
            assertEquals(403, e.status)
            assertEquals(AuthCodes.SECOND_STEP_UNAVAILABLE, e.code)
            assertEquals("This account needs a second sign-in step before it can be used here.", e.serverMessage)
        }
        // A plain 401 (an odd server) is not a challenge either.
        val plain = FakeServer { 401 to """{"message":"Unauthenticated."}""" }
        val error = runCatching { ApiClient("http://host", plain.client, device).signIn("a", "b", device) }.exceptionOrNull() as ApiError.Http
        assertEquals(401, error.status)
    }

    @Test
    fun emailCodeRequest() = runTest {
        val server = FakeServer { 204 to "" }
        ApiClient("http://host", server.client, device, token = "t").sendSignInCode(challenge)
        val request = server.requests.single()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/auth/token/email-code", request.url.encodedPath)
        assertNull(request.header("Authorization"))
        assertEquals("""{"challenge":"$challenge"}""", server.bodies.single())
    }

    @Test
    fun secondStepRequest() = runTest {
        val server = FakeServer { 200 to """{"token":"tok","user":{"id":2,"username":"reader","role":"reader"}}""" }
        val response = ApiClient("http://host", server.client, device, token = "t").finishSignIn(challenge, "totp", "123456")
        assertEquals("tok", response.token)
        assertEquals(2, response.user.id)
        val request = server.requests.single()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/auth/token/second-step", request.url.encodedPath)
        assertNull(request.header("Authorization"))
        assertEquals("""{"challenge":"$challenge","method":"totp","code":"123456"}""", server.bodies.single())
    }

    @Test
    fun secondStepErrorsKeepCodeAndTriesLeft() = runTest {
        val server = FakeServer {
            422 to """{"code":"wrong_code","message":"That code is not right. Check it and try again.","tries_left":3}"""
        }
        val error = runCatching { ApiClient("http://host", server.client, device).finishSignIn(challenge, "email", "000000") }
            .exceptionOrNull() as ApiError.Http
        assertEquals(422, error.status)
        assertEquals(AuthCodes.WRONG_CODE, error.code)
        assertEquals("3", error.body?.get("tries_left")?.jsonPrimitive?.content)
    }

    @Test
    fun noneOfTheSignInCallsReport401() = runTest {
        val server = FakeServer { 401 to """{"message":"Unauthenticated."}""" }
        var reported = 0
        val client = ApiClient("http://host", server.client, device, token = "t") { reported++ }
        runCatching { client.signIn("a", "b", device) }
        runCatching { client.sendSignInCode(challenge) }
        runCatching { client.finishSignIn(challenge, "recovery", "abcde-fghij") }
        runCatching { client.startPairing(device) }
        runCatching { client.pairingStatus("s3cret") }
        assertEquals(0, reported)
        assertEquals(5, server.requests.size)
        server.requests.forEach { assertNull(it.header("Authorization")) }
    }

    @Test
    fun pairingRequests() = runTest {
        val server = FakeServer { request ->
            if (request.method == "POST") 201 to """{"code":"K7M4QX","secret":"ab12cd","expires_in":600,"interval":3}"""
            else 200 to """{"status":"pending"}"""
        }
        val client = ApiClient("http://host", server.client, device, token = "t")
        val start = client.startPairing(device)
        assertEquals("K7M4QX", start.code)
        assertEquals("ab12cd", start.secret)
        assertEquals(3.0, start.interval!!, 0.0)
        assertEquals("pending", client.pairingStatus("ab12cd").status)
        assertEquals("/api/v1/auth/pairings", server.requests[0].url.encodedPath)
        assertEquals("a_TESTDEVICE123", body(server, 0)["device_id"]?.jsonPrimitive?.content)
        assertEquals("Pixel 8", body(server, 0)["device_name"]?.jsonPrimitive?.content)
        assertEquals("GET", server.requests[1].method)
        assertEquals("/api/v1/auth/pairings/ab12cd", server.requests[1].url.encodedPath)
        // Never from the HTTP cache.
        assertEquals("no-cache", server.requests[1].header("Cache-Control"))
    }

    @Test
    fun pairingSecretMustBeSafeInAPath() = runTest {
        val server = FakeServer { 200 to """{"status":"pending"}""" }
        val client = ApiClient("http://host", server.client, device)
        for (bad in listOf("", "..", ".", "a/b", "a b", "a?b", "%2e%2e")) {
            assertTrue(bad, runCatching { client.pairingStatus(bad) }.exceptionOrNull() is ApiError.Decoding)
        }
        assertTrue(server.requests.isEmpty())
    }

    @Test
    fun pairingExpiredIs404() = runTest {
        val server = FakeServer { 404 to """{"message":"Not found."}""" }
        val error = runCatching { ApiClient("http://host", server.client, device).pairingStatus("gone") }.exceptionOrNull() as ApiError.Http
        assertEquals(404, error.status)
    }
}
