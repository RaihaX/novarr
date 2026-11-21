package studio.visns.novarr

import kotlinx.serialization.json.jsonObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.PairingStart
import studio.visns.novarr.api.PairingStatus
import studio.visns.novarr.api.SecondStepRequired
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.api.SignInErrorBody

/** Every JSON shape of the second-step contract, and the meta keys that come with it. */
class SecondStepDecodingTest {
    private fun meta(json: String) = NovarrJson.decodeFromString(ServerMeta.serializer(), json)

    @Test
    fun metaSecondStepAndPairing() {
        val m = meta("""{"app":"novarr","api_version":1,"auth":{"required":true,"methods":["password","pairing"],"second_step":true}}""")
        assertTrue(m.requiresSignIn)
        assertTrue(m.asksForSecondStep)
        assertTrue(m.offersPairing)
        assertFalse(m.signInUnavailable)
        assertEquals(true, m.auth?.available)
    }

    @Test
    fun metaSignInOffAtThisAddress() {
        val m = meta("""{"app":"novarr","api_version":1,"auth":{"required":true,"available":false,"methods":["password","pairing"],"second_step":false}}""")
        assertTrue(m.requiresSignIn)
        assertTrue(m.signInUnavailable)
        assertFalse(m.asksForSecondStep)
    }

    @Test
    fun olderMetaDefaults() {
        // 1.8's servers: no second_step, no available; pairing may or may not be listed.
        val m = meta("""{"app":"novarr","api_version":1,"auth":{"required":true,"methods":["password"]}}""")
        assertTrue(m.requiresSignIn)
        assertFalse(m.auth!!.secondStep)
        assertTrue(m.auth!!.available)
        assertFalse(m.signInUnavailable)
        assertFalse(m.offersPairing)
        assertFalse(m.asksForSecondStep)
        // available: false only matters with sign-in required.
        assertFalse(meta("""{"app":"novarr","api_version":1,"auth":{"required":false,"available":false}}""").signInUnavailable)
        assertFalse(meta("""{"app":"novarr","api_version":1}""").signInUnavailable)
    }

    @Test
    fun secondStepRequiredBody() {
        val r = NovarrJson.decodeFromString(
            SecondStepRequired.serializer(),
            """{"code":"second_step_required","message":"Enter your sign-in code to finish signing in.",
               "challenge":"${"0f".repeat(32)}","expires_in":600,"methods":["totp","email","recovery"],"email_hint":"r•••@a•••.com.au"}""",
        )
        assertEquals("second_step_required", r.code)
        assertEquals(64, r.challenge.length)
        assertEquals(600L, r.expiresIn)
        assertEquals(listOf("totp", "email", "recovery"), r.methods)
        assertEquals("r•••@a•••.com.au", r.emailHint)
    }

    @Test
    fun secondStepRequiredWithoutEmailOrNumbers() {
        val r = NovarrJson.decodeFromString(SecondStepRequired.serializer(), """{"code":"second_step_required","challenge":"c","methods":["totp"]}""")
        assertNull(r.emailHint)
        assertNull(r.message)
        assertEquals(SecondStepRequired.DEFAULT_EXPIRES_IN, r.expiresIn)
    }

    @Test
    fun errorBodies() {
        fun body(json: String) = SignInErrorBody.from(NovarrJson.parseToJsonElement(json).jsonObject)!!
        val unavailable = body("""{"code":"second_step_unavailable","message":"This account needs a second sign-in step…"}""")
        assertEquals("second_step_unavailable", unavailable.code)
        val wrong = body("""{"code":"wrong_code","message":"That code is not right. Check it and try again.","tries_left":2}""")
        assertEquals(2, wrong.triesLeft)
        val expired = body("""{"code":"challenge_expired","message":"That took too long. Sign in again."}""")
        assertEquals("challenge_expired", expired.code)
        assertNull(expired.triesLeft)
        assertEquals("throttled", body("""{"code":"throttled"}""").code)
        assertNull(body("""{"code":"throttled"}""").message)
        assertNull(SignInErrorBody.from(null))
    }

    @Test
    fun pairingShapes() {
        val start = NovarrJson.decodeFromString(PairingStart.serializer(), """{"code":"K7M4QX","secret":"s3cr3t","expires_in":600,"interval":3}""")
        assertEquals("K7M4QX", start.code)
        assertEquals(600.0, start.expiresIn!!, 0.0)
        val bare = NovarrJson.decodeFromString(PairingStart.serializer(), """{"code":"K7M4QX","secret":"s"}""")
        assertNull(bare.interval)
        assertNull(bare.expiresIn)

        assertEquals("pending", NovarrJson.decodeFromString(PairingStatus.serializer(), """{"status":"pending"}""").status)
        val linked = NovarrJson.decodeFromString(
            PairingStatus.serializer(),
            """{"status":"linked","token":"nvr_abc","user":{"id":4,"name":"Rey","username":"rey","role":"admin"}}""",
        )
        assertEquals("nvr_abc", linked.token)
        assertEquals(4, linked.user?.id)
        // A broken user never fails the poll: the app asks auth/me instead.
        val noUser = NovarrJson.decodeFromString(PairingStatus.serializer(), """{"status":"linked","token":"t","user":"x"}""")
        assertNull(noUser.user)
    }
}
