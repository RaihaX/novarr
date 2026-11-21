package studio.visns.novarr

import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.launch
import kotlinx.coroutines.test.TestScope
import kotlinx.coroutines.test.advanceTimeBy
import kotlinx.coroutines.test.advanceUntilIdle
import kotlinx.coroutines.test.runCurrent
import kotlinx.coroutines.test.runTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AuthUser
import studio.visns.novarr.api.PairingStart
import studio.visns.novarr.api.PairingStatus
import studio.visns.novarr.domain.PairingMessages
import studio.visns.novarr.domain.PairingRules
import studio.visns.novarr.domain.PairingRun
import studio.visns.novarr.domain.PairingState

/** Mirrors koreader/spec/test_auth.lua's pairing cases. */
@OptIn(ExperimentalCoroutinesApi::class)
class PairingTest {
    private val user = AuthUser(4, "Rey", "rey", "admin")

    // Rules

    @Test
    fun codeFormatAndValidity() {
        assertEquals("K7M 4QX", PairingRules.format("K7M4QX"))
        assertEquals("K7M 4QX", PairingRules.format("k7m4qx"))
        assertEquals("ABCD", PairingRules.format("abcd"))
        assertTrue(PairingRules.validCode("k7m4qx"))
        assertTrue(PairingRules.validCode("K7M 4QX"))
        assertFalse(PairingRules.validCode("K7M4Q0")) // 0 is not in the alphabet
        assertFalse(PairingRules.validCode("K7M4Q"))
        assertFalse(PairingRules.validCode(null))
        assertEquals("K 7 M, 4 Q X", PairingRules.spoken("K7M4QX"))
    }

    @Test
    fun intervalClampedAndDefaults() {
        assertEquals(2_000L, PairingRules.intervalMs(0.1))
        assertEquals(30_000L, PairingRules.intervalMs(300.0))
        assertEquals(3_000L, PairingRules.intervalMs(null))
        assertEquals(3_000L, PairingRules.intervalMs(Double.NaN))
        assertEquals(5_500L, PairingRules.intervalMs(5.5))
        assertEquals(600_000L, PairingRules.lifetimeMs(null))
        assertEquals(600_000L, PairingRules.lifetimeMs(-1.0))
        assertEquals(10_000L, PairingRules.lifetimeMs(10.0))
    }

    @Test
    fun tokensAndSecrets() {
        assertTrue(PairingRules.validToken("nvr_abc123"))
        assertFalse(PairingRules.validToken(""))
        assertFalse(PairingRules.validToken("a b"))
        assertFalse(PairingRules.validToken("a\nb"))
        assertFalse(PairingRules.validToken("x".repeat(1025)))
        assertTrue(PairingRules.validSecret("ab12cd"))
        assertFalse(PairingRules.validSecret("../x"))
        assertFalse(PairingRules.validSecret(""))
    }

    @Test
    fun hostAndInstructions() {
        assertEquals("novarr.example.com", PairingRules.host("https://novarr.example.com"))
        assertEquals("192.168.1.34:8080", PairingRules.host("http://192.168.1.34:8080"))
        assertEquals("box", PairingRules.host("http://box"))
        assertEquals("box:443", PairingRules.host("http://box:443"))
        assertEquals(
            "On a phone or computer where you are signed in, open novarr.example.com → Account → Link a device and enter this code.",
            PairingRules.instructions("https://novarr.example.com"),
        )
    }

    @Test
    fun whichPollErrorsKeepPolling() {
        assertTrue(PairingMessages.keepsPolling(ApiError.Unreachable("x")))
        assertTrue(PairingMessages.keepsPolling(ApiError.TimedOut))
        assertTrue(PairingMessages.keepsPolling(ApiError.Http(503, null)))
        assertTrue(PairingMessages.keepsPolling(ApiError.Http(429, null)))
        assertFalse(PairingMessages.keepsPolling(ApiError.Http(422, "no")))
        assertFalse(PairingMessages.keepsPolling(ApiError.Http(403, null)))
    }

    @Test
    fun startMessages() {
        assertEquals(PairingMessages.THROTTLED, PairingMessages.startFailed(ApiError.Http(429, null)))
        assertEquals(PairingMessages.NOT_SUPPORTED, PairingMessages.startFailed(ApiError.Http(404, null)))
        assertEquals(PairingMessages.OFFLINE, PairingMessages.startFailed(ApiError.Unreachable("x")))
    }

    // The run, with virtual time

    private class World(
        val scope: TestScope,
        val start: () -> PairingStart,
        polls: List<Any>,
    ) {
        val states = mutableListOf<PairingState>()
        val linked = mutableListOf<Pair<String, AuthUser?>>()
        val pollTimes = mutableListOf<Long>()
        private val answers = ArrayDeque(polls)
        val run = PairingRun(
            start = { start() },
            poll = { secret ->
                pollTimes += scope.testScheduler.currentTime
                assertEquals("s3cret", secret)
                when (val next = answers.removeFirstOrNull() ?: PairingStatus("pending")) {
                    is PairingStatus -> next
                    is Exception -> throw next
                    else -> error("bad test answer")
                }
            },
            onLinked = { token, user -> linked += token to user },
            now = { scope.testScheduler.currentTime },
            onState = { states += it },
        )
    }

    private val code = PairingStart("K7M4QX", "s3cret", 600.0, 3.0)

    @Test
    fun codeShownThenPollsEveryIntervalUntilLinked() = runTest {
        val w = World(this, { code }, listOf(PairingStatus("pending"), PairingStatus("pending"), PairingStatus("linked", "nvr_tok", user)))
        w.run.run()
        assertEquals(PairingState.Starting, w.states[0])
        assertEquals(PairingState.Waiting("K7M 4QX", 600_000), w.states[1])
        // Pending polls change nothing on screen: the code is drawn once.
        assertEquals(PairingState.Linked, w.states[2])
        assertEquals(3, w.states.size)
        assertEquals(listOf(3_000L, 6_000L, 9_000L), w.pollTimes)
        assertEquals(listOf("nvr_tok" to user), w.linked)
    }

    @Test
    fun notFoundWhilePollingIsExpired() = runTest {
        val w = World(this, { code }, listOf(PairingStatus("pending"), ApiError.Http(404, null)))
        w.run.run()
        assertEquals(PairingState.Expired, w.states.last())
        assertEquals(2, w.run.polls)
    }

    @Test
    fun theCodesOwnLifetimeEndsIt() = runTest {
        val w = World(this, { PairingStart("K7M4QX", "s3cret", 10.0, 3.0) }, emptyList())
        w.run.run()
        assertEquals(PairingState.Expired, w.states.last())
        // Polls at 3, 6, 9 and 12 s; after the 12 s one the code is past its 10 s.
        assertEquals(listOf(3_000L, 6_000L, 9_000L, 12_000L), w.pollTimes)
    }

    @Test
    fun noAnswerKeepsPollingOtherErrorsEndIt() = runTest {
        val w = World(
            this,
            { code },
            listOf(ApiError.Unreachable("offline"), ApiError.Http(503, null), ApiError.Http(429, null), ApiError.Decoding("html"), PairingStatus("linked", "t", user)),
        )
        w.run.run()
        assertEquals(PairingState.Linked, w.states.last())
        assertEquals(5, w.run.polls)

        val w2 = World(this, { code }, listOf(ApiError.Http(422, "no")))
        w2.run.run()
        assertTrue(w2.states.last() is PairingState.Failed)
    }

    @Test
    fun cancelStopsPollingNothingMoreIsSent() = runTest {
        val w = World(this, { code }, emptyList())
        val job = launch { w.run.run() }
        advanceTimeBy(7_000)
        runCurrent()
        assertEquals(2, w.run.polls)
        job.cancel()
        advanceUntilIdle()
        assertEquals(2, w.run.polls)
        assertTrue(w.linked.isEmpty())
    }

    @Test
    fun startFailingMalformedCodeOrLinkedWithoutToken() = runTest {
        val offline = World(this, { throw ApiError.Unreachable("offline") }, emptyList())
        offline.run.run()
        assertEquals(PairingState.Failed(PairingMessages.OFFLINE), offline.states.last())

        val throttled = World(this, { throw ApiError.Http(429, null) }, emptyList())
        throttled.run.run()
        assertEquals(PairingState.Failed(PairingMessages.THROTTLED), throttled.states.last())

        val badCode = World(this, { PairingStart("K7M4Q0", "s3cret") }, emptyList())
        badCode.run.run()
        assertEquals(PairingState.Failed(PairingMessages.BAD_ANSWER), badCode.states.last())
        assertEquals(0, badCode.run.polls)

        val badSecret = World(this, { PairingStart("K7M4QX", "../etc") }, emptyList())
        badSecret.run.run()
        assertEquals(PairingState.Failed(PairingMessages.BAD_ANSWER), badSecret.states.last())

        val noToken = World(this, { code }, listOf(PairingStatus("linked", "")))
        noToken.run.run()
        assertEquals(PairingState.Failed(PairingMessages.BAD_ANSWER), noToken.states.last())
        assertTrue(noToken.linked.isEmpty())

        val odd = World(this, { code }, listOf(PairingStatus("weird")))
        odd.run.run()
        assertEquals(PairingState.Failed(PairingMessages.BAD_ANSWER), odd.states.last())
    }

    @Test
    fun linkedWithoutUserStillLinks() = runTest {
        val w = World(this, { code }, listOf(PairingStatus("linked", "t", null)))
        w.run.run()
        assertEquals(listOf("t" to null), w.linked)
    }

    @Test
    fun intervalDefaultsAndClamps() = runTest {
        val fast = World(this, { PairingStart("K7M4QX", "s3cret", 600.0, 0.1) }, listOf(PairingStatus("linked", "t", user)))
        fast.run.run()
        assertEquals(listOf(2_000L), fast.pollTimes)
        val missing = World(this, { PairingStart("k7m4qx", "s3cret") }, listOf(PairingStatus("linked", "t", user)))
        val before = testScheduler.currentTime
        missing.run.run()
        assertEquals(listOf(before + 3_000L), missing.pollTimes)
        assertEquals(PairingState.Waiting("K7M 4QX", before + 600_000), missing.states[1])
    }
}
