import Foundation
import Observation

/// The Library: the medium shelves, the category tabs, the status and
/// origin filters, sort and search (each remembered), and the multi-select.
/// Mirrors android ui/library/LibraryScreen.kt (LibraryViewModel).
@MainActor
@Observable
final class LibraryModel {
    private(set) var novels: [NovelSummary] = []
    private(set) var phase: LoadPhase = .idle
    private(set) var isShowingCache = false
    private var loadedRevision = -1
    private var loadedAt: Date?

    /// Long-press / context-menu multi-select and its actions.
    let selection = SeriesSelection()

    var search = ""
    var filter: LibraryFilter.Filter {
        didSet { UserDefaults.standard.set(filter.rawValue, forKey: "library.filter") }
    }
    var sort: LibraryFilter.Sort {
        didSet { UserDefaults.standard.set(sort.rawValue, forKey: "library.sort") }
    }
    /// The chosen shelf, remembered; see `shelf(comics:)` for the one in effect.
    var shelfChoice: LibraryFilter.Shelf {
        didSet { UserDefaults.standard.set(shelfChoice.rawValue, forKey: "library.shelf") }
    }
    /// The chosen Origin option, remembered; ignored while the filter is hidden.
    var originChoice: SeriesOrigin.Filter {
        didSet { UserDefaults.standard.set(originChoice.rawValue, forKey: "library.origin") }
    }
    /// The chosen category tab (`Categories.Tab.key`: "all", "none" or a category's id), remembered.
    private(set) var tabKey: String

    init() {
        let defaults = UserDefaults.standard
        filter = defaults.string(forKey: "library.filter").flatMap(LibraryFilter.Filter.init) ?? .all
        sort = defaults.string(forKey: "library.sort").flatMap(LibraryFilter.Sort.init) ?? .lastRead
        shelfChoice = defaults.string(forKey: "library.shelf").flatMap(LibraryFilter.Shelf.init) ?? .novels
        originChoice = defaults.string(forKey: "library.origin").flatMap(SeriesOrigin.Filter.init) ?? .all
        tabKey = defaults.string(forKey: "library.list").flatMap { $0.isEmpty ? nil : $0 } ?? "all"
    }

    func select(_ tab: Categories.Tab) {
        tabKey = tab.key
        UserDefaults.standard.set(tab.key, forKey: "library.list")
    }

    /// The tab in effect: a remembered category deleted elsewhere is nil (the screen goes back to All).
    func tab(_ lists: [ReadingList]) -> Categories.Tab? { Categories.Tab.from(tabKey, lists: lists) }

    /// Books only when the library has a book; Comics when the server knows comics (or the library has one).
    func shelves(comics: Bool) -> [LibraryFilter.Shelf] { LibraryFilter.shelves(novels, comics: comics) }

    /// The shelf shown: a remembered Books shelf with no books left falls back to Novels (the choice is kept).
    func shelf(comics: Bool) -> LibraryFilter.Shelf { LibraryFilter.effectiveShelf(shelfChoice, novels, comics: comics) }

    func onShelf(comics: Bool) -> [NovelSummary] { LibraryFilter.shelf(novels, shelf(comics: comics)) }

    /// Offered only when some series has an origin (an older server sends none), and never on Books.
    func originOffered(comics: Bool) -> Bool { shelf(comics: comics) != .books && SeriesOrigin.offered(novels) }

    func origin(comics: Bool) -> SeriesOrigin.Filter {
        originOffered(comics: comics) ? SeriesOrigin.effective(originChoice, novels) : .all
    }

    func visible(list: ReadingList?, comics: Bool) -> [NovelSummary] {
        LibraryFilter.apply(onShelf(comics: comics), filter: filter, sort: sort, search: search, list: list,
                            origin: origin(comics: comics))
    }

    func count(_ filter: LibraryFilter.Filter, list: ReadingList?, comics: Bool) -> Int {
        LibraryFilter.count(onShelf(comics: comics), list: list, filter: filter, origin: origin(comics: comics))
    }

    func count(_ origin: SeriesOrigin.Filter, list: ReadingList?, comics: Bool) -> Int {
        LibraryFilter.count(onShelf(comics: comics), list: list, filter: filter, origin: origin)
    }

    func count(_ shelf: LibraryFilter.Shelf, list: ReadingList?) -> Int {
        ReadingLists.filter(LibraryFilter.shelf(novels, shelf), list: list).count
    }

    /// Mark as read / unread for `ids` (the selection, or one series): the
    /// covers change at once (and back on Undo or a refusal); the library
    /// is fetched again once the server has it.
    func markRead(_ read: Bool, ids: Set<Int>? = nil) {
        selection.markRead(read, series: ids) { [weak self] ids, value in
            guard let self else { return {} }
            let before = Dictionary(self.novels.filter { ids.contains($0.id) }.map { ($0.id, $0) }, uniquingKeysWith: { a, _ in a })
            self.novels = self.novels.map { ids.contains($0.id) ? Categories.marked($0, read: value) : $0 }
            // Undo: these series as they were (whatever else changed since stays).
            return { [weak self] in
                guard let self else { return }
                self.novels = self.novels.map { before[$0.id] ?? $0 }
            }
        }
    }

    /// Reloads when read state changed elsewhere or the data is a while old.
    func refreshIfStale(app: AppModel) async {
        let stale = loadedAt.map { Date().timeIntervalSince($0) > 60 } ?? true
        if stale || loadedRevision != app.readStateRevision {
            await load(app: app)
        }
    }

    func load(app: AppModel) async {
        selection.attach(app)
        if novels.isEmpty {
            phase = .loading
            // Show the saved copy straight away while the network request runs.
            if let cached = await app.cached([NovelSummary].self, .novels) {
                novels = cached
                isShowingCache = true
            }
        }
        loadedRevision = app.readStateRevision
        // Lists come with the library refresh (and are saved for offline with it).
        async let lists: Void = app.lists.refresh()
        let result = await app.load(.novels) { try await $0.novels() }
        if let value = result.value {
            novels = value
            phase = .loaded
            loadedAt = result.fromCache ? nil : Date()
            // A series gone from the library is no longer selected.
            selection.retain(value.map(\.id))
        } else if novels.isEmpty {
            phase = .failed(result.error?.localizedDescription ?? "Couldn't load the library.")
        } else {
            phase = .loaded
        }
        isShowingCache = result.fromCache || (result.error != nil && !novels.isEmpty)
        await lists
    }
}
