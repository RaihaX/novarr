import Foundation
import Observation

@MainActor
@Observable
final class NovelModel {
    let novelId: Int
    private(set) var detail: NovelDetail?
    private(set) var placeholder: NovelSummary?
    private(set) var chapters: [ChapterListItem] = []
    private(set) var cachedIDs: Set<Int> = []
    private(set) var phase: LoadPhase = .idle
    private(set) var isShowingCache = false
    private(set) var actionError: String?
    /// The newest few highlights in this novel (empty when none, or the server has no highlights API).
    private(set) var highlights: [Highlight] = []
    private(set) var hasMoreHighlights = false
    /// This series' writes not sent yet (the local resume rule).
    private(set) var unsentWrites: [PendingWrite] = []
    var chapterSearch = ""

    static let highlightPreviewCount = 3

    init(route: NovelRoute) {
        novelId = route.id
        placeholder = route.summary
    }

    var summary: NovelSummary? { detail?.summary ?? placeholder }

    /// Chapters open in the manga reader and download as page images.
    var isManga: Bool { summary?.isManga == true }

    /// Where a chapter of this series opens.
    func readerRoute(chapterId: Int, offerLatest: Bool = true) -> ReaderRoute {
        ReaderRoute(novelId: novelId, chapterId: chapterId, offerLatest: offerLatest, manga: isManga)
    }

    var visibleChapters: [ChapterListItem] {
        let query = chapterSearch.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !query.isEmpty else { return chapters }
        // A bare number matches the chapter number exactly as well as the label text.
        let number = Double(query)
        return chapters.filter { chapter in
            if let number, chapter.chapter == number { return true }
            return chapter.label.range(of: query, options: [.caseInsensitive, .diacriticInsensitive]) != nil
        }
    }

    var firstUnread: ChapterListItem? {
        chapters.first { !$0.read && $0.downloaded && !$0.note } ?? chapters.first { !$0.read }
    }

    /// Where "Continue" opens: the server's choice plus this device's unsent
    /// reading, by the rule every device shares (`ResumeRule`).
    var resumeChapterId: Int? {
        ResumeRule.resolve(chapters: chapters, serverId: summary?.resumeChapterId, pending: unsentWrites)
    }

    var readCount: Int { chapters.isEmpty ? (summary?.chaptersRead ?? 0) : chapters.lazy.filter(\.read).count }

    // MARK: Loading

    func load(app: AppModel) async {
        if detail == nil && chapters.isEmpty {
            phase = .loading
            async let cachedDetail = app.cached(NovelDetail.self, .novel(novelId))
            async let cachedChapters = app.cached([ChapterListItem].self, .chapters(novelId))
            if let value = await cachedDetail { detail = value }
            if let value = await cachedChapters { chapters = value }
        }
        await refreshCachedIDs(app: app)
        await refreshUnsentWrites(app: app)

        let id = novelId
        async let detailResult = app.load(.novel(id)) { try await $0.novel(id: id) }
        async let chaptersResult = app.load(.chapters(id)) { try await $0.chapters(novelId: id) }
        let (d, c) = await (detailResult, chaptersResult)

        if let value = d.value { detail = value }
        if let value = c.value { chapters = value }
        isShowingCache = d.error != nil || c.error != nil
        if detail == nil && placeholder == nil && chapters.isEmpty {
            phase = .failed((d.error ?? c.error)?.localizedDescription ?? "Couldn't load this novel.")
        } else {
            phase = .loaded
        }
        if d.error == nil { await loadHighlights(app: app) }
    }

    /// Best effort: a server without highlights simply shows no section.
    func loadHighlights(app: AppModel) async {
        // Manga have pages, not text to highlight.
        guard !isManga, let client = app.client,
              let page = try? await client.highlights(novelId: novelId, page: 1, perPage: Self.highlightPreviewCount)
        else { return }
        highlights = page.highlights
        hasMoreHighlights = page.hasMore
    }

    func refreshUnsentWrites(app: AppModel) async {
        unsentWrites = await app.unsentWrites(novelId: novelId)
    }

    func refreshCachedIDs(app: AppModel) async {
        cachedIDs = await app.store?.cachedChapterIDs(novelId: novelId) ?? []
    }

    // MARK: Read state

    func setRead(_ chapter: ChapterListItem, read: Bool, app: AppModel) async {
        apply(read: read) { $0.id == chapter.id }
        await persistLocalState(app: app)
        await app.record(.setRead(chapterId: chapter.id, read: read), novelId: novelId)
        await app.store?.updateChapterState(id: chapter.id, novelId: novelId, progress: read ? 100 : 0, read: read)
        await refreshDetailIfOnline(app: app)
        await refreshUnsentWrites(app: app)
    }

    func bulk(_ request: BulkReadRequest, app: AppModel) async {
        switch request.scope {
        case .all:
            apply(read: request.read) { _ in true }
        case .upTo, .from:
            guard let anchor = request.anchorId, let index = chapters.firstIndex(where: { $0.id == anchor }) else { break }
            let range = request.scope == .upTo ? chapters.startIndex...index : index...(chapters.endIndex - 1)
            let ids = Set(chapters[range].map(\.id))
            apply(read: request.read) { ids.contains($0.id) }
        case .ids:
            let ids = Set(request.ids ?? [])
            apply(read: request.read) { ids.contains($0.id) }
        }
        await persistLocalState(app: app)
        await app.record(.bulkRead(request), novelId: novelId)
        await refreshDetailIfOnline(app: app)
        await refreshUnsentWrites(app: app)
    }

    private func apply(read: Bool, where predicate: (ChapterListItem) -> Bool) {
        for index in chapters.indices where predicate(chapters[index]) {
            chapters[index].read = read
            chapters[index].readProgress = read ? 100 : nil
        }
        // Keep the header counts honest until the server's numbers arrive.
        if var detail {
            let readCount = chapters.lazy.filter(\.read).count
            detail.summary.chaptersRead = readCount
            if detail.summary.chaptersTotal > 0 {
                detail.summary.readPercent = Int((Double(readCount) / Double(detail.summary.chaptersTotal) * 100).rounded(.down))
            }
            detail.summary.finished = detail.summary.chaptersTotal > 0 && readCount >= detail.summary.chaptersTotal
            if detail.summary.finished || !read { detail.summary.chaptersNew = 0 }
            self.detail = detail
        }
    }

    private func persistLocalState(app: AppModel) async {
        await app.store?.save(chapters, as: .chapters(novelId))
        if let detail { await app.store?.save(detail, as: .novel(novelId)) }
    }

    private func refreshDetailIfOnline(app: AppModel) async {
        guard app.isReachable, app.pendingWrites == 0 else { return }
        let id = novelId
        let result = await app.load(.novel(id)) { try await $0.novel(id: id) }
        if let value = result.value, !result.fromCache { detail = value }
    }

    // MARK: Offline downloads

    enum DownloadSelection { case all, unread }

    func download(_ selection: DownloadSelection, app: AppModel) async {
        guard let client = app.client, let store = app.store else { return }
        await refreshCachedIDs(app: app)
        // The chapter Continue opens is wanted even when it is marked read
        // but not finished (the web reader marks read on open).
        let resume = chapters.first { $0.id == resumeChapterId }
            .flatMap { ResumeRule.isUnfinished(read: $0.read, progress: $0.readProgress) ? $0.id : nil }
        let ids = chapters
            .filter { $0.downloaded && !cachedIDs.contains($0.id) && (selection == .all || !$0.read || $0.id == resume) }
            .map(\.id)
        app.downloads.start(novelId: novelId, chapterIds: ids, manga: isManga, client: client, store: store)
    }

    func removeDownloads(app: AppModel) async {
        guard let store = app.store else { return }
        await app.downloads.remove(novelId: novelId, store: store)
        await refreshCachedIDs(app: app)
    }
}
