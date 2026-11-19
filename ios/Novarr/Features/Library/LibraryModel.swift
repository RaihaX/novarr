import Foundation
import Observation

@MainActor
@Observable
final class LibraryModel {
    private(set) var novels: [NovelSummary] = []
    private(set) var phase: LoadPhase = .idle
    private(set) var isShowingCache = false
    private var loadedRevision = -1
    private var loadedAt: Date?

    var search = ""
    var filter: LibraryFilter.Filter {
        didSet { UserDefaults.standard.set(filter.rawValue, forKey: "library.filter") }
    }
    var sort: LibraryFilter.Sort {
        didSet { UserDefaults.standard.set(sort.rawValue, forKey: "library.sort") }
    }
    var shelf: LibraryFilter.Shelf {
        didSet { UserDefaults.standard.set(shelf.rawValue, forKey: "library.shelf") }
    }

    init() {
        filter = UserDefaults.standard.string(forKey: "library.filter").flatMap(LibraryFilter.Filter.init) ?? .all
        sort = UserDefaults.standard.string(forKey: "library.sort").flatMap(LibraryFilter.Sort.init) ?? .lastRead
        shelf = UserDefaults.standard.string(forKey: "library.shelf").flatMap(LibraryFilter.Shelf.init) ?? .novels
    }

    /// The series on the chosen shelf (novels or manga).
    var onShelf: [NovelSummary] { LibraryFilter.shelf(novels, shelf) }

    var visible: [NovelSummary] {
        LibraryFilter.apply(onShelf, filter: filter, sort: sort, search: search)
    }

    func count(for filter: LibraryFilter.Filter) -> Int {
        onShelf.lazy.filter(filter.matches).count
    }

    func count(for shelf: LibraryFilter.Shelf) -> Int {
        LibraryFilter.shelf(novels, shelf).count
    }

    /// Reloads when read state changed elsewhere or the data is a while old.
    func refreshIfStale(app: AppModel) async {
        let stale = loadedAt.map { Date().timeIntervalSince($0) > 60 } ?? true
        if stale || loadedRevision != app.readStateRevision {
            await load(app: app)
        }
    }

    func load(app: AppModel) async {
        if novels.isEmpty {
            phase = .loading
            // Show the saved copy straight away while the network request runs.
            if let cached = await app.cached([NovelSummary].self, .novels) {
                novels = cached
                isShowingCache = true
            }
        }
        loadedRevision = app.readStateRevision
        let result = await app.load(.novels) { try await $0.novels() }
        if let value = result.value {
            novels = value
            phase = .loaded
            loadedAt = result.fromCache ? nil : Date()
        } else if novels.isEmpty {
            phase = .failed(result.error?.localizedDescription ?? "Couldn't load the library.")
        } else {
            phase = .loaded
        }
        isShowingCache = result.fromCache || (result.error != nil && !novels.isEmpty)
    }
}
