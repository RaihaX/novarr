package studio.visns.novarr.ui.reader

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.snapshotFlow
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import coil3.compose.SubcomposeAsyncImage
import java.io.File
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.distinctUntilChanged
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.ChapterRef
import studio.visns.novarr.api.MangaPage
import studio.visns.novarr.api.MangaPages
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.LatestPosition
import studio.visns.novarr.domain.ReadingMode
import studio.visns.novarr.store.ReaderTheme
import studio.visns.novarr.ui.ReaderRoute
import studio.visns.novarr.ui.components.CenteredNote
import studio.visns.novarr.ui.components.CheckForChaptersControl
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.OfflinePill

class MangaReaderViewModel(app: AppModel, route: ReaderRoute) :
    BaseReaderViewModel(app, route.novelId, route.chapterId, route.offerLatest) {
    var pages by mutableStateOf<MangaPages?>(null)
        private set

    /** Downloaded page images for the open chapter, by page number. */
    var localFiles by mutableStateOf<Map<Int, File>>(emptyMap())
        private set

    /** The page shown, for the "3 / 12" indicator. */
    var currentPage by mutableStateOf(0)

    override val itemCount: Int get() = pages?.pages?.size ?: 0
    override val shownChapterId: Int? get() = pages?.chapter?.id
    override val hasContent: Boolean get() = itemCount > 0

    val mode: ReadingMode get() = ReadingMode.from(pages?.readingMode)

    init {
        load()
        checkLatestPosition()
    }

    override suspend fun loadChapter(id: Int) {
        pages = null
        currentPage = 0
        val session = app.active
        val cached = session?.store?.mangaChapter(id, novelId)
        var loaded: MangaPages? = null
        var failure: ApiError? = null
        if (session != null) {
            try {
                var fresh = session.client.mangaPages(id)
                app.noteSuccess()
                if (app.pendingWrites.value > 0 && cached != null) {
                    fresh = fresh.copy(chapter = fresh.chapter.copy(progress = cached.chapter.progress, read = fresh.chapter.read || cached.chapter.read))
                }
                loaded = fresh
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                failure = ApiError.from(e)
                app.noteFailure(failure)
            }
        }
        if (id != chapterId) return
        showingCache = loaded == null && cached != null
        var chapter = loaded ?: cached ?: run {
            phase = ReaderPhase.Failed(failure?.userMessage ?: "This chapter isn't available.")
            return
        }
        localFiles = session?.store?.let { store ->
            chapter.pages.mapNotNull { page -> store.pageFile(novelId, id, page.page).takeIf { it.exists() }?.let { page.page to it } }.toMap()
        }.orEmpty()
        val forced = takeForcedProgress(id)
        if (forced != null) {
            chapter = chapter.copy(chapter = chapter.chapter.copy(progress = forced))
            session?.store?.updateChapterState(id, novelId, forced, null)
        }
        pages = chapter
        val progress = forced ?: chapter.chapter.progress
        val restore = if (progress != null && (forced != null || progress < 100)) LatestPosition.itemIndex(progress, chapter.pages.size) else null
        currentPage = restore ?: 0
        loaded(chapter.chapter.read, chapter.chapter.progress, restore)
    }

    override fun onMarkedRead() {
        pages = pages?.let { it.copy(chapter = it.chapter.copy(read = true, progress = 100)) }
    }

    /** The image to load: the downloaded copy, else the pre-signed URL exactly as the API gave it. */
    fun model(page: MangaPage): Any = localFiles[page.page] ?: page.url

    fun pageShown(index: Int) {
        currentPage = index
        moved(index)
        if (index >= itemCount - 1) reachedEnd()
    }
}

@Composable
fun MangaReaderScreen(nav: NavController, route: ReaderRoute) {
    val app = LocalApp.current
    val model = viewModel { MangaReaderViewModel(app, route) }
    // Pages read best on black, whatever the text reader's theme.
    val colors = ReaderTheme.BLACK.colors().copy(text = Color(0xFFE6E6E6))
    var chromeHidden by rememberSaveable { mutableStateOf(false) }

    LifecycleEventEffect(Lifecycle.Event.ON_STOP) { model.leave() }
    ReaderSystemBars(hidden = chromeHidden, darkSurface = true, keepScreenOn = true)

    Box(Modifier.fillMaxSize().background(colors.background)) {
        when (val phase = model.phase) {
            ReaderPhase.Loading -> CircularProgressIndicator(Modifier.align(Alignment.Center), color = colors.secondary)
            is ReaderPhase.Failed -> Failure(phase.message, colors) { model.load() }
            ReaderPhase.Loaded -> model.pages?.let { pages ->
                if (pages.pages.isEmpty()) {
                    Column(Modifier.align(Alignment.Center).padding(32.dp)) {
                        CenteredNote("This chapter's pages haven't been downloaded on the server yet.", colors.secondary)
                    }
                } else if (model.mode.paged) {
                    PagedChapter(pages, model, colors, onTap = { chromeHidden = !chromeHidden }, onOpen = { model.open(it.id) })
                } else {
                    VerticalChapter(pages, model, colors, onTap = { chromeHidden = !chromeHidden }, onOpen = { model.open(it.id) })
                }
            }
        }

        AnimatedVisibility(!chromeHidden, enter = fadeIn() + slideInVertically(), exit = fadeOut() + slideOutVertically(), modifier = Modifier.align(Alignment.TopCenter)) {
            ReaderTopBar(model.pages?.chapter?.title.orEmpty(), modeLabel(model), colors, model.showingCache) { nav.popBackStack() }
        }
        Column(Modifier.align(Alignment.BottomCenter).fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            model.latestOffer?.let { offer ->
                LatestPositionBanner(offer.message, colors, onGo = model::acceptLatest, onStay = model::declineLatest, modifier = Modifier.navigationBarsPadding())
            }
            AnimatedVisibility(!chromeHidden, enter = fadeIn() + slideInVertically { it }, exit = fadeOut() + slideOutVertically { it }) {
                ReaderBottomBar(colors, model.pages?.prev, model.pages?.next, onOpen = { model.open(it.id) }) {
                    val total = model.pages?.pages?.size ?: 0
                    if (total > 0) {
                        Text(
                            "${model.currentPage + 1} / $total",
                            color = colors.text,
                            style = MaterialTheme.typography.labelLarge,
                            modifier = Modifier.semantics { contentDescription = "Page ${model.currentPage + 1} of $total" },
                        )
                    }
                }
            }
        }
        if (model.showingCache && !chromeHidden) {
            Box(Modifier.align(Alignment.TopCenter).statusBarsPadding().padding(top = 64.dp)) { OfflinePill(text = "Offline · saved copy") }
        }
    }
}

private fun modeLabel(model: MangaReaderViewModel): String = when (model.mode) {
    ReadingMode.RIGHT_TO_LEFT -> "Right to left"
    ReadingMode.LEFT_TO_RIGHT -> "Left to right"
    ReadingMode.VERTICAL -> "Vertical scroll"
}

/** Paged reading (ltr / rtl): one page per screen, pinch to zoom, and a last page with chapter links. */
@Composable
private fun PagedChapter(pages: MangaPages, model: MangaReaderViewModel, colors: ReaderColors, onTap: () -> Unit, onOpen: (ChapterRef) -> Unit) {
    val count = pages.pages.size
    val pager = rememberPagerState(initialPage = model.scrollRequest?.index?.coerceIn(0, count - 1) ?: 0) { count + 1 }
    LaunchedEffect(pages.chapter.id, model.scrollRequest) {
        model.scrollRequest?.let { pager.scrollToPage(it.index.coerceIn(0, count - 1)) }
        if (!model.tracking) {
            delay(600)
            model.tracking = true
            model.pageShown(pager.currentPage.coerceAtMost(count - 1))
        }
    }
    LaunchedEffect(pages.chapter.id) {
        snapshotFlow { pager.currentPage }.distinctUntilChanged().collect { model.pageShown(it.coerceAtMost(count - 1)) }
    }
    HorizontalPager(
        state = pager,
        reverseLayout = model.mode.rightToLeft,
        beyondViewportPageCount = 1,
        modifier = Modifier.fillMaxSize(),
        key = { it },
    ) { index ->
        if (index == count) {
            EndOfChapter(pages, model, colors, onOpen)
        } else {
            val zoom = remember(index) { ZoomState() }
            Box(Modifier.fillMaxSize().zoomable(zoom, onTap = onTap), contentAlignment = Alignment.Center) {
                PageImage(model.model(pages.pages[index]), index, count, ContentScale.Fit, Modifier.fillMaxSize())
            }
        }
    }
}

/** Vertical (webtoon) reading: pages stacked full width; pinch zooms the strip. */
@Composable
private fun VerticalChapter(pages: MangaPages, model: MangaReaderViewModel, colors: ReaderColors, onTap: () -> Unit, onOpen: (ChapterRef) -> Unit) {
    val count = pages.pages.size
    val list = rememberLazyListState()
    val zoom = remember(pages.chapter.id) { ZoomState(maxScale = 3f) }
    LaunchedEffect(pages.chapter.id, model.scrollRequest) {
        model.scrollRequest?.let { list.scrollToItem(it.index.coerceIn(0, count - 1)) }
        if (!model.tracking) {
            delay(600)
            model.tracking = true
        }
    }
    LaunchedEffect(pages.chapter.id) {
        snapshotFlow {
            // The last page counts as seen once its bottom edge is on screen.
            val info = list.layoutInfo
            val last = info.visibleItemsInfo.firstOrNull { it.index == count - 1 }
            if (last != null && last.offset + last.size <= info.viewportEndOffset + 4) count - 1 else list.firstVisibleItemIndex.coerceAtMost(count - 1)
        }.distinctUntilChanged().collect { model.pageShown(it) }
    }
    LazyColumn(
        state = list,
        modifier = Modifier.fillMaxSize().zoomable(zoom, panY = false, onTap = onTap),
        horizontalAlignment = Alignment.CenterHorizontally,
        contentPadding = PaddingValues(bottom = 24.dp),
    ) {
        itemsIndexed(pages.pages, key = { _, page -> page.page }) { index, page ->
            val ratio = page.aspect?.let { 1f / it }
            PageImage(
                model.model(page), index, count, ContentScale.FillWidth,
                Modifier.widthIn(max = 900.dp).fillMaxWidth().then(if (ratio != null) Modifier.aspectRatio(ratio) else Modifier.heightIn(min = 300.dp)),
            )
        }
        item(key = "end") { EndOfChapter(pages, model, colors, onOpen) }
    }
}

@Composable
private fun PageImage(data: Any, index: Int, count: Int, scale: ContentScale, modifier: Modifier) {
    SubcomposeAsyncImage(
        model = data,
        contentDescription = "Page ${index + 1} of $count",
        contentScale = scale,
        modifier = modifier,
        loading = { Box(Modifier.fillMaxWidth().heightIn(min = 300.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator() } },
        error = {
            Box(Modifier.fillMaxWidth().heightIn(min = 300.dp), contentAlignment = Alignment.Center) {
                Text("Couldn't load page ${index + 1}", color = Color(0xFFBDBDBD), textAlign = TextAlign.Center)
            }
        },
    )
}

@Composable
private fun EndOfChapter(pages: MangaPages, model: MangaReaderViewModel, colors: ReaderColors, onOpen: (ChapterRef) -> Unit) {
    Box(Modifier.fillMaxSize().padding(24.dp), contentAlignment = Alignment.Center) {
        Column(
            Modifier.widthIn(max = 520.dp).fillMaxWidth().padding(vertical = 32.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(16.dp),
        ) {
            Text("End of ${pages.chapter.title}", color = colors.text, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
            ChapterButtons(pages.prev, pages.next, colors, onOpen)
            val next = pages.next
            if (next != null) {
                CenteredNote(next.title + if (next.downloaded == false) " · not downloaded yet" else "", colors.secondary)
            } else {
                CenteredNote("You're all caught up.", colors.secondary)
                CheckForChaptersControl(model.novelId, compact = true, tint = colors.secondary)
            }
        }
    }
}
