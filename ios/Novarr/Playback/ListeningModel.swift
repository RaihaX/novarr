import AVFoundation
import Foundation
import Observation
import os

/// What the player shows: the audiobook loaded (playing or paused) and where it is.
struct NowPlaying: Equatable, Sendable {
    /// The session (server and account) it belongs to: another one never sees it.
    var sessionId: String
    var detail: AudiobookDetail
    var timeline: AudiobookTimeline
    /// Whole-book position.
    var positionMs: Int64
    var playing = false
    var buffering = false
    var speed: Double = 1
    var sleep: SleepTimer = .off
    var bookmarks: [AudiobookBookmark] = []
    /// Playing from this device's download.
    var local = false
    var error: String?

    var id: Int { detail.id }
    var chapterIndex: Int { timeline.chapterIndexAt(positionMs) }
    var chapterTitle: String? { timeline.chapterAt(positionMs)?.label }
}

/// The audiobook player for the whole app (android playback/ListeningModel.kt):
/// drives `BookPlayer` in whole-book positions (`AudiobookTimeline`), keeps
/// `nowPlaying` for the UI, the lock screen and the remote controls, runs the
/// sleep timer and saves progress (`ListeningStore`: every 15 s while playing,
/// on pause, stop, seek and when the app goes to the background; kept offline
/// and sent later, last write wins by when it was recorded on this device).
/// The server turns those positions into the reader's listening activity.
@MainActor
@Observable
final class ListeningModel {
    private(set) var nowPlaying: NowPlaying?
    /// A failure to open an audiobook, for the screen that asked (cleared by the next open).
    private(set) var lastError: String?
    /// Settings › Listening.
    var prefs: ListeningPrefs {
        didSet {
            guard prefs != oldValue else { return }
            prefs.save(defaults)
            applyPrefs()
            if prefs.wifiOnlyDownloads != oldValue.wifiOnlyDownloads || prefs.wifiOnlyUploads != oldValue.wifiOnlyUploads {
                network.wakeAll()
            }
        }
    }
    /// How many full players are on screen (the mini-player hides meanwhile).
    private(set) var playerScreens = 0

    let network: NetworkConditions
    let downloads: AudiobookDownloads
    let uploads: AudiobookUploadManager

    @ObservationIgnored private weak var app: AppModel?
    @ObservationIgnored private let defaults: UserDefaults
    @ObservationIgnored private lazy var player: BookPlayer = makePlayer()
    @ObservationIgnored private var playerMade = false
    @ObservationIgnored private let remote = RemoteControls()
    @ObservationIgnored private var store: ListeningStore?
    @ObservationIgnored private var storeSession: String?
    @ObservationIgnored private var storage: AudioStorage?
    /// The audiobook whose parts are on the player (nil: nothing, or another one).
    @ObservationIgnored private var preparedFor: Int?
    @ObservationIgnored private var ticker: Task<Void, Never>?
    @ObservationIgnored private var lastSyncAt: Int64 = 0
    @ObservationIgnored private var lastUrlRefresh: Int64 = -.max / 2
    /// When the loaded detail was fetched from the server (nil: from the offline cache).
    @ObservationIgnored private var detailFetchedAt: Date?
    /// Paused by an interruption (a call): resumed when it ends, if the system says so.
    @ObservationIgnored private var interrupted = false
    @ObservationIgnored private var sessionObservers: [NSObjectProtocol] = []
    @ObservationIgnored private let log = Logger(subsystem: "studio.visns.novarr", category: "Listening")

    /// Signed part URLs last 24 h; a cached detail older than this gets fresh ones before streaming.
    static let urlMaxAge: TimeInterval = 20 * 60 * 60

    init(defaults: UserDefaults = .standard) {
        self.defaults = defaults
        let prefs = ListeningPrefs.load(defaults)
        self.prefs = prefs
        let network = NetworkConditions()
        self.network = network
        var current: () -> ListeningPrefs = { prefs }
        downloads = AudiobookDownloads(network: network, prefs: { current() })
        uploads = AudiobookUploadManager(network: network, prefs: { current() })
        current = { [weak self] in self?.prefs ?? prefs }
        downloads.onChange = { [weak self] in self?.downloadsChanged() }
    }

    func attach(_ app: AppModel) {
        self.app = app
    }

    // MARK: Sessions

    /// The server or account changed (or the session ended): nothing of the
    /// last one may keep playing or show; its unsent positions stay in its file.
    func sessionChanged() {
        let active = app.flatMap { app -> (String, APIClient, OfflineStore)? in
            guard let client = app.client, let store = app.store, !app.needsSignIn else { return nil }
            return (app.sessionID, client, store)
        }
        let id = active?.0
        guard id != storeSession else { return }
        if let now = nowPlaying, now.sessionId != id {
            saveNow()
            if playerMade { player.clear() }
            preparedFor = nil
            ticker?.cancel()
            nowPlaying = nil
            remote.clear()
        }
        storeSession = id
        if let active {
            let (_, client, offline) = active
            let storage = AudioStorage(storeRoot: offline.root)
            self.storage = storage
            store = ListeningStore(fileURL: storage.listeningFile)
            downloads.sessionChanged(storage: storage, client: client)
            uploads.sessionChanged(storage: storage, client: client)
            Task {
                await restoreLast()
                await flush()
            }
        } else {
            storage = nil
            store = nil
            downloads.sessionChanged(storage: nil, client: nil)
            uploads.sessionChanged(storage: nil, client: nil)
        }
    }

    /// The server stopped offering audiobooks (meta `audiobooks: false`).
    func audiobooksOffered(_ offered: Bool) {
        if !offered, nowPlaying != nil { close() }
    }

    /// The app is back in front: send what is waiting and carry on with uploads.
    func becameActive() {
        Task {
            await flush()
            await uploads.refresh()
        }
    }

    // MARK: Opening

    /// Loads an audiobook at its resume position (the newer of this device's
    /// and the server's), playing if `play`. Reopening the one already loaded
    /// only plays it (and picks up bookmarks and progress from other devices when paused).
    func open(_ id: Int, play: Bool) {
        if let current = nowPlaying, current.id == id {
            if play { self.play() }
            if !current.playing { Task { await refreshDetail(id, moveToServer: true) } }
            return
        }
        Task {
            guard let app, let client = app.client, let offline = app.store, let session = storeSession else { return }
            // Leaving another book: its position is saved first.
            if nowPlaying != nil {
                refreshFromPlayer()
                record()
            }
            let cached = await offline.load(AudiobookDetail.self, as: Self.detailKey(id)) ?? downloads.savedDetail(id)
            var fresh: AudiobookDetail?
            do {
                fresh = try await client.audiobook(id: id)
                app.noteSuccess()
            } catch {
                app.noteFailure(APIError.from(error))
            }
            guard let detail = fresh ?? cached else {
                lastError = "Couldn't load this audiobook. Check your connection and try again."
                return
            }
            if let fresh {
                await offline.save(fresh, as: Self.detailKey(id))
                detailFetchedAt = Date()
            } else {
                detailFetchedAt = nil
            }
            guard storeSession == session else { return }
            lastError = nil
            await load(detail, session: session, play: play)
        }
    }

    static func detailKey(_ id: Int) -> OfflineStore.Key { .custom("audiobooks/\(id).json") }

    private func load(_ detail: AudiobookDetail, session: String, play: Bool) async {
        let timeline = AudiobookTimeline.of(detail)
        let local = await store?.latest(detail.id)
        let start = ListeningSync.startPosition(ListeningSync.resolve(local, detail.summary.progress), totalMs: timeline.totalMs)
        preparedFor = nil
        if playerMade { player.clear() }
        nowPlaying = NowPlaying(sessionId: session, detail: detail, timeline: timeline, positionMs: start,
                                speed: prefs.speed, bookmarks: detail.bookmarks,
                                local: downloads.localFiles(detail.id, partCount: detail.parts.count) != nil)
        await store?.setLastAudiobookId(detail.id)
        if play { self.play() }
        showRemote()
    }

    /// At launch (or a sign-in): the last audiobook comes back paused where it was, from the cache.
    private func restoreLast() async {
        guard nowPlaying == nil, app?.serverMeta?.audiobooks != false, let session = storeSession,
              let id = await store?.lastAudiobookId(), let offline = app?.store else { return }
        guard let cached = await offline.load(AudiobookDetail.self, as: Self.detailKey(id)) ?? downloads.savedDetail(id),
              storeSession == session, nowPlaying == nil else { return }
        detailFetchedAt = nil
        await load(cached, session: session, play: false)
    }

    // MARK: Transport

    func togglePlay() {
        if nowPlaying?.playing == true || player.wantsToPlay && preparedFor == nowPlaying?.id { pause() } else { play() }
    }

    func play() {
        guard let now = nowPlaying else { return }
        interrupted = false
        activateAudioSession()
        if preparedFor != now.id || !player.hasItems {
            Task { await prepare(play: true) }
        } else {
            if now.positionMs >= now.timeline.totalMs { seekTo(0) }
            player.play()
            nowPlaying?.error = nil
        }
        startTicker()
    }

    func pause() {
        player.pause()
        nowPlaying?.playing = false
        nowPlaying?.buffering = false
        saveNow()
        showRemote()
    }

    /// Seeks to a whole-book position (scrubber, chapter, bookmark).
    func seekTo(_ positionMs: Int64) {
        guard var now = nowPlaying else { return }
        let target = min(max(positionMs, 0), now.timeline.totalMs)
        if preparedFor == now.id, player.hasItems {
            let part = now.timeline.toPart(target)
            player.seek(part: part.partIndex, offsetMs: part.offsetMs)
        }
        now.positionMs = target
        now.sleep = SleepTimer.retarget(now.sleep, now.timeline, positionMs: target)
        nowPlaying = now
        record()
        showRemote()
    }

    var skipBackMs: Int64 { Int64(prefs.skipBackSeconds) * 1000 }
    var skipForwardMs: Int64 { Int64(prefs.skipForwardSeconds) * 1000 }

    func skipBack() { skip(by: -skipBackMs) }
    func skipForward() { skip(by: skipForwardMs) }

    private func skip(by delta: Int64) {
        refreshFromPlayer()
        guard let now = nowPlaying else { return }
        seekTo(now.timeline.skip(now.positionMs, delta))
    }

    func previousChapter() {
        refreshFromPlayer()
        guard let now = nowPlaying else { return }
        seekTo(now.timeline.previousChapterTarget(now.positionMs))
    }

    func nextChapter() {
        refreshFromPlayer()
        guard let now = nowPlaying, let target = now.timeline.nextChapterTarget(now.positionMs) else { return }
        seekTo(target)
    }

    func setSpeed(_ speed: Double) {
        prefs.speed = ListeningPrefs.validSpeed(speed)
    }

    // MARK: Sleep timer

    func sleepIn(_ minutes: Int) {
        nowPlaying?.sleep = SleepTimer.minutes(minutes, now: SleepTimer.monotonicNow())
        startTicker()
    }

    func sleepAtChapterEnd() {
        refreshFromPlayer()
        guard let now = nowPlaying else { return }
        nowPlaying?.sleep = SleepTimer.endOfChapter(now.timeline, positionMs: now.positionMs)
        startTicker()
    }

    func extendSleep(_ minutes: Int = 5) {
        player.volume = 1
        guard let now = nowPlaying else { return }
        nowPlaying?.sleep = SleepTimer.extend(now.sleep, minutes: minutes, now: SleepTimer.monotonicNow())
    }

    func cancelSleep() {
        player.volume = 1
        nowPlaying?.sleep = .off
    }

    // MARK: Bookmarks (online: they need the server's id)

    /// Adds a bookmark here; the error message on failure.
    func addBookmark(note: String?) async -> String? {
        refreshFromPlayer()
        guard let now = nowPlaying else { return nil }
        guard let client = app?.client else { return "Not connected." }
        do {
            let bookmark = try await client.createAudiobookBookmark(id: now.id, positionMs: now.positionMs, note: note)
            await updateBookmarks(now.id) { ($0 + [bookmark]).sorted { $0.positionMs < $1.positionMs } }
            return nil
        } catch {
            return APIError.from(error).errorDescription
        }
    }

    func deleteBookmark(_ bookmarkId: Int) async -> String? {
        guard let now = nowPlaying else { return nil }
        guard let client = app?.client else { return "Not connected." }
        do {
            try await client.deleteAudiobookBookmark(id: now.id, bookmarkId: bookmarkId)
            await updateBookmarks(now.id) { $0.filter { $0.id != bookmarkId } }
            return nil
        } catch {
            return APIError.from(error).errorDescription
        }
    }

    private func updateBookmarks(_ id: Int, _ change: ([AudiobookBookmark]) -> [AudiobookBookmark]) async {
        guard var now = nowPlaying, now.id == id else { return }
        now.bookmarks = change(now.bookmarks)
        now.detail.bookmarks = now.bookmarks
        nowPlaying = now
        await app?.store?.save(now.detail, as: Self.detailKey(id))
    }

    // MARK: Stopping

    /// The mini-player's close: saved, stopped and forgotten (nothing comes back at the next launch).
    func close() {
        refreshFromPlayer()
        saveNow()
        if playerMade { player.clear() }
        preparedFor = nil
        ticker?.cancel()
        ticker = nil
        nowPlaying = nil
        remote.clear()
        let store = store
        Task { await store?.setLastAudiobookId(nil) }
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }

    /// Saves the current position now (pause, the app going to the background, a stop).
    func saveNow() {
        guard nowPlaying != nil else { return }
        refreshFromPlayer()
        record()
    }

    // MARK: Player screens

    func playerAppeared() { playerScreens += 1 }
    func playerDisappeared() { playerScreens = max(playerScreens - 1, 0) }

    // MARK: Downloads

    func downloadsChanged() {
        refreshFromPlayer()
        guard let now = nowPlaying else { return }
        // Switch to (or away from) local files at the next play; never in the middle of playback.
        if !now.playing { preparedFor = nil }
    }

    // MARK: Progress

    private func record(finished: Bool = false) {
        guard let now = nowPlaying, let target = store, now.sessionId == storeSession else { return }
        let position = finished ? now.timeline.totalMs : now.positionMs
        let record = ListeningRecord(audiobookId: now.id, positionMs: position, speed: now.speed, finished: finished,
                                     recordedAt: ListeningSync.nowMs())
        lastSyncAt = SleepTimer.monotonicNow()
        Task {
            await target.record(record)
            await flush()
        }
    }

    /// Sends unsent positions (also when the app returns and the network comes back).
    func flush() async {
        guard let client = app?.client, let store else { return }
        let outcome = await store.flush { record in
            _ = try await client.saveAudiobookProgress(id: record.audiobookId, positionMs: record.positionMs,
                                                       speed: record.speed, finished: record.finished,
                                                       recordedAt: ListeningSync.isoOf(record.recordedAt))
        }
        if case .stalled = outcome { app?.noteFailure(.unreachable("Listening progress is waiting to be sent.")) }
    }

    // MARK: Internals

    private func makePlayer() -> BookPlayer {
        playerMade = true
        let player = BookPlayer()
        player.onEvent = { [weak self] event in self?.handle(event) }
        remote.register(RemoteControls.Actions(
            play: { [weak self] in self?.play() },
            pause: { [weak self] in self?.pause() },
            toggle: { [weak self] in self?.togglePlay() },
            skipForward: { [weak self] in self?.skipForward() },
            skipBack: { [weak self] in self?.skipBack() },
            seek: { [weak self] in self?.seekTo($0) }))
        remote.onArtwork = { [weak self] in self?.showRemote() }
        remote.setSkipIntervals(back: prefs.skipBackSeconds, forward: prefs.skipForwardSeconds)
        observeAudioSession()
        return player
    }

    private func handle(_ event: BookPlayer.Event) {
        switch event {
        case .changed:
            let wasPlaying = nowPlaying?.playing == true
            refreshFromPlayer()
            let playing = nowPlaying?.playing == true
            if wasPlaying && !playing && nowPlaying?.buffering != true { saveNow() }
            if playing { startTicker() }
            showRemote()
        case .ended:
            guard var now = nowPlaying else { return }
            now.positionMs = now.timeline.totalMs
            now.playing = false
            now.buffering = false
            now.sleep = .off
            nowPlaying = now
            record(finished: true)
            showRemote()
        case .failed(let message):
            guard let now = nowPlaying else { return }
            let clock = SleepTimer.monotonicNow()
            // The pre-signed part URLs last 24 h: fetch fresh ones and carry on from here (once a minute at most).
            if !now.local, clock - lastUrlRefresh > 60_000 {
                lastUrlRefresh = clock
                Task { await reloadParts(play: true) }
                return
            }
            refreshFromPlayer()
            nowPlaying?.playing = false
            nowPlaying?.buffering = false
            nowPlaying?.error = now.local ? "The downloaded audio couldn't be played (\(message))."
                : "Can't play this audio right now. Check your connection, or download the audiobook to listen offline."
            player.pause()
            saveNow()
            showRemote()
        }
    }

    /// Puts the loaded audiobook's parts on the player at the current position.
    private func prepare(play: Bool) async {
        guard var now = nowPlaying else { return }
        var files = downloads.localFiles(now.id, partCount: now.detail.parts.count)
        // Streaming from a cached detail: its signed URLs may have expired, so try for fresh ones first.
        if files == nil, detailFetchedAt.map({ Date().timeIntervalSince($0) > Self.urlMaxAge }) ?? true {
            await refreshDetail(now.id, moveToServer: false)
            guard let refreshed = nowPlaying, refreshed.id == now.id else { return }
            now = refreshed
            files = downloads.localFiles(now.id, partCount: now.detail.parts.count)
        }
        guard !now.detail.parts.isEmpty else {
            nowPlaying?.error = "This audiobook has no audio files."
            return
        }
        let urls = now.detail.parts.compactMap { files?[$0.index] ?? $0.url }
        guard urls.count == now.detail.parts.count else {
            nowPlaying?.error = "Can't reach your server. Download the audiobook to listen offline."
            return
        }
        let part = now.timeline.toPart(now.positionMs)
        player.load(urls, part: part.partIndex, offsetMs: part.offsetMs, speed: prefs.speed, play: play)
        preparedFor = now.id
        nowPlaying?.local = files != nil
        nowPlaying?.speed = prefs.speed
        nowPlaying?.error = nil
        showRemote()
    }

    /// Fresh part URLs (and progress from other devices) from the server; quiet offline.
    private func refreshDetail(_ id: Int, moveToServer: Bool) async {
        guard let client = app?.client, let offline = app?.store,
              let fresh = try? await client.audiobook(id: id) else { return }
        await offline.save(fresh, as: Self.detailKey(id))
        detailFetchedAt = Date()
        guard var now = nowPlaying, now.id == id else { return }
        let timeline = AudiobookTimeline.of(fresh)
        var position = now.positionMs
        if moveToServer && !now.playing {
            let local = await store?.latest(id)
            let resolved = ListeningSync.resolve(local, fresh.summary.progress)
            if !resolved.fromLocal { position = ListeningSync.startPosition(resolved, totalMs: timeline.totalMs) }
        }
        guard let latest = nowPlaying, latest.id == id else { return }
        now = latest
        let moved = position != now.positionMs
        now.detail = fresh
        now.timeline = timeline
        now.bookmarks = fresh.bookmarks
        now.positionMs = position
        nowPlaying = now
        if moved, preparedFor == id, player.hasItems {
            let part = timeline.toPart(position)
            player.seek(part: part.partIndex, offsetMs: part.offsetMs)
        }
    }

    private func reloadParts(play: Bool) async {
        refreshFromPlayer()
        guard let now = nowPlaying else { return }
        await refreshDetail(now.id, moveToServer: false)
        preparedFor = nil
        await prepare(play: play)
    }

    /// Reads the player's position into `nowPlaying` (as a whole-book position).
    private func refreshFromPlayer() {
        guard playerMade, var now = nowPlaying, preparedFor == now.id, let position = player.position else { return }
        now.positionMs = now.timeline.toBook(position.part, position.offsetMs)
        now.playing = player.isPlaying
        now.buffering = player.isBuffering
        if now != nowPlaying { nowPlaying = now }
    }

    /// While something is playing: the position for the UI, the sleep timer and the 15-second sync.
    private func startTicker() {
        guard ticker == nil else { return }
        ticker = Task { [weak self] in
            while !Task.isCancelled {
                guard let self else { return }
                self.refreshFromPlayer()
                guard let now = self.nowPlaying else { break }
                self.tickSleep(now)
                if now.playing, SleepTimer.monotonicNow() - self.lastSyncAt >= ListeningSync.syncIntervalMs { self.record() }
                self.showRemote()
                let waiting = self.player.wantsToPlay && self.preparedFor == now.id
                // Paused: only a duration sleep timer keeps counting.
                if !now.playing && !now.buffering && !waiting && now.sleep.durationMinutes == nil { break }
                try? await Task.sleep(for: .milliseconds(now.playing ? 500 : 1000))
            }
            self?.ticker = nil
        }
    }

    private func tickSleep(_ now: NowPlaying) {
        switch SleepTimer.decide(now.sleep, now: SleepTimer.monotonicNow(), positionMs: now.positionMs) {
        case .keep:
            break
        case .fade(let volume):
            if now.playing { player.volume = volume }
        case .stop:
            player.pause()
            player.volume = 1
            nowPlaying?.sleep = .off
            nowPlaying?.playing = false
            saveNow()
            showRemote()
        }
    }

    private func applyPrefs() {
        if playerMade {
            player.setSpeed(prefs.speed)
            remote.setSkipIntervals(back: prefs.skipBackSeconds, forward: prefs.skipForwardSeconds)
        }
        if nowPlaying?.speed != prefs.speed { nowPlaying?.speed = prefs.speed }
        showRemote()
    }

    private func showRemote() {
        guard playerMade else { return }
        if let now = nowPlaying { remote.show(now) } else { remote.clear() }
    }

    // MARK: Audio session

    private func activateAudioSession() {
        let session = AVAudioSession.sharedInstance()
        do {
            try session.setCategory(.playback, mode: .spokenAudio, policy: .longFormAudio)
            try session.setActive(true)
        } catch {
            log.error("Audio session: \(error.localizedDescription, privacy: .public)")
        }
    }

    private func observeAudioSession() {
        let center = NotificationCenter.default
        sessionObservers.append(center.addObserver(forName: AVAudioSession.interruptionNotification, object: nil,
                                                   queue: .main) { [weak self] note in
            let type = (note.userInfo?[AVAudioSessionInterruptionTypeKey] as? UInt).flatMap(AVAudioSession.InterruptionType.init)
            let options = (note.userInfo?[AVAudioSessionInterruptionOptionKey] as? UInt)
                .map(AVAudioSession.InterruptionOptions.init(rawValue:)) ?? []
            MainActor.assumeIsolated { self?.interruption(type, shouldResume: options.contains(.shouldResume)) }
        })
        sessionObservers.append(center.addObserver(forName: AVAudioSession.routeChangeNotification, object: nil,
                                                   queue: .main) { [weak self] note in
            let reason = (note.userInfo?[AVAudioSessionRouteChangeReasonKey] as? UInt)
                .flatMap(AVAudioSession.RouteChangeReason.init)
            // Headphones unplugged (or a Bluetooth device gone): pause, as Android's "becoming noisy".
            guard reason == .oldDeviceUnavailable else { return }
            MainActor.assumeIsolated { self?.pause() }
        })
    }

    private func interruption(_ type: AVAudioSession.InterruptionType?, shouldResume: Bool) {
        switch type {
        case .began:
            if nowPlaying?.playing == true { interrupted = true }
            refreshFromPlayer()
            nowPlaying?.playing = false
            saveNow()
            showRemote()
        case .ended:
            if interrupted && shouldResume { play() }
            interrupted = false
        default:
            break
        }
    }
}
