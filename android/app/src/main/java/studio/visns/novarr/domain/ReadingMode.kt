package studio.visns.novarr.domain

/**
 * How a manga chapter is laid out, from the series' `reading_mode`
 * (Novel::READING_MODES: rtl, ltr, vertical). Anything else reads as ltr,
 * which is also the server's own fallback (Novel::effectiveReadingMode()).
 */
enum class ReadingMode(val paged: Boolean, val rightToLeft: Boolean) {
    LEFT_TO_RIGHT(paged = true, rightToLeft = false),
    RIGHT_TO_LEFT(paged = true, rightToLeft = true),
    VERTICAL(paged = false, rightToLeft = false);

    companion object {
        fun from(raw: String?): ReadingMode = when (raw?.trim()?.lowercase()) {
            "rtl" -> RIGHT_TO_LEFT
            "vertical" -> VERTICAL
            else -> LEFT_TO_RIGHT
        }
    }
}
