package studio.visns.novarr.playback

import android.app.PendingIntent
import android.content.Intent
import androidx.annotation.OptIn
import androidx.media3.common.AudioAttributes
import androidx.media3.common.C
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.source.DefaultMediaSourceFactory
import androidx.media3.session.CommandButton
import androidx.media3.session.MediaSession
import androidx.media3.session.MediaSessionService
import com.google.common.util.concurrent.ListenableFuture
import com.google.common.util.concurrent.SettableFuture
import kotlinx.coroutines.launch
import studio.visns.novarr.MainActivity
import studio.visns.novarr.NovarrApplication
import studio.visns.novarr.app.Notifications

/**
 * Owns the audiobook player, so it keeps playing with the app's UI closed:
 * a foreground media service while playing (notification, lock screen,
 * Bluetooth and headset controls through its media session). Audio focus
 * is handled by ExoPlayer (speech: it pauses for calls and other audio
 * rather than ducking), and unplugging headphones pauses (becoming noisy).
 */
@OptIn(UnstableApi::class)
class PlaybackService : MediaSessionService() {
    private var session: MediaSession? = null
    private var exo: ExoPlayer? = null

    private val model get() = (application as NovarrApplication).model

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

        session = MediaSession.Builder(this, BookPlayer(player, listening))
            .setId("novarr-audiobooks")
            .setSessionActivity(activity)
            .setCallback(Callback())
            // Skip back / forward around play, instead of "previous / next file".
            .setMediaButtonPreferences(
                listOf(
                    CommandButton.Builder(CommandButton.ICON_SKIP_BACK)
                        .setDisplayName("Skip back")
                        .setPlayerCommand(Player.COMMAND_SEEK_BACK)
                        .setSlots(CommandButton.SLOT_BACK)
                        .build(),
                    CommandButton.Builder(CommandButton.ICON_SKIP_FORWARD)
                        .setDisplayName("Skip forward")
                        .setPlayerCommand(Player.COMMAND_SEEK_FORWARD)
                        .setSlots(CommandButton.SLOT_FORWARD)
                        .build(),
                ),
            )
            .build()
    }

    override fun onGetSession(controllerInfo: MediaSession.ControllerInfo): MediaSession? = session

    /** Swiped away from Recents: keeps playing if it is, otherwise the service goes. */
    override fun onTaskRemoved(rootIntent: Intent?) {
        val player = session?.player
        if (player == null || !player.playWhenReady || player.mediaItemCount == 0 || player.playbackState == Player.STATE_ENDED) {
            model.listening.saveNow()
            stopSelf()
        }
    }

    override fun onDestroy() {
        exo?.let { model.listening.detach(it) }
        session?.run {
            player.release()
            release()
        }
        session = null
        exo = null
        super.onDestroy()
    }

    private inner class Callback : MediaSession.Callback {
        /** A play from a headset or the system's controls after the process died: the last audiobook, where it was. */
        override fun onPlaybackResumption(
            mediaSession: MediaSession,
            controller: MediaSession.ControllerInfo,
        ): ListenableFuture<MediaSession.MediaItemsWithStartPosition> {
            val future = SettableFuture.create<MediaSession.MediaItemsWithStartPosition>()
            model.scope.launch {
                try {
                    val items = model.listening.resumption()
                    if (items != null) future.set(items) else future.setException(UnsupportedOperationException("Nothing to resume"))
                } catch (e: Exception) {
                    future.setException(e)
                }
            }
            return future
        }
    }
}
