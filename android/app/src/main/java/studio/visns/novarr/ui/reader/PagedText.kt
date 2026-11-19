package studio.visns.novarr.ui.reader

import android.os.SystemClock
import android.util.Log
import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.EnterTransition
import androidx.compose.animation.ExitTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.systemBarsIgnoringVisibility
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.LocalTextStyle
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.clipRect
import androidx.compose.ui.graphics.drawscope.translate
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalFontFamilyResolver
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.semantics.CustomAccessibilityAction
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.customActions
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.text
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.text.TextMeasurer
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.drawText
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.unit.Constraints
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.LayoutDirection
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.min
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.withContext
import studio.visns.novarr.BuildConfig
import studio.visns.novarr.api.ChapterContent
import studio.visns.novarr.domain.Pagination
import studio.visns.novarr.domain.TapAction
import studio.visns.novarr.domain.TapZones
import studio.visns.novarr.store.ReaderSettings
import kotlin.coroutines.coroutineContext

/** A chapter measured and broken into pages for one text width, page height and style. */
class MeasuredChapter(
    val pages: List<Pagination.Page>,
    /** Layouts of the chapter's paragraphs; block `i + 1` of the pages is paragraph `i` (block 0 is the heading). */
    val paragraphs: List<TextLayoutResult>,
    val headerHeightPx: Float,
    val labelHeightPx: Float,
    val titleHeightPx: Float,
)

/** What the page area shows: a page of one pagination (a new pagination swaps without animating). */
private data class Shown(val layout: MeasuredChapter, val page: Int)

/** The heading's spacing (dp), shared by the scroll and paged layouts. */
internal object HeaderMetrics {
    const val TOP = 20f
    const val GAP = 8f
    const val RULE = 7f // 4dp padding + 3dp bar
    const val BOTTOM = 12f
}

/** Everything the measuring depends on; a change re-paginates. */
private data class PageSpec(
    val chapterId: Int,
    val widthPx: Int,
    val pageHeightPx: Float,
    val gapPx: Float,
    val body: TextStyle,
    val title: TextStyle,
    val label: TextStyle,
    val density: Density,
)

/**
 * Measures every paragraph at the page's text width (off the main thread:
 * this is the costly part for long chapters) and paginates.
 */
private suspend fun measureChapter(
    content: ChapterContent,
    spec: PageSpec,
    resolver: FontFamily.Resolver,
    direction: LayoutDirection,
): MeasuredChapter {
    val measurer = TextMeasurer(resolver, spec.density, direction, cacheSize = 0)
    val constraints = Constraints(maxWidth = spec.widthPx.coerceAtLeast(1))
    val dp = spec.density.density
    val labelHeight = if (content.novelName.isNotEmpty()) {
        measurer.measure(content.novelName.uppercase(), spec.label, maxLines = 2, constraints = constraints).size.height.toFloat()
    } else 0f
    val titleHeight = measurer.measure(content.title, spec.title, constraints = constraints).size.height.toFloat()
    val header = HeaderMetrics.TOP * dp +
        (if (labelHeight > 0f) labelHeight + HeaderMetrics.GAP * dp else 0f) +
        titleHeight + HeaderMetrics.GAP * dp + HeaderMetrics.RULE * dp + HeaderMetrics.BOTTOM * dp

    val blocks = ArrayList<Pagination.Block>(content.paragraphs.size + 1)
    blocks += Pagination.Block.single(0, header)
    val layouts = ArrayList<TextLayoutResult>(content.paragraphs.size)
    var offset = 0
    content.paragraphs.forEachIndexed { i, text ->
        coroutineContext.ensureActive()
        val layout = measurer.measure(text, spec.body, constraints = constraints)
        layouts += layout
        val n = layout.lineCount
        val tops = FloatArray(n) { layout.getLineTop(it) }
        val bottoms = FloatArray(n) { layout.getLineBottom(it) }
        val starts = IntArray(n) { layout.getLineStart(it) }
        // The scroll reader's bottom padding after every paragraph.
        blocks += Pagination.Block(offset, tops, bottoms, starts, spacingAfter = if (i < content.paragraphs.size - 1) spec.gapPx else 0f)
        offset += text.length
    }
    return MeasuredChapter(Pagination.paginate(blocks, spec.pageHeightPx), layouts, header, labelHeight, titleHeight)
}

/**
 * Paged reading: the chapter laid out into screen-sized pages with the
 * current typography. Taps on the outer thirds, swipes and the volume keys
 * turn pages; the middle third toggles the controls. Going past the last page
 * opens the next chapter; going back from the first opens the previous one at
 * its last page.
 */
@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
internal fun PagedChapterText(
    content: ChapterContent,
    model: TextReaderViewModel,
    settings: ReaderSettings,
    colors: ReaderColors,
    body: TextStyle,
    titleStyle: TextStyle,
    onTap: () -> Unit,
    onBackToSeries: () -> Unit,
    onTurn: () -> Unit,
) {
    val density = LocalDensity.current
    val resolver = LocalFontFamilyResolver.current
    val direction = LocalLayoutDirection.current
    // Measure with exactly the style Text would use, so the heading's height matches.
    val local = LocalTextStyle.current
    val labelStyle = local.merge(MaterialTheme.typography.labelMedium)
    val bars = WindowInsets.systemBarsIgnoringVisibility.asPaddingValues()
    val topPad = bars.calculateTopPadding() + 28.dp
    val bottomPad = bars.calculateBottomPadding() + 44.dp
    val margin = settings.margins.paddingDp.dp

    BoxWithConstraints(Modifier.fillMaxSize()) {
        val column = min(maxWidth, settings.width.maxWidthDp.dp)
        val pageArea = maxHeight - topPad - bottomPad
        val spec = with(density) {
            PageSpec(
                chapterId = content.id,
                widthPx = (column - margin * 2).roundToPx(),
                pageHeightPx = pageArea.toPx().coerceAtLeast(1f),
                gapPx = (settings.fontSize * 0.9f * density.fontScale).dp.toPx(),
                // Colour doesn't change the layout: a theme switch must not re-paginate.
                body = local.merge(body).copy(color = Color.Unspecified),
                title = local.merge(titleStyle).copy(color = Color.Unspecified),
                label = labelStyle,
                density = density,
            )
        }
        var measured by remember(content.id) { mutableStateOf<MeasuredChapter?>(null) }
        var page by remember(content.id) { mutableIntStateOf(0) }

        LaunchedEffect(spec) {
            val started = SystemClock.elapsedRealtime()
            val result = withContext(Dispatchers.Default) { measureChapter(content, spec, resolver, direction) }
            if (BuildConfig.DEBUG) {
                Log.d("NovarrPaging", "chapter ${content.id}: ${content.paragraphs.size} paragraphs, ${content.paragraphs.sumOf { it.length }} chars -> ${result.pages.size} pages in ${SystemClock.elapsedRealtime() - started} ms")
            }
            measured = result
            // Same text position as before (never the same page number).
            page = model.pageForAnchor(result.pages)
        }

        val layout = measured
        if (layout == null) {
            CircularProgressIndicator(Modifier.align(Alignment.Center), color = colors.secondary)
            return@BoxWithConstraints
        }
        val textPages = layout.pages.size
        // With nowhere to go next, an end page offers the series' end-of-chapter actions.
        val endPage = content.next == null || content.next.downloaded == false
        val total = textPages + if (endPage) 1 else 0

        // A restore or "Go to latest" request lands on the page holding that position.
        LaunchedEffect(layout, model.scrollRequest) {
            if (model.takeRequest(layout.pages)) page = model.pageForAnchor(layout.pages)
        }

        fun show(index: Int) {
            // Reading on: the bars would cover the new page's first line.
            if (index != page) onTurn()
            page = index
            model.anchorOffset = if (index < textPages) layout.pages[index].startOffset else model.offsets.total
        }

        val turn by rememberUpdatedState { forward: Boolean ->
            if (forward) {
                if (page < total - 1) show(page + 1) else content.next?.let { model.open(it.id) }
            } else {
                if (page > 0) show(page - 1) else content.prev?.let { model.openAtEnd(it.id) }
            }
        }

        // Progress: same rules as scrolling; the last text page is the end of the chapter.
        val currentPages by rememberUpdatedState(layout.pages)
        LaunchedEffect(content.id) {
            if (!model.tracking) {
                delay(600)
                model.tracking = true
            }
            var lastPages: List<Pagination.Page>? = null
            snapshotFlow { page to currentPages }.distinctUntilChanged().collect { (index, pages) ->
                // The restored page, or one a re-pagination moved to, is not a new position:
                // reporting its first line would creep backwards. Only the end still counts.
                if (pages === lastPages) model.pageShown(index, pages) else model.endVisible(index >= pages.size - 1)
                lastPages = pages
            }
        }
        LaunchedEffect(page, textPages, total) { model.pageLabel = (page.coerceAtMost(textPages - 1) + 1) to textPages }
        DisposableEffect(Unit) { onDispose { model.pageLabel = null } }

        // Volume keys: down = next, up = previous.
        DisposableEffect(Unit) {
            val handler: (Boolean) -> Boolean = { forward -> turn(forward); true }
            VolumeKeys.handler = handler
            onDispose { if (VolumeKeys.handler === handler) VolumeKeys.handler = null }
        }

        val swipeThreshold = with(density) { 48.dp.toPx() }
        AnimatedContent(
            targetState = Shown(layout, page),
            transitionSpec = {
                val oldPage = initialState.page
                val newPage = targetState.page
                if (initialState.layout !== targetState.layout) {
                    ContentTransform(EnterTransition.None, ExitTransition.None)
                } else {
                    val forward = newPage > oldPage
                    (slideInHorizontally(tween(220)) { if (forward) it / 3 else -it / 3 } + fadeIn(tween(220))) togetherWith
                        (slideOutHorizontally(tween(220)) { if (forward) -it / 3 else it / 3 } + fadeOut(tween(160)))
                }
            },
            label = "page",
            modifier = Modifier
                .fillMaxSize()
                .pointerInput(Unit) {
                    detectTapGestures { at ->
                        when (TapZones.actionFor(at.x, size.width.toFloat())) {
                            TapAction.NEXT -> turn(true)
                            TapAction.PREVIOUS -> turn(false)
                            TapAction.TOGGLE_CONTROLS -> onTap()
                        }
                    }
                }
                .pointerInput(Unit) {
                    var dx = 0f
                    detectHorizontalDragGestures(
                        onDragStart = { dx = 0f },
                        onDragEnd = {
                            when (TapZones.swipeAction(dx, swipeThreshold)) {
                                TapAction.NEXT -> turn(true)
                                TapAction.PREVIOUS -> turn(false)
                                else -> Unit
                            }
                        },
                    ) { change, amount ->
                        change.consume()
                        dx += amount
                    }
                }
                .semantics {
                    customActions = listOf(
                        CustomAccessibilityAction("Next page") { turn(true); true },
                        CustomAccessibilityAction("Previous page") { turn(false); true },
                    )
                },
        ) { shown ->
            val index = shown.page
            Box(Modifier.fillMaxSize()) {
                if (index >= shown.layout.pages.size) {
                    Box(Modifier.fillMaxSize().padding(top = topPad, bottom = bottomPad).verticalScroll(rememberScrollState()), contentAlignment = Alignment.Center) {
                        Footer(content, model, colors, onBackToSeries, Modifier.width(column).padding(horizontal = margin))
                    }
                } else {
                    PageBody(content, shown.layout, shown.layout.pages[index], colors, titleStyle, Modifier.align(Alignment.TopCenter).padding(top = topPad).width(column).height(pageArea).padding(horizontal = margin))
                }
            }
        }

        // A quiet "12 / 48" at the foot of the page.
        Text(
            "${page.coerceAtMost(textPages - 1) + 1} / $textPages",
            color = colors.secondary.copy(alpha = 0.5f),
            style = MaterialTheme.typography.labelSmall,
            modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = bars.calculateBottomPadding() + 14.dp).clearAndSetSemantics { },
        )
    }
}

/** One page: the heading (first page only) and whole lines of paragraphs, drawn from the measured layouts. */
@Composable
private fun PageBody(content: ChapterContent, layout: MeasuredChapter, page: Pagination.Page, colors: ReaderColors, titleStyle: TextStyle, modifier: Modifier) {
    val density = LocalDensity.current
    val pageText = remember(page) {
        page.slices.filter { it.block > 0 }.joinToString("\n") { slice ->
            val l = layout.paragraphs[slice.block - 1]
            val text = l.layoutInput.text.text
            text.substring(l.getLineStart(slice.fromLine), l.getLineEnd(slice.toLine - 1))
        }
    }
    Box(modifier) {
        page.slices.firstOrNull { it.block == 0 }?.let { slice ->
            ChapterHeader(
                content, colors, titleStyle,
                Modifier.fillMaxWidth().offset(y = with(density) { slice.y.toDp() }).height(with(density) { slice.height.toDp() }),
            )
        }
        Canvas(Modifier.fillMaxSize().semantics { text = AnnotatedString(pageText) }) {
            page.slices.forEach { slice ->
                if (slice.block == 0) return@forEach
                val l = layout.paragraphs[slice.block - 1]
                val top = l.getLineTop(slice.fromLine)
                // Only this slice's lines: the rest of the paragraph is on other pages.
                clipRect(left = -size.width, top = slice.y, right = size.width * 2, bottom = slice.y + slice.height) {
                    translate(top = slice.y - top) { drawText(l, color = colors.text) }
                }
            }
        }
    }
}

/** Page turns by volume key, while a paged reader is on screen (see MainActivity). */
object VolumeKeys {
    @Volatile
    var handler: ((forward: Boolean) -> Boolean)? = null
}
