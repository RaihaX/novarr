import Foundation
import Observation

@MainActor
@Observable
final class HomeModel {
    private(set) var feed: HomeFeed?
    private(set) var phase: LoadPhase = .idle
    private(set) var isShowingCache = false
    /// Why the last load failed, for the failure state's title.
    private(set) var failure: APIError?
    private var loadedRevision = -1
    private var loadedAt: Date?

    func refreshIfStale(app: AppModel) async {
        let stale = loadedAt.map { Date().timeIntervalSince($0) > 60 } ?? true
        if stale || loadedRevision != app.readStateRevision {
            await load(app: app)
        }
    }

    func load(app: AppModel) async {
        if feed == nil {
            phase = .loading
            if let cached = await app.cached(HomeFeed.self, .home) {
                feed = cached
                isShowingCache = true
            }
        }
        loadedRevision = app.readStateRevision
        let result = await app.load(.home) { try await $0.home() }
        failure = result.error
        if var value = result.value {
            value.continueReading = await Self.applyingUnsentReading(value.continueReading, app: app)
            feed = value
            phase = .loaded
            loadedAt = result.fromCache ? nil : Date()
        } else if feed == nil {
            phase = .failed(result.error?.localizedDescription ?? "Couldn't load Home.")
        } else {
            phase = .loaded
        }
        isShowingCache = result.fromCache || (result.error != nil && feed != nil)
    }

    /// Continue items whose series has reading not sent yet (offline): the
    /// chapter the shared rule picks with it (`ResumeRule`), from the cached
    /// chapter list; the server's choice otherwise.
    static func applyingUnsentReading(_ items: [ContinueItem], app: AppModel) async -> [ContinueItem] {
        guard app.pendingWrites > 0 else { return items }
        var out = items
        for index in out.indices {
            let novelId = out[index].novel.id
            let unsent = await app.unsentWrites(novelId: novelId)
            guard !unsent.isEmpty,
                  let chapters = await app.cached([ChapterListItem].self, .chapters(novelId)) else { continue }
            out[index] = adjusted(out[index], chapters: chapters, unsent: unsent)
        }
        return out
    }

    /// One Continue item with the chapter `ResumeRule` picks (unchanged when it can't tell).
    nonisolated static func adjusted(_ item: ContinueItem, chapters: [ChapterListItem], unsent: [PendingWrite]) -> ContinueItem {
        let serverId = item.novel.resumeChapterId ?? item.chapter.id
        guard let id = ResumeRule.resolve(chapters: chapters, serverId: serverId, pending: unsent),
              id != item.chapter.id,
              let chapter = chapters.first(where: { $0.id == id }) else { return item }
        var item = item
        item.chapter = ChapterRef(id: chapter.id, label: chapter.label, chapter: chapter.chapter, book: chapter.book,
                                  downloaded: chapter.downloaded)
        item.novel.resumeChapterId = chapter.id
        return item
    }
}
