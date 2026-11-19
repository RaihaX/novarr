package studio.visns.novarr.ui.reader

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import java.util.UUID
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withTimeoutOrNull
import studio.visns.novarr.api.ReadingPosition
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.LatestPosition
import studio.visns.novarr.domain.ProgressTracker
import studio.visns.novarr.store.WriteKind

/** The prompt to jump to where another device read more recently. */
data class LatestOffer(val position: ReadingPosition, val message: String)

/**
 * Asks the screen to scroll to an item (paragraph or page). The token makes repeats distinct.
 * [toEnd]: show the end of the chapter (going back a page from the next chapter's start).
 */
data class ScrollRequest(val index: Int, val toEnd: Boolean = false, val token: String = UUID.randomUUID().toString())

sealed interface ReaderPhase {
    data object Loading : ReaderPhase
    data object Loaded : ReaderPhase
    data class Failed(val message: String) : ReaderPhase
}

/**
 * What the text and manga readers share (the iOS ReaderModel's rules):
 * position tracking with throttled, queued progress writes; the read mark on
 * reaching the end; and the once-per-open latest-position prompt.
 */
abstract class BaseReaderViewModel(
    protected val app: AppModel,
    val novelId: Int,
    startChapterId: Int,
    private val offersLatest: Boolean,
) : ViewModel() {
    var chapterId by mutableIntStateOf(startChapterId)
        private set
    var phase by mutableStateOf<ReaderPhase>(ReaderPhase.Loading)
        protected set
    var showingCache by mutableStateOf(false)
        protected set
    var latestOffer by mutableStateOf<LatestOffer?>(null)
        private set

    /** Where to put the reader once content is laid out (restore, or "Go to latest" in this chapter). */
    var scrollRequest by mutableStateOf<ScrollRequest?>(null)
        protected set

    /** The token of the last [scrollRequest] the screen acted on, so a re-created screen doesn't jump again. */
    private var handledRequest: String? = null

    /** The scroll request not yet acted on, if any (marks it handled). */
    fun takeScrollRequest(): ScrollRequest? =
        scrollRequest?.takeIf { it.token != handledRequest }?.also { handledRequest = it.token }

    /**
     * Position events are ignored until the screen has restored the saved
     * position, so the initial jump never reports a bogus value.
     */
    var tracking = false

    protected val tracker = ProgressTracker(now = System::currentTimeMillis)
    private var sendJob: Job? = null

    // Latest-position check: runs once, for the chapter the reader was opened on.
    private val openedChapterId = startChapterId
    private var positionChecked = false
    private var fetchedPosition: ReadingPosition? = null
    private var offerEvaluated = false

    /** "Go to latest" into another chapter: restore there, ignoring local progress. */
    protected var forcedProgress: Pair<Int, Int>? = null

    /** Paragraphs or pages in the open chapter. */
    protected abstract val itemCount: Int

    /** The chapter currently shown (null while loading). */
    protected abstract val shownChapterId: Int?

    /** Whether the open chapter has readable content (not "not downloaded yet"). */
    protected abstract val hasContent: Boolean

    /** Loads [chapterId]; implementations call [loaded] when it is on screen. */
    protected abstract suspend fun loadChapter(id: Int)

    /** Lets the screen's content reflect a read mark made here. */
    protected open fun onMarkedRead() {}

    fun load() = viewModelScope.launch {
        phase = ReaderPhase.Loading
        tracking = false
        atEnd = false
        scrollRequest = null
        loadChapter(chapterId)
    }

    /** Implementations call this once content is in place, with what the server (or cache) knows. */
    protected suspend fun loaded(read: Boolean, progress: Int?, restoreIndex: Int?, toEnd: Boolean = false) {
        tracker.reset(read, progress)
        scrollRequest = restoreIndex?.let { ScrollRequest(it, toEnd) }
        phase = ReaderPhase.Loaded
        evaluateLatestOffer()
    }

    /** The forced "Go to latest" position for [id], if one is pending (consumed). */
    protected fun takeForcedProgress(id: Int): Int? =
        forcedProgress?.takeIf { it.first == id }?.second.also { forcedProgress = null }

    fun open(id: Int) {
        // Moving on answers the prompt as far as this reader is concerned.
        latestOffer = null
        leave()
        chapterId = id
        load()
    }

    // Progress

    /**
     * Whether the end of the chapter is on screen. While it is, positions are
     * not reported: a fling past the end keeps moving the top line, and those
     * moves must not follow the read mark with a lower percentage.
     */
    private var atEnd = false

    /** The top visible item changed (paragraph or page index). */
    fun moved(index: Int) {
        if (!tracking || itemCount == 0 || !hasContent || atEnd) return
        when (val decision = tracker.moved(ProgressTracker.progressFor(index, itemCount))) {
            ProgressTracker.Decision.SendNow -> {
                sendJob?.cancel()
                sendPending()
            }
            is ProgressTracker.Decision.SendAfter -> {
                sendJob?.cancel()
                sendJob = viewModelScope.launch {
                    delay(decision.delayMs)
                    sendPending()
                }
            }
            ProgressTracker.Decision.AlreadyScheduled -> Unit
        }
    }

    /** The end of the chapter came into view or left it. */
    fun endVisible(visible: Boolean) {
        atEnd = visible && tracking && hasContent
        if (visible) reachedEnd()
    }

    /** The end of the chapter is on screen: mark it read, once. */
    fun reachedEnd() {
        if (!tracking || !hasContent) return
        sendJob?.cancel()
        if (!tracker.reachedEnd()) return
        onMarkedRead()
        write(WriteKind.Progress(chapterId, 100, true))
    }

    /** Sends any unsent position; call when leaving the chapter or the app. */
    fun leave() {
        sendJob?.cancel()
        sendPending()
    }

    private fun sendPending() {
        val progress = tracker.takePending() ?: return
        write(WriteKind.Progress(chapterId, progress, false))
    }

    /**
     * Updates the offline copy and queues the write, in the order the reader
     * produced them (a position followed by the read mark must reach the
     * queue in that order). The app's scope: a write must survive the reader closing.
     */
    private fun write(kind: WriteKind.Progress) {
        app.scope.launch {
            WRITES.withLock {
                app.active?.store?.updateChapterState(kind.chapterId, novelId, kind.progress, if (kind.read) true else null)
                app.record(kind, novelId)
            }
        }
    }

    override fun onCleared() {
        leave()
    }

    private companion object {
        /** FIFO across every reader, so writes are queued in the order they were made. */
        val WRITES = Mutex()
    }

    // Latest position

    /**
     * Asks the server where this series was last read. Runs alongside the
     * load; any failure or slow answer (3 s) simply means no prompt.
     */
    fun checkLatestPosition() {
        if (!offersLatest || positionChecked) return
        positionChecked = true
        viewModelScope.launch {
            val client = app.active?.client ?: return@launch
            val position = withTimeoutOrNull(3_500) { runCatching { client.latestPosition(novelId) }.getOrNull() } ?: return@launch
            fetchedPosition = position
            evaluateLatestOffer()
        }
    }

    /** Decides once both the chapter and the server's answer are in. */
    private suspend fun evaluateLatestOffer() {
        val position = fetchedPosition ?: return
        if (offerEvaluated || phase != ReaderPhase.Loaded || chapterId != openedChapterId || shownChapterId != openedChapterId) return
        offerEvaluated = true

        val localTimes = app.unsentWriteTimes(novelId) + listOfNotNull(tracker.pendingSince)
        val localNewer = LatestPosition.localIsNewer(localTimes, position.ageSeconds, System.currentTimeMillis())
        var canOpen = position.chapterId == openedChapterId || position.downloaded == true
        if (!canOpen) canOpen = app.active?.store?.hasChapter(position.chapterId, novelId) == true
        // The user may have turned the page while we waited.
        if (chapterId != openedChapterId) return

        if (LatestPosition.shouldOffer(
                position, app.device.id, openedChapterId,
                localProgress = tracker.current,
                localNewer = localNewer,
                declinedAt = app.declinedLatestPosition(novelId),
                canOpen = canOpen,
            )
        ) {
            latestOffer = LatestOffer(position, LatestPosition.message(position))
        }
    }

    /** "Go to latest": the other device's chapter at its position. */
    fun acceptLatest() {
        val offer = latestOffer ?: return
        latestOffer = null
        val target = offer.position
        if (target.chapterId == chapterId) {
            val progress = target.progress ?: return
            scrollRequest = ScrollRequest(LatestPosition.itemIndex(progress, itemCount) ?: 0)
        } else {
            forcedProgress = target.chapterId to (target.progress ?: 0)
            open(target.chapterId)
        }
    }

    /** "Stay here": don't offer this position again. */
    fun declineLatest() {
        val offer = latestOffer ?: return
        latestOffer = null
        LatestPosition.parseInstant(offer.position.at)?.let { at ->
            app.scope.launch { app.declineLatestPosition(at, novelId) }
        }
    }
}
