package studio.visns.novarr.playback

import android.app.PendingIntent
import android.content.Intent
import android.os.Bundle
import androidx.annotation.OptIn
import androidx.media3.common.AudioAttributes
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.source.DefaultMediaSourceFactory
import androidx.media3.session.CommandButton
import androidx.media3.session.LibraryResult
import androidx.media3.session.MediaConstants
import androidx.media3.session.MediaLibraryService
import androidx.media3.session.MediaSession
import androidx.media3.session.SessionCommand
import androidx.media3.session.SessionError
import androidx.media3.session.SessionResult
import com.google.common.collect.ImmutableList
import com.google.common.util.concurrent.Futures
import com.google.common.util.concurrent.ListenableFuture
import com.google.common.util.concurrent.SettableFuture
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.drop
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch
import studio.visns.novarr.MainActivity
import studio.visns.novarr.NovarrApplication
import studio.visns.novarr.R
import studio.visns.novarr.app.Notifications
import studio.visns.novarr.domain.AutoBrowse
import studio.visns.novarr.domain.SleepTimer
import studio.visns.novarr.store.ListeningPrefs

/**
 * Owns the audiobook player, so it keeps playing with the app's UI closed:
 * a foreground media service while playing (notification, lock screen,
 * Bluetooth and headset controls through its media session). Audio focus
 * is handled by ExoPlayer (speech: it pauses for calls and other audio
 * rather than ducking), and unplugging headphones pauses (becoming noisy).
 *
 * It is also a media library (Android Auto, a car's own system, Wear): the
 * browse tree of [AutoBrowse] through [AutoLibrary], playing an item at its
 * resume position ([ListeningModel.itemsFor]), search and voice ("play
 * <title> on Novarr"), and the player's custom buttons (speed, chapters,
 * sleep timer) beside skip back / forward.
 */
@OptIn(UnstableApi::class)
class PlaybackService : MediaLibraryService() {
    private var session: MediaLibrarySession? = null
    private var exo: ExoPlayer? = null
    private val watchers = mutableListOf<Job>()

    private val model get() = (application as NovarrApplication).model
    private val scope: CoroutineScope get() = model.scope
    private val library by lazy { AutoLibrary(applicationContext, model) }

    override fun onCreate() {
        super.onCreate()
        val listening = model.listening
        val player = ExoPlayer.Builder(this)
            .setMediaSourceFactory(DefaultMediaSourceFactory(AudioDataSource.factory(this, model)))
            .setAudioAttributes(
                AudioAttributes.Builder().setUsage(C.USAGE_MEDIA).setContentType(C.AUDIO_CONTENT_TYPE_SPEECH).build(),
                /* handleAudioFocus = */ true,
            )
            .setHandleAudioBecomingNoisy(true)
            .setWakeMode(C.WAKE_MODE_NETWORK)
            .build()
        exo = player
        listening.attach(player)

        val open = Intent(this, MainActivity::class.java)
            .setAction(Notifications.ACTION_OPEN_PLAYER)
            .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP)
        val activity = PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)

        session = MediaLibrarySession.Builder(this, BookPlayer(player, listening), Callback())
            .setId("novarr-audiobooks")
            .setSessionActivity(activity)
            .setMediaButtonPreferences(buttons(ListeningPrefs(), 1f, SleepTimer.Off))
            .build()

        // The buttons follow the speed, the sleep timer and the skip intervals.
        watchers += scope.launch {
            combine(model.prefs.listening, listening.nowPlaying) { prefs, now -> Triple(prefs, now?.speed ?: prefs.speed, sleepKind(now?.sleep)) }
                .distinctUntilChanged()
                .collect { (prefs, speed, _) -> session?.setMediaButtonPreferences(buttons(prefs, speed, listening.nowPlaying.value?.sleep ?: SleepTimer.Off)) }
        }
        // A finished or removed download, another book or a changed list: the car's folders are asked again.
        watchers += scope.launch {
            listening.downloadsRevision.drop(1).collect {
                library.invalidate()
                session?.notifyChildrenChanged(AutoBrowse.DOWNLOADED, Int.MAX_VALUE, null)
            }
        }
        watchers += scope.launch {
            listening.nowPlaying.map { it?.id }.distinctUntilChanged().drop(1).collect {
                library.invalidate()
                session?.notifyChildrenChanged(AutoBrowse.CONTINUE, Int.MAX_VALUE, null)
            }
        }
        watchers += scope.launch {
            model.lists.state.map { it.lists }.distinctUntilChanged().drop(1).collect {
                library.invalidate()
                session?.notifyChildrenChanged(AutoBrowse.FAVOURITES, Int.MAX_VALUE, null)
                session?.notifyChildrenChanged(AutoBrowse.WANT, Int.MAX_VALUE, null)
                session?.notifyChildrenChanged(AutoBrowse.BROWSE, Int.MAX_VALUE, null)
            }
        }
    }

    override fun onGetSession(controllerInfo: MediaSession.ControllerInfo): MediaLibrarySession? = session

    /** Swiped away from Recents: keeps playing if it is, otherwise the service goes. */
    override fun onTaskRemoved(rootIntent: Intent?) {
        val player = session?.player
        if (player == null || !player.playWhenReady || player.mediaItemCount == 0 || player.playbackState == Player.STATE_ENDED) {
            model.listening.saveNow()
            stopSelf()
        }
    }

    override fun onDestroy() {
        watchers.forEach { it.cancel() }
        watchers.clear()
        exo?.let { model.listening.detach(it) }
        session?.run {
            player.release()
            release()
        }
        session = null
        exo = null
        super.onDestroy()
    }

    // The player's buttons

    private fun sleepKind(sleep: SleepTimer?): String = when (sleep) {
        null, SleepTimer.Off -> "off"
        is SleepTimer.Duration -> "min:${sleep.minutes}"
        is SleepTimer.EndOfChapter -> "chapter"
    }

    /**
     * Skip back / forward around play (the reader's intervals; "previous /
     * next" skip too, see [BookPlayer]), then speed, chapters and the sleep
     * timer — the car's custom actions and the notification's extra buttons.
     */
    private fun buttons(prefs: ListeningPrefs, speed: Float, sleep: SleepTimer): List<CommandButton> = listOf(
        CommandButton.Builder(backIcon(prefs.skipBackSeconds))
            .setDisplayName("Back ${prefs.skipBackSeconds} seconds")
            .setPlayerCommand(Player.COMMAND_SEEK_BACK)
            .setSlots(CommandButton.SLOT_BACK)
            .build(),
        CommandButton.Builder(forwardIcon(prefs.skipForwardSeconds))
            .setDisplayName("Forward ${prefs.skipForwardSeconds} seconds")
            .setPlayerCommand(Player.COMMAND_SEEK_FORWARD)
            .setSlots(CommandButton.SLOT_FORWARD)
            .build(),
        CommandButton.Builder(speedIcon(speed))
            .setDisplayName("Speed ${ListeningPrefs.validSpeed(speed).let { if (it % 1f == 0f) it.toInt().toString() else it.toString() }}×")
            .setSessionCommand(SPEED)
            .setSlots(CommandButton.SLOT_OVERFLOW)
            .build(),
        CommandButton.Builder(CommandButton.ICON_PREVIOUS)
            .setDisplayName("Previous chapter")
            .setSessionCommand(PREVIOUS_CHAPTER)
            .setSlots(CommandButton.SLOT_OVERFLOW)
            .build(),
        CommandButton.Builder(CommandButton.ICON_NEXT)
            .setDisplayName("Next chapter")
            .setSessionCommand(NEXT_CHAPTER)
            .setSlots(CommandButton.SLOT_OVERFLOW)
            .build(),
        CommandButton.Builder(CommandButton.ICON_UNDEFINED)
            .setCustomIconResId(R.drawable.ic_sleep_timer)
            .setDisplayName(
                when (sleep) {
                    SleepTimer.Off -> "Sleep timer"
                    is SleepTimer.Duration -> "Sleep in ${sleep.minutes} min"
                    is SleepTimer.EndOfChapter -> "Sleep at chapter end"
                },
            )
            .setSessionCommand(SLEEP)
            .setSlots(CommandButton.SLOT_OVERFLOW)
            .build(),
    )

    private fun backIcon(seconds: Int) = when (seconds) {
        10 -> CommandButton.ICON_SKIP_BACK_10
        15 -> CommandButton.ICON_SKIP_BACK_15
        30 -> CommandButton.ICON_SKIP_BACK_30
        else -> CommandButton.ICON_SKIP_BACK
    }

    private fun forwardIcon(seconds: Int) = when (seconds) {
        10 -> CommandButton.ICON_SKIP_FORWARD_10
        15 -> CommandButton.ICON_SKIP_FORWARD_15
        30 -> CommandButton.ICON_SKIP_FORWARD_30
        else -> CommandButton.ICON_SKIP_FORWARD
    }

    private fun speedIcon(speed: Float) = when {
        speed < 0.65f -> CommandButton.ICON_PLAYBACK_SPEED_0_5
        speed < 0.9f -> CommandButton.ICON_PLAYBACK_SPEED_0_8
        speed < 1.1f -> CommandButton.ICON_PLAYBACK_SPEED_1_0
        speed < 1.35f -> CommandButton.ICON_PLAYBACK_SPEED_1_2
        speed < 1.65f -> CommandButton.ICON_PLAYBACK_SPEED_1_5
        speed < 1.9f -> CommandButton.ICON_PLAYBACK_SPEED_1_8
        speed < 2.2f -> CommandButton.ICON_PLAYBACK_SPEED_2_0
        else -> CommandButton.ICON_PLAYBACK_SPEED
    }

    /** Runs [block] on the app's scope and completes the future with what it returns (an exception fails it). */
    private fun <T> future(block: suspend () -> T): ListenableFuture<T> {
        val future = SettableFuture.create<T>()
        scope.launch {
            try {
                future.set(block())
            } catch (e: Exception) {
                future.setException(e)
            }
        }
        return future
    }

    private inner class Callback : MediaLibrarySession.Callback {
        /**
         * Every controller (the app, the notification, Bluetooth, Android Auto, a watch) gets the
         * full session, library and player commands plus ours, as before Media3 1.11. Media3's new
         * default would give "untrusted" controllers read access only, and a car or a headset
         * companion app is not always counted as trusted.
         */
        override fun onConnectAsync(
            session: MediaSession,
            controller: MediaSession.ControllerInfo,
        ): ListenableFuture<MediaSession.ConnectionResult> {
            val commands = MediaSession.ConnectionResult.DEFAULT_SESSION_AND_LIBRARY_COMMANDS.buildUpon()
                .add(SPEED)
                .add(PREVIOUS_CHAPTER)
                .add(NEXT_CHAPTER)
                .add(SLEEP)
                .build()
            return Futures.immediateFuture(
                MediaSession.ConnectionResult.accept(commands, MediaSession.ConnectionResult.DEFAULT_PLAYER_COMMANDS),
            )
        }

        override fun onCustomCommand(
            session: MediaSession,
            controller: MediaSession.ControllerInfo,
            customCommand: SessionCommand,
            args: Bundle,
        ): ListenableFuture<SessionResult> {
            val listening = model.listening
            when (customCommand.customAction) {
                SPEED.customAction -> listening.cycleSpeed()
                PREVIOUS_CHAPTER.customAction -> listening.previousChapter()
                NEXT_CHAPTER.customAction -> listening.nextChapter()
                SLEEP.customAction -> listening.cycleSleep()
                else -> return Futures.immediateFuture(SessionResult(SessionError.ERROR_NOT_SUPPORTED))
            }
            return Futures.immediateFuture(SessionResult(SessionResult.RESULT_SUCCESS))
        }

        /**
         * A play from a headset or the system's controls after the process died: the last audiobook,
         * where it was. The same answer when the system only asks what would play (isForPlayback false).
         */
        override fun onPlaybackResumption(
            mediaSession: MediaSession,
            controller: MediaSession.ControllerInfo,
            isForPlayback: Boolean,
        ): ListenableFuture<MediaSession.MediaItemsWithStartPosition> = future {
            model.listening.resumption() ?: throw UnsupportedOperationException("Nothing to resume")
        }

        // Browsing

        override fun onGetLibraryRoot(
            session: MediaLibrarySession,
            browser: MediaSession.ControllerInfo,
            params: LibraryParams?,
        ): ListenableFuture<LibraryResult<MediaItem>> {
            val recent = params?.isRecent == true
            val extras = Bundle().apply {
                putBoolean(SEARCH_SUPPORTED, true)
                putInt(MediaConstants.EXTRAS_KEY_CONTENT_STYLE_BROWSABLE, MediaConstants.EXTRAS_VALUE_CONTENT_STYLE_GRID_ITEM)
                putInt(MediaConstants.EXTRAS_KEY_CONTENT_STYLE_PLAYABLE, MediaConstants.EXTRAS_VALUE_CONTENT_STYLE_LIST_ITEM)
            }
            val rootParams = LibraryParams.Builder().setRecent(recent).setExtras(extras).build()
            return Futures.immediateFuture(LibraryResult.ofItem(library.item(AutoBrowse.rootNode(recent)), rootParams))
        }

        override fun onGetChildren(
            session: MediaLibrarySession,
            browser: MediaSession.ControllerInfo,
            parentId: String,
            page: Int,
            pageSize: Int,
            params: LibraryParams?,
        ): ListenableFuture<LibraryResult<ImmutableList<MediaItem>>> = future {
            val items = library.children(parentId, page, pageSize)
            if (items == null) LibraryResult.ofError(SessionError.ERROR_BAD_VALUE) else LibraryResult.ofItemList(items, params)
        }

        override fun onGetItem(
            session: MediaLibrarySession,
            browser: MediaSession.ControllerInfo,
            mediaId: String,
        ): ListenableFuture<LibraryResult<MediaItem>> = future {
            library.item(mediaId)?.let { LibraryResult.ofItem(it, null) } ?: LibraryResult.ofError(SessionError.ERROR_BAD_VALUE)
        }

        override fun onSearch(
            session: MediaLibrarySession,
            browser: MediaSession.ControllerInfo,
            query: String,
            params: LibraryParams?,
        ): ListenableFuture<LibraryResult<Void>> {
            scope.launch {
                val count = runCatching { library.search(query).size }.getOrDefault(0)
                session.notifySearchResultChanged(browser, query, count, params)
            }
            return Futures.immediateFuture(LibraryResult.ofVoid())
        }

        override fun onGetSearchResult(
            session: MediaLibrarySession,
            browser: MediaSession.ControllerInfo,
            query: String,
            page: Int,
            pageSize: Int,
            params: LibraryParams?,
        ): ListenableFuture<LibraryResult<ImmutableList<MediaItem>>> = future {
            LibraryResult.ofItemList(library.searchItems(query, page, pageSize), params)
        }

        // Playing what was chosen (a tap in the car, a voice request)

        override fun onSetMediaItems(
            mediaSession: MediaSession,
            controller: MediaSession.ControllerInfo,
            mediaItems: MutableList<MediaItem>,
            startIndex: Int,
            startPositionMs: Long,
        ): ListenableFuture<MediaSession.MediaItemsWithStartPosition> {
            val first = mediaItems.firstOrNull()
            // Items that can already play (a controller with real URIs) go on as they are.
            if (first == null || mediaItems.all { it.localConfiguration != null }) {
                return super.onSetMediaItems(mediaSession, controller, mediaItems, startIndex, startPositionMs)
            }
            return future { resolve(first) ?: throw UnsupportedOperationException("Nothing to play for ${first.mediaId}") }
        }

        override fun onAddMediaItems(
            mediaSession: MediaSession,
            controller: MediaSession.ControllerInfo,
            mediaItems: MutableList<MediaItem>,
        ): ListenableFuture<MutableList<MediaItem>> {
            val first = mediaItems.firstOrNull()
            if (first == null || mediaItems.all { it.localConfiguration != null }) {
                return super.onAddMediaItems(mediaSession, controller, mediaItems)
            }
            // An audiobook is one item to the car but several parts to the player: the whole book, where it was.
            return future { resolve(first)?.mediaItems?.toMutableList() ?: throw UnsupportedOperationException("Nothing to add") }
        }
    }

    /**
     * What a chosen item plays: an audiobook by id (book:<id>), a search
     * ("play <title>": the best match), or with nothing said ("play
     * Novarr") the last audiobook.
     */
    private suspend fun resolve(item: MediaItem): MediaSession.MediaItemsWithStartPosition? {
        val listening = model.listening
        AutoBrowse.parseBook(item.mediaId)?.let { return listening.itemsFor(it) }
        val query = item.requestMetadata.searchQuery
        if (query != null) {
            if (query.isBlank()) return listening.resumption()
            val match = library.search(query).firstOrNull() ?: return null
            return listening.itemsFor(match.audiobookId)
        }
        // A folder chosen to play: its first unfinished audiobook.
        val children = library.children(item.mediaId, 0, 50).orEmpty()
        val pick = children.firstOrNull { child ->
            AutoBrowse.parseBook(child.mediaId) != null &&
                child.mediaMetadata.extras?.getInt(MediaConstants.EXTRAS_KEY_COMPLETION_STATUS) != MediaConstants.EXTRAS_VALUE_COMPLETION_STATUS_FULLY_PLAYED
        } ?: children.firstOrNull { AutoBrowse.parseBook(it.mediaId) != null }
        return AutoBrowse.parseBook(pick?.mediaId)?.let { listening.itemsFor(it) }
    }

    private companion object {
        val SPEED = SessionCommand("studio.visns.novarr.SPEED", Bundle.EMPTY)
        val PREVIOUS_CHAPTER = SessionCommand("studio.visns.novarr.PREVIOUS_CHAPTER", Bundle.EMPTY)
        val NEXT_CHAPTER = SessionCommand("studio.visns.novarr.NEXT_CHAPTER", Bundle.EMPTY)
        val SLEEP = SessionCommand("studio.visns.novarr.SLEEP", Bundle.EMPTY)

        /** The legacy browser's "search supported" root hint (MediaBrowserCompat.EXTRA_... / Android Auto's search box). */
        const val SEARCH_SUPPORTED = "android.media.browse.SEARCH_SUPPORTED"
    }
}
