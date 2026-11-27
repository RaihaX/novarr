package studio.visns.novarr.domain

import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.contentOrNull
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.Medium

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

/** This device's choice for a manga series, over the series' own [ReadingMode]. Never sent to the server. */
enum class MangaModeOverride(val title: String) {
    FOLLOW("Follow series"), PAGES("Pages"), SCROLL("Scroll");

    /** The layout to use for a series whose server mode is [series]. */
    fun resolve(series: ReadingMode): ReadingMode = when (this) {
        FOLLOW -> series
        SCROLL -> ReadingMode.VERTICAL
        // Paged keeps the series' direction; a vertical series pages left to right.
        PAGES -> if (series.paged) series else ReadingMode.LEFT_TO_RIGHT
    }

    companion object {
        fun from(raw: String?): MangaModeOverride = entries.firstOrNull { it.name == raw } ?: FOLLOW
    }
}

/** How the text (novel) reader lays a chapter out. */
enum class TextReadingMode(val title: String) {
    SCROLL("Scroll"), PAGES("Pages");

    companion object {
        fun from(raw: String?): TextReadingMode = entries.firstOrNull { it.name == raw } ?: SCROLL
    }
}

/**
 * The text chapter endpoint's refusal of a page chapter: 422 with
 * `{"medium": "manga" | "comic", "pages": true}`. The text reader then
 * opens the chapter in the page reader instead (a series whose medium the
 * app had wrong, or an old link).
 */
object PageReaderRedirect {
    fun applies(error: ApiError?): Boolean {
        if (error !is ApiError.Http || error.status != 422) return false
        val body = error.body ?: return false
        if ((body["pages"] as? JsonPrimitive)?.booleanOrNull == true) return true
        return Medium.isPaged((body["medium"] as? JsonPrimitive)?.contentOrNull)
    }
}
