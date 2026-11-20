package studio.visns.novarr

import android.view.KeyEvent
import androidx.datastore.preferences.core.PreferenceDataStoreFactory
import java.io.File
import java.nio.file.Files
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.domain.Eink
import studio.visns.novarr.domain.EinkMode
import studio.visns.novarr.domain.PageKeyMap
import studio.visns.novarr.domain.TextReadingMode
import studio.visns.novarr.store.AppPrefs

class EinkTest {
    @Test
    fun autoIsOnForOnyxOnly() {
        assertTrue(Eink.resolve(EinkMode.AUTO, "ONYX", "MAX3"))
        assertTrue(Eink.resolve(EinkMode.AUTO, "onyx", null))
        assertTrue(Eink.resolve(EinkMode.AUTO, "Unknown", "Onyx"))
        assertTrue(Eink.resolve(EinkMode.AUTO, null, "ONYX"))
        assertFalse(Eink.resolve(EinkMode.AUTO, "Google", "google"))
        assertFalse(Eink.resolve(EinkMode.AUTO, "onyx international", "boox"))
        assertFalse(Eink.resolve(EinkMode.AUTO, null, null))
    }

    @Test
    fun onAndOffIgnoreTheDevice() {
        assertTrue(Eink.resolve(EinkMode.ON, "Google", "google"))
        assertTrue(Eink.resolve(EinkMode.ON, null, null))
        assertFalse(Eink.resolve(EinkMode.OFF, "ONYX", "ONYX"))
    }

    @Test
    fun unknownStoredModeIsAuto() {
        assertEquals(EinkMode.AUTO, EinkMode.from(null))
        assertEquals(EinkMode.AUTO, EinkMode.from("sometimes"))
        assertEquals(EinkMode.OFF, EinkMode.from("OFF"))
    }

    @Test
    fun bolderTextFollowsTheModeUntilChosen() {
        assertTrue(Eink.bolderText(null, eink = true))
        assertFalse(Eink.bolderText(null, eink = false))
        assertFalse(Eink.bolderText(false, eink = true))
        assertTrue(Eink.bolderText(true, eink = false))
    }

    @Test
    fun keyCodesMatchAndroid() {
        assertEquals(KeyEvent.KEYCODE_PAGE_DOWN, PageKeyMap.PAGE_DOWN)
        assertEquals(KeyEvent.KEYCODE_PAGE_UP, PageKeyMap.PAGE_UP)
        assertEquals(KeyEvent.KEYCODE_DPAD_RIGHT, PageKeyMap.DPAD_RIGHT)
        assertEquals(KeyEvent.KEYCODE_DPAD_LEFT, PageKeyMap.DPAD_LEFT)
        assertEquals(KeyEvent.KEYCODE_DPAD_DOWN, PageKeyMap.DPAD_DOWN)
        assertEquals(KeyEvent.KEYCODE_DPAD_UP, PageKeyMap.DPAD_UP)
        assertEquals(KeyEvent.KEYCODE_SPACE, PageKeyMap.SPACE)
        assertEquals(KeyEvent.KEYCODE_VOLUME_DOWN, PageKeyMap.VOLUME_DOWN)
        assertEquals(KeyEvent.KEYCODE_VOLUME_UP, PageKeyMap.VOLUME_UP)
    }

    @Test
    fun pageKeysMapToDirections() {
        listOf(PageKeyMap.PAGE_DOWN, PageKeyMap.DPAD_RIGHT, PageKeyMap.DPAD_DOWN, PageKeyMap.SPACE).forEach {
            assertEquals(true, PageKeyMap.direction(it, shift = false, volumeKeys = false))
        }
        listOf(PageKeyMap.PAGE_UP, PageKeyMap.DPAD_LEFT, PageKeyMap.DPAD_UP).forEach {
            assertEquals(false, PageKeyMap.direction(it, shift = false, volumeKeys = false))
        }
        // Shift+space goes back; shift doesn't change the other keys.
        assertEquals(false, PageKeyMap.direction(PageKeyMap.SPACE, shift = true, volumeKeys = false))
        assertEquals(true, PageKeyMap.direction(PageKeyMap.PAGE_DOWN, shift = true, volumeKeys = false))
        // Anything else is left to the system.
        assertNull(PageKeyMap.direction(KeyEvent.KEYCODE_ENTER, shift = false, volumeKeys = true))
        assertNull(PageKeyMap.direction(KeyEvent.KEYCODE_A, shift = false, volumeKeys = true))
    }

    @Test
    fun volumeKeysOnlyWhenAllowed() {
        assertEquals(true, PageKeyMap.direction(PageKeyMap.VOLUME_DOWN, shift = false, volumeKeys = true))
        assertEquals(false, PageKeyMap.direction(PageKeyMap.VOLUME_UP, shift = false, volumeKeys = true))
        assertNull(PageKeyMap.direction(PageKeyMap.VOLUME_DOWN, shift = false, volumeKeys = false))
        assertNull(PageKeyMap.direction(PageKeyMap.VOLUME_UP, shift = false, volumeKeys = false))
    }

    @Test
    fun volumeKeysDefaultAsBeforeOutsideEink() {
        // Untouched: the novel Pages mode only (as in 1.7), or every mode on e-ink.
        assertTrue(PageKeyMap.volumeKeys(null, eink = false, novelPaged = true))
        assertFalse(PageKeyMap.volumeKeys(null, eink = false, novelPaged = false))
        assertTrue(PageKeyMap.volumeKeys(null, eink = true, novelPaged = false))
        // The switch wins once touched.
        assertFalse(PageKeyMap.volumeKeys(false, eink = true, novelPaged = true))
        assertTrue(PageKeyMap.volumeKeys(true, eink = false, novelPaged = false))
    }

    @Test
    fun keyDirectionIgnoresReadingDirection() {
        // There is no reading-direction input: forward is the next page in right-to-left manga too,
        // where the reader's turn(true) moves the pager to the next index.
        assertEquals(PageKeyMap.direction(PageKeyMap.DPAD_RIGHT, false, false), PageKeyMap.direction(PageKeyMap.PAGE_DOWN, false, false))
        assertEquals(true, PageKeyMap.direction(PageKeyMap.DPAD_RIGHT, false, false))
    }

    @Test
    fun scrollStepIsAScreenLessTheOverlap() {
        assertEquals(1700, PageKeyMap.scrollStep(1800, 100))
        // A huge overlap still moves at least half a screen.
        assertEquals(400, PageKeyMap.scrollStep(800, 700))
        assertEquals(800, PageKeyMap.scrollStep(800, -5))
        // Never zero, so a press always moves.
        assertEquals(1, PageKeyMap.scrollStep(0, 48))
        assertEquals(1, PageKeyMap.scrollStep(1, 0))
    }
}

class EinkPrefsTest {
    private fun withPrefs(block: suspend (AppPrefs) -> Unit) = runBlocking {
        val dir = Files.createTempDirectory("prefs").toFile()
        val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())
        try {
            block(AppPrefs(PreferenceDataStoreFactory.create(scope = scope) { File(dir, "novarr.preferences_pb") }))
        } finally {
            scope.cancel()
        }
    }

    @Test
    fun modeDefaultsToAuto() = withPrefs { prefs ->
        assertEquals(EinkMode.AUTO, prefs.einkMode.first())
        prefs.setEinkMode(EinkMode.OFF)
        assertEquals(EinkMode.OFF, prefs.einkMode.first())
    }

    @Test
    fun einkDefaultsApplyOnce() = withPrefs { prefs ->
        assertTrue(prefs.applyEinkDefaultsOnce())
        val settings = prefs.readerSettings.first()
        assertEquals(TextReadingMode.PAGES, settings.mode)
        assertFalse(settings.keepScreenOn)
        // The reader changes them back: never applied again.
        prefs.updateReader { it.copy(mode = TextReadingMode.SCROLL, keepScreenOn = true) }
        assertFalse(prefs.applyEinkDefaultsOnce())
        assertEquals(TextReadingMode.SCROLL, prefs.readerSettings.first().mode)
        assertTrue(prefs.readerSettings.first().keepScreenOn)
    }

    @Test
    fun einkDefaultsKeepAChosenScreenSetting() = withPrefs { prefs ->
        prefs.updateReader { it.copy(keepScreenOn = false) }
        prefs.updateReader { it.copy(keepScreenOn = true) }
        assertTrue(prefs.applyEinkDefaultsOnce())
        assertTrue(prefs.readerSettings.first().keepScreenOn)
        assertEquals(TextReadingMode.PAGES, prefs.readerSettings.first().mode)
    }

    @Test
    fun bolderTextIsStoredOnlyOnceChosen() = withPrefs { prefs ->
        assertNull(prefs.readerSettings.first().bolder)
        prefs.updateReader { it.copy(fontSize = 21f) }
        assertNull(prefs.readerSettings.first().bolder)
        prefs.updateReader { it.copy(bolder = false) }
        assertEquals(false, prefs.readerSettings.first().bolder)
        prefs.updateReader { it.copy(fontSize = 22f) }
        assertEquals(false, prefs.readerSettings.first().bolder)
    }

    @Test
    fun volumeKeysSwitchIsUnsetUntilTouched() = withPrefs { prefs ->
        assertNull(prefs.volumeKeys.first())
        prefs.setVolumeKeys(false)
        assertEquals(false, prefs.volumeKeys.first())
    }
}
