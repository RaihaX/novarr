import AVFoundation
import Foundation

/// The audio engine under `ListeningModel`: an AVQueuePlayer over one
/// audiobook's parts (pre-signed URLs, or this device's downloaded files),
/// played back to back. It only knows (part, offset in part); the model maps
/// that to whole-book positions (`AudiobookTimeline`).
///
/// Two items are queued at a time (the one playing and the next), so a part
/// change is seamless without opening every file of a 40-part book at once.
@MainActor
final class BookPlayer {
    enum Event: Sendable {
        /// Playing, paused, waiting for data or a part change: read the state again.
        case changed
        /// The last part played to its end.
        case ended
        /// The current part couldn't play.
        case failed(String)
    }

    let player = AVQueuePlayer()
    var onEvent: (Event) -> Void = { _ in }

    private var urls: [URL] = []
    /// Which part each queued item is.
    private var parts: [ObjectIdentifier: Int] = [:]
    private var observations: [NSKeyValueObservation] = []
    private var itemObservations: [ObjectIdentifier: NSKeyValueObservation] = [:]
    private var notificationTokens: [NSObjectProtocol] = []
    private var speed: Float = 1
    /// The part a seek is moving to (the queue is being rebuilt), for positions read meanwhile.
    private var pendingPosition: (part: Int, offsetMs: Int64)?

    init() {
        player.actionAtItemEnd = .advance
        observations = Self.observe(player) { [weak self] in self?.currentItemChanged() } onState: { [weak self] in
            self?.onEvent(.changed)
        }
        let center = NotificationCenter.default
        notificationTokens.append(center.addObserver(forName: AVPlayerItem.didPlayToEndTimeNotification, object: nil,
                                                     queue: .main) { [weak self] note in
            let item = note.object.map { ObjectIdentifier($0 as AnyObject) }
            MainActor.assumeIsolated { self?.itemEnded(item) }
        })
        notificationTokens.append(center.addObserver(forName: AVPlayerItem.failedToPlayToEndTimeNotification, object: nil,
                                                     queue: .main) { [weak self] note in
            let item = note.object.map { ObjectIdentifier($0 as AnyObject) }
            let message = (note.userInfo?[AVPlayerItemFailedToPlayToEndTimeErrorKey] as? Error)?.localizedDescription
            MainActor.assumeIsolated { self?.itemFailed(item, message: message) }
        })
    }

    /// Parts are on the player.
    var hasItems: Bool { !urls.isEmpty }

    var isPlaying: Bool { player.timeControlStatus == .playing }

    /// Asked to play, waiting for data.
    var isBuffering: Bool { player.timeControlStatus == .waitingToPlayAtSpecifiedRate }

    /// Wants to play (playing or buffering).
    var wantsToPlay: Bool { player.rate != 0 || player.timeControlStatus != .paused }

    /// The part playing (or about to after a seek), and where in it.
    var position: (part: Int, offsetMs: Int64)? {
        guard hasItems else { return nil }
        if let pendingPosition { return pendingPosition }
        guard let item = player.currentItem, let part = parts[ObjectIdentifier(item)] else { return nil }
        let seconds = item.currentTime().seconds
        return (part, seconds.isFinite ? Int64((seconds * 1000).rounded()) : 0)
    }

    var volume: Float {
        get { player.volume }
        set { player.volume = min(max(newValue, 0), 1) }
    }

    /// Puts `urls` on the player at `part` / `offsetMs`, playing if `play`.
    func load(_ urls: [URL], part: Int, offsetMs: Int64, speed: Double, play: Bool) {
        self.urls = urls
        self.speed = Float(speed)
        player.defaultRate = Float(speed)
        rebuild(from: part, offsetMs: offsetMs, play: play)
    }

    /// Moves to `part` / `offsetMs`: within the current part a plain seek, else the queue is rebuilt.
    func seek(part: Int, offsetMs: Int64) {
        guard hasItems else { return }
        if let item = player.currentItem, parts[ObjectIdentifier(item)] == part {
            pendingPosition = (part, offsetMs)
            item.seek(to: Self.time(offsetMs), toleranceBefore: .zero, toleranceAfter: .zero) { [weak self] _ in
                Task { @MainActor in self?.pendingPosition = nil }
            }
        } else {
            rebuild(from: part, offsetMs: offsetMs, play: wantsToPlay)
        }
    }

    func play() {
        guard hasItems else { return }
        player.playImmediately(atRate: speed)
    }

    func pause() { player.pause() }

    func setSpeed(_ speed: Double) {
        self.speed = Float(speed)
        player.defaultRate = Float(speed)
        if player.rate != 0 { player.rate = Float(speed) }
    }

    /// Nothing on the player any more.
    func clear() {
        player.pause()
        player.removeAllItems()
        urls = []
        parts = [:]
        itemObservations = [:]
        pendingPosition = nil
        volume = 1
    }

    // Internals

    private func rebuild(from part: Int, offsetMs: Int64, play: Bool) {
        player.pause()
        player.removeAllItems()
        parts = [:]
        itemObservations = [:]
        guard urls.indices.contains(part) else { return }
        let first = makeItem(part)
        player.insert(first, after: nil)
        if urls.indices.contains(part + 1) { player.insert(makeItem(part + 1), after: first) }
        pendingPosition = (part, offsetMs)
        if offsetMs > 0 {
            player.seek(to: Self.time(offsetMs), toleranceBefore: .zero, toleranceAfter: .zero) { [weak self] _ in
                Task { @MainActor in self?.pendingPosition = nil }
            }
        } else {
            pendingPosition = nil
        }
        if play { player.playImmediately(atRate: speed) }
        onEvent(.changed)
    }

    private func makeItem(_ part: Int) -> AVPlayerItem {
        let item = AVPlayerItem(asset: AVURLAsset(url: urls[part]))
        item.audioTimePitchAlgorithm = .timeDomain
        item.preferredForwardBufferDuration = 60
        let id = ObjectIdentifier(item)
        parts[id] = part
        itemObservations[id] = Self.observeStatus(item) { [weak self] message in self?.itemFailed(id, message: message) }
        return item
    }

    /// A new item is playing: queue the one after it.
    private func currentItemChanged() {
        guard let item = player.currentItem, let part = parts[ObjectIdentifier(item)] else {
            onEvent(.changed)
            return
        }
        // Forget items that already went by.
        let queued = Set(player.items().map(ObjectIdentifier.init))
        parts = parts.filter { queued.contains($0.key) }
        itemObservations = itemObservations.filter { queued.contains($0.key) }
        let lastQueued = player.items().last.flatMap { parts[ObjectIdentifier($0)] } ?? part
        if lastQueued == part, urls.indices.contains(part + 1) {
            player.insert(makeItem(part + 1), after: player.items().last)
        }
        onEvent(.changed)
    }

    private func itemEnded(_ item: ObjectIdentifier?) {
        guard let item, let part = parts[item] else { return }
        if part == urls.count - 1 { onEvent(.ended) }
    }

    private func itemFailed(_ item: ObjectIdentifier?, message: String?) {
        guard let item, parts[item] != nil else { return }
        onEvent(.failed(message ?? "This audio couldn't be played."))
    }

    private static func time(_ ms: Int64) -> CMTime { CMTime(value: max(ms, 0), timescale: 1000) }

    /// KVO with closures that are not main-actor isolated (AVFoundation may call them on any thread).
    private nonisolated static func observe(_ player: AVQueuePlayer, onItem: @escaping @MainActor () -> Void,
                                            onState: @escaping @MainActor () -> Void) -> [NSKeyValueObservation] {
        [
            player.observe(\.currentItem, options: [.new]) { _, _ in Task { @MainActor in onItem() } },
            player.observe(\.timeControlStatus, options: [.new]) { _, _ in Task { @MainActor in onState() } },
            player.observe(\.rate, options: [.new]) { _, _ in Task { @MainActor in onState() } },
        ]
    }

    private nonisolated static func observeStatus(_ item: AVPlayerItem,
                                                  onFailure: @escaping @MainActor (String?) -> Void) -> NSKeyValueObservation {
        item.observe(\.status, options: [.new]) { item, _ in
            guard item.status == .failed else { return }
            let message = item.error?.localizedDescription
            Task { @MainActor in onFailure(message) }
        }
    }
}
