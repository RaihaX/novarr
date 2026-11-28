import Foundation

/// Pure filter/sort/search logic for the library grid (unit tested).
/// Mirrors android domain/LibraryFilter.kt.
enum LibraryFilter {
    /// The library's shelves (medium tabs). The raw value is what is remembered.
    enum Shelf: String, CaseIterable, Identifiable, Sendable {
        case novels, manga, comics, books
        var id: String { rawValue }
        var title: String {
            switch self {
            case .novels: "Web Novels"
            case .manga: "Manga"
            case .comics: "Comics"
            case .books: "Books"
            }
        }

        /// The shelf's series in running text ("No books yet").
        var noun: String {
            switch self {
            case .novels: "web novels"
            case .manga: "manga"
            case .comics: "comics"
            case .books: "books"
            }
        }

        /// Novels takes every series that is neither manga, a comic nor a
        /// book (including a blank or unknown medium).
        func matches(_ novel: NovelSummary) -> Bool {
            switch self {
            case .novels: !novel.isManga && !novel.isComic && !novel.isBook
            case .manga: novel.isManga
            case .comics: novel.isComic
            case .books: novel.isBook
            }
        }
    }

    static func shelf(_ novels: [NovelSummary], _ shelf: Shelf) -> [NovelSummary] {
        novels.filter(shelf.matches)
    }

    /// The shelves offered: Books only when the library has a book (so a
    /// library without books looks as before); Comics when the server knows
    /// comics (meta `comics`) or the library has one (never hiding series).
    static func shelves(_ novels: [NovelSummary], comics: Bool = false) -> [Shelf] {
        Shelf.allCases.filter { shelf in
            switch shelf {
            case .books: novels.contains(where: \.isBook)
            case .comics: comics || novels.contains(where: \.isComic)
            default: true
            }
        }
    }

    /// The shelf in effect: a remembered shelf that isn't offered (Books with no books) falls back to Novels.
    static func effectiveShelf(_ shelf: Shelf, _ novels: [NovelSummary], comics: Bool = false) -> Shelf {
        shelves(novels, comics: comics).contains(shelf) ? shelf : .novels
    }

    enum Filter: String, CaseIterable, Identifiable, Sendable {
        case all, reading, newChapters, notStarted, finished
        var id: String { rawValue }
        var title: String {
            switch self {
            case .all: "All"
            case .reading: "Reading"
            case .newChapters: "New chapters"
            case .notStarted: "Not started"
            case .finished: "Finished"
            }
        }

        func matches(_ novel: NovelSummary) -> Bool {
            switch self {
            case .all: true
            case .reading: novel.chaptersRead > 0 && !novel.finished
            case .newChapters: novel.chaptersNew > 0
            case .notStarted: novel.chaptersRead == 0
            case .finished: novel.finished
            }
        }
    }

    enum Sort: String, CaseIterable, Identifiable, Sendable {
        case lastRead, name, recentlyUpdated
        var id: String { rawValue }
        var title: String {
            switch self {
            case .lastRead: "Last read"
            case .name: "Name"
            case .recentlyUpdated: "Recently updated"
            }
        }
    }

    /// The grid: `novels` in `list` (when one is chosen: a category tab),
    /// matching the status `filter`, the `origin` filter and `search`, sorted.
    static func apply(_ novels: [NovelSummary], filter: Filter, sort: Sort, search: String,
                      list: ReadingList? = nil, origin: SeriesOrigin.Filter = .all) -> [NovelSummary] {
        let query = search.trimmingCharacters(in: .whitespacesAndNewlines)
        let filtered = ReadingLists.filter(novels, list: list).filter { novel in
            guard filter.matches(novel), origin.matches(novel) else { return false }
            guard !query.isEmpty else { return true }
            return matches(novel.name, query) || novel.author.map { matches($0, query) } == true
        }
        return sorted(filtered, by: sort)
    }

    /// A chip's or Origin option's count: the series in `list` matching both
    /// the status `filter` and the `origin` filter (search is not counted).
    static func count(_ novels: [NovelSummary], list: ReadingList?, filter: Filter,
                      origin: SeriesOrigin.Filter = .all) -> Int {
        ReadingLists.filter(novels, list: list).lazy.filter { filter.matches($0) && origin.matches($0) }.count
    }

    static func sorted(_ novels: [NovelSummary], by sort: Sort) -> [NovelSummary] {
        switch sort {
        case .name:
            return novels.sorted(by: nameOrder)
        case .lastRead:
            return novels.sorted { descendingDate($0.lastReadAt, $1.lastReadAt, $0, $1) }
        case .recentlyUpdated:
            return novels.sorted { descendingDate($0.updatedAt, $1.updatedAt, $0, $1) }
        }
    }

    /// Case- and accent-insensitive "contains".
    static func matches(_ text: String, _ query: String) -> Bool {
        text.range(of: query, options: [.caseInsensitive, .diacriticInsensitive]) != nil
    }

    private static func nameOrder(_ a: NovelSummary, _ b: NovelSummary) -> Bool {
        let order = a.name.localizedStandardCompare(b.name)
        return order == .orderedSame ? a.id < b.id : order == .orderedAscending
    }

    /// Newest first; novels without a date go last, then by name.
    private static func descendingDate(_ a: Date?, _ b: Date?, _ lhs: NovelSummary, _ rhs: NovelSummary) -> Bool {
        switch (a, b) {
        case let (a?, b?) where a != b: a > b
        case (_?, nil): true
        case (nil, _?): false
        default: nameOrder(lhs, rhs)
        }
    }
}

/// A series' country of origin: the server's `origin_country` ("JP" | "KR"
/// | "CN" | "EN" | null). Anything not recognised (blank, an unknown code,
/// a newer server's addition) counts as unknown; a book has none.
/// Mirrors android domain/Origin.kt.
enum SeriesOrigin {
    enum Country: String, CaseIterable, Sendable {
        case jp = "JP", kr = "KR", cn = "CN", en = "EN"

        /// The badge's spoken label.
        func label(manga: Bool) -> String {
            switch self {
            case .jp: manga ? "Japanese manga" : "Japanese"
            case .kr: manga ? "Korean manhwa" : "Korean"
            case .cn: manga ? "Chinese manhua" : "Chinese"
            case .en: "English original"
            }
        }

        /// The badge's short text on a cover.
        var short: String { rawValue }
    }

    /// The country of a raw `origin_country`; nil when absent or not recognised.
    static func country(_ raw: String?) -> Country? {
        guard let code = raw?.trimmingCharacters(in: .whitespaces).uppercased(), !code.isEmpty else { return nil }
        return Country(rawValue: code)
    }

    /// Nil for a book (no origin, even if a value were sent).
    static func country(_ novel: NovelSummary) -> Country? { novel.isBook ? nil : country(novel.originCountry) }

    static func label(_ raw: String?, manga: Bool) -> String? { country(raw)?.label(manga: manga) }

    static func label(_ novel: NovelSummary) -> String? { country(novel)?.label(manga: novel.isManga) }

    /// The Library's Origin filter (raw value remembered).
    enum Filter: String, CaseIterable, Identifiable, Sendable {
        case all, japanese, korean, chinese, english, unknown
        var id: String { rawValue }

        var title: String {
            switch self {
            case .all: "All"
            case .japanese: "Japanese"
            case .korean: "Korean"
            case .chinese: "Chinese"
            case .english: "English original"
            case .unknown: "Unknown"
            }
        }

        var country: Country? {
            switch self {
            case .japanese: .jp
            case .korean: .kr
            case .chinese: .cn
            case .english: .en
            case .all, .unknown: nil
            }
        }

        func matches(_ novel: NovelSummary) -> Bool {
            switch self {
            case .all: true
            case .unknown: SeriesOrigin.country(novel) == nil
            default: SeriesOrigin.country(novel) == country
            }
        }
    }

    /// Offered only when some series in the loaded library has an origin
    /// (an older server sends none, and every series would be "Unknown").
    static func offered(_ novels: [NovelSummary]) -> Bool { novels.contains { country($0) != nil } }

    /// The filter in effect: a remembered choice is ignored while the filter is hidden.
    static func effective(_ filter: Filter, _ novels: [NovelSummary]) -> Filter { offered(novels) ? filter : .all }
}
