package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.domain.ApkCheck
import studio.visns.novarr.domain.UpdateEvent
import studio.visns.novarr.domain.UpdateFailure
import studio.visns.novarr.domain.UpdateMachine
import studio.visns.novarr.domain.UpdateMachine.reduce
import studio.visns.novarr.domain.UpdateState
import java.io.File

class UpdateInstallTest {
    private val downloading = UpdateState.Downloading(0, null)

    @Test
    fun theHappyPathRunsDownloadVerifyConfirm() {
        var s: UpdateState = UpdateState.Idle
        s = reduce(s, UpdateEvent.Start)
        assertEquals(downloading, s)
        s = reduce(s, UpdateEvent.Progress(50, 200))
        assertEquals(25, (s as UpdateState.Downloading).percent)
        s = reduce(s, UpdateEvent.Downloaded)
        assertEquals(UpdateState.Verifying, s)
        s = reduce(s, UpdateEvent.Verified(canInstall = true))
        assertEquals(UpdateState.AwaitingConfirmation, s)
        s = reduce(s, UpdateEvent.InstallStatus(UpdateMachine.STATUS_PENDING_USER_ACTION))
        assertEquals(UpdateState.AwaitingConfirmation, s)
        assertEquals(UpdateState.Idle, reduce(s, UpdateEvent.InstallStatus(UpdateMachine.STATUS_SUCCESS)))
    }

    @Test
    fun withoutInstallPermissionItWaitsForSettings() {
        val s = reduce(UpdateState.Verifying, UpdateEvent.Verified(canInstall = false))
        assertEquals(UpdateState.NeedsPermission, s)
        assertEquals(UpdateState.AwaitingConfirmation, reduce(s, UpdateEvent.PermissionGranted))
        // A stray grant outside that state changes nothing.
        assertEquals(UpdateState.Idle, reduce(UpdateState.Idle, UpdateEvent.PermissionGranted))
    }

    @Test
    fun percentIsUnknownWithoutALengthAndClamped() {
        assertNull(UpdateState.Downloading(10, null).percent)
        assertNull(UpdateState.Downloading(10, 0).percent)
        assertEquals(100, UpdateState.Downloading(300, 200).percent)
        assertEquals(0, UpdateState.Downloading(0, 200).percent)
    }

    @Test
    fun cancellingTheConfirmationOffersTheUpdateAgain() {
        assertEquals(UpdateState.Idle, reduce(UpdateState.AwaitingConfirmation, UpdateEvent.InstallStatus(UpdateMachine.STATUS_FAILURE_ABORTED)))
    }

    @Test
    fun installFailuresMapToPlainReasons() {
        fun after(status: Int) = reduce(UpdateState.AwaitingConfirmation, UpdateEvent.InstallStatus(status))
        assertEquals(UpdateState.Failed(UpdateFailure.Signature), after(UpdateMachine.STATUS_FAILURE_CONFLICT))
        assertEquals(UpdateState.Failed(UpdateFailure.Signature), after(UpdateMachine.STATUS_FAILURE_INCOMPATIBLE))
        assertEquals(UpdateState.Failed(UpdateFailure.Storage), after(UpdateMachine.STATUS_FAILURE_STORAGE))
        assertEquals(UpdateState.Failed(UpdateFailure.Blocked), after(UpdateMachine.STATUS_FAILURE_BLOCKED))
        assertEquals(UpdateState.Failed(UpdateFailure.Invalid), after(UpdateMachine.STATUS_FAILURE_INVALID))
        assertEquals(UpdateState.Failed(UpdateFailure.Other), after(UpdateMachine.STATUS_FAILURE))
        assertEquals(UpdateState.Failed(UpdateFailure.Other), after(42))
        assertTrue(UpdateFailure.Signature.message.startsWith("This build is signed differently"))
    }

    @Test
    fun failAndCancelFromEveryBusyState() {
        val busy = listOf(downloading, UpdateState.Verifying, UpdateState.NeedsPermission, UpdateState.AwaitingConfirmation)
        for (s in busy) {
            assertTrue(s.busy)
            assertEquals(UpdateState.Failed(UpdateFailure.Mismatch), reduce(s, UpdateEvent.Fail(UpdateFailure.Mismatch)))
            assertEquals(UpdateState.Idle, reduce(s, UpdateEvent.Cancel))
        }
        // A late failure after Cancel (the aborted download's IOException) doesn't override Idle.
        assertEquals(UpdateState.Idle, reduce(UpdateState.Idle, UpdateEvent.Fail(UpdateFailure.Network)))
    }

    @Test
    fun outOfOrderEventsAreIgnored() {
        assertEquals(UpdateState.Idle, reduce(UpdateState.Idle, UpdateEvent.Progress(5, 10)))
        assertEquals(UpdateState.Idle, reduce(UpdateState.Idle, UpdateEvent.Downloaded))
        assertEquals(UpdateState.Idle, reduce(UpdateState.Idle, UpdateEvent.InstallStatus(UpdateMachine.STATUS_SUCCESS)))
        assertEquals(downloading, reduce(downloading, UpdateEvent.Verified(true)))
        // A second Start while one runs doesn't restart it; a failed one can be retried.
        val mid = UpdateState.Downloading(5, 10)
        assertEquals(mid, reduce(mid, UpdateEvent.Start))
        assertEquals(downloading, reduce(UpdateState.Failed(UpdateFailure.Network), UpdateEvent.Start))
        assertFalse(UpdateState.Failed(UpdateFailure.Network).busy)
    }

    @Test
    fun sizeAndHashAreCheckedOnlyWhenMetaGivesThem() {
        val sha = "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08" // "test"
        assertTrue(ApkCheck.matches(4, sha, 4, sha))
        assertTrue(ApkCheck.matches(4, sha, 4, sha.uppercase()))
        assertTrue(ApkCheck.matches(4, null, null, null))
        assertTrue(ApkCheck.matches(4, null, 4, null))
        assertTrue(ApkCheck.matches(4, sha, null, sha))
        assertFalse(ApkCheck.matches(5, sha, 4, sha))
        assertFalse(ApkCheck.matches(4, "00" + sha.drop(2), 4, sha))
        assertFalse(ApkCheck.matches(4, null, 4, sha))
    }

    @Test
    fun verifyHashesTheFile() {
        val file = File.createTempFile("novarr-update", ".apk")
        try {
            file.writeText("test")
            val sha = "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"
            assertEquals(sha, file.inputStream().use(ApkCheck::sha256Hex))
            assertTrue(ApkCheck.verify(file, 4, sha))
            assertTrue(ApkCheck.verify(file, null, null))
            assertFalse(ApkCheck.verify(file, 4, "0".repeat(64)))
            assertFalse(ApkCheck.verify(file, 5, null))
            assertFalse(ApkCheck.verify(File(file.path + ".missing"), null, null))
        } finally {
            file.delete()
        }
    }

    @Test
    fun metaDecodesSizeAndHashAndToleratesTheirAbsence() {
        val withCheck = NovarrJson.decodeFromString(
            ServerMeta.serializer(),
            """{"app":"novarr","api_version":1,"android":{"version":"1.3","build":4,"url":"http://x/app/android","size":123,"sha256":"ab"}}""",
        ).android!!
        assertEquals(123L, withCheck.size)
        assertEquals("ab", withCheck.sha256)
        val older = NovarrJson.decodeFromString(
            ServerMeta.serializer(),
            """{"app":"novarr","api_version":1,"android":{"version":"1.3","build":4,"url":"http://x/app/android"}}""",
        ).android!!
        assertNull(older.size)
        assertNull(older.sha256)
    }
}
