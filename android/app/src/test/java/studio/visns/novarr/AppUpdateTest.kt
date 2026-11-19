package studio.visns.novarr

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.AndroidRelease
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.domain.AppUpdate

class AppUpdateTest {
    @Test
    fun onlyANewerKnownBuildIsAvailable() {
        assertTrue(AppUpdate.available(build = 4, installed = 3, dismissedBuild = null))
        assertFalse(AppUpdate.available(build = 3, installed = 3, dismissedBuild = null))
        assertFalse(AppUpdate.available(build = 2, installed = 3, dismissedBuild = null))
        // The sidecar gives no build: nothing to compare, nothing offered.
        assertFalse(AppUpdate.available(build = null, installed = 3, dismissedBuild = null))
    }

    @Test
    fun dismissingHidesThatBuildUntilANewerOne() {
        assertFalse(AppUpdate.available(build = 4, installed = 3, dismissedBuild = 4))
        assertTrue(AppUpdate.available(build = 5, installed = 3, dismissedBuild = 4))
        // A dismissal from long ago doesn't hide anything newer.
        assertTrue(AppUpdate.available(build = 4, installed = 3, dismissedBuild = 2))
    }

    @Test
    fun aReleaseNeedsADownloadAddress() {
        assertTrue(AppUpdate.available(AndroidRelease("1.3", 4, "https://host/app/android"), installed = 3))
        assertFalse(AppUpdate.available(AndroidRelease("1.3", 4, ""), installed = 3))
        assertFalse(AppUpdate.available(null as AndroidRelease?, installed = 3))
    }

    @Test
    fun labelPrefersTheVersionName() {
        assertEquals("1.3", AppUpdate.label(AndroidRelease("1.3", 4, "u")))
        assertEquals("build 4", AppUpdate.label(AndroidRelease(null, 4, "u")))
    }

    @Test
    fun metaIsDueAfterSixHours() {
        val now = 100_000_000L
        assertTrue(AppUpdate.metaDue(null, now))
        assertFalse(AppUpdate.metaDue(now - 60_000, now))
        assertFalse(AppUpdate.metaDue(now - AppUpdate.META_REFRESH_MS + 1, now))
        assertTrue(AppUpdate.metaDue(now - AppUpdate.META_REFRESH_MS, now))
        // The clock went backwards: ask again rather than wait.
        assertTrue(AppUpdate.metaDue(now + 60_000, now))
    }

    @Test
    fun metaDecodesTheAndroidRelease() {
        val meta = NovarrJson.decodeFromString(
            ServerMeta.serializer(),
            """{"app":"novarr","api_version":1,"android":{"version":"1.3","build":4,"url":"https://host/app/android"}}""",
        )
        assertEquals(AndroidRelease("1.3", 4, "https://host/app/android"), meta.android)

        val unknownBuild = NovarrJson.decodeFromString(
            ServerMeta.serializer(),
            """{"app":"novarr","api_version":1,"android":{"version":null,"build":null,"url":"https://host/app/android"}}""",
        )
        assertNull(unknownBuild.android?.build)
        // No APK published, an older server, or something unexpected: no release.
        assertNull(NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"android":null}""").android)
        assertNull(NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1}""").android)
        assertNull(NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"android":"yes"}""").android)
    }
}
