package studio.visns.novarr

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
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.domain.LatestPosition
import studio.visns.novarr.domain.MangaModeOverride
import studio.visns.novarr.domain.Pagination
import studio.visns.novarr.domain.Pagination.Block
import studio.visns.novarr.domain.ProgressTracker
import studio.visns.novarr.domain.ReadingMode
import studio.visns.novarr.domain.TapAction
import studio.visns.novarr.domain.TapZones
import studio.visns.novarr.domain.TextOffsets
import studio.visns.novarr.domain.TextReadingMode
import studio.visns.novarr.store.AppPrefs
import studio.visns.novarr.store.ReaderTheme

/** A measured chapter stand-in: a heading, then paragraphs of [lines] lines of [lineHeight] px, [charsPerLine] each. */
private class FakeChapter(val lines: List<Int>, val lineHeight: Float = 30f, val charsPerLine: Int = 50, val gap: Float = 20f, val header: Float = 100f) {
    val lengths = lines.map { it * charsPerLine }
    val offsets = TextOffsets(lengths)

    fun blocks(lineHeight: Float = this.lineHeight): List<Block> {
        val result = mutableListOf(Block.single(0, header))
        var start = 0
        lines.forEachIndexed { i, n ->
            result += Block(
                startOffset = start,
                lineTops = FloatArray(n) { it * lineHeight },
                lineBottoms = FloatArray(n) { (it + 1) * lineHeight },
                lineStarts = IntArray(n) { it * charsPerLine },
                spacingAfter = if (i < lines.size - 1) gap else 0f,
            )
            start += lengths[i]
        }
        return result
    }
}

class PaginationTest {
    private val chapter = FakeChapter(listOf(3, 12, 1, 7, 25, 4, 9, 2, 40, 6))

    private fun assertWellFormed(blocks: List<Block>, pages: List<Pagination.Page>, height: Float) {
        // Every line of every block is on exactly one page, in order, and nothing overflows.
        val seen = blocks.map { BooleanArray(it.lineCount) }
        var previousStart = -1
        pages.forEach { page ->
            assertTrue("page starts move forward", page.startOffset > previousStart || page.startOffset == 0)
            previousStart = page.startOffset
            assertTrue("page fits: ${page.bottom} <= $height", page.bottom <= height + 0.01f || page.slices.size == 1)
            var y = -1f
            page.slices.forEach { slice ->
                assertTrue("slices go down the page", slice.y >= y)
                y = slice.y + slice.height
                for (line in slice.fromLine until slice.toLine) {
                    assertTrue("line $line of block ${slice.block} placed twice", !seen[slice.block][line])
                    seen[slice.block][line] = true
                }
                // A slice's height is whole lines: never half a line.
                assertEquals(blocks[slice.block].height(slice.fromLine, slice.toLine), slice.height, 0.001f)
            }
            val first = page.slices.first()
            assertEquals(0f, first.y, 0f) // spacing is dropped at the top of a page
            assertEquals(blocks[first.block].startOffset + blocks[first.block].lineStarts[first.fromLine], page.startOffset)
        }
        seen.forEachIndexed { b, lines -> lines.forEachIndexed { l, placed -> assertTrue("line $l of block $b missing", placed) } }
    }

    @Test
    fun linesAreNeverSplitAndEveryLineIsPlacedOnce() {
        for (height in listOf(95f, 100f, 250f, 333f, 600f, 1234f)) {
            val blocks = chapter.blocks()
            assertWellFormed(blocks, Pagination.paginate(blocks, height), height)
        }
    }

    @Test
    fun theHeadingStaysOnTheFirstPageWithText() {
        val pages = Pagination.paginate(chapter.blocks(), 400f)
        assertEquals(0, pages[0].slices[0].block)
        assertEquals(0f, pages[0].slices[0].y, 0f)
        // The first paragraph follows the heading on the same page.
        assertEquals(1, pages[0].slices[1].block)
        assertEquals(100f, pages[0].slices[1].y, 0f)
        assertTrue(pages.drop(1).none { page -> page.slices.any { it.block == 0 } })
    }

    @Test
    fun paragraphSpacingIsKeptWithinAPage() {
        val pages = Pagination.paginate(chapter.blocks(), 2000f)
        val slices = pages[0].slices
        // Heading 100, paragraph 1 (3 lines) to 190, then 20 spacing.
        assertEquals(210f, slices[2].y, 0.001f)
    }

    @Test
    fun aLineTallerThanThePageGetsAPageOfItsOwn() {
        val blocks = listOf(
            Block(0, floatArrayOf(0f, 50f, 550f), floatArrayOf(50f, 550f, 600f), intArrayOf(0, 10, 20)),
        )
        val pages = Pagination.paginate(blocks, 300f)
        assertEquals(listOf(0, 10, 20), pages.map { it.startOffset })
        assertEquals(1, pages[1].slices.single().toLine - pages[1].slices.single().fromLine)
    }

    @Test
    fun anEmptyChapterStillHasAPage() {
        assertEquals(1, Pagination.paginate(emptyList(), 300f).size)
    }

    @Test
    fun offsetAndPageRoundTrip() {
        val pages = Pagination.paginate(chapter.blocks(), 333f)
        pages.forEachIndexed { index, page ->
            assertEquals(index, Pagination.pageFor(pages, page.startOffset))
            // Any character before the next page's start is on this page.
            val end = pages.getOrNull(index + 1)?.startOffset ?: chapter.offsets.total
            if (end - 1 >= page.startOffset) assertEquals(index, Pagination.pageFor(pages, end - 1))
        }
        assertEquals(pages.size - 1, Pagination.pageFor(pages, Int.MAX_VALUE))
        assertEquals(0, Pagination.pageFor(pages, 0))
    }

    @Test
    fun repaginationKeepsTheTextPosition() {
        val small = Pagination.paginate(chapter.blocks(lineHeight = 30f), 400f)
        for (index in small.indices) {
            val anchor = small[index].startOffset
            for ((lineHeight, height) in listOf(45f to 400f, 30f to 900f, 22f to 250f)) {
                val pages = Pagination.paginate(chapter.blocks(lineHeight), height)
                val page = Pagination.pageFor(pages, anchor)
                val start = pages[page].startOffset
                val end = pages.getOrNull(page + 1)?.startOffset ?: Int.MAX_VALUE
                assertTrue("anchor $anchor on page $page [$start, $end)", anchor in start until end)
                // And going back gives the same page as before.
                val back = Pagination.pageFor(Pagination.paginate(chapter.blocks(30f), 400f), anchor)
                assertEquals(index, back)
            }
        }
    }
}

class TextPositionTest {
    private val offsets = TextOffsets(listOf(120, 0, 300, 45, 800, 10))

    @Test
    fun paragraphsAndOffsets() {
        assertEquals(1275, offsets.total)
        assertEquals(0, offsets.paragraphAt(0))
        assertEquals(0, offsets.paragraphAt(119))
        // Paragraph 1 is empty: offset 120 shows paragraph 2 at the top.
        assertEquals(2, offsets.paragraphAt(120))
        assertEquals(4, offsets.paragraphAt(500))
        assertEquals(5, offsets.paragraphAt(5000))
    }

    @Test
    fun pagedProgressMatchesScrollProgressForTheSamePlace() {
        // Scroll mode reports the paragraph at the top; Pages reports the paragraph of the page's first line.
        for (p in 0 until offsets.paragraphCount) {
            if (p == 1) continue // empty
            val start = offsets.paragraphStart(p)
            assertEquals(ProgressTracker.progressFor(p, offsets.paragraphCount), offsets.progressAt(start))
            // Mid-paragraph is still that paragraph, as when it is scrolled partly off the top.
            assertEquals(ProgressTracker.progressFor(p, offsets.paragraphCount), offsets.progressAt(start + 3))
        }
    }

    @Test
    fun savedProgressRestoresToTheParagraphScrollModeWouldShow() {
        for (progress in listOf(0, 1, 20, 40, 60, 80, 99)) {
            val paragraph = LatestPosition.itemIndex(progress, offsets.paragraphCount) ?: 0
            assertEquals(offsets.paragraphStart(paragraph), offsets.offsetFor(progress))
        }
        // Restoring then reporting gives the same percentage back (no creep between devices or modes).
        for (p in listOf(0, 2, 3, 4, 5)) {
            val progress = ProgressTracker.progressFor(p, offsets.paragraphCount)
            assertEquals(progress, offsets.progressAt(offsets.offsetFor(progress)))
        }
    }

    @Test
    fun aRestoredPositionLandsOnThePageHoldingIt() {
        val chapter = FakeChapter(listOf(3, 12, 1, 7, 25, 4, 9, 2, 40, 6))
        val pages = Pagination.paginate(chapter.blocks(), 333f)
        for (progress in 0..99 step 7) {
            val target = chapter.offsets.offsetFor(progress)
            val page = Pagination.pageFor(pages, target)
            val end = pages.getOrNull(page + 1)?.startOffset ?: Int.MAX_VALUE
            assertTrue(target >= pages[page].startOffset && target < end)
        }
    }
}

class TapZoneTest {
    @Test
    fun thirdsLeftToRight() {
        assertEquals(TapAction.PREVIOUS, TapZones.actionFor(10f, 900f))
        assertEquals(TapAction.PREVIOUS, TapZones.actionFor(299f, 900f))
        assertEquals(TapAction.TOGGLE_CONTROLS, TapZones.actionFor(300f, 900f))
        assertEquals(TapAction.TOGGLE_CONTROLS, TapZones.actionFor(599f, 900f))
        assertEquals(TapAction.NEXT, TapZones.actionFor(600f, 900f))
        assertEquals(TapAction.NEXT, TapZones.actionFor(899f, 900f))
    }

    @Test
    fun rightToLeftGoesForwardOnTheLeft() {
        assertEquals(TapAction.NEXT, TapZones.actionFor(10f, 900f, rightToLeft = true))
        assertEquals(TapAction.TOGGLE_CONTROLS, TapZones.actionFor(450f, 900f, rightToLeft = true))
        assertEquals(TapAction.PREVIOUS, TapZones.actionFor(890f, 900f, rightToLeft = true))
    }

    @Test
    fun noWidthOnlyToggles() {
        assertEquals(TapAction.TOGGLE_CONTROLS, TapZones.actionFor(0f, 0f))
    }

    @Test
    fun swipes() {
        // Finger moves left: next page in left-to-right reading.
        assertEquals(TapAction.NEXT, TapZones.swipeAction(-120f, 48f))
        assertEquals(TapAction.PREVIOUS, TapZones.swipeAction(120f, 48f))
        assertNull(TapZones.swipeAction(20f, 48f))
        assertEquals(TapAction.NEXT, TapZones.swipeAction(120f, 48f, rightToLeft = true))
        assertEquals(TapAction.PREVIOUS, TapZones.swipeAction(-120f, 48f, rightToLeft = true))
    }
}

class ModeTest {
    @Test
    fun mangaModeResolution() {
        val series = listOf(ReadingMode.LEFT_TO_RIGHT, ReadingMode.RIGHT_TO_LEFT, ReadingMode.VERTICAL)
        assertEquals(series, series.map(MangaModeOverride.FOLLOW::resolve))
        assertEquals(List(3) { ReadingMode.VERTICAL }, series.map(MangaModeOverride.SCROLL::resolve))
        // Pages keeps the series' direction; a webtoon pages left to right.
        assertEquals(
            listOf(ReadingMode.LEFT_TO_RIGHT, ReadingMode.RIGHT_TO_LEFT, ReadingMode.LEFT_TO_RIGHT),
            series.map(MangaModeOverride.PAGES::resolve),
        )
    }

    @Test
    fun unknownStoredValuesFallBackToDefaults() {
        assertEquals(MangaModeOverride.FOLLOW, MangaModeOverride.from(null))
        assertEquals(MangaModeOverride.FOLLOW, MangaModeOverride.from("sideways"))
        assertEquals(MangaModeOverride.SCROLL, MangaModeOverride.from("SCROLL"))
        assertEquals(TextReadingMode.SCROLL, TextReadingMode.from(null))
        assertEquals(TextReadingMode.PAGES, TextReadingMode.from("PAGES"))
    }
}

class ReaderPrefsTest {
    private fun withPrefs(block: suspend (AppPrefs, File) -> Unit) = runBlocking {
        val dir = Files.createTempDirectory("prefs").toFile()
        val scope = CoroutineScope(Dispatchers.IO + SupervisorJob())
        try {
            val store = PreferenceDataStoreFactory.create(scope = scope) { File(dir, "novarr.preferences_pb") }
            block(AppPrefs(store), dir)
        } finally {
            scope.cancel()
        }
    }

    @Test
    fun textReadingModeIsAReaderDefault() = withPrefs { prefs, _ ->
        assertEquals(TextReadingMode.SCROLL, prefs.readerSettings.first().mode)
        prefs.updateReader { it.copy(mode = TextReadingMode.PAGES, theme = ReaderTheme.SEPIA) }
        val settings = prefs.readerSettings.first()
        assertEquals(TextReadingMode.PAGES, settings.mode)
        assertEquals(ReaderTheme.SEPIA, settings.theme)
        // Other edits keep it.
        prefs.updateReader { it.copy(fontSize = 22f) }
        assertEquals(TextReadingMode.PAGES, prefs.readerSettings.first().mode)
    }

    @Test
    fun mangaModeIsPerServerAndSeries() = withPrefs { prefs, _ ->
        val a = "http://10.0.2.2:8773"
        val b = "https://other.example"
        assertEquals(MangaModeOverride.FOLLOW, prefs.mangaMode(a, 7).first())
        prefs.setMangaMode(a, 7, MangaModeOverride.SCROLL)
        prefs.setMangaMode(a, 8, MangaModeOverride.PAGES)
        assertEquals(MangaModeOverride.SCROLL, prefs.mangaMode(a, 7).first())
        assertEquals(MangaModeOverride.PAGES, prefs.mangaMode(a, 8).first())
        assertEquals(MangaModeOverride.FOLLOW, prefs.mangaMode(b, 7).first())
        // Back to the default clears it.
        prefs.setMangaMode(a, 7, MangaModeOverride.FOLLOW)
        assertEquals(MangaModeOverride.FOLLOW, prefs.mangaMode(a, 7).first())
        assertTrue(prefs.snapshot().asMap().keys.none { it.name.endsWith("#7") })
    }
}
