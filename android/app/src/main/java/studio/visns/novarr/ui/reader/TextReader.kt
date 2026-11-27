package studio.visns.novarr.ui.reader

import android.widget.Toast
import studio.visns.novarr.ui.theme.BrandFonts
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.EnterTransition
import androidx.compose.animation.ExitTransition
import androidx.compose.foundation.gestures.scrollBy
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.WindowInsetsSides
import androidx.compose.foundation.layout.asPaddingValues
import androidx.compose.foundation.layout.systemBarsIgnoringVisibility
import androidx.compose.foundation.layout.navigationBarsIgnoringVisibility
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.contextmenu.builder.item
import androidx.compose.foundation.text.contextmenu.data.TextContextMenuItem
import androidx.compose.foundation.text.contextmenu.data.TextContextMenuKeys
import androidx.compose.foundation.text.contextmenu.data.TextContextMenuSession
import androidx.compose.foundation.text.contextmenu.modifier.appendTextContextMenuComponents
import androidx.compose.foundation.text.contextmenu.modifier.filterTextContextMenuComponents
import androidx.compose.foundation.text.selection.DisableSelection
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.platform.ClipEntry
import androidx.compose.ui.platform.Clipboard
import androidx.compose.ui.platform.LocalClipboard
import androidx.compose.ui.platform.NativeClipboard
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.outlined.CloudDownload
import androidx.compose.material.icons.outlined.TextFields
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.runtime.withFrameNanos
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.em
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.SavedStateHandle
import androidx.lifecycle.createSavedStateHandle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.launch
import kotlin.math.roundToInt
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.ChapterContent
import studio.visns.novarr.api.ChapterRef
import studio.visns.novarr.api.Highlight
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.Highlights
import studio.visns.novarr.domain.LatestPosition
import studio.visns.novarr.domain.Pagination
import studio.visns.novarr.domain.ReadMark
import studio.visns.novarr.domain.TextOffsets
import studio.visns.novarr.domain.TextReadingMode
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.store.ReaderSettings
import studio.visns.novarr.store.WriteKind
import studio.visns.novarr.ui.ReaderRoute
import studio.visns.novarr.ui.components.BrandMark
import studio.visns.novarr.ui.components.CenteredNote
import studio.visns.novarr.ui.components.CheckForChaptersControl
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.LoadingIndicator
import studio.visns.novarr.domain.Eink
import studio.visns.novarr.domain.PageKeyMap
import studio.visns.novarr.ui.theme.LocalEink

class TextReaderViewModel(app: AppModel, route: ReaderRoute, saved: SavedStateHandle) :
    BaseReaderViewModel(app, route.novelId, route.chapterId, route.offerLatest, saved, lookup = route.lookupExcerpt.isNotBlank()) {
    var content by mutableStateOf<ChapterContent?>(null)
        private set

    /** The highlight a look-up opened at: the reader starts at this passage. */
    private val lookupExcerpt = route.lookupExcerpt

    /** The series' highlights: the server's list, else its offline copy. */
    private var seriesHighlights: List<Highlight> = emptyList()

    /** Highlights saved here and still in the write queue. */
    private var unsentHighlights: List<WriteKind.CreateHighlight> = emptyList()

    /** The open chapter prepared for matching excerpts (built once per chapter). */
    private var matchIndex: Pair<Int, Highlights.Index>? = null

    /** Characters to tint in the open chapter, by paragraph (Highlights.Index.ranges). */
    var highlightRanges by mutableStateOf<Map<Int, List<IntRange>>>(emptyMap())
        private set

    /** Character offsets of the open chapter's paragraphs. */
    var offsets = TextOffsets(emptyList())
        private set

    /**
     * Where the reader is: the character offset of the first visible line,
     * kept by both modes so a re-pagination, rotation or switch between
     * Scroll and Pages lands on the same text (never the same page number).
     */
    var anchorOffset = 0

    /** "12 / 48" while paged; null while scrolling. */
    var pageLabel by mutableStateOf<Pair<Int, Int>?>(null)

    /** Open the chapter with this id at its end (paging back from the next chapter). */
    private var openAtEndId: Int? = null

    override val itemCount: Int get() = content?.paragraphs?.size ?: 0
    override val shownChapterId: Int? get() = content?.id
    override val hasContent: Boolean get() = content?.hasText == true
    override val nextChapterId: Int? get() = content?.next?.id

    init {
        load()
        checkLatestPosition()
        loadHighlights()
    }

    override suspend fun loadChapter(id: Int) {
        content = null
        highlightRanges = emptyMap()
        val session = app.active
        val cached = session?.store?.chapter(id, novelId)
        var loaded: ChapterContent? = null
        var failure: ApiError? = null
        if (session != null) {
            try {
                var fresh = session.client.chapter(id)
                app.noteSuccess()
                if (fresh.novelId == 0) fresh = fresh.copy(novelId = novelId)
                // Unsent local progress for this chapter is newer than what the server knows.
                if (cached != null && app.hasUnsentWrite(id)) {
                    fresh = fresh.copy(readProgress = cached.readProgress, read = fresh.read || cached.read)
                }
                session.store.saveChapter(fresh)
                loaded = fresh
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                failure = ApiError.from(e)
                app.noteFailure(failure)
            }
        }
        if (id != chapterId) return // the user moved on meanwhile

        showingCache = loaded == null && cached != null
        var chapter = loaded ?: cached ?: run {
            phase = ReaderPhase.Failed(failure?.userMessage ?: "This chapter isn't available.")
            return
        }
        val forced = takeForcedProgress(id)
        if (forced != null) {
            // Go to latest: the other device's position wins over anything saved here.
            chapter = chapter.copy(readProgress = forced)
            session?.store?.updateChapterState(id, novelId, forced, null)
        }
        offsets = TextOffsets(chapter.paragraphs.map { it.length })
        content = chapter
        updateHighlightRanges()
        val toEnd = forced == null && openAtEndId == id && chapter.paragraphs.isNotEmpty()
        openAtEndId = null
        // A look-up starts at its passage (when it can be found) instead of the saved position.
        val passage = if (lookingUp && forced == null) matcher(chapter).locate(lookupExcerpt)?.first() else null
        val passageOffset = passage?.let { offsets.paragraphStart(it.paragraph) + it.start }
        val restore = when {
            passage != null -> passage.paragraph
            forced != null -> LatestPosition.itemIndex(forced, chapter.paragraphs.size)
            toEnd -> chapter.paragraphs.size - 1
            chapter.readProgress != null && chapter.readProgress < 100 -> LatestPosition.itemIndex(chapter.readProgress, chapter.paragraphs.size)
            else -> null
        }
        anchorOffset = passageOffset ?: restore?.let(offsets::paragraphStart) ?: 0
        if (chapter.downloaded) app.downloads.contentChanged()
        prefetch(chapter.next)
        loaded(chapter.read, chapter.readProgress, restore, toEnd, restoreOffset = passageOffset)
    }

    // Highlights

    private fun matcher(chapter: ChapterContent): Highlights.Index =
        matchIndex?.takeIf { it.first == chapter.id }?.second
            ?: Highlights.Index(chapter.paragraphs).also { matchIndex = chapter.id to it }

    /** Re-matches the open chapter's highlights (after the chapter or the list changed). */
    private fun updateHighlightRanges() {
        val chapter = content?.takeIf { it.hasText } ?: return
        val excerpts = seriesHighlights.filter { it.chapterId == chapter.id }.map { it.excerpt } +
            unsentHighlights.filter { it.chapterId == chapter.id }.map { it.excerpt }
        highlightRanges = if (excerpts.isEmpty()) emptyMap() else matcher(chapter).ranges(excerpts)
    }

    /**
     * The series' highlights, once per reader: the offline copy first, then
     * the server's list (saved as the new offline copy). There is no chapter
     * filter on the server, so the whole series is fetched and each chapter
     * picks its own. Failing (offline, an older server) keeps the copy.
     */
    private fun loadHighlights() = viewModelScope.launch {
        val session = app.active ?: return@launch
        val key = OfflineStore.Key.highlights(novelId)
        session.store.load(key)?.let { seriesHighlights = it }
        unsentHighlights = app.unsentHighlights(novelId)
        updateHighlightRanges()
        val fresh = try {
            buildList<Highlight> {
                var page = 1
                do {
                    val answer = session.client.highlights(novelId, page, HIGHLIGHTS_PER_PAGE)
                    addAll(answer.highlights)
                    page++
                } while (answer.hasMore && page <= HIGHLIGHTS_MAX_PAGES)
            }
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            return@launch
        }
        session.store.save(fresh, key)
        seriesHighlights = fresh
        // What was still queued a moment ago may have been sent and be in the list now.
        unsentHighlights = app.unsentHighlights(novelId)
        updateHighlightRanges()
    }

    /**
     * Saves a highlight of the open chapter through the write queue (works
     * offline, survives restarts) and tints it straight away. The excerpt and
     * note are clipped to the server's limits. False when there is nothing to save.
     */
    fun saveHighlight(excerpt: String, note: String): Boolean {
        val chapter = content?.takeIf { it.hasText } ?: return false
        val text = Highlights.clip(excerpt)
        if (text.isEmpty()) return false
        val write = WriteKind.CreateHighlight(chapter.id, text, Highlights.clip(note).ifEmpty { null })
        unsentHighlights = unsentHighlights + write
        updateHighlightRanges()
        val session = app.active ?: return true
        app.scope.launch {
            // Also into the offline copy, so it is still drawn offline after the queue has sent it.
            val key = OfflineStore.Key.highlights(novelId)
            val provisional = Highlight(
                id = 0, novelId = novelId, novelName = chapter.novelName, chapterId = chapter.id,
                chapterLabel = chapter.title, excerpt = write.excerpt, note = write.note,
            )
            session.store.save(session.store.load(key).orEmpty() + provisional, key)
            app.record(write, novelId)
        }
        return true
    }

    /** Opens chapter [id] at its last page (paging back past the first page). */
    fun openAtEnd(id: Int) {
        openAtEndId = id
        open(id)
    }

    /** The page showing [anchorOffset]. */
    fun pageForAnchor(pages: List<Pagination.Page>): Int = Pagination.pageFor(pages, anchorOffset)

    /** Applies a pending restore / "Go to latest" / look-up to the anchor; true when there was one. */
    fun takeRequest(pages: List<Pagination.Page>): Boolean {
        val request = takeScrollRequest() ?: return false
        anchorOffset = when {
            request.offset != null -> request.offset
            request.toEnd -> pages.lastOrNull()?.startOffset ?: 0
            else -> offsets.paragraphStart(request.index)
        }
        return true
    }

    /** A page is on screen: its first line is the position; the last page is the end of the chapter. */
    fun pageShown(index: Int, pages: List<Pagination.Page>) {
        val last = index >= pages.size - 1
        endVisible(last)
        if (!last) moved(offsets.paragraphAt(pages[index].startOffset))
    }

    override fun onMarkedRead() {
        content = content?.copy(read = true, readProgress = 100)
    }

    /** Fetches the next chapter in the background so it's ready (and offline). */
    private fun prefetch(ref: ChapterRef?) {
        if (ref == null || ref.downloaded == false) return
        val session = app.active ?: return
        app.scope.launch {
            if (session.store.hasChapter(ref.id, novelId)) return@launch
            runCatching { session.client.chapter(ref.id) }.getOrNull()?.let {
                session.store.saveChapter(if (it.novelId == 0) it.copy(novelId = novelId) else it)
            }
        }
    }

    /** Re-reads only the `next` link after a check found new chapters, leaving the text alone. */
    fun refreshNext() = viewModelScope.launch {
        val current = content ?: return@launch
        if (current.next != null && current.next.downloaded != false) return@launch
        val fresh = runCatching { app.active?.client?.chapter(current.id) }.getOrNull() ?: return@launch
        val latest = content ?: return@launch
        if (latest.id == fresh.id && fresh.next != latest.next) {
            content = latest.copy(next = fresh.next)
            prefetch(fresh.next)
        }
    }
}

private const val HEADER_ITEMS = 1

/** The end of a scrolled chapter is in view: its footer, or the bottom of 95% of its paragraphs (ReadMark). */
private fun endInView(list: androidx.compose.foundation.lazy.LazyListState, footerIndex: Int, paragraphCount: Int, hasText: Boolean): Boolean {
    val info = list.layoutInfo
    if (info.visibleItemsInfo.any { it.index == footerIndex }) return true
    if (!hasText) return false
    val lastFull = info.visibleItemsInfo
        .filter { it.index in HEADER_ITEMS until HEADER_ITEMS + paragraphCount && it.offset + it.size <= info.viewportEndOffset + 2 }
        .maxOfOrNull { it.index - HEADER_ITEMS } ?: -1
    return ReadMark.textEndReached(lastFull, paragraphCount)
}

/** The highlights list is read in pages of this size (the server's maximum), up to this many pages. */
private const val HIGHLIGHTS_PER_PAGE = 200
private const val HIGHLIGHTS_MAX_PAGES = 10

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TextReaderScreen(nav: NavController, route: ReaderRoute) {
    val app = LocalApp.current
    val model = viewModel { TextReaderViewModel(app, route, createSavedStateHandle()) }
    val settings by app.prefs.readerSettings.collectAsStateWithLifecycle(ReaderSettings())
    val eink = LocalEink.current
    val colors = if (eink) ReaderColors.Eink else settings.theme.colors()
    var chromeHidden by rememberSaveable { mutableStateOf(false) }
    var showSettings by remember { mutableStateOf(false) }
    /** The passage the highlight sheet is open for. */
    var highlightDraft by rememberSaveable { mutableStateOf<String?>(null) }
    val context = LocalContext.current
    val checkRevisions by app.checks.revisions.collectAsStateWithLifecycle()

    LifecycleEventEffect(Lifecycle.Event.ON_STOP) { model.stopped() }
    LifecycleEventEffect(Lifecycle.Event.ON_START) { model.started() }
    LaunchedEffect(checkRevisions[model.novelId]) { if (checkRevisions[model.novelId] != null) model.refreshNext() }
    ReaderSystemBars(hidden = chromeHidden, darkSurface = colors.isDark, keepScreenOn = settings.keepScreenOn)

    Box(Modifier.fillMaxSize().background(colors.background)) {
        when (val phase = model.phase) {
            ReaderPhase.Loading -> LoadingIndicator(Modifier.align(Alignment.Center), color = colors.secondary)
            is ReaderPhase.Failed -> Failure(phase.message, colors) { model.load() }
            ReaderPhase.Loaded -> model.content?.let { content ->
                ChapterText(
                    content, model, settings, colors,
                    onTap = { chromeHidden = !chromeHidden },
                    onBackToSeries = { nav.popBackStack() },
                    // Only a write when the bars are showing: no extra redraw per turn.
                    onTurn = { if (!chromeHidden) chromeHidden = true },
                    onHighlight = { highlightDraft = it },
                )
            }
        }

        AnimatedVisibility(
            !chromeHidden,
            enter = if (eink) EnterTransition.None else fadeIn() + slideInVertically(),
            exit = if (eink) ExitTransition.None else fadeOut() + slideOutVertically(),
            modifier = Modifier.align(Alignment.TopCenter),
        ) {
            ReaderTopBar(model.content?.title.orEmpty(), model.content?.novelName.orEmpty(), colors, showingCache = model.showingCache) { nav.popBackStack() }
        }
        Column(Modifier.align(Alignment.BottomCenter).fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            model.latestOffer?.let { offer ->
                LatestPositionBanner(offer.message, colors, onGo = model::acceptLatest, onStay = model::declineLatest, modifier = Modifier.navigationBarsPadding())
            }
            AnimatedVisibility(
                !chromeHidden,
                enter = if (eink) EnterTransition.None else fadeIn() + slideInVertically { it },
                exit = if (eink) ExitTransition.None else fadeOut() + slideOutVertically { it },
            ) {
                ReaderBottomBar(
                    colors,
                    prev = model.content?.prev,
                    next = model.content?.next,
                    onOpen = { model.open(it.id) },
                    middle = {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            model.pageLabel?.let { (page, total) ->
                                Text(
                                    "$page / $total",
                                    color = colors.secondary,
                                    style = MaterialTheme.typography.labelLarge.copy(fontFamily = BrandFonts.GeistMono),
                                    modifier = Modifier.padding(end = 4.dp).semantics { contentDescription = "Page $page of $total" },
                                )
                            }
                            IconButton(onClick = { showSettings = true }) {
                                Icon(Icons.Outlined.TextFields, contentDescription = "Reading settings", tint = colors.text)
                            }
                        }
                    },
                )
            }
        }
    }

    highlightDraft?.let { draft ->
        HighlightSheet(draft, onDismiss = { highlightDraft = null }) { excerpt, note ->
            highlightDraft = null
            if (model.saveHighlight(excerpt, note)) {
                val message = if (app.isReachable.value) "Highlight saved." else "Highlight saved. It syncs when you're back online."
                Toast.makeText(context, message, Toast.LENGTH_SHORT).show()
            }
        }
    }

    if (showSettings) {
        ModalBottomSheet(onDismissRequest = { showSettings = false }, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = false)) {
            Column(Modifier.verticalScroll(rememberScrollState()).padding(horizontal = 20.dp).padding(bottom = 24.dp)) {
                Text("Reading", style = MaterialTheme.typography.titleLarge, modifier = Modifier.padding(bottom = 16.dp))
                ReaderSettingsForm()
            }
        }
    }
}

@Composable
private fun ChapterText(
    content: ChapterContent,
    model: TextReaderViewModel,
    settings: ReaderSettings,
    colors: ReaderColors,
    onTap: () -> Unit,
    onBackToSeries: () -> Unit,
    onTurn: () -> Unit,
    onHighlight: (String) -> Unit,
) {
    val size = settings.fontSize.sp
    // Part of the style, so the paged layout re-measures when it changes.
    val bolder = Eink.bolderText(settings.bolder, LocalEink.current)
    val body = TextStyle(
        fontFamily = settings.font.family(),
        fontWeight = if (bolder) FontWeight.SemiBold else null,
        fontSize = size,
        lineHeight = (settings.spacing.lineHeight).em,
        color = colors.text,
        textAlign = if (settings.justify) TextAlign.Justify else TextAlign.Start,
    )
    val title = body.copy(fontSize = size * 1.45f, lineHeight = 1.25.em, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Start)
    if (settings.mode == TextReadingMode.PAGES && content.hasText) {
        PagedChapterText(content, model, settings, colors, body, title, onTap, onBackToSeries, onTurn, onHighlight)
    } else {
        ScrollChapterText(content, model, settings, colors, body, title, onTap, onBackToSeries, onHighlight)
    }
}

/**
 * Continuous scrolling. The text is selectable (long-press), and the
 * selection toolbar offers "Highlight" after the usual Copy; each paragraph
 * stays its own Text, so TalkBack still reads paragraph by paragraph, and a
 * tap (no selection) still shows or hides the bars.
 */
@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
private fun ScrollChapterText(
    content: ChapterContent,
    model: TextReaderViewModel,
    settings: ReaderSettings,
    colors: ReaderColors,
    body: TextStyle,
    titleStyle: TextStyle,
    onTap: () -> Unit,
    onBackToSeries: () -> Unit,
    onHighlight: (String) -> Unit,
) {
    val list = rememberLazyListState()
    // Follow the system font scale on top of the chosen size.
    val fontScale = LocalDensity.current.fontScale
    val paragraphCount = content.paragraphs.size
    val footerIndex = HEADER_ITEMS + (if (content.hasText) paragraphCount else 1)
    // Each paragraph's last layout, to turn the scroll position into a character offset.
    val layouts = remember(content.id) { HashMap<Int, TextLayoutResult>() }
    var anchorLive by remember(content.id) { mutableStateOf(false) }

    // Restore (or "Go to latest" within the chapter), then start tracking.
    LaunchedEffect(content.id, model.scrollRequest) {
        val request = model.takeScrollRequest()
        if (request != null) {
            val paragraph = request.index.coerceIn(0, (paragraphCount - 1).coerceAtLeast(0))
            list.scrollToItem(HEADER_ITEMS + paragraph)
            model.anchorOffset = request.offset ?: model.offsets.paragraphStart(request.index)
            // A passage inside a long paragraph: bring its line to the top.
            val inParagraph = (request.offset ?: 0) - model.offsets.paragraphStart(paragraph)
            if (request.offset != null && inParagraph > 0) {
                withFrameNanos { }
                layouts[paragraph]?.let { l ->
                    val char = inParagraph.coerceIn(0, l.layoutInput.text.length)
                    list.scrollToItem(HEADER_ITEMS + paragraph, l.getLineTop(l.getLineForOffset(char)).roundToInt())
                }
            }
        } else if (!anchorLive && model.anchorOffset > 0 && content.hasText) {
            // Back from Pages, or a re-created screen: the same line as before.
            val anchor = model.anchorOffset
            val paragraph = model.offsets.paragraphAt(anchor)
            list.scrollToItem(HEADER_ITEMS + paragraph)
            withFrameNanos { }
            layouts[paragraph]?.let { l ->
                val char = (anchor - model.offsets.paragraphStart(paragraph)).coerceIn(0, l.layoutInput.text.length)
                list.scrollToItem(HEADER_ITEMS + paragraph, l.getLineTop(l.getLineForOffset(char)).roundToInt())
            }
        }
        anchorLive = true
        if (!model.tracking) {
            delay(600)
            model.tracking = true
        }
        // Short chapters show the end straight away.
        if (endInView(list, footerIndex, paragraphCount, content.hasText)) model.endVisible(true)
    }
    LaunchedEffect(content.id) {
        snapshotFlow { list.firstVisibleItemIndex }
            .distinctUntilChanged()
            .collect { index -> model.moved((index - HEADER_ITEMS).coerceIn(0, (paragraphCount - 1).coerceAtLeast(0))) }
    }
    LaunchedEffect(content.id) {
        snapshotFlow { if (anchorLive) list.firstVisibleItemIndex to list.firstVisibleItemScrollOffset else null }
            .collect { position ->
                val (index, offset) = position ?: return@collect
                val paragraph = index - HEADER_ITEMS
                model.anchorOffset = when {
                    !content.hasText || paragraph < 0 -> 0
                    paragraph >= paragraphCount -> model.offsets.paragraphStart(paragraphCount - 1)
                    else -> model.offsets.paragraphStart(paragraph) +
                        (layouts[paragraph]?.let { l ->
                            // The first line fully on screen (not one half-hidden under the top edge).
                            var line = l.getLineForVerticalPosition(offset.toFloat())
                            if (l.getLineTop(line) < offset - 1 && line < l.lineCount - 1) line++
                            l.getLineStart(line)
                        } ?: 0)
                }
            }
    }
    // The end: the footer on screen, or the bottom of 95% of the paragraphs (ReadMark).
    LaunchedEffect(content.id) {
        snapshotFlow { endInView(list, footerIndex, paragraphCount, content.hasText) }
            .distinctUntilChanged()
            .collect { visible -> model.endVisible(visible) }
    }

    // Page keys: a screen at a time, less a line or so; past either end, the next or previous chapter (as Pages does).
    val keyScope = rememberCoroutineScope()
    val density = LocalDensity.current
    val overlapPx = with(density) { maxOf(48.dp.toPx(), (settings.fontSize * settings.spacing.lineHeight * 1.5f).sp.toPx()) }.roundToInt()
    RegisterPageKeys(novelPaged = false) { forward ->
        when {
            forward && !list.canScrollForward -> content.next?.let { model.open(it.id) }
            !forward && !list.canScrollBackward -> content.prev?.let { model.openAtEnd(it.id) }
            else -> keyScope.launch {
                val step = PageKeyMap.scrollStep(list.layoutInfo.viewportSize.height, overlapPx).toFloat()
                list.scrollBy(if (forward) step else -step)
            }
        }
    }

    val margin = settings.margins.paddingDp.dp
    // Room for the reader's bars; stable while the system bars hide and show.
    val bars = WindowInsets.systemBarsIgnoringVisibility.asPaddingValues()
    val highlightRanges = model.highlightRanges
    val tint = colors.highlight
    // The selection's text is only handed out by the toolbar's own Copy (this
    // Compose version has no public selection state), so "Highlight" runs
    // that Copy against a clipboard that hands the text over instead of keeping it.
    val systemClipboard = LocalClipboard.current
    val clipboardScope = rememberCoroutineScope()
    val clipboard = remember(systemClipboard) { HighlightingClipboard(systemClipboard, clipboardScope) }
    val highlightSelection by rememberUpdatedState(onHighlight)
    CompositionLocalProvider(LocalClipboard provides clipboard) {
        SelectionContainer(
            Modifier
                .filterTextContextMenuComponents { component ->
                    // Keeps everything; only notes the current menu's Copy.
                    if (component is TextContextMenuItem && component.key == TextContextMenuKeys.CopyKey) clipboard.copyItem = component
                    true
                }
                .appendTextContextMenuComponents {
                    item(HighlightMenuItem, "Highlight") {
                        clipboard.highlight(this) { text -> text.trim().takeIf { it.isNotEmpty() }?.let(highlightSelection) }
                        close()
                    }
                },
        ) {
            LazyColumn(
                state = list,
                modifier = Modifier
                    .fillMaxSize()
                    .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClickLabel = "Show or hide controls", onClick = onTap),
                contentPadding = PaddingValues(top = bars.calculateTopPadding() + 64.dp, bottom = bars.calculateBottomPadding() + 96.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                item(key = "header") {
                    DisableSelection {
                        ChapterHeader(content, colors, titleStyle, Modifier.widthIn(max = settings.width.maxWidthDp.dp).fillMaxWidth().padding(horizontal = margin))
                    }
                }
                if (content.hasText) {
                    itemsIndexed(content.paragraphs, key = { index, _ -> index }) { index, paragraph ->
                        val ranges = highlightRanges[index]
                        val text = remember(paragraph, ranges, tint) { highlighted(paragraph, ranges, tint) }
                        // One node per paragraph, so TalkBack reads (and resumes) paragraph by paragraph.
                        Text(
                            text,
                            style = body,
                            onTextLayout = { layouts[index] = it },
                            modifier = Modifier
                                .widthIn(max = settings.width.maxWidthDp.dp)
                                .fillMaxWidth()
                                .padding(horizontal = margin)
                                .padding(bottom = (settings.fontSize * 0.9f * fontScale).dp),
                        )
                    }
                } else {
                    item(key = "missing") { DisableSelection { NotDownloaded(colors) } }
                }
                item(key = "footer") {
                    DisableSelection {
                        Footer(content, model, colors, onBackToSeries, Modifier.widthIn(max = settings.width.maxWidthDp.dp).padding(horizontal = margin))
                    }
                }
            }
        }
    }
}

/**
 * The system clipboard, except while [highlight] runs the selection's Copy
 * ([copyItem], noted as the menu is built): the text it puts here goes to
 * that callback instead (once), so the clipboard is left alone.
 */
private class HighlightingClipboard(private val system: Clipboard, private val scope: CoroutineScope) : Clipboard {
    var copyItem: TextContextMenuItem? = null
    private var capture: ((String) -> Unit)? = null

    fun highlight(session: TextContextMenuSession, onText: (String) -> Unit) {
        val copy = copyItem ?: return
        capture = onText
        copy.onClick(session)
        // Copy writes asynchronously; should it write nothing, a later real Copy must still reach the clipboard.
        scope.launch {
            delay(1_000)
            if (capture === onText) capture = null
        }
    }

    override suspend fun getClipEntry(): ClipEntry? = system.getClipEntry()

    override suspend fun setClipEntry(clipEntry: ClipEntry?) {
        val onText = capture ?: return system.setClipEntry(clipEntry)
        capture = null
        val clip = clipEntry?.clipData
        val text = if (clip != null && clip.itemCount > 0) clip.getItemAt(0).text?.toString() else null
        if (text != null) onText(text)
    }

    override val nativeClipboard: NativeClipboard get() = system.nativeClipboard
}

/** Identifies the reader's "Highlight" entry in the text selection toolbar. */
private object HighlightMenuItem

/** [paragraph] with [ranges] (highlighted characters) tinted. */
internal fun highlighted(paragraph: String, ranges: List<IntRange>?, tint: Color): AnnotatedString {
    if (ranges.isNullOrEmpty()) return AnnotatedString(paragraph)
    return buildAnnotatedString {
        append(paragraph)
        ranges.forEach { range ->
            val end = (range.last + 1).coerceAtMost(paragraph.length)
            if (range.first in 0 until end) addStyle(SpanStyle(background = tint), range.first, end)
        }
    }
}

/** Series name, chapter title and a short rule; the paged layout measures the same pieces (HeaderMetrics). */
@Composable
internal fun ChapterHeader(content: ChapterContent, colors: ReaderColors, titleStyle: TextStyle, modifier: Modifier) {
    Column(
        modifier.padding(top = HeaderMetrics.TOP.dp, bottom = HeaderMetrics.BOTTOM.dp).semantics(mergeDescendants = true) { heading() },
        verticalArrangement = Arrangement.spacedBy(HeaderMetrics.GAP.dp),
    ) {
        if (content.novelName.isNotEmpty()) {
            Text(content.novelName.uppercase(), style = MaterialTheme.typography.labelMedium, color = colors.secondary, maxLines = 2)
        }
        Text(content.title, style = titleStyle, color = colors.text)
        Box(Modifier.padding(top = 4.dp).width(36.dp).height(3.dp).background(MaterialTheme.colorScheme.primary))
    }
}

@Composable
private fun NotDownloaded(colors: ReaderColors) {
    Column(
        Modifier.fillMaxWidth().padding(horizontal = 32.dp, vertical = 60.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Icon(Icons.Outlined.CloudDownload, contentDescription = null, tint = colors.secondary, modifier = Modifier.size(40.dp))
        Text("Not downloaded yet", style = MaterialTheme.typography.titleMedium, color = colors.text)
        Text(
            "Your Novarr server hasn't fetched this chapter yet. It will be readable here once the server downloads it.",
            style = MaterialTheme.typography.bodyMedium,
            color = colors.secondary,
            textAlign = TextAlign.Center,
        )
    }
}

@Composable
internal fun Footer(content: ChapterContent, model: BaseReaderViewModel, colors: ReaderColors, onBackToSeries: () -> Unit, modifier: Modifier) {
    Column(modifier.fillMaxWidth().padding(top = 16.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(16.dp)) {
        if (content.hasText) BrandMark(Modifier.width(22.dp).padding(top = 24.dp), color = if (colors.eink) colors.secondary else colors.secondary.copy(alpha = 0.5f))
        ChapterButtons(content.prev, content.next, colors) { model.open(it.id) }
        val next = content.next
        when {
            next != null -> CenteredNote(next.title + if (next.downloaded == false) " · not downloaded yet" else "", colors.secondary)
            content.downloaded -> {
                if (model.isBook) {
                    CenteredNote("The end.", colors.secondary)
                } else {
                    CenteredNote("You're all caught up.", colors.secondary)
                    CheckForChaptersControl(model.novelId, compact = true, tint = colors.secondary)
                }
                TextButton(onClick = onBackToSeries) { Text("Back to series") }
            }
        }
    }
}

@Composable
fun ChapterButtons(prev: ChapterRef?, next: ChapterRef?, colors: ReaderColors, onOpen: (ChapterRef) -> Unit) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
        prev?.let {
            Button(
                onClick = { onOpen(it) },
                colors = ButtonDefaults.buttonColors(containerColor = colors.quietFill, contentColor = colors.text),
                border = colors.rule,
                shape = RoundedCornerShape(12.dp),
                modifier = Modifier.weight(1f).heightIn(min = 48.dp),
            ) {
                Icon(Icons.AutoMirrored.Filled.KeyboardArrowLeft, contentDescription = null)
                Text("Previous")
            }
        }
        next?.let {
            Button(onClick = { onOpen(it) }, shape = RoundedCornerShape(12.dp), modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                Text("Next chapter")
                Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = null)
            }
        }
    }
}

@Composable
fun ReaderTopBar(
    title: String,
    subtitle: String,
    colors: ReaderColors,
    showingCache: Boolean = false,
    actions: @Composable () -> Unit = {},
    onBack: () -> Unit,
) {
    // E-ink: a 1 dp black rule instead of a shadow.
    Surface(color = colors.background, contentColor = colors.text, shadowElevation = if (colors.eink) 0.dp else 3.dp, modifier = Modifier.fillMaxWidth()) {
        Column {
            Row(
                Modifier.windowInsetsPadding(WindowInsets.safeDrawing.only(WindowInsetsSides.Top + WindowInsetsSides.Horizontal))
                    .heightIn(min = 56.dp)
                    .padding(end = 16.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back", tint = colors.text) }
                Column(Modifier.weight(1f)) {
                    Text(title, color = colors.text, style = MaterialTheme.typography.titleMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    val sub = listOfNotNull(subtitle.takeIf { it.isNotBlank() }, "offline copy".takeIf { showingCache }).joinToString(" · ")
                    if (sub.isNotEmpty()) Text(sub, color = colors.secondary, style = MaterialTheme.typography.labelMedium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                actions()
            }
            if (colors.eink) HorizontalDivider(thickness = 1.dp, color = colors.text)
        }
    }
}

@OptIn(androidx.compose.foundation.layout.ExperimentalLayoutApi::class)
@Composable
fun ReaderBottomBar(colors: ReaderColors, prev: ChapterRef?, next: ChapterRef?, onOpen: (ChapterRef) -> Unit, middle: @Composable () -> Unit) {
    Surface(color = colors.background, contentColor = colors.text, shadowElevation = if (colors.eink) 0.dp else 3.dp, modifier = Modifier.fillMaxWidth()) {
        Column {
            if (colors.eink) HorizontalDivider(thickness = 1.dp, color = colors.text)
            Row(
                // E-ink: the final inset straight away, not one that follows the system bars sliding in.
                Modifier
                    .then(if (colors.eink) Modifier.windowInsetsPadding(WindowInsets.navigationBarsIgnoringVisibility) else Modifier.navigationBarsPadding())
                    .heightIn(min = 56.dp)
                    .padding(horizontal = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                // E-ink: a disabled arrow in opaque light grey rather than a faded black.
                val disabled = if (colors.eink) Color(0xFFBBBBBB) else colors.text.copy(alpha = 0.3f)
                val iconColors = IconButtonDefaults.iconButtonColors(contentColor = colors.text, disabledContentColor = disabled)
                IconButton(onClick = { prev?.let(onOpen) }, enabled = prev != null, colors = iconColors) {
                    Icon(Icons.AutoMirrored.Filled.KeyboardArrowLeft, contentDescription = "Previous chapter")
                }
                Spacer(Modifier.weight(1f))
                middle()
                Spacer(Modifier.weight(1f))
                IconButton(onClick = { next?.let(onOpen) }, enabled = next != null, colors = iconColors) {
                    Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = "Next chapter")
                }
            }
        }
    }
}

@Composable
fun Failure(message: String, colors: ReaderColors, onRetry: () -> Unit) {
    Column(
        Modifier.fillMaxSize().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text("Couldn't open this chapter", style = MaterialTheme.typography.titleLarge, color = colors.text, textAlign = TextAlign.Center)
        Spacer(Modifier.height(8.dp))
        Text(message, style = MaterialTheme.typography.bodyMedium, color = colors.secondary, textAlign = TextAlign.Center)
        Spacer(Modifier.height(16.dp))
        Button(onClick = onRetry) { Text("Try again") }
    }
}
