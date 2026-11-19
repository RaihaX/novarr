package studio.visns.novarr.domain

/**
 * How a placeholder cover fits its title (a cover image is missing). Lines
 * break between words; when the longest word is wider than the cover, the
 * text shrinks until it fits (down to [MIN_SCALE]), and only a word still too
 * wide at that size is hyphenated. Overflowing lines end in an ellipsis
 * rather than splitting short words ("Max-Leve / l Newbie").
 */
object CoverTitle {
    /** The smallest text, as a share of the normal size. */
    const val MIN_SCALE = 0.6f

    /** Room left for rounding: text measured to fit exactly can still wrap. */
    private const val SLACK = 0.97f

    /** The text scale and whether a word has to be hyphenated. */
    data class Fit(val scale: Float, val hyphenate: Boolean)

    /** Words as the line breaker sees them: split at spaces, and after a hyphen ("Max-" "Level"). */
    fun words(title: String): List<String> =
        title.trim().split(Regex("\\s+")).flatMap { it.split(Regex("(?<=-)")) }.filter { it.isNotBlank() }

    /** [longestWord]: the widest word's width at the normal size; [available]: the line width (same unit). */
    fun fit(longestWord: Float, available: Float, minScale: Float = MIN_SCALE): Fit {
        if (longestWord <= 0f || available <= 0f || longestWord <= available) return Fit(1f, false)
        val scale = available / longestWord * SLACK
        return if (scale >= minScale) Fit(scale, false) else Fit(minScale, true)
    }
}
