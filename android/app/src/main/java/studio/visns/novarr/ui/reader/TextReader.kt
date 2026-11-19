package studio.visns.novarr.ui.reader

import androidx.compose.animation.AnimatedVisibility
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
import androidx.compose.foundation.layout.only
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawing
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.windowInsetsPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
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
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
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
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.ChapterContent
import studio.visns.novarr.api.ChapterRef
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.LatestPosition
import studio.visns.novarr.store.ReaderSettings
import studio.visns.novarr.ui.ReaderRoute
import studio.visns.novarr.ui.components.BrandMark
import studio.visns.novarr.ui.components.CenteredNote
import studio.visns.novarr.ui.components.CheckForChaptersControl
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.OfflinePill

class TextReaderViewModel(app: AppModel, route: ReaderRoute) :
    BaseReaderViewModel(app, route.novelId, route.chapterId, route.offerLatest) {
    var content by mutableStateOf<ChapterContent?>(null)
        private set

    override val itemCount: Int get() = content?.paragraphs?.size ?: 0
    override val shownChapterId: Int? get() = content?.id
    override val hasContent: Boolean get() = content?.hasText == true

    init {
        load()
        checkLatestPosition()
    }

    override suspend fun loadChapter(id: Int) {
        content = null
        val session = app.active
        val cached = session?.store?.chapter(id, novelId)
        var loaded: ChapterContent? = null
        var failure: ApiError? = null
        if (session != null) {
            try {
                var fresh = session.client.chapter(id)
                app.noteSuccess()
                if (fresh.novelId == 0) fresh = fresh.copy(novelId = novelId)
                // Unsent local progress is newer than what the server knows.
                if (app.pendingWrites.value > 0 && cached != null) {
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
        content = chapter
        val restore = when {
            forced != null -> LatestPosition.itemIndex(forced, chapter.paragraphs.size)
            chapter.readProgress != null && chapter.readProgress < 100 -> LatestPosition.itemIndex(chapter.readProgress, chapter.paragraphs.size)
            else -> null
        }
        if (chapter.downloaded) app.downloads.contentChanged()
        prefetch(chapter.next)
        loaded(chapter.read, chapter.readProgress, restore)
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

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TextReaderScreen(nav: NavController, route: ReaderRoute) {
    val app = LocalApp.current
    val model = viewModel { TextReaderViewModel(app, route) }
    val settings by app.prefs.readerSettings.collectAsStateWithLifecycle(ReaderSettings())
    val colors = settings.theme.colors()
    var chromeHidden by rememberSaveable { mutableStateOf(false) }
    var showSettings by remember { mutableStateOf(false) }
    val checkRevisions by app.checks.revisions.collectAsStateWithLifecycle()

    LifecycleEventEffect(Lifecycle.Event.ON_STOP) { model.leave() }
    LaunchedEffect(checkRevisions[model.novelId]) { if (checkRevisions[model.novelId] != null) model.refreshNext() }
    ReaderSystemBars(hidden = chromeHidden, darkSurface = colors.isDark, keepScreenOn = settings.keepScreenOn)

    Box(Modifier.fillMaxSize().background(colors.background)) {
        when (val phase = model.phase) {
            ReaderPhase.Loading -> CircularProgressIndicator(Modifier.align(Alignment.Center), color = colors.secondary)
            is ReaderPhase.Failed -> Failure(phase.message, colors) { model.load() }
            ReaderPhase.Loaded -> model.content?.let { content ->
                ChapterText(content, model, settings, colors, onTap = { chromeHidden = !chromeHidden }, onBackToSeries = { nav.popBackStack() })
            }
        }

        AnimatedVisibility(!chromeHidden, enter = fadeIn() + slideInVertically(), exit = fadeOut() + slideOutVertically(), modifier = Modifier.align(Alignment.TopCenter)) {
            ReaderTopBar(model.content?.title.orEmpty(), model.content?.novelName.orEmpty(), colors, showingCache = model.showingCache) { nav.popBackStack() }
        }
        Column(Modifier.align(Alignment.BottomCenter).fillMaxWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
            model.latestOffer?.let { offer ->
                LatestPositionBanner(offer.message, colors, onGo = model::acceptLatest, onStay = model::declineLatest, modifier = Modifier.navigationBarsPadding())
            }
            AnimatedVisibility(!chromeHidden, enter = fadeIn() + slideInVertically { it }, exit = fadeOut() + slideOutVertically { it }) {
                ReaderBottomBar(
                    colors,
                    prev = model.content?.prev,
                    next = model.content?.next,
                    onOpen = { model.open(it.id) },
                    middle = {
                        IconButton(onClick = { showSettings = true }) {
                            Icon(Icons.Outlined.TextFields, contentDescription = "Reading settings", tint = colors.text)
                        }
                    },
                )
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
) {
    val list = rememberLazyListState()
    // Follow the system font scale on top of the chosen size.
    val fontScale = LocalDensity.current.fontScale
    val size = settings.fontSize.sp
    val body = TextStyle(
        fontFamily = settings.font.family(),
        fontSize = size,
        lineHeight = (settings.spacing.lineHeight).em,
        color = colors.text,
        textAlign = if (settings.justify) TextAlign.Justify else TextAlign.Start,
    )
    val paragraphCount = content.paragraphs.size
    val footerIndex = HEADER_ITEMS + (if (content.hasText) paragraphCount else 1)

    // Restore (or "Go to latest" within the chapter), then start tracking.
    LaunchedEffect(content.id, model.scrollRequest) {
        val request = model.scrollRequest
        if (request != null) {
            list.scrollToItem(HEADER_ITEMS + request.index.coerceIn(0, (paragraphCount - 1).coerceAtLeast(0)))
        }
        if (!model.tracking) {
            delay(600)
            model.tracking = true
        }
        // Short chapters show the end straight away.
        if (list.layoutInfo.visibleItemsInfo.any { it.index == footerIndex }) model.reachedEnd()
    }
    LaunchedEffect(content.id) {
        snapshotFlow { list.firstVisibleItemIndex }
            .distinctUntilChanged()
            .collect { index -> model.moved((index - HEADER_ITEMS).coerceIn(0, (paragraphCount - 1).coerceAtLeast(0))) }
    }
    LaunchedEffect(content.id) {
        snapshotFlow { list.layoutInfo.visibleItemsInfo.any { it.index == footerIndex } }
            .distinctUntilChanged()
            .collect { visible -> if (visible) model.reachedEnd() }
    }

    val margin = settings.margins.paddingDp.dp
    LazyColumn(
        state = list,
        modifier = Modifier
            .fillMaxSize()
            .clickable(interactionSource = remember { MutableInteractionSource() }, indication = null, onClickLabel = "Show or hide controls", onClick = onTap),
        contentPadding = PaddingValues(top = 72.dp, bottom = 120.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        item(key = "header") {
            Column(
                Modifier.widthIn(max = settings.width.maxWidthDp.dp).fillMaxWidth().padding(horizontal = margin).padding(top = 20.dp, bottom = 12.dp)
                    .semantics(mergeDescendants = true) { heading() },
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                if (content.novelName.isNotEmpty()) {
                    Text(content.novelName.uppercase(), style = MaterialTheme.typography.labelMedium, color = colors.secondary, maxLines = 2)
                }
                Text(content.title, style = body.copy(fontSize = size * 1.45f, lineHeight = 1.25.em, fontWeight = FontWeight.SemiBold, textAlign = TextAlign.Start))
                Box(Modifier.padding(top = 4.dp).width(36.dp).height(3.dp).background(MaterialTheme.colorScheme.primary))
            }
        }
        if (content.hasText) {
            itemsIndexed(content.paragraphs, key = { index, _ -> index }) { _, paragraph ->
                // One node per paragraph, so TalkBack reads (and resumes) paragraph by paragraph.
                Text(
                    paragraph,
                    style = body,
                    modifier = Modifier
                        .widthIn(max = settings.width.maxWidthDp.dp)
                        .fillMaxWidth()
                        .padding(horizontal = margin)
                        .padding(bottom = (settings.fontSize * 0.9f * fontScale).dp),
                )
            }
        } else {
            item(key = "missing") { NotDownloaded(colors) }
        }
        item(key = "footer") {
            Footer(content, model, colors, onBackToSeries, Modifier.widthIn(max = settings.width.maxWidthDp.dp).padding(horizontal = margin))
        }
    }
    if (model.showingCache) {
        Box(Modifier.fillMaxWidth().statusBarsPadding().padding(top = 64.dp), contentAlignment = Alignment.TopCenter) {
            OfflinePill(text = "Offline · saved copy")
        }
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
private fun Footer(content: ChapterContent, model: BaseReaderViewModel, colors: ReaderColors, onBackToSeries: () -> Unit, modifier: Modifier) {
    Column(modifier.fillMaxWidth().padding(top = 16.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(16.dp)) {
        if (content.hasText) BrandMark(Modifier.width(22.dp).padding(top = 24.dp), color = colors.secondary.copy(alpha = 0.5f))
        ChapterButtons(content.prev, content.next, colors) { model.open(it.id) }
        val next = content.next
        when {
            next != null -> CenteredNote(next.title + if (next.downloaded == false) " · not downloaded yet" else "", colors.secondary)
            content.downloaded -> {
                CenteredNote("You're all caught up.", colors.secondary)
                CheckForChaptersControl(model.novelId, compact = true, tint = colors.secondary)
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
                colors = ButtonDefaults.buttonColors(containerColor = colors.text.copy(alpha = 0.08f), contentColor = colors.text),
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
fun ReaderTopBar(title: String, subtitle: String, colors: ReaderColors, showingCache: Boolean = false, onBack: () -> Unit) {
    Surface(color = colors.background.copy(alpha = 0.97f), shadowElevation = 2.dp, modifier = Modifier.fillMaxWidth()) {
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
        }
    }
}

@Composable
fun ReaderBottomBar(colors: ReaderColors, prev: ChapterRef?, next: ChapterRef?, onOpen: (ChapterRef) -> Unit, middle: @Composable () -> Unit) {
    Surface(color = colors.background.copy(alpha = 0.97f), shadowElevation = 2.dp, modifier = Modifier.fillMaxWidth()) {
        Row(
            Modifier.navigationBarsPadding().heightIn(min = 56.dp).padding(horizontal = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            val iconColors = IconButtonDefaults.iconButtonColors(contentColor = colors.text, disabledContentColor = colors.text.copy(alpha = 0.3f))
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
