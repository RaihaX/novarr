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

    /// "Continue listening" (audiobooks started and not finished); empty without audiobooks on the server.
    private(set) var listening: [AudiobookSummary] = []

    /// The library by id (the categories' covers, Favourites, the search): the saved copy, refreshed with Home.
    let library = LibraryNovels()

    /// "Your stats": the 30-day answer (saved copy, then fresh); nil hides the card.
    private(set) var stats: ReadingStats?
    private var statsLoadedAt: Date?
    private var statsRevision = -1

    // Search: the library on this device at once, the server's audiobooks after a pause in typing.
    private(set) var query = ""
    private(set) var seriesResults: [NovelSummary] = []
    private(set) var audiobookResults: [AudiobookSummary] = []
    private(set) var searchingAudiobooks = false
    @ObservationIgnored private var audiobookSearch: Task<Void, Never>?

    /// Where Continue listening is saved for offline.
    static let listeningKey = OfflineStore.Key.custom("audiobooks/continue.json")

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
        await loadListening(app: app)
        // The library copy: fetched with every Home load (a pull too), the saved one first.
        await library.load(app: app, fetch: true)
    }

    func refreshLibrary(app: AppModel) async { await library.load(app: app, fetch: true) }

    func loadListening(app: AppModel) async {
        guard app.serverMeta?.audiobooks == true else {
            listening = []
            return
        }
        if listening.isEmpty, let cached = await app.cached([AudiobookSummary].self, Self.listeningKey) { listening = cached }
        if let value = await app.load(Self.listeningKey, fetch: { try await $0.continueListening() }).value { listening = value }
    }

    /// The stats card's figures, reloaded when read state changed or they are a few minutes old.
    func refreshStatsIfStale(app: AppModel) async {
        let stale = statsLoadedAt.map { Date().timeIntervalSince($0) > 300 } ?? true
        guard stale || statsRevision != app.readStateRevision else { return }
        statsRevision = app.readStateRevision
        if stats == nil { stats = await app.cached(ReadingStats.self, .stats(30)) }
        let result = await app.load(.stats(30)) { try await $0.stats(range: 30) }
        if let value = result.value, !result.fromCache {
            stats = value
            statsLoadedAt = Date()
        } else if result.value == nil, let status = result.error?.status, status == 404 || status == 405 {
            // An older server without stats (and nothing saved): no card.
            stats = nil
        } else if let value = result.value {
            stats = value
        }
    }

    func search(_ text: String, app: AppModel) {
        query = text
        seriesResults = HomeShelves.search(text, novels: library.novels.values)
        audiobookSearch?.cancel()
        let trimmed = text.trimmingCharacters(in: .whitespaces)
        guard !trimmed.isEmpty, app.serverMeta?.audiobooks == true, let client = app.client else {
            audiobookResults = []
            searchingAudiobooks = false
            return
        }
        searchingAudiobooks = true
        audiobookSearch = Task {
            try? await Task.sleep(for: .milliseconds(400))
            guard !Task.isCancelled else { return }
            let found = try? await client.audiobooks(search: trimmed).audiobooks
            guard !Task.isCancelled else { return }
            // Offline: what this device has seen (Continue listening).
            let needle = HomeShelves.normalize(trimmed)
            audiobookResults = found ?? listening.filter {
                HomeShelves.normalize("\($0.title) \($0.author ?? "") \($0.narrator ?? "")").contains(needle)
            }
            searchingAudiobooks = false
        }
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
