package studio.visns.novarr.ui.reader

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.SavedStateHandle
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
data class ScrollRequest(
    val index: Int,
    val toEnd: Boolean = false,
    /** A character offset in the chapter to land on, when finer than the item (a highlight's passage). */
    val offset: Int? = null,
    val token: String = UUID.randomUUID().toString(),
)

sealed interface ReaderPhase {
    data object Loading : ReaderPhase
    data object Loaded : ReaderPhase
    data class Failed(val message: String) : ReaderPhase
}

/**
 * What the text and manga readers share (the iOS ReaderModel's rules):
 * position tracking with throttled, queued progress writes; the read mark on
 * reaching the end; and the latest-position prompt (on open, and again on
 * returning from the background after a while).
 *
 * The hold: from the moment a latest-position check starts until it is
 * resolved, and then while its prompt is unanswered, scroll positions are
 * tracked but not sent ([ProgressTracker.hold]), so a stale position here
 * can't replace the newer one from another device before the user chose.
 * Every way out of a check goes through [finishCheck] (or [abandonCheck]),
 * and every answer to the prompt through [releaseHoldIfIdle].
 *
 * A look-up ([lookup]: opened at a highlight) is not reading there: the
 * chapter it opened on uses the same hold for as long as it is shown, so no
 * position is sent for it (and no latest-position check runs); reaching its
 * end still marks it read, flagged `lookup` so the server leaves the
 * series' latest position alone (as the website does). Moving to another
 * chapter ends the look-up, and from there the reader tracks as usual.
 *
 * The chapter being read is kept in [saved] (the navigation entry's saved
 * state), so after the system kills the app in the background the reader
 * reopens the chapter it was on, not the one it was opened with; the route
 * itself is untouched, so going back still returns to where it came from.
 */
abstract class BaseReaderViewModel(
    protected val app: AppModel,
    val novelId: Int,
    startChapterId: Int,
    private val offersLatest: Boolean,
    private val saved: SavedStateHandle,
    lookup: Boolean = false,
) : ViewModel() {
    var chapterId by mutableIntStateOf(saved.get<Int>(KEY_CHAPTER) ?: startChapterId)
        private set

    /** Still on the chapter a look-up opened (see the class comment). */
    protected var lookingUp = lookup && saved.get<Boolean>(KEY_LOOKUP_ENDED) != true && chapterId == startChapterId
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

    init {
        // A look-up holds positions from the start; only [endLookup] lets go.
        if (lookingUp) tracker.hold()
    }

    // Latest-position check: once on open (if [offersLatest]), and again on
    // returning from the background, for the chapter shown at the time.
    private var positionChecked = false

    /** The chapter the running check was started for; null when none is running. */
    private var checkChapterId: Int? = null

    /** The server's answer, waiting for the chapter to be on screen (evaluated once). */
    private var fetchedPosition: ReadingPosition? = null
    private var checkJob: Job? = null

    /** Bumped whenever a check ends, so a late answer from an older one is ignored. */
    private var checkGeneration = 0

    /** When the screen went to the background (ON_STOP); null while in the foreground. */
    private var stoppedAt: Long? = null

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

    /**
     * Implementations call this once content is in place, with what the
     * server (or cache) knows. [restoreOffset]: a character offset to land
     * on within [restoreIndex] (text chapters).
     */
    protected suspend fun loaded(read: Boolean, progress: Int?, restoreIndex: Int?, toEnd: Boolean = false, restoreOffset: Int? = null) {
        tracker.reset(read, progress)
        scrollRequest = restoreIndex?.let { ScrollRequest(it, toEnd, restoreOffset) }
        phase = ReaderPhase.Loaded
        evaluateLatestOffer()
    }

    /** The forced "Go to latest" position for [id], if one is pending (consumed). */
    protected fun takeForcedProgress(id: Int): Int? =
        forcedProgress?.takeIf { it.first == id }?.second.also { forcedProgress = null }

    fun open(id: Int) {
        // Moving on answers the prompt as far as this reader is concerned
        // ("Stay here", implicitly), and a running check is for a chapter no
        // longer shown: the held position goes out as normal.
        latestOffer = null
        abandonCheck()
        endLookup()
        leave()
        chapterId = id
        saved[KEY_CHAPTER] = id
        load()
    }

    /** Leaving a look-up's chapter: its unsent position is dropped and tracking returns to normal. */
    private fun endLookup() {
        if (!lookingUp) return
        lookingUp = false
        saved[KEY_LOOKUP_ENDED] = true
        tracker.discardPending()
        releaseHoldIfIdle()
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
            ProgressTracker.Decision.AlreadyScheduled, ProgressTracker.Decision.Held -> Unit
        }
    }

    /** The end of the chapter came into view or left it. */
    fun endVisible(visible: Boolean) {
        atEnd = visible && tracking && hasContent
        if (visible) reachedEnd()
    }

    /**
     * The end of the chapter is on screen: mark it read, once. The read mark
     * is written even while writes are held, and finishing the chapter
     * answers an open prompt (it is dismissed, not declined).
     */
    fun reachedEnd() {
        if (!tracking || !hasContent) return
        sendJob?.cancel()
        if (!tracker.reachedEnd()) return
        onMarkedRead()
        write(WriteKind.Progress(chapterId, 100, true, lookup = lookingUp))
        if (latestOffer != null) {
            latestOffer = null
            releaseHoldIfIdle()
        }
    }

    /**
     * Sends any unsent position; call when leaving the chapter or the app.
     * With a prompt still unanswered the held position is dropped instead:
     * an unanswered prompt must not overwrite the other device.
     */
    fun leave() {
        sendJob?.cancel()
        if (latestOffer != null) tracker.discardPending() else sendPending()
    }

    /** The screen went to the background (ON_STOP). */
    fun stopped() {
        stoppedAt = System.currentTimeMillis()
        leave()
    }

    /** The screen is back in the foreground (ON_START): after a while away, ask again. */
    fun started() {
        val since = stoppedAt
        stoppedAt = null
        // A look-up asks for no position (it isn't reading there).
        if (lookingUp) return
        if (LatestPosition.recheckOnReturn(since, System.currentTimeMillis())) startCheck()
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
        // The scope is already cancelled: settle a running check here (no
        // prompt came of it, so the held position is sent as normal).
        abandonCheck()
        leave()
    }

    private companion object {
        /** FIFO across every reader, so writes are queued in the order they were made. */
        val WRITES = Mutex()

        /**
         * Saved-state keys. Not "chapterId": the navigation entry's saved
         * state already holds the route's arguments under their own names.
         */
        const val KEY_CHAPTER = "reader.currentChapterId"
        const val KEY_LOOKUP_ENDED = "reader.lookupEnded"
    }

    // Latest position

    /**
     * Asks the server where this series was last read, once, for the chapter
     * the reader was opened on. Runs alongside the load.
     */
    fun checkLatestPosition() {
        if (!offersLatest || positionChecked || lookingUp) return
        positionChecked = true
        startCheck()
    }

    /**
     * Starts a check for the chapter being shown, holding writes until it is
     * resolved. Any failure or slow answer (3.5 s) simply means no prompt.
     */
    private fun startCheck() {
        if (checkChapterId != null || lookingUp) return // one is already running, or a look-up
        val client = app.active?.client ?: return
        val generation = ++checkGeneration
        checkChapterId = chapterId
        fetchedPosition = null
        tracker.hold()
        checkJob = viewModelScope.launch {
            val position = withTimeoutOrNull(3_500) { runCatching { client.latestPosition(novelId) }.getOrNull() }
            if (generation != checkGeneration) return@launch
            if (position == null) {
                finishCheck()
                return@launch
            }
            fetchedPosition = position
            evaluateLatestOffer()
        }
    }

    /**
     * Decides once both the chapter and the server's answer are in (called
     * by the check and by [loaded]), then ends the check.
     */
    private suspend fun evaluateLatestOffer() {
        val position = fetchedPosition ?: return
        val target = checkChapterId ?: return
        // The user moved to another chapter: no prompt for this one.
        if (chapterId != target) return finishCheck()
        // Not on screen yet: [loaded] calls again.
        if (phase != ReaderPhase.Loaded || shownChapterId != target) return
        fetchedPosition = null
        val generation = checkGeneration

        val localTimes = app.unsentWriteTimes(novelId) + listOfNotNull(tracker.unheldPendingSince)
        val localNewer = LatestPosition.localIsNewer(localTimes, position.ageSeconds, System.currentTimeMillis())
        var canOpen = position.chapterId == target || position.downloaded == true
        if (!canOpen) canOpen = app.active?.store?.hasChapter(position.chapterId, novelId) == true
        val declinedAt = app.declinedLatestPosition(novelId)
        // The check was abandoned while we waited (it has been settled already).
        if (generation != checkGeneration) return
        // The user may have turned the page while we waited.
        if (chapterId == target && LatestPosition.shouldOffer(
                position, app.device.id, target,
                localProgress = tracker.current,
                localNewer = localNewer,
                declinedAt = declinedAt,
                canOpen = canOpen,
            )
        ) {
            latestOffer = LatestOffer(position, LatestPosition.message(position))
        }
        finishCheck()
    }

    /** The running check is resolved: the hold ends unless its prompt is now waiting for an answer. */
    private fun finishCheck() {
        checkGeneration++
        checkChapterId = null
        fetchedPosition = null
        checkJob = null
        releaseHoldIfIdle()
    }

    /** Stops a running check without a prompt (moving to another chapter, the reader going away). */
    private fun abandonCheck() {
        if (checkChapterId == null) return
        checkJob?.cancel()
        finishCheck()
    }

    /** Sends the held position once nothing holds it any more: no check running, no prompt open, no look-up. */
    private fun releaseHoldIfIdle() {
        if (!tracker.holding || checkChapterId != null || latestOffer != null || lookingUp) return
        tracker.release()
        sendJob?.cancel()
        sendPending()
    }

    /** "Go to latest": the other device's chapter at its position; the held position here is dropped. */
    fun acceptLatest() {
        val offer = latestOffer ?: return
        latestOffer = null
        tracker.discardPending()
        releaseHoldIfIdle()
        val target = offer.position
        if (target.chapterId == chapterId) {
            val progress = target.progress ?: return
            scrollRequest = ScrollRequest(LatestPosition.itemIndex(progress, itemCount) ?: 0)
        } else {
            forcedProgress = target.chapterId to (target.progress ?: 0)
            open(target.chapterId)
        }
    }

    /** "Stay here": don't offer this position again; the held position is sent. */
    fun declineLatest() {
        val offer = latestOffer ?: return
        latestOffer = null
        releaseHoldIfIdle()
        LatestPosition.parseInstant(offer.position.at)?.let { at ->
            app.scope.launch { app.declineLatestPosition(at, novelId) }
        }
    }
}
