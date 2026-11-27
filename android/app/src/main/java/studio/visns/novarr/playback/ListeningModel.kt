package studio.visns.novarr.playback

import android.content.ComponentName
import android.content.Context
import android.media.audiofx.LoudnessEnhancer
import android.net.Uri
import android.os.SystemClock
import androidx.core.content.ContextCompat
import androidx.annotation.OptIn
import androidx.media3.common.C
import androidx.media3.common.util.UnstableApi
import androidx.media3.common.MediaItem
import androidx.media3.common.MediaMetadata
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.datasource.HttpDataSource
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.session.MediaController
import androidx.media3.session.MediaSession
import androidx.media3.session.SessionToken
import com.google.common.util.concurrent.ListenableFuture
import java.io.File
import kotlin.coroutines.resume
import kotlin.coroutines.resumeWithException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.suspendCancellableCoroutine
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AudiobookBookmark
import studio.visns.novarr.api.AudiobookDetail
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.app.Session
import studio.visns.novarr.domain.AudiobookTimeline
import studio.visns.novarr.domain.ListeningRecord
import studio.visns.novarr.domain.ListeningSync
import studio.visns.novarr.domain.SleepTimer
import studio.visns.novarr.store.ListeningPrefs
import studio.visns.novarr.store.ListeningStore
import studio.visns.novarr.store.OfflineStore

/** What the player shows: the audiobook loaded (playing or paused) and where it is. */
data class NowPlaying(
    /** The session (server and account) it belongs to: another one never sees it. */
    val sessionId: String,
    val detail: AudiobookDetail,
    val timeline: AudiobookTimeline,
    /** Whole-book position. */
    val positionMs: Long,
    val playing: Boolean = false,
    val buffering: Boolean = false,
    val speed: Float = 1f,
    val sleep: SleepTimer = SleepTimer.Off,
    val bookmarks: List<AudiobookBookmark> = emptyList(),
    /** Playing from this device's download. */
    val local: Boolean = false,
    val error: String? = null,
) {
    val id: Int get() = detail.id
    val chapterIndex: Int get() = timeline.chapterIndexAt(positionMs)
    val chapterTitle: String? get() = timeline.chapters.getOrNull(chapterIndex)?.label
}

/**
 * The audiobook player for the whole process. The ExoPlayer itself belongs to
 * [PlaybackService] (so playback outlives the UI); the service attaches it
 * here, and this drives it in whole-book positions ([AudiobookTimeline]),
 * keeps [nowPlaying] for the UI, runs the sleep timer and saves progress
 * ([ListeningStore]: every 15 s while playing, on pause, stop and when the
 * app goes to the background; queued offline, last write wins).
 *
 * Everything runs on the main thread (AppModel's scope; ExoPlayer's looper).
 */
@OptIn(UnstableApi::class)
class ListeningModel(private val context: Context, private val app: AppModel) {
    private val scope = app.scope

    private val _nowPlaying = MutableStateFlow<NowPlaying?>(null)
    val nowPlaying: StateFlow<NowPlaying?> = _nowPlaying.asStateFlow()

    /** Bumped when an audiobook download finished or was removed. */
    private val _downloadsRevision = MutableStateFlow(0)
    val downloadsRevision: StateFlow<Int> = _downloadsRevision.asStateFlow()

    private var player: ExoPlayer? = null
    private var controller: ListenableFuture<MediaController>? = null
    private var store: ListeningStore? = null
    private var storeSession: String? = null

    /** The audiobook whose parts are set on the player (null: nothing, or another one). */
    private var preparedFor: Int? = null
    private var prefs = ListeningPrefs()
    private var ticker: Job? = null
    private var lastSyncAt = 0L
    private var lastUrlRefresh = 0L
    private var enhancer: LoudnessEnhancer? = null

    /** When the loaded detail was fetched from the server (0: from the offline cache). */
    private var detailFetchedAt = 0L

    init {
        scope.launch { app.prefs.listening.collect { applyPrefs(it) } }
        scope.launch { app.session.collect { onSession(it) } }
        scope.launch {
            app.audiobooksOffered.collect { offered -> if (!offered && _nowPlaying.value != null && app.metaKnown) close() }
        }
    }

    // The service's player

    private val listener = object : Player.Listener {
        override fun onIsPlayingChanged(isPlaying: Boolean) {
            refreshFromPlayer()
            if (!isPlaying) saveNow()
            if (isPlaying) startTicker()
        }

        override fun onPlaybackStateChanged(playbackState: Int) {
            refreshFromPlayer()
            if (playbackState == Player.STATE_ENDED) {
                val now = _nowPlaying.value ?: return
                _nowPlaying.value = now.copy(positionMs = now.timeline.totalMs, playing = false, sleep = SleepTimer.Off)
                record(finished = true)
            }
        }

        override fun onMediaItemTransition(mediaItem: MediaItem?, reason: Int) = refreshFromPlayer()

        override fun onAudioSessionIdChanged(audioSessionId: Int) = applyBoost()

        override fun onPlayerError(error: PlaybackException) {
            val cause = error.cause
            val expired = cause is HttpDataSource.InvalidResponseCodeException && cause.responseCode in setOf(401, 403, 410)
            // The pre-signed part URLs last 24 h: fetch fresh ones and carry on from here (once a minute at most).
            if (expired && SystemClock.elapsedRealtime() - lastUrlRefresh > 60_000) {
                lastUrlRefresh = SystemClock.elapsedRealtime()
                scope.launch { reloadParts(play = true) }
                return
            }
            _nowPlaying.update { it?.copy(playing = false, buffering = false, error = playbackMessage(error)) }
            saveNow()
        }
    }

    /** Called by [PlaybackService] when it created its player. */
    fun attach(exo: ExoPlayer) {
        player = exo
        preparedFor = null
        exo.addListener(listener)
        applyPrefs(prefs)
    }

    /** The service is going away: the position is saved and the player forgotten. */
    fun detach(exo: ExoPlayer) {
        if (player !== exo) return
        refreshFromPlayer()
        saveNow()
        exo.removeListener(listener)
        releaseEnhancer()
        player = null
        preparedFor = null
        controller?.let { MediaController.releaseFuture(it) }
        controller = null
        _nowPlaying.update { it?.copy(playing = false, buffering = false) }
    }

    /** Skip intervals for the notification and headset buttons ([BookPlayer]). */
    val skipBackMs: Long get() = prefs.skipBackSeconds * 1000L
    val skipForwardMs: Long get() = prefs.skipForwardSeconds * 1000L

    // Opening

    /**
     * Loads an audiobook into the player at its resume position (the newer of
     * this device's and the server's), playing if [play]. Reopening the one
     * already loaded only plays it.
     */
    fun open(audiobookId: Int, play: Boolean) {
        val current = _nowPlaying.value
        if (current != null && current.id == audiobookId) {
            if (play) play()
            // Fresh bookmarks and progress from another device, without moving a playing book.
            if (!current.playing) scope.launch { refreshDetail(audiobookId, moveToServer = true) }
            return
        }
        scope.launch {
            val session = app.active ?: return@launch
            // Leaving another book: its position is saved first.
            if (current != null) {
                refreshFromPlayer()
                record()
            }
            val cached = session.store.load(OfflineStore.Key.audiobook(audiobookId))
            val fresh = runCatching { session.client.audiobook(audiobookId) }
                .onFailure { it.rethrowIfCancellation() }
                .getOrNull()
            val detail = fresh ?: cached ?: run {
                _nowPlaying.value = null
                lastError.value = "Couldn't load this audiobook. Check your connection and try again."
                return@launch
            }
            if (fresh != null) {
                session.store.save(fresh, OfflineStore.Key.audiobook(audiobookId))
                detailFetchedAt = System.currentTimeMillis()
            } else {
                detailFetchedAt = 0
            }
            if (app.active?.id != session.id) return@launch
            lastError.value = null
            load(session, detail, play)
        }
    }

    /** A failure to open an audiobook, for the screen that asked (cleared by the next open). */
    val lastError = MutableStateFlow<String?>(null)

    private suspend fun load(session: Session.Active, detail: AudiobookDetail, play: Boolean) {
        val timeline = AudiobookTimeline.of(detail)
        val local = storeFor(session)?.latest(detail.id)
        val start = ListeningSync.startPosition(ListeningSync.resolve(local, detail.summary.progress), timeline.totalMs)
        val files = session.store.audiobookFiles(detail.id)
        preparedFor = null
        _nowPlaying.value = NowPlaying(
            sessionId = session.id,
            detail = detail,
            timeline = timeline,
            positionMs = start,
            speed = prefs.speed,
            bookmarks = detail.bookmarks,
            local = files.size == detail.parts.size && files.isNotEmpty(),
        )
        storeFor(session)?.setLastAudiobookId(detail.id)
        player?.let { if (it.mediaItemCount > 0 && preparedFor == null) it.clearMediaItems() }
        if (play) play()
    }

    /** After process death (or a relaunch): the last audiobook comes back paused where it was, from the cache. */
    private suspend fun restoreLast(session: Session.Active) {
        if (!app.audiobooksOffered.value || _nowPlaying.value != null) return
        val id = storeFor(session)?.lastAudiobookId() ?: return
        val cached = session.store.load(OfflineStore.Key.audiobook(id)) ?: return
        detailFetchedAt = 0
        load(session, cached, play = false)
    }

    // Transport

    fun togglePlay() = if (_nowPlaying.value?.playing == true) pause() else play()

    fun play() {
        val now = _nowPlaying.value ?: return
        scope.launch {
            val exo = ensurePlayer() ?: return@launch
            if (preparedFor != now.id || exo.mediaItemCount == 0) {
                if (!prepare(exo, play = true)) return@launch
            } else {
                if (exo.playbackState == Player.STATE_ENDED) seekTo(0)
                if (exo.playbackState == Player.STATE_IDLE) exo.prepare()
                exo.play()
            }
            _nowPlaying.update { it?.copy(error = null) }
            startTicker()
        }
    }

    fun pause() {
        player?.pause()
        _nowPlaying.update { it?.copy(playing = false) }
    }

    /** Seeks to a whole-book position (scrubber, chapter, bookmark). */
    fun seekTo(positionMs: Long) {
        val now = _nowPlaying.value ?: return
        val target = positionMs.coerceIn(0, now.timeline.totalMs)
        val exo = player
        if (exo != null && preparedFor == now.id) {
            val part = now.timeline.toPart(target)
            exo.seekTo(part.partIndex, part.offsetMs)
        }
        _nowPlaying.value = now.copy(positionMs = target, sleep = SleepTimer.retarget(now.sleep, now.timeline, target))
        record()
    }

    fun skipBack() = skipBy(-skipBackMs)
    fun skipForward() = skipBy(skipForwardMs)

    private fun skipBy(deltaMs: Long) {
        refreshFromPlayer()
        val now = _nowPlaying.value ?: return
        seekTo(now.timeline.skip(now.positionMs, deltaMs))
    }

    fun previousChapter() {
        refreshFromPlayer()
        val now = _nowPlaying.value ?: return
        seekTo(now.timeline.previousChapterTarget(now.positionMs))
    }

    fun nextChapter() {
        refreshFromPlayer()
        val now = _nowPlaying.value ?: return
        now.timeline.nextChapterTarget(now.positionMs)?.let(::seekTo)
    }

    fun setSpeed(speed: Float) {
        val valid = ListeningPrefs.validSpeed(speed)
        player?.setPlaybackSpeed(valid)
        _nowPlaying.update { it?.copy(speed = valid) }
        scope.launch { app.prefs.updateListening { it.copy(speed = valid) } }
    }

    fun setSkipSilence(on: Boolean) = scope.launch { app.prefs.updateListening { it.copy(skipSilence = on) } }
    fun setBoost(on: Boolean) = scope.launch { app.prefs.updateListening { it.copy(boost = on) } }

    // Sleep timer

    fun sleepIn(minutes: Int) = _nowPlaying.update { it?.copy(sleep = SleepTimer.minutes(minutes, SystemClock.elapsedRealtime())) }

    fun sleepAtChapterEnd() {
        refreshFromPlayer()
        _nowPlaying.update { it?.copy(sleep = SleepTimer.endOfChapter(it.timeline, it.positionMs)) }
    }

    fun extendSleep(minutes: Int = 5) {
        player?.volume = 1f
        _nowPlaying.update { it?.copy(sleep = SleepTimer.extend(it.sleep, minutes, SystemClock.elapsedRealtime())) }
    }

    fun cancelSleep() {
        player?.volume = 1f
        _nowPlaying.update { it?.copy(sleep = SleepTimer.Off) }
    }

    // Bookmarks (online: they need the server's id)

    /** Adds a bookmark here; the error message on failure. */
    suspend fun addBookmark(note: String?): String? {
        refreshFromPlayer()
        val now = _nowPlaying.value ?: return null
        val session = app.active ?: return "Not connected."
        return try {
            val bookmark = session.client.createAudiobookBookmark(now.id, now.positionMs, note)
            updateBookmarks(now.id) { (it + bookmark).sortedBy { b -> b.positionMs } }
            null
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            ApiError.from(e).userMessage
        }
    }

    suspend fun deleteBookmark(bookmarkId: Int): String? {
        val now = _nowPlaying.value ?: return null
        val session = app.active ?: return "Not connected."
        return try {
            session.client.deleteAudiobookBookmark(now.id, bookmarkId)
            updateBookmarks(now.id) { list -> list.filterNot { it.id == bookmarkId } }
            null
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            ApiError.from(e).userMessage
        }
    }

    private suspend fun updateBookmarks(id: Int, change: (List<AudiobookBookmark>) -> List<AudiobookBookmark>) {
        val now = _nowPlaying.value?.takeIf { it.id == id } ?: return
        val bookmarks = change(now.bookmarks)
        val detail = now.detail.copy(extras = now.detail.extras.copy(bookmarks = bookmarks))
        _nowPlaying.value = now.copy(bookmarks = bookmarks, detail = detail)
        app.active?.store?.save(detail, OfflineStore.Key.audiobook(id))
    }

    // Stopping

    /** The mini-player's close: saved, stopped and forgotten (nothing comes back at the next launch). */
    fun close() {
        refreshFromPlayer()
        saveNow()
        player?.let {
            it.stop()
            it.clearMediaItems()
        }
        preparedFor = null
        ticker?.cancel()
        _nowPlaying.value = null
        scope.launch { store?.setLastAudiobookId(null) }
        controller?.let { MediaController.releaseFuture(it) }
        controller = null
    }

    /** Saves the current position now (pause, the app going to the background, a stop). */
    fun saveNow() {
        if (_nowPlaying.value == null) return
        refreshFromPlayer()
        record()
    }

    // Downloads

    fun downloadsChanged() {
        _downloadsRevision.update { it + 1 }
        refreshFromPlayer()
        val now = _nowPlaying.value ?: return
        // Switch to (or away from) local files at the next pause; never in the middle of playback.
        if (!now.playing) preparedFor = null
    }

    // Progress

    private fun record(finished: Boolean = false) {
        val now = _nowPlaying.value ?: return
        val target = store ?: return
        if (now.sessionId != storeSession) return
        val position = if (finished) now.timeline.totalMs else now.positionMs
        val record = ListeningRecord(now.id, position, now.speed, finished, System.currentTimeMillis())
        lastSyncAt = SystemClock.elapsedRealtime()
        scope.launch {
            target.record(record)
            flush()
        }
    }

    /** Sends unsent positions (also called when the network comes back and when the app returns). */
    suspend fun flush() {
        val session = app.active ?: return
        val target = storeFor(session) ?: return
        target.flush { record ->
            session.client.saveAudiobookProgress(
                record.audiobookId,
                record.positionMs,
                record.speed,
                record.finished,
                ListeningSync.isoOf(record.recordedAt),
            )
        }
    }

    // Internals

    private suspend fun onSession(session: Session) {
        val id = (session as? Session.Active)?.id
        if (session is Session.Starting) return
        if (id == storeSession) return
        // Another server or account (or signed out): nothing of the last one may keep playing or show.
        if (_nowPlaying.value != null && _nowPlaying.value?.sessionId != id) {
            saveNow()
            player?.let {
                it.stop()
                it.clearMediaItems()
            }
            preparedFor = null
            _nowPlaying.value = null
        }
        storeSession = id
        store = (session as? Session.Active)?.let { ListeningStore.open(it.store.listeningFile) }
        if (session is Session.Active) {
            restoreLast(session)
            flush()
        }
    }

    private fun storeFor(session: Session.Active): ListeningStore? = store?.takeIf { storeSession == session.id }

    /** Starts (binds) the service; its player is attached by the time the controller connects. */
    private suspend fun ensurePlayer(): ExoPlayer? {
        player?.let { return it }
        val future = controller ?: MediaController.Builder(context, SessionToken(context, ComponentName(context, PlaybackService::class.java)))
            .buildAsync()
            .also { controller = it }
        return try {
            future.await(context)
            player
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            controller = null
            _nowPlaying.update { it?.copy(error = "The player couldn't start.") }
            null
        }
    }

    /** Puts the loaded audiobook's parts on the player at the current position. False when it can't play. */
    private suspend fun prepare(exo: ExoPlayer, play: Boolean): Boolean {
        val session = app.active ?: return false
        var now = _nowPlaying.value ?: return false
        val files = session.store.audiobookFiles(now.id)
        val local = files.size == now.detail.parts.size && files.isNotEmpty()
        // Streaming from a cached detail: its signed URLs may have expired, so try for fresh ones first.
        if (!local && System.currentTimeMillis() - detailFetchedAt > URL_MAX_AGE_MS) {
            refreshDetail(now.id, moveToServer = false)
            now = _nowPlaying.value ?: return false
        }
        if (now.detail.parts.isEmpty()) {
            _nowPlaying.value = now.copy(error = "This audiobook has no audio files.")
            return false
        }
        val items = mediaItems(now.detail, files)
        val part = now.timeline.toPart(now.positionMs)
        exo.setMediaItems(items, part.partIndex, part.offsetMs)
        exo.setPlaybackSpeed(prefs.speed)
        exo.prepare()
        exo.playWhenReady = play
        preparedFor = now.id
        _nowPlaying.value = now.copy(local = local, speed = prefs.speed, error = null)
        return true
    }

    /** Fresh part URLs (and progress from other devices) from the server; quiet offline. */
    private suspend fun refreshDetail(id: Int, moveToServer: Boolean) {
        val session = app.active ?: return
        val fresh = runCatching { session.client.audiobook(id) }.onFailure { it.rethrowIfCancellation() }.getOrNull() ?: return
        session.store.save(fresh, OfflineStore.Key.audiobook(id))
        detailFetchedAt = System.currentTimeMillis()
        val now = _nowPlaying.value?.takeIf { it.id == id } ?: return
        var position = now.positionMs
        if (moveToServer && !now.playing) {
            val local = storeFor(session)?.latest(id)
            val resolved = ListeningSync.resolve(local, fresh.summary.progress)
            if (!resolved.fromLocal) position = ListeningSync.startPosition(resolved, AudiobookTimeline.of(fresh).totalMs)
        }
        _nowPlaying.value = now.copy(detail = fresh, timeline = AudiobookTimeline.of(fresh), bookmarks = fresh.bookmarks, positionMs = position)
        if (position != now.positionMs) {
            val exo = player
            if (exo != null && preparedFor == id) {
                val part = AudiobookTimeline.of(fresh).toPart(position)
                exo.seekTo(part.partIndex, part.offsetMs)
            }
        }
    }

    private suspend fun reloadParts(play: Boolean) {
        val exo = player ?: return
        refreshFromPlayer()
        val now = _nowPlaying.value ?: return
        refreshDetail(now.id, moveToServer = false)
        prepare(exo, play)
    }

    /** Reads the player's position into [nowPlaying] (as a whole-book position). */
    private fun refreshFromPlayer() {
        val exo = player ?: return
        val now = _nowPlaying.value ?: return
        if (preparedFor != now.id || exo.mediaItemCount == 0) return
        val position = now.timeline.toBook(exo.currentMediaItemIndex, exo.currentPosition)
        val buffering = exo.playbackState == Player.STATE_BUFFERING && exo.playWhenReady
        val next = now.copy(positionMs = position, playing = exo.isPlaying, buffering = buffering)
        if (next != now) _nowPlaying.value = next
    }

    /** While something is playing: the position for the UI, the sleep timer and the 15-second sync. */
    private fun startTicker() {
        if (ticker?.isActive == true) return
        ticker = scope.launch {
            while (isActive) {
                refreshFromPlayer()
                val now = _nowPlaying.value ?: break
                tickSleep(now)
                if (now.playing && SystemClock.elapsedRealtime() - lastSyncAt >= ListeningSync.SYNC_INTERVAL_MS) record()
                if (!now.playing && !now.buffering && now.sleep !is SleepTimer.Duration) break
                delay(if (now.playing) 500 else 1000)
            }
        }
    }

    private fun tickSleep(now: NowPlaying) {
        when (val decision = SleepTimer.decide(now.sleep, SystemClock.elapsedRealtime(), now.positionMs)) {
            SleepTimer.Decision.Keep -> Unit
            is SleepTimer.Decision.Fade -> if (now.playing) player?.volume = decision.volume
            SleepTimer.Decision.Stop -> {
                player?.pause()
                player?.volume = 1f
                _nowPlaying.value = now.copy(sleep = SleepTimer.Off, playing = false)
                saveNow()
            }
        }
    }

    private fun applyPrefs(next: ListeningPrefs) {
        prefs = next
        player?.let { exo ->
            exo.skipSilenceEnabled = next.skipSilence
            if (exo.playbackParameters.speed != next.speed) exo.setPlaybackSpeed(next.speed)
        }
        _nowPlaying.update { it?.copy(speed = next.speed) }
        applyBoost()
    }

    private fun applyBoost() {
        val exo = player ?: return releaseEnhancer()
        if (!prefs.boost) return releaseEnhancer()
        val sessionId = exo.audioSessionId
        if (sessionId == C.AUDIO_SESSION_ID_UNSET) return
        runCatching {
            releaseEnhancer()
            enhancer = LoudnessEnhancer(sessionId).apply {
                setTargetGain(BOOST_MILLIBELS)
                enabled = true
            }
        }
    }

    private fun releaseEnhancer() {
        runCatching { enhancer?.release() }
        enhancer = null
    }

    // Playback resumption (a headset's play button or the system's media controls after the process died)

    /** The last audiobook as media items at its position, or null; used by [PlaybackService]. */
    suspend fun resumption(): MediaSession.MediaItemsWithStartPosition? {
        val session = app.active ?: return null
        if (_nowPlaying.value == null) restoreLast(session)
        val now = _nowPlaying.value ?: return null
        val files = session.store.audiobookFiles(now.id)
        if (files.size != now.detail.parts.size && System.currentTimeMillis() - detailFetchedAt > URL_MAX_AGE_MS) {
            refreshDetail(now.id, moveToServer = false)
        }
        val current = _nowPlaying.value ?: return null
        if (current.detail.parts.isEmpty()) return null
        val part = current.timeline.toPart(current.positionMs)
        preparedFor = current.id
        startTicker()
        return MediaSession.MediaItemsWithStartPosition(mediaItems(current.detail, files), part.partIndex, part.offsetMs)
    }

    companion object {
        /** Signed part URLs last 24 h; a cached detail older than this gets fresh ones before streaming. */
        const val URL_MAX_AGE_MS = 20 * 60 * 60 * 1000L

        /** Volume boost: +8 dB. */
        const val BOOST_MILLIBELS = 800

        fun mediaItems(detail: AudiobookDetail, files: Map<Int, File>): List<MediaItem> {
            val summary = detail.summary
            val metadata = MediaMetadata.Builder()
                .setTitle(summary.title)
                .setArtist(summary.authorOrNull)
                .setAlbumTitle(summary.title)
                .setAlbumArtist(summary.authorOrNull)
                .setArtworkUri(summary.coverUrl?.takeIf { it.isNotBlank() }?.let(Uri::parse))
                .setIsPlayable(true)
                .setIsBrowsable(false)
                .setMediaType(MediaMetadata.MEDIA_TYPE_AUDIO_BOOK_CHAPTER)
                .build()
            return detail.parts.map { part ->
                val uri = files[part.index]?.let(Uri::fromFile) ?: Uri.parse(part.url)
                MediaItem.Builder()
                    .setMediaId("audiobook:${detail.id}:${part.index}")
                    .setUri(uri)
                    .setMediaMetadata(metadata)
                    .build()
            }
        }

        fun playbackMessage(error: PlaybackException): String = when (error.errorCode) {
            PlaybackException.ERROR_CODE_IO_NETWORK_CONNECTION_FAILED,
            PlaybackException.ERROR_CODE_IO_NETWORK_CONNECTION_TIMEOUT,
            -> "Can't reach your server. Download the audiobook to listen offline."
            PlaybackException.ERROR_CODE_IO_BAD_HTTP_STATUS -> "The server wouldn't send this audio."
            PlaybackException.ERROR_CODE_IO_FILE_NOT_FOUND -> "The audio file is missing."
            else -> "This audio couldn't be played (${error.errorCodeName})."
        }
    }
}

/** Waits for a controller to connect (on the main thread). */
private suspend fun ListenableFuture<MediaController>.await(context: Context): MediaController =
    suspendCancellableCoroutine { cont ->
        addListener({
            try {
                cont.resume(get())
            } catch (e: Exception) {
                cont.resumeWithException(e.cause ?: e)
            }
        }, ContextCompat.getMainExecutor(context))
    }
