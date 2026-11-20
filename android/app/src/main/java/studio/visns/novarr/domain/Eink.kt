package studio.visns.novarr.domain

/** Settings' "E-ink mode": Auto (on for Onyx Boox devices), On or Off. */
enum class EinkMode(val title: String) {
    AUTO("Auto"), ON("On"), OFF("Off");

    companion object {
        fun from(raw: String?): EinkMode = entries.firstOrNull { it.name == raw } ?: AUTO
    }
}

/** E-ink mode: whether it is on, and the reader defaults that follow it. Pure, for tests. */
object Eink {
    /** An Onyx Boox (Build.MANUFACTURER or Build.BRAND is "ONYX"). */
    fun isOnyx(manufacturer: String?, brand: String?): Boolean =
        manufacturer.equals("ONYX", ignoreCase = true) || brand.equals("ONYX", ignoreCase = true)

    fun resolve(mode: EinkMode, manufacturer: String?, brand: String?): Boolean = when (mode) {
        EinkMode.ON -> true
        EinkMode.OFF -> false
        EinkMode.AUTO -> isOnyx(manufacturer, brand)
    }

    /** Reader's "Bolder text": the reader's choice, else on in e-ink mode only. */
    fun bolderText(stored: Boolean?, eink: Boolean): Boolean = stored ?: eink
}

/**
 * Hardware keys that turn pages in the readers: page up/down, the arrow keys,
 * space (shift+space goes back) and, when allowed, the volume keys (down = next).
 * Key codes are android.view.KeyEvent's.
 */
object PageKeyMap {
    const val DPAD_UP = 19
    const val DPAD_DOWN = 20
    const val DPAD_LEFT = 21
    const val DPAD_RIGHT = 22
    const val VOLUME_UP = 24
    const val VOLUME_DOWN = 25
    const val SPACE = 62
    const val PAGE_UP = 92
    const val PAGE_DOWN = 93

    /** True: forward (next page), false: back, null: not a page key here. Reading direction never changes it. */
    fun direction(keyCode: Int, shift: Boolean, volumeKeys: Boolean): Boolean? = when (keyCode) {
        PAGE_DOWN, DPAD_RIGHT, DPAD_DOWN -> true
        PAGE_UP, DPAD_LEFT, DPAD_UP -> false
        SPACE -> !shift
        VOLUME_DOWN -> if (volumeKeys) true else null
        VOLUME_UP -> if (volumeKeys) false else null
        else -> null
    }

    /**
     * Whether the volume keys turn pages in a reader. Settings' switch wins once
     * touched; untouched, they do in every mode in e-ink mode, and otherwise only
     * in the novel Pages mode (as before 1.8).
     */
    fun volumeKeys(stored: Boolean?, eink: Boolean, novelPaged: Boolean): Boolean = stored ?: (eink || novelPaged)

    /** How far one key press scrolls a continuous reader: a screen, less [overlap] so the last lines stay in view. */
    fun scrollStep(viewport: Int, overlap: Int): Int =
        (viewport - overlap.coerceAtLeast(0)).coerceAtLeast(viewport / 2).coerceAtLeast(1)
}
