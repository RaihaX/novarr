package studio.visns.novarr.domain

/**
 * Finding highlights in a chapter's text. A highlight is stored as its
 * excerpt only (the website's bookmarks), copied from a selection that may
 * span paragraphs and whose whitespace differs from the API's paragraphs
 * (line breaks between paragraphs, doubled spaces, non-breaking spaces). So
 * matching works on a whitespace-normalised copy of the chapter: every run
 * of whitespace is one space, paragraphs are joined by one space, and the
 * excerpt is normalised the same way. An excerpt that can't be found is
 * simply not shown.
 */
object Highlights {
    /** The server's limit on an excerpt and on a note (HighlightController::MAX_LENGTH), in characters. */
    const val MAX_LENGTH = 2000

    /** Part of a match inside one paragraph: characters [start, end) of paragraph [paragraph]. */
    data class Span(val paragraph: Int, val start: Int, val end: Int)

    /** Every run of whitespace as one space, without leading or trailing whitespace. */
    fun normalize(text: String): String = buildString(text.length) {
        var pendingSpace = false
        for (c in text) {
            if (c.isWhitespace()) {
                pendingSpace = isNotEmpty()
            } else {
                if (pendingSpace) append(' ')
                pendingSpace = false
                append(c)
            }
        }
    }

    /**
     * [text] trimmed and cut to at most [max] UTF-16 units, never between the
     * two halves of a surrogate pair (the server counts characters, so this is
     * never over its limit).
     */
    fun clip(text: String, max: Int = MAX_LENGTH): String {
        val trimmed = text.trim()
        if (trimmed.length <= max) return trimmed
        val end = if (Character.isHighSurrogate(trimmed[max - 1])) max - 1 else max
        return trimmed.substring(0, end).trimEnd()
    }

    /**
     * A chapter's paragraphs prepared for matching: the normalised text, and
     * for each of its characters the paragraph and offset it came from
     * (-1 for the space joining two paragraphs).
     */
    class Index(private val paragraphs: List<String>) {
        private val text: String
        private val paragraphOf: IntArray
        private val offsetOf: IntArray

        init {
            val total = paragraphs.sumOf { it.length + 1 }
            val builder = StringBuilder(total)
            val para = IntArray(total)
            val offs = IntArray(total)
            var n = 0
            paragraphs.forEachIndexed { p, source ->
                var pendingSpace = -1 // the offset of the whitespace run waiting to become one space
                var wrote = false
                source.forEachIndexed { i, c ->
                    if (c.isWhitespace()) {
                        if (wrote && pendingSpace < 0) pendingSpace = i
                    } else {
                        if (pendingSpace >= 0) {
                            builder.append(' '); para[n] = p; offs[n] = pendingSpace; n++
                            pendingSpace = -1
                        } else if (!wrote && builder.isNotEmpty()) {
                            // The first character of a paragraph after earlier text: the joining space.
                            builder.append(' '); para[n] = -1; offs[n] = -1; n++
                        }
                        builder.append(c); para[n] = p; offs[n] = i; n++
                        wrote = true
                    }
                }
            }
            text = builder.toString()
            paragraphOf = para.copyOf(n)
            offsetOf = offs.copyOf(n)
        }

        /**
         * Where [excerpt] first occurs, as one span per paragraph it covers (in
         * order), or null when it isn't in the chapter.
         */
        fun locate(excerpt: String): List<Span>? {
            val needle = normalize(excerpt)
            if (needle.isEmpty()) return null
            val at = text.indexOf(needle)
            if (at < 0) return null
            val spans = ArrayList<Span>(2)
            var current = -1
            var start = 0
            var end = 0
            for (k in at until at + needle.length) {
                val p = paragraphOf[k]
                if (p < 0) continue
                if (p != current) {
                    if (current >= 0) spans += Span(current, start, end)
                    current = p
                    start = offsetOf[k]
                }
                end = offsetOf[k] + 1
            }
            if (current >= 0) spans += Span(current, start, end)
            return spans.takeIf { it.isNotEmpty() }
        }

        /**
         * The character ranges to tint for [excerpts], by paragraph: each
         * paragraph's ranges sorted, with overlapping or touching ones merged.
         * Excerpts that can't be found are skipped.
         */
        fun ranges(excerpts: Collection<String>): Map<Int, List<IntRange>> {
            val byParagraph = HashMap<Int, MutableList<IntRange>>()
            excerpts.forEach { excerpt ->
                locate(excerpt)?.forEach { span ->
                    if (span.end > span.start) byParagraph.getOrPut(span.paragraph) { ArrayList() } += span.start until span.end
                }
            }
            return byParagraph.mapValues { (_, list) -> merge(list) }
        }
    }

    /** [ranges] sorted, with overlapping or adjacent ranges joined. */
    fun merge(ranges: List<IntRange>): List<IntRange> {
        val sorted = ranges.filter { !it.isEmpty() }.sortedBy { it.first }
        val merged = ArrayList<IntRange>(sorted.size)
        for (range in sorted) {
            val last = merged.lastOrNull()
            if (last != null && range.first <= last.last + 1) {
                merged[merged.size - 1] = last.first..maxOf(last.last, range.last)
            } else {
                merged += range
            }
        }
        return merged
    }

    /** [Index.locate] for a one-off look-up. */
    fun locate(paragraphs: List<String>, excerpt: String): List<Span>? = Index(paragraphs).locate(excerpt)

    /** [Index.ranges] for a one-off look-up. */
    fun ranges(paragraphs: List<String>, excerpts: Collection<String>): Map<Int, List<IntRange>> = Index(paragraphs).ranges(excerpts)
}
