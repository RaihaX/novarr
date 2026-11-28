import Foundation
import Testing
@testable import Novarr

/// A series with the fields Home and the Library look at.
private func series(_ id: Int, _ name: String, medium: String = Medium.novel, author: String? = nil,
                    cover: String? = nil, read: Int = 0, total: Int = 100, new: Int = 0,
                    finished: Bool = false, origin: String? = nil) -> NovelSummary {
    var novel = makeNovel(id: id, name: name, author: author, read: read, total: total, new: new, finished: finished)
    novel.medium = medium
    novel.coverUrl = cover.flatMap(URL.init(string:))
    novel.originCountry = origin
    return novel
}

/// Ported from android HomeShelvesTest, CategoriesTest (Home) and HomeAndCoverTest (New chapters).
@Suite("Home: shelves, sections, in progress and search")
struct HomeShelvesTests {
    private let novels: [Int: NovelSummary] = Dictionary(uniqueKeysWithValues: [
        series(1, "Solo Leveling: Ragnarok", medium: Medium.manga, cover: "https://x/c1"),
        series(2, "Attack on Titan", medium: Medium.manga, author: "Hajime Isayama", cover: "https://x/c2"),
        series(3, "Lord of the Mysteries", author: "Cuttlefish", cover: "https://x/c3"),
        series(4, "Piranesi", medium: Medium.book, author: "Susanna Clarke"),
        series(5, "Les Misérables", medium: Medium.book, author: "Victor Hugo", cover: "https://x/c5"),
    ].map { ($0.id, $0) })

    private let want = ReadingList(id: 10, name: "Want to read", novelIds: [3], kind: ReadingList.kindWantToRead)
    private let favourites = ReadingList(id: 11, name: "Favourites", count: 2, novelIds: [2, 1], kind: ReadingList.kindFavourites)
    private let action = ReadingList(id: 12, name: "Manga – Action", count: 3, novelIds: [1, 2, 99])
    private let classics = ReadingList(id: 13, name: "classics", count: 2, novelIds: [4, 5])

    @Test func favouritesIsBuiltInAndNotAFolder() {
        let lists = [classics, favourites, action, want]
        #expect(favourites.isFavourites && favourites.isBuiltIn)
        #expect(ReadingLists.own(lists).map(\.id).sorted() == [12, 13])
        #expect(ReadingLists.sorted(lists).map(\.id) == [10, 11, 13, 12])
        #expect(ReadingLists.favourites(lists) == favourites)
        #expect(ReadingLists.isFavourite(lists, novelId: 2))
        #expect(!ReadingLists.isFavourite(lists, novelId: 3))
        #expect(!ReadingLists.isFavourite([], novelId: 2))
    }

    @Test func addingToFavouritesPutsItFirst() {
        let added = ReadingLists.apply([favourites], ReadingLists.Toggle(listId: 11, novelId: 3, add: true))
        #expect(added.first?.novelIds == [3, 2, 1])
        #expect(added.first?.count == 3)
    }

    @Test func shelvesAndFoldersFromTheLibrary() {
        #expect(HomeShelves.shelf(favourites, novels: novels).map(\.id) == [2, 1])
        #expect(HomeShelves.shelf(nil, novels: novels).isEmpty)
        let folders = HomeShelves.folders([favourites, want, action, classics], novels: novels)
        // Only the reader's own lists, by name; unknown series don't count; covers without a URL are skipped.
        #expect(folders.map(\.name) == ["classics", "Manga – Action"])
        #expect(folders.map(\.count) == [2, 2])
        #expect(folders[0].covers.map(\.absoluteString) == ["https://x/c5"])
        #expect(folders[1].covers.map(\.absoluteString) == ["https://x/c1", "https://x/c2"])
        // Before the library is known: the server's count.
        #expect(HomeShelves.folders([action], novels: [:]).first?.count == 3)
        // Never more than four covers, and a repeated id counts once.
        let big = ReadingList(id: 14, name: "Big", novelIds: [1, 2, 3, 5, 1])
        #expect(HomeShelves.folders([big], novels: novels).first?.covers.count == 4)
        #expect(HomeShelves.shelf(big, novels: novels).map(\.id) == [1, 2, 3, 5])
    }

    private func reading(_ id: Int) -> ContinueItem {
        ContinueItem(novel: novels[id]!, chapter: ChapterRef(id: 100 + id, label: "Chapter \(id)", chapter: nil, book: nil, downloaded: nil))
    }

    private func listening(_ id: Int, position: Int64 = 10, finished: Bool = false) -> AudiobookSummary {
        AudiobookSummary(id: id, title: "Book \(id)", durationMs: 100,
                         progress: AudiobookProgress(positionMs: position, finished: finished))
    }

    @Test func inProgressInterleavesAndSkipsFinished() {
        let items = HomeShelves.inProgress(reading: [reading(1), reading(2), reading(3)],
                                           listening: [listening(7), listening(8, finished: true), listening(9)])
        #expect(items.map(\.key) == ["r-1", "l-7", "r-2", "l-9", "r-3"])
        // Not started yet: not in progress.
        #expect(HomeShelves.inProgress(reading: [], listening: [listening(4, position: 0)]).isEmpty)
    }

    @Test func quickResumeTakesTheLatestOfEachThatIsOn() {
        let r = [reading(1), reading(2)]
        let l = [listening(7, finished: true), listening(9)]
        #expect(HomeShelves.quickResume(reading: r, listening: l, showReading: true, showListening: true).map(\.key) == ["r-1", "l-9"])
        #expect(HomeShelves.quickResume(reading: r, listening: l, showReading: false, showListening: true).map(\.key) == ["l-9"])
        #expect(HomeShelves.quickResume(reading: r, listening: l, showReading: false, showListening: false).isEmpty)
        #expect(HomeShelves.quickResume(reading: [], listening: [], showReading: true, showListening: true).isEmpty)
    }

    @Test func searchTitlesAndAuthors() {
        let all = Array(novels.values)
        #expect(HomeShelves.search("attack", novels: all).map(\.id) == [2])
        #expect(HomeShelves.search("isayama", novels: all).map(\.id) == [2])
        #expect(HomeShelves.search("miserables", novels: all).map(\.id) == [5])
        #expect(HomeShelves.search("solo leveling", novels: all).map(\.id) == [1])
        #expect(HomeShelves.search("  ", novels: all).isEmpty)
        // A title starting with the query comes before one merely containing it.
        let two = [series(20, "The Lord"), series(21, "Lord of Light")]
        #expect(HomeShelves.search("lord", novels: two).map(\.id) == [21, 20])
        #expect(HomeShelves.normalize("Tom & Jerry’s  Café!") == "tom and jerrys cafe")
    }

    @Test func newChaptersListsOnlySeriesWithSomethingToRead() {
        let items = [
            NewChaptersItem(novel: series(1, "Kagurabachi"), count: 0, firstChapter: nil),
            NewChaptersItem(novel: series(2, "B"), count: 3, firstChapter: nil),
            NewChaptersItem(novel: series(3, "C"), count: 2, firstChapter: ChapterRef(id: 30, label: "Chapter 12", chapter: nil, book: nil, downloaded: nil)),
        ]
        #expect(NewChapters.shown(items).map(\.novel.id) == [3])
        #expect(NewChapters.shown([]).isEmpty)
        #expect(NewChapters.badge(0) == nil)
        #expect(NewChapters.badge(-1) == nil)
        #expect(NewChapters.badge(1) == "1 NEW")
        #expect(NewChapters.badge(12) == "12 NEW")
    }

    @Test func layoutIsSavedWithDefaults() throws {
        let defaults = try #require(UserDefaults(suiteName: "HomeShelvesTests-\(UUID().uuidString)"))
        #expect(HomeLayout(defaults: defaults) == HomeLayout())
        let changed = HomeLayout(continueListening: false, showContinue: true, showInProgress: true)
        changed.save(to: defaults)
        #expect(HomeLayout(defaults: defaults) == changed)
    }
}

/// Ported from android CategoriesTest (Home's sections).
@Suite("Home's sections: categories first")
struct HomeSectionsTests {
    private let want = ReadingList(id: 10, name: "Want to read", count: 1, novelIds: [3], kind: ReadingList.kindWantToRead, position: 0)
    private let favourites = ReadingList(id: 11, name: "Favourites", count: 2, novelIds: [2, 1], kind: ReadingList.kindFavourites, position: 1)
    private let action = ReadingList(id: 12, name: "Action", count: 2, novelIds: [1, 2], position: 3)
    private let comfort = ReadingList(id: 13, name: "Comfort reads", count: 1, novelIds: [4], position: 2)
    private let empty = ReadingList(id: 14, name: "Someday", position: 4)
    private var lists: [ReadingList] { [want, favourites, action, comfort, empty] }

    private var byId: [Int: NovelSummary] {
        Dictionary(uniqueKeysWithValues: [
            series(1, "One", medium: Medium.manga, read: 4, total: 10),
            series(2, "Two", total: 5),
            series(3, "Three", medium: Medium.book, total: 1),
            series(4, "Four", read: 3, total: 3, finished: true),
            series(5, "Five", total: 7, new: 2),
        ].map { ($0.id, $0) })
    }

    private func sections(_ lists: [ReadingList]? = nil, layout: HomeLayout = HomeLayout(), offered: Bool = true,
                          loaded: Bool = true, stats: Bool = false) -> [HomeShelves.Section] {
        HomeShelves.sections(lists: lists ?? self.lists, novels: byId, layout: layout, listsOffered: offered,
                             listsLoaded: loaded, hasStats: stats)
    }

    @Test func homeIsTheVisibleNonEmptyCategoriesInOrder() {
        var hidden = lists
        hidden[1].hiddenOnHome = true
        let shown = sections(hidden)
        #expect(shown.map(\.key) == ["search", "category-10", "category-13", "category-12", "manage-categories", "new-chapters", "library"])
        // Want to read's row: its series from the library, and the count.
        if case .category(_, let novels, let count)? = shown.first(where: { $0.key == "category-10" }) {
            #expect(novels.map(\.id) == [3])
            #expect(count == 1)
        } else {
            Issue.record("Want to read's row is missing")
        }
        // Nothing of 1.16's by default.
        #expect(!shown.contains { [.continueStrip, .favourites, .wantToRead, .inProgress, .folders].contains($0) })
    }

    @Test func theStatsSlotOnlyWithACard() {
        #expect(!sections().contains(.stats))
        #expect(Array(sections(stats: true).prefix(2)) == [.search, .stats])
        // In the first-run layout too.
        #expect(sections([empty], stats: true)[1] == .stats)
    }

    @Test func settingsBringBackTheOldShelves() {
        let layout = HomeLayout(showContinue: true, showFavourites: true, showWantToRead: true, showInProgress: true)
        #expect(sections(layout: layout).map(\.key) == [
            "search", "quick", "favourites", "want-to-read", "in-progress", "category-13", "category-12",
            "manage-categories", "new-chapters", "library",
        ])
        // Favourites back as its own shelf isn't repeated as a category; Want to read still is.
        let onlyFavourites = sections(layout: HomeLayout(showFavourites: true)).map(\.key)
        #expect(onlyFavourites.contains("favourites"))
        #expect(!onlyFavourites.contains("category-11"))
        #expect(onlyFavourites.contains("category-10"))
    }

    @Test func firstRunKeepsThe116Layout() {
        var emptyWant = want
        emptyWant.novelIds = []
        emptyWant.count = 0
        var emptyFavourites = favourites
        emptyFavourites.novelIds = []
        emptyFavourites.count = 0
        let nothing = [emptyWant, emptyFavourites, empty]
        #expect(HomeShelves.firstRun(nothing, listsOffered: true, listsLoaded: true))
        #expect(sections(nothing).map(\.key) == [
            "search", "first-category", "quick", "favourites", "want-to-read", "in-progress", "folders", "new-chapters", "library",
        ])
        // One series anywhere (a built-in counts) ends it.
        #expect(!HomeShelves.firstRun(nothing + [action], listsOffered: true, listsLoaded: true))
        // A server without categories: 1.16's layout without the card or folders.
        #expect(sections([], offered: false).map(\.key) == ["search", "quick", "in-progress", "new-chapters", "library"])
        // Still loading the categories: neither layout yet.
        #expect(sections([], loaded: false).map(\.key) == ["search", "new-chapters", "library"])
        // An older server's Home feed with Want to read items but no list: the shelf still shows.
        let feedOnly = HomeShelves.sections(lists: [], novels: byId, layout: HomeLayout(), listsOffered: false,
                                            listsLoaded: false, wantToReadShelf: true)
        #expect(feedOnly.map(\.key).contains("want-to-read"))
    }
}

/// Ported from android LibraryAndCheckTest (shelves) and OriginTest.
@Suite("Library: medium shelves, origin and category filters")
struct LibraryShelvesTests {
    private let novels = [
        series(1, "Beta", author: "Zoë", read: 3, total: 10),
        series(2, "alpha", new: 2),
        series(3, "Gamma", read: 5, total: 5, finished: true),
        series(4, "Manga One", medium: Medium.manga, read: 1),
    ]

    @Test func shelvesSeparateNovelsMangaComicsAndBooks() {
        let library = novels + [
            series(5, "A Book", medium: Medium.book),
            series(6, "Blank medium", medium: ""),
            series(7, "Another Book", medium: "Book"),
            series(8, "A Comic", medium: Medium.comic),
        ]
        #expect(LibraryFilter.shelf(library, .novels).map(\.id) == [1, 2, 3, 6])
        #expect(LibraryFilter.shelf(library, .manga).map(\.id) == [4])
        #expect(LibraryFilter.shelf(library, .books).map(\.id) == [5, 7])
        #expect(LibraryFilter.shelf(library, .comics).map(\.id) == [8])
    }

    @Test func booksAndComicsOnlyWhenThereAreSome() {
        #expect(LibraryFilter.shelves(novels) == [.novels, .manga])
        #expect(LibraryFilter.shelves([]) == [.novels, .manga])
        #expect(LibraryFilter.shelves(novels + [series(5, "A Book", medium: Medium.book)]) == [.novels, .manga, .books])
        // Comics: the server knows them, or the library has one.
        #expect(LibraryFilter.shelves(novels, comics: true) == [.novels, .manga, .comics])
        #expect(LibraryFilter.shelves(novels + [series(8, "C", medium: Medium.comic)]) == [.novels, .manga, .comics])
    }

    @Test func rememberedShelfFallsBackToNovels() {
        #expect(LibraryFilter.effectiveShelf(.books, novels) == .novels)
        #expect(LibraryFilter.effectiveShelf(.manga, novels) == .manga)
        #expect(LibraryFilter.effectiveShelf(.books, novels + [series(5, "B", medium: Medium.book)]) == .books)
        #expect(LibraryFilter.effectiveShelf(.comics, novels) == .novels)
        #expect(LibraryFilter.effectiveShelf(.comics, novels, comics: true) == .comics)
    }

    @Test func searchIgnoresCaseAndAccentsAndMatchesAuthors() {
        #expect(LibraryFilter.apply(novels, filter: .all, sort: .name, search: "zoe").map(\.id) == [1])
        #expect(LibraryFilter.apply(novels, filter: .all, sort: .name, search: "ALPH").map(\.id) == [2])
    }

    @Test func originLabels() {
        #expect(SeriesOrigin.label("JP", manga: false) == "Japanese")
        #expect(SeriesOrigin.label("KR", manga: true) == "Korean manhwa")
        #expect(SeriesOrigin.label("CN", manga: true) == "Chinese manhua")
        #expect(SeriesOrigin.label("EN", manga: true) == "English original")
        #expect(SeriesOrigin.label(series(1, "a", medium: "MANGA", origin: "KR")) == "Korean manhwa")
        #expect(SeriesOrigin.label(series(1, "a", origin: "KR")) == "Korean")
        // A book has no origin, so the filter isn't offered for books alone.
        let book = series(1, "b", medium: Medium.book, origin: "JP")
        #expect(SeriesOrigin.label(book) == nil)
        #expect(!SeriesOrigin.offered([book]))
        #expect(SeriesOrigin.country(" kr ") == .kr)
        #expect(SeriesOrigin.country("Cn") == .cn)
        for raw in [nil, "", "   ", "FR", "ja", "JPN"] { #expect(SeriesOrigin.country(raw) == nil) }
    }

    private let mixed = [
        series(1, "Lanterns", read: 3, total: 9, origin: "CN"),
        series(2, "Glass Archive", origin: "KR"),
        series(3, "Moonlit Ferryman", read: 2, total: 6, origin: "JP"),
        series(4, "Salt Road", origin: "EN"),
        series(5, "Nine Heavens", read: 1, total: 20),
        series(6, "Odd Code", origin: "zz"),
        series(7, "Korean Lanterns", author: "Lee", origin: "kr"),
    ]

    private func ids(_ origin: SeriesOrigin.Filter, _ filter: LibraryFilter.Filter = .all, search: String = "",
                     list: ReadingList? = nil) -> [Int] {
        LibraryFilter.apply(mixed, filter: filter, sort: .name, search: search, list: list, origin: origin).map(\.id).sorted()
    }

    @Test func originFilterAlone() {
        #expect(ids(.all) == [1, 2, 3, 4, 5, 6, 7])
        #expect(ids(.japanese) == [3])
        #expect(ids(.korean) == [2, 7])
        #expect(ids(.chinese) == [1])
        #expect(ids(.english) == [4])
        // Null and unrecognised codes are both "Unknown": each series is in exactly one option.
        #expect(ids(.unknown) == [5, 6])
        #expect(SeriesOrigin.Filter.allCases.filter { $0 != .all }.map { ids($0).count }.reduce(0, +) == mixed.count)
    }

    @Test func originCombinesWithStatusSearchAndList() {
        #expect(ids(.all, .reading) == [1, 3, 5])
        #expect(ids(.unknown, .reading) == [5])
        #expect(ids(.korean, .reading).isEmpty)
        #expect(ids(.korean, search: "lanterns") == [7])
        #expect(ids(.korean, search: "lee") == [7])
        let list = ReadingList(id: 10, name: "Favourites", novelIds: [1, 2, 5, 7])
        #expect(ids(.all, list: list) == [1, 2, 5, 7])
        #expect(ids(.korean, list: list) == [2, 7])
        #expect(ids(.japanese, list: list).isEmpty)
        #expect(LibraryFilter.count(mixed, list: nil, filter: .all, origin: .korean) == 2)
        #expect(LibraryFilter.count(mixed, list: list, filter: .reading, origin: .unknown) == 1)
        #expect(LibraryFilter.count(mixed, list: list, filter: .all) == 4)
        #expect(LibraryFilter.count(mixed, list: list, filter: .reading) == 2)
    }

    @Test func oldServerHidesTheOriginFilter() {
        let old = [series(1, "A"), series(2, "B", origin: ""), series(3, "C", origin: "??")]
        #expect(!SeriesOrigin.offered(old))
        #expect(!SeriesOrigin.offered([]))
        #expect(SeriesOrigin.effective(.korean, old) == .all)
        #expect(SeriesOrigin.effective(.korean, mixed) == .korean)
    }

    @Test func uncategorisedTabFiltersTheGrid() {
        let lists = [ReadingList(id: 12, name: "Action", novelIds: [1, 2])]
        let none = Categories.asList(.uncategorised, lists: lists, novels: novels)
        #expect(LibraryFilter.apply(novels, filter: .all, sort: .name, search: "", list: none).map(\.id) == [3, 4])
    }
}
