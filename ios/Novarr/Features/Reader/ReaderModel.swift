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
    /// The chapter turned out to be manga (page images): the view opens the
    /// manga reader for it instead of showing the failure.
    private(set) var isMangaChapter = false
    /// Global UTF-16 offsets of the open chapter's paragraphs.
    private(set) var offsets = TextOffsets(lengths: [])
    /// Where the reader is: the offset (see `offsets`) of the first line on
    /// screen; in scroll mode, the top paragraph's start. Both modes keep it,
    /// so a re-pagination, rotation or switch between Scroll and Pages lands
    /// on the same text. Set on load to where the chapter should open.
    @ObservationIgnored var anchorOffset = 0
    /// Open at the end of the chapter (paging back from the next one).
    private(set) var restoreToEnd = false
    /// The position label's percentage, 0–100.
    private(set) var shownProgress = 0
    /// "Chapter 12 of 323", from the cached chapter list; nil when unknown.
    private(set) var chapterPosition: ChapterPosition?
    /// The open chapter's highlights (the server's, cached, and ones made
    /// here that may not have reached it yet), and the ranges to tint per paragraph.
    private(set) var highlights: [Highlight] = []
    private(set) var highlightRanges: [Int: [NSRange]] = [:]
    /// Scroll events are ignored until the view has restored the position,
    /// so the initial jump never reports a bogus progress value.
    var isTracking = false
    /// "Read more recently on another device" prompt, when it applies.
    private(set) var latestOffer: LatestOffer?
    /// Asks the view to scroll within the current chapter (Go to latest, same chapter).
    private(set) var scrollRequest: ScrollRequest?

    /// The progress-write rules (throttling, the read mark once).
    @ObservationIgnored private var tracker = ProgressTracker()
    @ObservationIgnored private var sendTask: Task<Void, Never>?
    /// The end of the chapter is on screen: positions are not reported (a
    /// fling past the end keeps moving the top line, and those moves must
    /// not follow the read mark with a lower percentage).
    @ObservationIgnored private var atEnd = false

    // Latest-position check: once when the reader opens (if the route offers
    // it), and again on coming back from the background, for the chapter shown.
    @ObservationIgnored private let offersLatest: Bool
    @ObservationIgnored private var firstCheckStarted = false
    /// The current check's answer, once in (`positionFetched`); may be nil.
    @ObservationIgnored private var fetchedPosition: ReadingPosition?
    @ObservationIgnored private var positionFetched = false
    /// Scroll positions wait here while a check or its prompt is open.
    @ObservationIgnored private var hold = PositionHold()
    @ObservationIgnored private var backgroundedAt: Date?
    /// "Go to latest" into another chapter: restore here, ignoring local progress.
    @ObservationIgnored private var forcedProgress: (chapterId: Int, progress: Int)?
    /// Opened from a highlight: the chapter opens at its passage, as a
    /// look-up (`PositionHold.startLookup`). Cleared on moving on.
    @ObservationIgnored private var lookupHighlight: Highlight?
    /// Paging back from the next chapter: open this one at its end.
    @ObservationIgnored private var openAtEndId: Int?

    // Highlights: the series' list is fetched once per reader (and cached
    // for offline); ones saved here are shown at once and kept until a
    // fetch has them.
    @ObservationIgnored private var seriesHighlights: [Highlight]?
    @ObservationIgnored private var highlightsFetched = false
    @ObservationIgnored private var savedHere: [Highlight] = []
    @ObservationIgnored private var previewIds = 0
    @ObservationIgnored private var chapterList: [ChapterListItem]?

    /// The series is a book (per the saved library listing or detail):
    /// complete on arrival, so its last chapter offers no "check for new chapters".
    private(set) var isBook = false

    struct ChapterPosition: Equatable {
        var number: Int
        var count: Int
    }

    struct LatestOffer: Equatable {
        var position: ReadingPosition
        var message: String
    }

    struct ScrollRequest: Equatable {
        var index: Int
        var token = UUID()
    }

    /// Minimum seconds between progress writes while scrolling.
    static let progressInterval: TimeInterval = ProgressTracker.defaultInterval
    /// Longest wait for the server's latest position before giving up on the prompt.
    static let checkTimeout: TimeInterval = 3.5

    init(route: ReaderRoute) {
        novelId = route.novelId
        chapterId = route.chapterId
        offersLatest = route.offerLatest
        if let highlight = route.highlight, highlight.chapterId == route.chapterId {
            lookupHighlight = highlight
            hold.startLookup()
        }
    }

    var paragraphCount: Int { content?.paragraphs.count ?? 0 }

    /// The chapter was opened at a highlight and nothing about the position is sent.
    var isLookup: Bool { hold.lookup }

    /// Paragraph the scroll view should show at the top when it appears; nil means the header.
    var restoreParagraph: Int? {
        guard paragraphCount > 0 else { return nil }
        if restoreToEnd { return paragraphCount - 1 }
        return anchorOffset > 0 ? offsets.paragraphAt(anchorOffset) : nil
    }

    /// 0–99 for paragraph `index`, the rule every progress write uses
    /// (100 is reserved for actually reaching the end of the chapter).
    func progress(forParagraph index: Int) -> Int {
        ProgressTracker.progress(index: index, count: paragraphCount)
    }

    // MARK: Loading

    func load(app: AppModel) async {
        phase = .loading
        isTracking = false
        atEnd = false
        tracker.reset(read: false, progress: nil)
        restoreToEnd = false
        if !isBookKnown { await lookUpMedium(app: app) }

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
                // Unsent local progress for this chapter is newer than what the server knows.
                let unsent = await app.hasUnsentWrite(chapterId: id)
                if unsent, let cached {
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

        // A manga chapter (the server's 422, or pages saved offline) belongs
        // in the manga reader; the view swaps itself for it.
        if loaded == nil, let failure, failure == .mangaChapter || failure.isConnectivity {
            var manga = failure == .mangaChapter
            if !manga, let store = app.store { manga = await store.mangaChapter(id: id, novelId: novelId) != nil }
            guard id == chapterId else { return }
            isMangaChapter = manga
        }
        guard var loaded else {
            phase = .failed(failure?.localizedDescription ?? "This chapter isn't available.")
            await evaluateLatestOffer(app: app) // ends a check waiting for this chapter
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
        let offsets = TextOffsets(paragraphs: loaded.paragraphs)
        let count = loaded.paragraphs.count
        // Where to open: "Go to latest", a highlight's passage, the end (paging
        // back), else the saved progress. A highlight not found in the text
        // falls back to the saved progress.
        var anchor: Int?
        if let forced {
            anchor = LatestPosition.paragraphIndex(progress: forced, paragraphCount: count).map(offsets.paragraphStart)
        } else if let lookupHighlight, lookupHighlight.chapterId == id,
                  let found = HighlightMatcher.locate(lookupHighlight.excerpt, in: loaded.paragraphs) {
            anchor = offsets.paragraphStart(found.start.paragraph) + found.start.offset
        } else if openAtEndId == id, count > 0 {
            restoreToEnd = true
            anchor = offsets.paragraphStart(count - 1)
        } else if let progress = loaded.readProgress, progress < 100 {
            anchor = LatestPosition.paragraphIndex(progress: progress, paragraphCount: count).map(offsets.paragraphStart)
        }
        openAtEndId = nil
        self.offsets = offsets
        anchorOffset = anchor ?? 0
        content = loaded
        tracker.reset(read: loaded.read, progress: loaded.readProgress)
        highlights = []
        highlightRanges = [:]
        shownProgress = restoreToEnd ? 100 : progress(forParagraph: offsets.paragraphAt(anchorOffset))
        phase = .loaded
        if loaded.downloaded { app.downloads.contentChanged() }
        prefetch(loaded.next, app: app)
        Task {
            await locateChapter(id, app: app)
            await loadHighlights(chapterId: id, app: app)
        }
        await evaluateLatestOffer(app: app)
    }

    /// "Chapter 12 of 323" from the cached chapter list (the chapter API
    /// doesn't say how many there are); nil when the list isn't cached.
    private func locateChapter(_ id: Int, app: AppModel) async {
        if chapterList == nil { chapterList = await app.cached([ChapterListItem].self, .chapters(novelId)) }
        guard id == chapterId else { return }
        chapterPosition = chapterList.flatMap { list in
            list.firstIndex { $0.id == id }.map { ChapterPosition(number: $0 + 1, count: list.count) }
        }
    }

    /// Fetches the next chapter in the background so it's ready (and offline).
    private func prefetch(_ ref: ChapterRef?, app: AppModel) {
        guard let ref, ref.downloaded != false, let client = app.client, let store = app.store else { return }
        let novelId = self.novelId
        Task(priority: .utility) {
            guard await !store.hasChapter(id: ref.id, novelId: novelId),
                  var chapter = try? await client.chapter(id: ref.id) else { return }
            if chapter.novelId == 0 { chapter.novelId = novelId }
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

    /// Opens `ref`. Moving on to the open chapter's next chapter (Next
    /// chapter in the bars or on the end page, a tap or swipe past the last
    /// page) finishes the one being left first: it is marked read
    /// (`ReadMark`), even if its last lines never reached the tracker.
    func go(to ref: ChapterRef, app: AppModel) async {
        if ReadMark.finishesOnOpen(target: ref.id, next: content?.next?.id, loaded: hasLoadedText) {
            markFinished(app: app)
        }
        await open(chapterId: ref.id, app: app)
    }

    /// The chapter on screen is the one asked for and has text to read.
    private var hasLoadedText: Bool {
        guard phase == .loaded, let content else { return false }
        return content.id == chapterId && content.downloaded && !content.paragraphs.isEmpty
    }

    @ObservationIgnored private var isBookKnown = false

    /// Whether the series is a book: the saved library listing, else the saved detail.
    private func lookUpMedium(app: AppModel) async {
        let id = novelId
        if let listed = await app.cached([NovelSummary].self, .novels)?.first(where: { $0.id == id }) {
            isBook = listed.isBook
        } else if let detail = await app.cached(NovelDetail.self, .novel(id)) {
            isBook = detail.summary.isBook
        } else {
            return
        }
        isBookKnown = true
    }

    /// Opens `ref` at its end (paging back past the first page).
    func openAtEnd(_ ref: ChapterRef, app: AppModel) async {
        openAtEndId = ref.id
        await open(chapterId: ref.id, app: app)
    }

    private func open(chapterId id: Int, app: AppModel) async {
        // Moving on answers the prompt ("stay") as far as this reader is
        // concerned, and ends a look-up (its position is dropped).
        latestOffer = nil
        lookupHighlight = nil
        apply(hold.moveOn(), app: app)
        await leave(app: app)
        chapterId = id
        content = nil
        await load(app: app)
    }

    // MARK: Latest position

    /// First open: asks the server where this series was last read. Runs
    /// alongside `load`; any failure or slow answer simply means no prompt.
    func checkLatestPosition(app: AppModel) async {
        guard offersLatest, !firstCheckStarted else { return }
        firstCheckStarted = true
        await runCheck(app: app)
    }

    func enteredBackground() {
        if backgroundedAt == nil { backgroundedAt = Date() }
    }

    /// Back in the foreground: after a while away another device may have
    /// read further, so check again for the chapter shown (whatever the route).
    func becameActive(app: AppModel) async {
        let since = backgroundedAt
        backgroundedAt = nil
        // A look-up never asks: checking a passage isn't reading there.
        guard !hold.lookup, LatestPosition.shouldRecheck(backgroundedAt: since, now: Date()) else { return }
        await runCheck(app: app)
    }

    /// Holds scroll writes from now until the check ends: answered and
    /// evaluated, failed, or given up on after `checkTimeout`.
    private func runCheck(app: AppModel) async {
        guard let client = app.client else { return }
        let round = hold.startCheck(chapterId: chapterId)
        fetchedPosition = nil
        positionFetched = false
        let timeout = Task { [weak self] in
            try? await Task.sleep(for: .seconds(Self.checkTimeout))
            guard !Task.isCancelled, let self, self.hold.isCurrent(round), !self.positionFetched else { return }
            self.endCheck(round, offered: false, app: app)
        }
        let position = try? await client.latestPosition(novelId: novelId)
        timeout.cancel()
        guard hold.isCurrent(round), !positionFetched else { return } // timed out or superseded
        fetchedPosition = position
        positionFetched = true
        await evaluateLatestOffer(app: app)
    }

    /// Decides once both the chapter and the server's answer are in, then
    /// ends the check. Waits (returns) only while the check's chapter is
    /// still loading; `load` calls back here when it's done.
    private func evaluateLatestOffer(app: AppModel) async {
        guard let check = hold.check, positionFetched else { return }
        let round = check.round
        let failed = if case .failed = phase { true } else { false }
        guard let position = fetchedPosition, chapterId == check.chapterId, !failed else {
            endCheck(round, offered: false, app: app)
            return
        }
        guard phase == .loaded, content?.id == check.chapterId else { return }

        var localTimes = await app.unsentWriteTimes(novelId: novelId)
        // Positions taken during the hold don't count (`pendingSince` skips them).
        if let pendingSince = tracker.pendingSince { localTimes.append(pendingSince) }
        let localNewer = LatestPosition.localIsNewer(
            localWriteTimes: localTimes, ageSeconds: position.ageSeconds, now: Date())
        var canOpen = position.chapterId == check.chapterId || position.downloaded == true
        if !canOpen {
            canOpen = await app.store?.hasChapter(id: position.chapterId, novelId: novelId) ?? false
        }
        // Ended meanwhile (the user turned the page, or a newer check started).
        guard hold.isCurrent(round) else { return }
        guard chapterId == check.chapterId else {
            endCheck(round, offered: false, app: app)
            return
        }

        let offered = LatestPosition.shouldOffer(
            position: position, deviceID: app.device.id, chapterID: check.chapterId,
            localProgress: currentProgress, localNewer: localNewer,
            declinedAt: app.declinedLatestPosition(novelId: novelId), canOpen: canOpen)
        if offered {
            latestOffer = LatestOffer(position: position, message: LatestPosition.message(for: position))
        }
        endCheck(round, offered: offered, app: app)
    }

    private func endCheck(_ round: Int, offered: Bool, app: AppModel) {
        apply(hold.resolveCheck(round, offered: offered), app: app)
    }

    /// This device's position in the open chapter, 0–100.
    private var currentProgress: Int? {
        tracker.current ?? content?.readProgress
    }

    /// "Go to latest": the other device's chapter at its position.
    func acceptLatestOffer(app: AppModel) async {
        guard let offer = latestOffer else { return }
        latestOffer = nil
        // The position here must not replace the one being gone to.
        apply(hold.goToLatest(), app: app)
        let target = offer.position
        if target.chapterId == chapterId {
            guard let progress = target.progress else { return }
            let index = LatestPosition.paragraphIndex(progress: progress, paragraphCount: paragraphCount) ?? 0
            anchorOffset = offsets.paragraphStart(index)
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
        apply(hold.stay(), app: app)
    }

    // MARK: Progress

    /// Called as the top visible paragraph changes (scroll mode), or a page
    /// is turned to (pages mode: `offset` is the page's first line).
    func scrolled(toParagraph index: Int, offset: Int? = nil, app: AppModel) {
        guard isTracking, paragraphCount > 0, content?.downloaded == true else { return }
        anchorOffset = offset ?? offsets.paragraphStart(index)
        // While the end is on screen the read mark stands (and the label shows 100%).
        guard !atEnd else { return }
        let progress = progress(forParagraph: index)
        shownProgress = progress
        switch tracker.moved(progress, now: Date(), holding: hold.isHolding) {
        case .sendNow:
            sendTask?.cancel()
            sendTask = Task { await sendPending(app: app) }
        case .sendAfter(let delay):
            sendTask?.cancel()
            sendTask = Task {
                try? await Task.sleep(for: .seconds(delay))
                guard !Task.isCancelled else { return }
                await sendPending(app: app)
            }
        case .alreadyScheduled, .held:
            break
        }
    }

    /// The end of the chapter came into view (its footer or last page, or
    /// the bottom of 95% of a scrolled chapter's paragraphs) or left it.
    func endVisible(_ visible: Bool, app: AppModel) {
        atEnd = visible && isTracking && content?.downloaded == true
        if visible { reachedEnd(app: app) }
    }

    /// The end of the chapter is on screen: mark it read, once.
    func reachedEnd(app: AppModel) {
        guard isTracking, let content, content.downloaded else { return }
        shownProgress = 100
        markFinished(app: app)
    }

    /// Marks the open chapter read (once): position 100 with the read flag,
    /// queued like any write. The read mark goes out regardless of the hold,
    /// and answers any prompt (it is dismissed, not declined).
    private func markFinished(app: AppModel) {
        sendTask?.cancel()
        sendTask = nil
        guard var content, tracker.reachedEnd(now: Date()) else { return }
        if latestOffer != nil {
            latestOffer = nil
            apply(hold.dismissOffer(), app: app)
        }
        content.read = true
        content.readProgress = 100
        self.content = content
        let id = chapterId, novelId = self.novelId
        // A look-up's read mark is flagged so the series' latest position
        // stays put; its position (held) is still never sent.
        let write: PendingWrite.Kind = hold.lookup
            ? .lookupRead(chapterId: id)
            : .progress(chapterId: id, progress: 100, read: true)
        Task {
            await app.store?.updateChapterState(id: id, novelId: novelId, progress: 100, read: true)
            await app.record(write, novelId: novelId)
        }
    }

    /// Sends any unsent position; call when leaving the chapter or the app.
    /// With a prompt unanswered the position is dropped instead, and while a
    /// check is still waiting it stays held until the check ends.
    func leave(app: AppModel) async {
        sendTask?.cancel()
        sendTask = nil
        switch hold.leaving() {
        case .send: await sendPending(app: app)
        case .discard: discardPending()
        case .keep: break
        }
    }

    /// Carries out what the hold says about the unsent position: once
    /// nothing holds it, it goes out straight away.
    private func apply(_ release: PositionHold.Release, app: AppModel) {
        switch release {
        case .send:
            guard tracker.pending != nil else { return }
            sendTask?.cancel()
            sendTask = Task { await sendPending(app: app) }
        case .discard: discardPending()
        case .keep: break
        }
    }

    private func discardPending() {
        sendTask?.cancel()
        sendTask = nil
        tracker.discardPending()
    }

    private func sendPending(app: AppModel) async {
        // A send scheduled before a check started waits for the hold to end.
        guard let progress = tracker.takePending(now: Date(), holding: hold.isHolding) else { return }
        let id = chapterId
        await app.store?.updateChapterState(id: id, novelId: novelId, progress: progress, read: nil)
        await app.record(.progress(chapterId: id, progress: progress, read: false), novelId: novelId)
    }

    // MARK: Highlights

    /// Shows what is known at once (this session's or the cached list, plus
    /// highlights still queued), then fetches the series' list once per reader.
    private func loadHighlights(chapterId id: Int, app: AppModel) async {
        if seriesHighlights == nil {
            seriesHighlights = await app.cached([Highlight].self, .highlights(novelId))
        }
        await applyHighlights(chapterId: id, app: app)
        guard !highlightsFetched, let client = app.client,
              let fresh = await Self.fetchHighlights(novelId: novelId, client: client) else { return }
        highlightsFetched = true
        seriesHighlights = fresh
        await app.store?.save(fresh, as: .highlights(novelId))
        await applyHighlights(chapterId: id, app: app)
    }

    /// Every highlight in the series, page by page; nil if any page fails
    /// (offline, or a server without highlights).
    private static func fetchHighlights(novelId: Int, client: APIClient) async -> [Highlight]? {
        var all: [Highlight] = []
        // The list has no chapter filter; 25 pages of 200 is far beyond real use.
        for page in 1...25 {
            guard let answer = try? await client.highlights(novelId: novelId, page: page, perPage: 200) else { return nil }
            all += answer.highlights
            if !answer.hasMore || answer.highlights.isEmpty { break }
        }
        return all
    }

    /// The chapter's highlights: the series' list, ones saved here and ones
    /// still queued from earlier, each excerpt once; then where to tint.
    private func applyHighlights(chapterId id: Int, app: AppModel) async {
        let queued = PendingWrite.highlightDrafts(await app.queue?.items ?? [], chapterId: id)
        guard id == chapterId, let content, content.id == id else { return }
        var list: [Highlight] = []
        var seen = Set<String>()
        func add(_ highlight: Highlight) {
            if seen.insert(HighlightMatcher.normalize(highlight.excerpt)).inserted { list.append(highlight) }
        }
        (seriesHighlights ?? []).filter { $0.chapterId == id }.forEach(add)
        savedHere.filter { $0.chapterId == id }.forEach(add)
        for draft in queued {
            previewIds -= 1
            add(draft.preview(id: previewIds, novelId: novelId, novelName: content.novelName, chapterLabel: content.label))
        }
        highlights = list
        let matches = HighlightMatcher.matches(list, in: content.paragraphs)
        highlightRanges = HighlightMatcher.ranges(for: matches, lengths: content.paragraphs.map { $0.utf16.count })
    }

    /// Saves a highlight of the open chapter through the write queue (sent
    /// now when online, kept until it can be otherwise) and tints it at once.
    /// False when the text is empty or over the server's limits.
    @discardableResult
    func saveHighlight(excerpt: String, note: String?, app: AppModel) async -> Bool {
        guard let content, content.id == chapterId,
              let draft = HighlightDraft(chapterId: content.id, excerpt: excerpt, note: note) else { return false }
        previewIds -= 1
        let preview = draft.preview(id: previewIds, novelId: novelId, novelName: content.novelName,
                                    chapterLabel: content.label)
        savedHere.append(preview)
        // Kept with the offline copy too, so it shows when reopened offline
        // even after it was sent; the next fetch replaces the list.
        var cached = seriesHighlights ?? []
        cached.append(preview)
        seriesHighlights = cached
        await app.store?.save(cached, as: .highlights(novelId))
        await app.record(.highlight(draft), novelId: novelId)
        await applyHighlights(chapterId: content.id, app: app)
        return true
    }
}
