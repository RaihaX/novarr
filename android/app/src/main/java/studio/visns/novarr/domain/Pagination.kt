package studio.visns.novarr.domain

/**
 * Lays a measured chapter out into screen-sized pages, never cutting a line
 * in half. Pure (no Compose): the reader measures the text and hands over line
 * geometry; this decides where pages break.
 *
 * Positions are global character offsets into the chapter: paragraph `i`
 * starts at the sum of the lengths of paragraphs before it (see [TextOffsets]).
 * A page is identified by the offset of its first line, so a re-pagination
 * (new font size, rotation...) can find the page holding the same text.
 */
object Pagination {
    /**
     * One flow element (the chapter heading or a paragraph), measured at the
     * page's text width. Line `i` spans [lineTops]`[i]`..[lineBottoms]`[i]` (px,
     * relative to the block's own top) and starts at character
     * [startOffset] + [lineStarts]`[i]`. [spacingAfter] is the gap to the next
     * block; it is dropped at a page break. A [keepTogether] block is never split.
     */
    class Block(
        val startOffset: Int,
        val lineTops: FloatArray,
        val lineBottoms: FloatArray,
        val lineStarts: IntArray,
        val spacingAfter: Float = 0f,
        val keepTogether: Boolean = false,
    ) {
        init {
            require(lineTops.size == lineBottoms.size && lineTops.size == lineStarts.size) { "line arrays differ in size" }
        }

        val lineCount: Int get() = lineTops.size
        fun height(from: Int, to: Int): Float = if (to <= from) 0f else lineBottoms[to - 1] - lineTops[from]

        companion object {
            /** A block drawn as one piece (e.g. the heading), [height] px tall. */
            fun single(startOffset: Int, height: Float, spacingAfter: Float = 0f) =
                Block(startOffset, floatArrayOf(0f), floatArrayOf(height), intArrayOf(0), spacingAfter, keepTogether = true)
        }
    }

    /** Lines [fromLine, toLine) of block [block], drawn with their top at [y] on the page. */
    data class Slice(val block: Int, val fromLine: Int, val toLine: Int, val y: Float, val height: Float)

    /** One screen: what it shows and the offset of its first line. */
    data class Page(val slices: List<Slice>, val startOffset: Int) {
        val bottom: Float get() = slices.lastOrNull()?.let { it.y + it.height } ?: 0f
    }

    /**
     * Breaks [blocks] into pages of at most [pageHeight] px. A line (or a
     * keep-together block) taller than a whole page gets a page of its own.
     * There is always at least one page.
     */
    fun paginate(blocks: List<Block>, pageHeight: Float): List<Page> {
        require(pageHeight > 0f) { "page height must be positive" }
        val pages = ArrayList<Page>()
        var slices = ArrayList<Slice>()
        var y = 0f
        var pageStart = -1
        // Spacing owed by the previous block; only paid if more follows on this page.
        var gap = 0f

        fun breakPage() {
            if (slices.isNotEmpty()) pages += Page(slices, pageStart)
            slices = ArrayList()
            y = 0f
            pageStart = -1
            gap = 0f
        }

        blocks.forEachIndexed { index, block ->
            if (block.lineCount == 0) {
                return@forEachIndexed
            }
            if (block.keepTogether) {
                val h = block.height(0, block.lineCount)
                if (slices.isNotEmpty() && y + gap + h > pageHeight) breakPage()
                val top = if (slices.isEmpty()) 0f else y + gap
                if (pageStart < 0) pageStart = block.startOffset + block.lineStarts[0]
                slices += Slice(index, 0, block.lineCount, top, h)
                y = top + h
                gap = block.spacingAfter
                return@forEachIndexed
            }
            var from = 0
            while (from < block.lineCount) {
                // Fit as many whole lines of this block as the page has room for.
                val top = if (slices.isEmpty()) 0f else y + gap
                var to = from
                while (to < block.lineCount && top + block.height(from, to + 1) <= pageHeight) to++
                if (to == from) {
                    if (slices.isEmpty()) to = from + 1 // a line taller than the page: alone, clipped
                    else {
                        breakPage()
                        continue
                    }
                }
                if (pageStart < 0) pageStart = block.startOffset + block.lineStarts[from]
                val h = block.height(from, to)
                slices += Slice(index, from, to, top, h)
                y = top + h
                gap = 0f
                from = to
                if (from < block.lineCount) breakPage()
            }
            gap = block.spacingAfter
        }
        breakPage()
        if (pages.isEmpty()) pages += Page(emptyList(), 0)
        return pages
    }

    /** The page showing [offset]: the last page starting at or before it. */
    fun pageFor(pages: List<Page>, offset: Int): Int {
        if (pages.isEmpty()) return 0
        var lo = 0
        var hi = pages.size - 1
        while (lo < hi) {
            val mid = (lo + hi + 1) / 2
            if (pages[mid].startOffset <= offset) lo = mid else hi = mid - 1
        }
        return lo
    }
}

/**
 * Global character offsets of a chapter's paragraphs, and the conversion
 * between an offset and the paragraph-based 0–99 progress that the scroll
 * reader reports (so both modes write the same percentage for the same place).
 */
class TextOffsets(paragraphLengths: List<Int>) {
    private val starts = IntArray(paragraphLengths.size)
    val total: Int

    init {
        var sum = 0
        paragraphLengths.forEachIndexed { i, len ->
            starts[i] = sum
            sum += len.coerceAtLeast(0)
        }
        total = sum
    }

    val paragraphCount: Int get() = starts.size

    fun paragraphStart(index: Int): Int = if (starts.isEmpty()) 0 else starts[index.coerceIn(0, starts.size - 1)]

    /** The paragraph holding [offset] (a paragraph's own start belongs to it). */
    fun paragraphAt(offset: Int): Int {
        if (starts.isEmpty()) return 0
        var lo = 0
        var hi = starts.size - 1
        while (lo < hi) {
            val mid = (lo + hi + 1) / 2
            if (starts[mid] <= offset) lo = mid else hi = mid - 1
        }
        // Empty paragraphs share a start with the next one: report the last of them, as scrolling would show it on top.
        return lo
    }

    /** 0–99 progress for a reader whose first visible line starts at [offset]; same as scroll mode's paragraph rule. */
    fun progressAt(offset: Int): Int = ProgressTracker.progressFor(paragraphAt(offset), paragraphCount)

    /** The offset to restore for a saved 0–100 position (the start of the paragraph scroll mode would show). */
    fun offsetFor(progress: Int): Int = LatestPosition.itemIndex(progress, paragraphCount)?.let(::paragraphStart) ?: 0
}

/** What a tap on the reading surface does. */
enum class TapAction { PREVIOUS, NEXT, TOGGLE_CONTROLS }

object TapZones {
    /**
     * Thirds of the width: the middle toggles the controls; the outer thirds
     * turn pages. Left-to-right reading goes forward on the right; right-to-left
     * (manga) goes forward on the left.
     */
    fun actionFor(x: Float, width: Float, rightToLeft: Boolean = false): TapAction {
        if (width <= 0f) return TapAction.TOGGLE_CONTROLS
        val third = width / 3f
        val side = when {
            x < third -> -1
            x >= width - third -> 1
            else -> 0
        }
        if (side == 0) return TapAction.TOGGLE_CONTROLS
        val forward = if (rightToLeft) side < 0 else side > 0
        return if (forward) TapAction.NEXT else TapAction.PREVIOUS
    }

    /** A horizontal swipe of [dx] px (positive = finger moved right); null when too short. */
    fun swipeAction(dx: Float, threshold: Float, rightToLeft: Boolean = false): TapAction? {
        if (kotlin.math.abs(dx) < threshold) return null
        // Dragging the page leftwards reveals the next one in left-to-right reading.
        val forward = if (rightToLeft) dx > 0 else dx < 0
        return if (forward) TapAction.NEXT else TapAction.PREVIOUS
    }
}
