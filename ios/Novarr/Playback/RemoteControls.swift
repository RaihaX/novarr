import AVFoundation
import MediaPlayer
import UIKit

/// The lock screen, Control Centre, headphones and car (Bluetooth) controls:
/// `MPNowPlayingInfoCenter` (title, author, chapter, artwork, whole-book
/// elapsed / duration and rate) and `MPRemoteCommandCenter` (play, pause,
/// skip back / forward by the reader's intervals, scrubbing). As on Android
/// (BookPlayer.kt), "next" / "previous track" (a headset's double / triple
/// press) skip forward and back rather than jump between files.
@MainActor
final class RemoteControls {
    struct Actions {
        var play: () -> Void
        var pause: () -> Void
        var toggle: () -> Void
        var skipForward: () -> Void
        var skipBack: () -> Void
        var seek: (Int64) -> Void
    }

    private var actions: Actions?
    /// The cover arrived: show the current state again (with it).
    var onArtwork: () -> Void = {}
    private var registered = false
    private var shownKey: String?
    private var shownAt: (position: Int64, at: TimeInterval, rate: Double)?
    private var artworkFor: URL?
    private var artwork: MPMediaItemArtwork?

    func register(_ actions: Actions) {
        self.actions = actions
        guard !registered else { return }
        registered = true
        let center = MPRemoteCommandCenter.shared()
        center.playCommand.addTarget { [weak self] _ in self?.run { $0.play() } ?? .commandFailed }
        center.pauseCommand.addTarget { [weak self] _ in self?.run { $0.pause() } ?? .commandFailed }
        center.togglePlayPauseCommand.addTarget { [weak self] _ in self?.run { $0.toggle() } ?? .commandFailed }
        center.skipForwardCommand.addTarget { [weak self] _ in self?.run { $0.skipForward() } ?? .commandFailed }
        center.skipBackwardCommand.addTarget { [weak self] _ in self?.run { $0.skipBack() } ?? .commandFailed }
        center.nextTrackCommand.addTarget { [weak self] _ in self?.run { $0.skipForward() } ?? .commandFailed }
        center.previousTrackCommand.addTarget { [weak self] _ in self?.run { $0.skipBack() } ?? .commandFailed }
        center.changePlaybackPositionCommand.addTarget { [weak self] event in
            guard let event = event as? MPChangePlaybackPositionCommandEvent else { return .commandFailed }
            let ms = Int64((event.positionTime * 1000).rounded())
            return self?.run { $0.seek(ms) } ?? .commandFailed
        }
        // Rating, likes, speed menus and the like: not offered.
        center.changePlaybackRateCommand.isEnabled = false
        center.seekForwardCommand.isEnabled = false
        center.seekBackwardCommand.isEnabled = false
        setEnabled(false)
    }

    /// The skip buttons' numbers follow Settings › Listening.
    func setSkipIntervals(back: Int, forward: Int) {
        let center = MPRemoteCommandCenter.shared()
        center.skipBackwardCommand.preferredIntervals = [NSNumber(value: back)]
        center.skipForwardCommand.preferredIntervals = [NSNumber(value: forward)]
    }

    /// Shows (or updates) what is playing. Cheap to call often: the info is
    /// only rewritten when something the system can't work out itself changed.
    func show(_ now: NowPlaying) {
        setEnabled(true)
        let rate = now.playing ? now.speed : 0
        let chapter = now.timeline.chapterAt(now.positionMs)
        let key = "\(now.id)|\(rate)|\(chapter?.index ?? -1)|\(now.timeline.totalMs)|\(artwork != nil && artworkFor == now.detail.summary.coverUrl)"
        let clock = ProcessInfo.processInfo.systemUptime
        if key == shownKey, let shownAt {
            // The system advances the elapsed time by the rate; rewrite only after a jump.
            let expected = shownAt.position + Int64((clock - shownAt.at) * 1000 * shownAt.rate)
            if abs(expected - now.positionMs) < 1_500 { return }
        }
        shownKey = key
        shownAt = (now.positionMs, clock, rate)

        let summary = now.detail.summary
        var info: [String: Any] = [
            MPMediaItemPropertyTitle: summary.title,
            MPMediaItemPropertyPlaybackDuration: Double(now.timeline.totalMs) / 1000,
            MPNowPlayingInfoPropertyElapsedPlaybackTime: Double(now.positionMs) / 1000,
            MPNowPlayingInfoPropertyPlaybackRate: rate,
            MPNowPlayingInfoPropertyDefaultPlaybackRate: now.speed,
            MPNowPlayingInfoPropertyMediaType: MPNowPlayingInfoMediaType.audio.rawValue,
        ]
        if let author = summary.authorOrNil { info[MPMediaItemPropertyArtist] = author }
        if let chapter {
            info[MPMediaItemPropertyAlbumTitle] = chapter.label
            info[MPNowPlayingInfoPropertyChapterNumber] = chapter.index
            info[MPNowPlayingInfoPropertyChapterCount] = now.timeline.chapters.count
        } else {
            info[MPMediaItemPropertyAlbumTitle] = summary.title
        }
        if let artwork, artworkFor == summary.coverUrl { info[MPMediaItemPropertyArtwork] = artwork }
        MPNowPlayingInfoCenter.default().nowPlayingInfo = info
        MPNowPlayingInfoCenter.default().playbackState = now.playing ? .playing : .paused
        loadArtwork(summary.coverUrl)
    }

    /// Nothing loaded: the controls go away.
    func clear() {
        shownKey = nil
        shownAt = nil
        MPNowPlayingInfoCenter.default().nowPlayingInfo = nil
        MPNowPlayingInfoCenter.default().playbackState = .stopped
        setEnabled(false)
    }

    private func run(_ action: (Actions) -> Void) -> MPRemoteCommandHandlerStatus {
        guard let actions else { return .noActionableNowPlayingItem }
        action(actions)
        return .success
    }

    private func setEnabled(_ on: Bool) {
        let center = MPRemoteCommandCenter.shared()
        for command in [center.playCommand, center.pauseCommand, center.togglePlayPauseCommand,
                        center.skipForwardCommand, center.skipBackwardCommand, center.nextTrackCommand,
                        center.previousTrackCommand, center.changePlaybackPositionCommand] {
            command.isEnabled = on
        }
    }

    private func loadArtwork(_ url: URL?) {
        guard let url, artworkFor != url else { return }
        artworkFor = url
        artwork = nil
        Task { [weak self] in
            guard let image = await CoverCache.shared.image(for: url, maxPixel: 600) else { return }
            guard let self, self.artworkFor == url else { return }
            self.artwork = Self.makeArtwork(image)
            self.shownKey = nil
            self.onArtwork()
        }
    }

    /// Built outside the main actor: the system calls the handler on its own queue.
    private nonisolated static func makeArtwork(_ image: UIImage) -> MPMediaItemArtwork {
        MPMediaItemArtwork(boundsSize: image.size) { _ in image }
    }
}
