import Foundation

/// Pure filter/sort/search logic for the library grid (unit tested).
enum LibraryFilter {
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

    static func apply(_ novels: [NovelSummary], filter: Filter, sort: Sort, search: String) -> [NovelSummary] {
        let query = search.trimmingCharacters(in: .whitespacesAndNewlines)
        let filtered = novels.filter { novel in
            guard filter.matches(novel) else { return false }
            guard !query.isEmpty else { return true }
            return matches(novel.name, query) || novel.author.map { matches($0, query) } == true
        }
        return sorted(filtered, by: sort)
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

    private static func matches(_ text: String, _ query: String) -> Bool {
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
