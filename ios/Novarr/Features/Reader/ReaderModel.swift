import Foundation
import Observation

@MainActor
@Observable
final class ReaderModel {
    let novelId: Int
    private(set) var chapterId: Int
    private(set) var content: ChapterContent?
    private(set) var phase: LoadPhase = .idle
    private(set) var isShowingCache = false
    /// Paragraph index to scroll to once the text is laid out.
    private(set) var restoreIndex: Int?
    /// Scroll events are ignored until the view has restored the position,
    /// so the initial jump never reports a bogus progress value.
    var isTracking = false
    /// "Read more recently on another device" prompt, when it applies.
    private(set) var latestOffer: LatestOffer?
    /// Asks the view to scroll within the current chapter (Go to latest, same chapter).
    private(set) var scrollRequest: ScrollRequest?

    @ObservationIgnored private var pendingProgress: Int?
    @ObservationIgnored private var lastSentProgress: Int?
    @ObservationIgnored private var lastSentAt = Date.distantPast
    @ObservationIgnored private var sendTask: Task<Void, Never>?
    @ObservationIgnored private var markedRead = false
    /// When the oldest still-unsent scroll position was taken.
    @ObservationIgnored private var pendingSince: Date?

    // Latest-position check: runs once, for the chapter the reader was opened on.
    @ObservationIgnored private let offersLatest: Bool
    @ObservationIgnored private let openedChapterId: Int
    @ObservationIgnored private var positionChecked = false
    @ObservationIgnored private var fetchedPosition: ReadingPosition?
    @ObservationIgnored private var offerEvaluated = false
    /// "Go to latest" into another chapter: restore here, ignoring local progress.
    @ObservationIgnored private var forcedProgress: (chapterId: Int, progress: Int)?

    struct LatestOffer: Equatable {
        var position: ReadingPosition
        var message: String
    }

    struct ScrollRequest: Equatable {
        var index: Int
        var token = UUID()
    }

    /// Minimum seconds between progress writes while scrolling.
    static let progressInterval: TimeInterval = 4

    init(route: ReaderRoute) {
        novelId = route.novelId
        chapterId = route.chapterId
        offersLatest = route.offerLatest
        openedChapterId = route.chapterId
    }

    var paragraphCount: Int { content?.paragraphs.count ?? 0 }

    // MARK: Loading

    func load(app: AppModel) async {
        phase = .loading
        isTracking = false
        markedRead = false
        pendingProgress = nil
        pendingSince = nil
        lastSentProgress = nil
        restoreIndex = nil

        let id = chapterId
        let novelId = self.novelId
        let cached = await app.store?.chapter(id: id, novelId: novelId)
        var loaded: ChapterContent?
        var failure: APIError?

        if let client = app.client {
            do {
                var fresh = try await client.chapter(id: id)
                app.noteSuccess()
                if fresh.novelId == 0 { fresh.novelId = novelId }
                // Unsent local progress is newer than what the server knows.
                if app.pendingWrites > 0, let cached {
                    fresh.readProgress = cached.readProgress
                    fresh.read = fresh.read || cached.read
                }
                await app.store?.saveChapter(fresh)
                loaded = fresh
            } catch {
                failure = APIError.from(error)
                app.noteFailure(failure ?? .timedOut)
            }
        }
        guard id == chapterId else { return } // the user moved on meanwhile

        if loaded == nil, let cached {
            loaded = cached
            isShowingCache = true
        } else {
            isShowingCache = false
        }

        guard var loaded else {
            phase = .failed(failure?.localizedDescription ?? "This chapter isn't available.")
            return
        }
        let forced = forcedProgress.flatMap { $0.chapterId == id ? $0.progress : nil }
        forcedProgress = nil
        if let forced {
            // Go to latest: the other device's position wins over anything saved here.
            loaded.readProgress = forced
            await app.store?.updateChapterState(id: id, novelId: novelId, progress: forced, read: nil)
            guard id == chapterId else { return }
        }
        content = loaded
        markedRead = loaded.read
        lastSentProgress = loaded.readProgress
        if let forced {
            restoreIndex = LatestPosition.paragraphIndex(progress: forced, paragraphCount: loaded.paragraphs.count)
        } else if let progress = loaded.readProgress, progress < 100 {
            restoreIndex = LatestPosition.paragraphIndex(progress: progress, paragraphCount: loaded.paragraphs.count)
        }
        phase = .loaded
        if loaded.downloaded { app.downloads.contentChanged() }
        prefetch(loaded.next, app: app)
        await evaluateLatestOffer(app: app)
    }

    /// Fetches the next chapter in the background so it's ready (and offline).
    private func prefetch(_ ref: ChapterRef?, app: AppModel) {
        guard let ref, ref.downloaded != false, let client = app.client, let store = app.store else { return }
        let novelId = self.novelId
        Task(priority: .utility) {
            guard await !store.hasChapter(id: ref.id, novelId: novelId),
                  let chapter = try? await client.chapter(id: ref.id) else { return }
            await store.saveChapter(chapter)
        }
    }

    /// Re-reads only the chapter's `next` link (after a check found new
    /// chapters), leaving the text and scroll position alone.
    func refreshNext(app: AppModel) async {
        guard let client = app.client, let current = content,
              current.next == nil || current.next?.downloaded == false else { return }
        let id = chapterId
        guard let fresh = try? await client.chapter(id: id),
              var latest = content, latest.id == id, fresh.next != latest.next else { return }
        latest.next = fresh.next
        content = latest
        prefetch(fresh.next, app: app)
    }

    func go(to ref: ChapterRef, app: AppModel) async {
        await open(chapterId: ref.id, app: app)
    }

    private func open(chapterId id: Int, app: AppModel) async {
        // Moving on answers the prompt as far as this reader is concerned.
        latestOffer = nil
        await leave(app: app)
        chapterId = id
        content = nil
        await load(app: app)
    }

    // MARK: Latest position

    /// Asks the server where this series was last read. Runs alongside
    /// `load`; any failure or slow answer (3 s) simply means no prompt.
    func checkLatestPosition(app: AppModel) async {
        guard offersLatest, !positionChecked, let client = app.client else { return }
        positionChecked = true
        guard let position = try? await client.latestPosition(novelId: novelId) else { return }
        fetchedPosition = position
        await evaluateLatestOffer(app: app)
    }

    /// Decides once both the chapter and the server's answer are in.
    private func evaluateLatestOffer(app: AppModel) async {
        guard let position = fetchedPosition, !offerEvaluated, phase == .loaded,
              chapterId == openedChapterId, content?.id == openedChapterId else { return }
        offerEvaluated = true

        var localTimes = await app.unsentWriteTimes(novelId: novelId)
        if let pendingSince { localTimes.append(pendingSince) }
        let localNewer = LatestPosition.localIsNewer(
            localWriteTimes: localTimes, ageSeconds: position.ageSeconds, now: Date())
        var canOpen = position.chapterId == openedChapterId || position.downloaded == true
        if !canOpen {
            canOpen = await app.store?.hasChapter(id: position.chapterId, novelId: novelId) ?? false
        }
        // The user may have turned the page while we waited.
        guard chapterId == openedChapterId else { return }

        if LatestPosition.shouldOffer(
            position: position, deviceID: app.device.id, chapterID: openedChapterId,
            localProgress: currentProgress, localNewer: localNewer,
            declinedAt: app.declinedLatestPosition(novelId: novelId), canOpen: canOpen) {
            latestOffer = LatestOffer(position: position, message: LatestPosition.message(for: position))
        }
    }

    /// This device's position in the open chapter, 0–100.
    private var currentProgress: Int? {
        pendingProgress ?? lastSentProgress ?? content?.readProgress
    }

    /// "Go to latest": the other device's chapter at its position.
    func acceptLatestOffer(app: AppModel) async {
        guard let offer = latestOffer else { return }
        latestOffer = nil
        let target = offer.position
        if target.chapterId == chapterId {
            guard let progress = target.progress else { return }
            let index = LatestPosition.paragraphIndex(progress: progress, paragraphCount: paragraphCount) ?? 0
            scrollRequest = ScrollRequest(index: index)
        } else {
            forcedProgress = (target.chapterId, target.progress ?? 0)
            await open(chapterId: target.chapterId, app: app)
        }
    }

    /// "Stay here": don't offer this position again.
    func declineLatestOffer(app: AppModel) {
        guard let offer = latestOffer else { return }
        latestOffer = nil
        if let at = offer.position.at {
            app.declineLatestPosition(at: at, novelId: novelId)
        }
    }

    // MARK: Progress

    /// Called as the top visible paragraph changes.
    func scrolled(toParagraph index: Int, app: AppModel) {
        guard isTracking, paragraphCount > 0, content?.downloaded == true else { return }
        let fraction = Double(index) / Double(max(paragraphCount - 1, 1))
        // 100 is reserved for actually reaching the end of the chapter.
        pendingProgress = min(99, max(0, Int((fraction * 100).rounded())))
        if pendingSince == nil { pendingSince = Date() }
        scheduleSend(app: app)
    }

    func reachedEnd(app: AppModel) {
        guard isTracking, var content, content.downloaded else { return }
        sendTask?.cancel()
        sendTask = nil
        pendingProgress = nil
        pendingSince = nil
        guard !markedRead else { return }
        markedRead = true
        lastSentProgress = 100
        lastSentAt = Date()
        content.read = true
        content.readProgress = 100
        self.content = content
        let id = chapterId, novelId = self.novelId
        Task {
            await app.store?.updateChapterState(id: id, novelId: novelId, progress: 100, read: true)
            await app.record(.progress(chapterId: id, progress: 100, read: true), novelId: novelId)
        }
    }

    /// Sends any unsent position; call when leaving the chapter or the app.
    func leave(app: AppModel) async {
        sendTask?.cancel()
        sendTask = nil
        await sendPending(app: app)
    }

    private func scheduleSend(app: AppModel) {
        let elapsed = Date().timeIntervalSince(lastSentAt)
        if elapsed >= Self.progressInterval {
            sendTask?.cancel()
            sendTask = Task { await sendPending(app: app) }
        } else if sendTask == nil {
            let delay = Self.progressInterval - elapsed
            sendTask = Task {
                try? await Task.sleep(for: .seconds(delay))
                guard !Task.isCancelled else { return }
                await sendPending(app: app)
            }
        }
    }

    private func sendPending(app: AppModel) async {
        sendTask = nil
        guard let progress = pendingProgress else { return }
        pendingProgress = nil
        pendingSince = nil
        guard progress != lastSentProgress else { return }
        lastSentProgress = progress
        lastSentAt = Date()
        let id = chapterId
        await app.store?.updateChapterState(id: id, novelId: novelId, progress: progress, read: nil)
        await app.record(.progress(chapterId: id, progress: progress, read: false), novelId: novelId)
    }
}
