import Foundation
import Observation

/// The manga reader's state: a chapter's pages, where the reader is, and
/// progress by page under the text reader's rules (`ReaderModel`):
/// throttled positions through the write queue, the read mark on the last
/// page, and the latest-position prompt with its hold (`PositionHold`).
/// Android's `MangaReaderViewModel` over `BaseReaderViewModel`.
@MainActor
@Observable
final class MangaReaderModel {
    let novelId: Int
    private(set) var chapterId: Int
    private(set) var pages: MangaPages?
    private(set) var phase: LoadPhase = .idle
    private(set) var isShowingCache = false
    /// Downloaded page images of the open chapter, by page number.
    private(set) var localFiles: [Int: URL] = [:]
    /// The page shown (0-based), for "3 / 12" and for re-laying out at the same page.
    var currentPage = 0
    /// This device's choice for the series (not sent to the server).
    private(set) var override: MangaModeOverride = .follow
    /// Positions are ignored until the view has shown the restored page.
    var isTracking = false
    private(set) var latestOffer: ReaderModel.LatestOffer?
    /// Asks the view to show a page (restore, "Go to latest", a mode change).
    private(set) var scrollRequest: ReaderModel.ScrollRequest?

    @ObservationIgnored private var pendingProgress: Int?
    @ObservationIgnored private var lastSentProgress: Int?
    @ObservationIgnored private var lastSentAt = Date.distantPast
    @ObservationIgnored private var sendTask: Task<Void, Never>?
    @ObservationIgnored private var markedRead = false
    @ObservationIgnored private var pendingSince: Date?
    @ObservationIgnored private let offersLatest: Bool
    @ObservationIgnored private var firstCheckStarted = false
    @ObservationIgnored private var fetchedPosition: ReadingPosition?
    @ObservationIgnored private var positionFetched = false
    @ObservationIgnored private var hold = PositionHold()
    @ObservationIgnored private var backgroundedAt: Date?
    @ObservationIgnored private var forcedProgress: (chapterId: Int, progress: Int)?
    @ObservationIgnored private var server: URL?

    init(route: ReaderRoute) {
        novelId = route.novelId
        chapterId = route.chapterId
        offersLatest = route.offerLatest
    }

    var pageCount: Int { pages?.pages.count ?? 0 }

    /// The series' own mode, from the server.
    var seriesMode: MangaReadingMode { MangaReadingMode(raw: pages?.readingMode) }

    /// The layout in use: the series' mode unless overridden on this device.
    var mode: MangaReadingMode { override.resolve(seriesMode) }

    /// The image to show: the downloaded copy, else the pre-signed URL as given.
    func source(for page: MangaPage) -> URL? {
        localFiles[page.page] ?? page.url
    }

    /// Remembers the choice for this series on this device and re-lays the
    /// chapter out at the same page.
    func choose(_ value: MangaModeOverride) {
        guard value != override else { return }
        let before = mode
        override = value
        value.store(server: server, novelId: novelId)
        if mode != before, pages != nil {
            // Positions wait until the new layout has settled on the page.
            isTracking = false
            scrollRequest = ReaderModel.ScrollRequest(index: currentPage)
        }
    }

    // MARK: Loading

    func load(app: AppModel) async {
        phase = .loading
        isTracking = false
        markedRead = false
        pendingProgress = nil
        pendingSince = nil
        lastSentProgress = nil
        scrollRequest = nil
        server = app.serverURL
        override = MangaModeOverride.stored(server: server, novelId: novelId)

        let id = chapterId
        let novelId = self.novelId
        let cached = await app.store?.mangaChapter(id: id, novelId: novelId)
        var loaded: MangaPages?
        var failure: APIError?
        if let client = app.client {
            do {
                var fresh = try await client.mangaPages(id: id)
                app.noteSuccess()
                // Unsent local progress for this chapter is newer than what the server knows.
                if let cached, await app.hasUnsentWrite(chapterId: id) {
                    fresh.chapter.progress = cached.chapter.progress
                    fresh.chapter.read = fresh.chapter.read || cached.chapter.read
                }
                loaded = fresh
            } catch {
                failure = APIError.from(error)
                app.noteFailure(failure ?? .timedOut)
            }
        }
        guard id == chapterId else { return } // the user moved on meanwhile

        isShowingCache = loaded == nil && cached != nil
        guard var chapter = loaded ?? cached else {
            phase = .failed(failure?.localizedDescription ?? "This chapter isn't available.")
            await evaluateLatestOffer(app: app)
            return
        }
        if let store = app.store {
            let fm = FileManager.default
            localFiles = Dictionary(uniqueKeysWithValues: chapter.pages.compactMap { page in
                let file = store.pageFile(novelId: novelId, chapterId: id, page: page.page)
                return fm.fileExists(atPath: file.path) ? (page.page, file) : nil
            })
        } else {
            localFiles = [:]
        }
        let forced = forcedProgress.flatMap { $0.chapterId == id ? $0.progress : nil }
        forcedProgress = nil
        if let forced {
            // Go to latest: the other device's position wins over anything saved here.
            chapter.chapter.progress = forced
            await app.store?.updateChapterState(id: id, novelId: novelId, progress: forced, read: nil)
            guard id == chapterId else { return }
        }
        let progress = forced ?? chapter.chapter.progress
        var restore: Int?
        if let progress, forced != nil || progress < 100 {
            restore = MangaProgress.page(progress: progress, count: chapter.pages.count)
        }
        pages = chapter
        currentPage = restore ?? 0
        markedRead = chapter.chapter.read
        lastSentProgress = chapter.chapter.progress
        scrollRequest = ReaderModel.ScrollRequest(index: currentPage)
        phase = .loaded
        await evaluateLatestOffer(app: app)
    }

    func go(to ref: ChapterRef, app: AppModel) async {
        // Moving on answers the prompt ("stay") as far as this reader is concerned.
        latestOffer = nil
        apply(hold.moveOn(), app: app)
        await leave(app: app)
        chapterId = ref.id
        pages = nil
        await load(app: app)
    }

    // MARK: Progress

    /// A page is on screen (paged: the page turned to; vertical: the top
    /// page, or the last one once its end is in view). The last page is the
    /// end of the chapter (the read mark), not a position.
    func pageShown(_ index: Int, app: AppModel) {
        currentPage = index
        guard isTracking, pageCount > 0 else { return }
        if index >= pageCount - 1 {
            reachedEnd(app: app)
            return
        }
        pendingProgress = MangaProgress.progress(page: index, count: pageCount)
        // Held positions don't date local reading: they'd hide the very prompt the hold waits for.
        guard !hold.isHolding else { return }
        if pendingSince == nil { pendingSince = Date() }
        scheduleSend(app: app)
    }

    func reachedEnd(app: AppModel) {
        guard isTracking, var current = pages, !current.pages.isEmpty else { return }
        sendTask?.cancel()
        sendTask = nil
        pendingProgress = nil
        pendingSince = nil
        guard !markedRead else { return }
        markedRead = true
        // The read mark goes out regardless of the hold, and answers any prompt.
        if latestOffer != nil {
            latestOffer = nil
            apply(hold.dismissOffer(), app: app)
        }
        lastSentProgress = 100
        lastSentAt = Date()
        current.chapter.read = true
        current.chapter.progress = 100
        pages = current
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
        switch hold.leaving() {
        case .send: await sendPending(app: app)
        case .discard: discardPending()
        case .keep: break
        }
    }

    private func apply(_ release: PositionHold.Release, app: AppModel) {
        switch release {
        case .send: if pendingProgress != nil { scheduleSend(app: app) }
        case .discard: discardPending()
        case .keep: break
        }
    }

    private func discardPending() {
        sendTask?.cancel()
        sendTask = nil
        pendingProgress = nil
        pendingSince = nil
    }

    private func scheduleSend(app: AppModel) {
        let elapsed = Date().timeIntervalSince(lastSentAt)
        if elapsed >= ReaderModel.progressInterval {
            sendTask?.cancel()
            sendTask = Task { await sendPending(app: app) }
        } else if sendTask == nil {
            let delay = ReaderModel.progressInterval - elapsed
            sendTask = Task {
                try? await Task.sleep(for: .seconds(delay))
                guard !Task.isCancelled else { return }
                await sendPending(app: app)
            }
        }
    }

    private func sendPending(app: AppModel) async {
        sendTask = nil
        guard !hold.isHolding, let progress = pendingProgress else { return }
        pendingProgress = nil
        pendingSince = nil
        guard progress != lastSentProgress else { return }
        lastSentProgress = progress
        lastSentAt = Date()
        let id = chapterId
        await app.store?.updateChapterState(id: id, novelId: novelId, progress: progress, read: nil)
        await app.record(.progress(chapterId: id, progress: progress, read: false), novelId: novelId)
    }

    // MARK: Latest position

    func checkLatestPosition(app: AppModel) async {
        guard offersLatest, !firstCheckStarted else { return }
        firstCheckStarted = true
        await runCheck(app: app)
    }

    func enteredBackground() {
        if backgroundedAt == nil { backgroundedAt = Date() }
    }

    func becameActive(app: AppModel) async {
        let since = backgroundedAt
        backgroundedAt = nil
        guard LatestPosition.shouldRecheck(backgroundedAt: since, now: Date()) else { return }
        await runCheck(app: app)
    }

    private func runCheck(app: AppModel) async {
        guard let client = app.client else { return }
        let round = hold.startCheck(chapterId: chapterId)
        fetchedPosition = nil
        positionFetched = false
        let timeout = Task { [weak self] in
            try? await Task.sleep(for: .seconds(ReaderModel.checkTimeout))
            guard !Task.isCancelled, let self, self.hold.isCurrent(round), !self.positionFetched else { return }
            self.endCheck(round, offered: false, app: app)
        }
        let position = try? await client.latestPosition(novelId: novelId)
        timeout.cancel()
        guard hold.isCurrent(round), !positionFetched else { return }
        fetchedPosition = position
        positionFetched = true
        await evaluateLatestOffer(app: app)
    }

    private func evaluateLatestOffer(app: AppModel) async {
        guard let check = hold.check, positionFetched else { return }
        let round = check.round
        let failed = if case .failed = phase { true } else { false }
        guard let position = fetchedPosition, chapterId == check.chapterId, !failed else {
            endCheck(round, offered: false, app: app)
            return
        }
        guard phase == .loaded, pages?.chapter.id == check.chapterId else { return }

        var localTimes = await app.unsentWriteTimes(novelId: novelId)
        if let pendingSince { localTimes.append(pendingSince) }
        let localNewer = LatestPosition.localIsNewer(
            localWriteTimes: localTimes, ageSeconds: position.ageSeconds, now: Date())
        var canOpen = position.chapterId == check.chapterId || position.downloaded == true
        if !canOpen {
            canOpen = await app.store?.hasChapter(id: position.chapterId, novelId: novelId) ?? false
        }
        guard hold.isCurrent(round) else { return }
        guard chapterId == check.chapterId else {
            endCheck(round, offered: false, app: app)
            return
        }
        let offered = LatestPosition.shouldOffer(
            position: position, deviceID: app.device.id, chapterID: check.chapterId,
            localProgress: pendingProgress ?? lastSentProgress ?? pages?.chapter.progress, localNewer: localNewer,
            declinedAt: app.declinedLatestPosition(novelId: novelId), canOpen: canOpen)
        if offered {
            latestOffer = ReaderModel.LatestOffer(position: position, message: LatestPosition.message(for: position))
        }
        endCheck(round, offered: offered, app: app)
    }

    private func endCheck(_ round: Int, offered: Bool, app: AppModel) {
        apply(hold.resolveCheck(round, offered: offered), app: app)
    }

    /// "Go to latest": the other device's chapter at its page.
    func acceptLatestOffer(app: AppModel) async {
        guard let offer = latestOffer else { return }
        latestOffer = nil
        apply(hold.goToLatest(), app: app)
        let target = offer.position
        if target.chapterId == chapterId {
            guard let progress = target.progress else { return }
            scrollRequest = ReaderModel.ScrollRequest(index: MangaProgress.page(progress: progress, count: pageCount) ?? 0)
        } else {
            forcedProgress = (target.chapterId, target.progress ?? 0)
            await go(to: ChapterRef(id: target.chapterId, label: ""), app: app)
        }
    }

    /// "Stay here": don't offer this position again.
    func declineLatestOffer(app: AppModel) {
        guard let offer = latestOffer else { return }
        latestOffer = nil
        if let at = offer.position.at {
            app.declineLatestPosition(at: at, novelId: novelId)
        }
        apply(hold.stay(), app: app)
    }
}
