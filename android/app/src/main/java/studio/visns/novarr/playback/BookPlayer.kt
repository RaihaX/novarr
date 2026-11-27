package studio.visns.novarr.playback

import androidx.annotation.OptIn
import androidx.media3.common.ForwardingPlayer
import androidx.media3.common.Player
import androidx.media3.common.util.UnstableApi

/**
 * The player the media session (notification, lock screen, Bluetooth and
 * headset buttons) sees. Skips move through the WHOLE book, across parts
 * ([ListeningModel] does the mapping), by the reader's skip intervals; and
 * "next" / "previous" (a headset's double / triple press, the car's buttons)
 * skip forward and back rather than jump between files, which in an
 * audiobook are arbitrary.
 */
@OptIn(UnstableApi::class)
class BookPlayer(player: Player, private val listening: ListeningModel) : ForwardingPlayer(player) {
    override fun seekBack() = listening.skipBack()
    override fun seekForward() = listening.skipForward()
    override fun seekToNext() = listening.skipForward()
    override fun seekToPrevious() = listening.skipBack()
    override fun seekToNextMediaItem() = listening.skipForward()
    override fun seekToPreviousMediaItem() = listening.skipBack()
    override fun getSeekBackIncrement(): Long = listening.skipBackMs
    override fun getSeekForwardIncrement(): Long = listening.skipForwardMs

    override fun play() {
        // A play from outside the app (notification, headset) on a book not on the player yet goes through the model.
        if (wrappedPlayer.mediaItemCount == 0) listening.play() else super.play()
    }

    override fun getAvailableCommands(): Player.Commands {
        val base = super.getAvailableCommands()
        if (wrappedPlayer.mediaItemCount == 0) return base
        return base.buildUpon().addAll(*SKIPS).build()
    }

    override fun isCommandAvailable(command: Int): Boolean =
        (command in SKIPS && wrappedPlayer.mediaItemCount > 0) || super.isCommandAvailable(command)

    private companion object {
        val SKIPS = intArrayOf(
            Player.COMMAND_SEEK_BACK,
            Player.COMMAND_SEEK_FORWARD,
            Player.COMMAND_SEEK_TO_NEXT,
            Player.COMMAND_SEEK_TO_PREVIOUS,
            Player.COMMAND_SEEK_TO_NEXT_MEDIA_ITEM,
            Player.COMMAND_SEEK_TO_PREVIOUS_MEDIA_ITEM,
        )
    }
}
