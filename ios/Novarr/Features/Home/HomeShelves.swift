import Foundation

/// Home, pure: which sections show and in what order (`sections`: 1.18's
/// summary of the categories, or 1.16's layout on a first run), the
/// Favourites and Want to read shelves from the local lists and the library,
/// 1.16's category tiles with up to four covers each, what is in progress
/// across reading and listening, and the search over everything this device
/// knows. Mirrors android domain/HomeShelves.kt.
enum HomeShelves {
    /// Covers on a folder tile.
    static let collage = 4
    /// Covers on Home's Favourites row and on each category's row.
    static let shelfMax = 12
    /// Covers on Home's "Want to read" row (the server sends as many).
    static let wantToReadShelf = 12

    /// A folder tile: one of the reader's own lists.
    struct Folder: Hashable, Identifiable {
        var id: Int
        var name: String
        var count: Int
        var covers: [URL]
    }

    /// One thing in progress: a series being read or an audiobook being
    /// listened to, newest first as the server sent them.
    enum InProgress: Hashable, Identifiable {
        case reading(ContinueItem)
        case listening(AudiobookSummary)

        var key: String {
            switch self {
            case .reading(let item): "r-\(item.novel.id)"
            case .listening(let book): "l-\(book.id)"
            }
        }

        var id: String { key }

        var title: String {
            switch self {
            case .reading(let item): item.novel.name
            case .listening(let book): book.title
            }
        }

        var coverUrl: URL? {
            switch self {
            case .reading(let item): item.novel.coverUrl
            case .listening(let book): book.coverUrl
            }
        }

        var fraction: Double {
            switch self {
            case .reading(let item): item.novel.readFraction
            case .listening(let book): book.fraction
            }
        }
    }

    /// A list's series as the library has them, in the list's order (newest
    /// first for the built-ins); unknown ids are skipped.
    static func shelf(_ list: ReadingList?, novels: [Int: NovelSummary]) -> [NovelSummary] {
        var seen = Set<Int>()
        return (list?.novelIds ?? []).compactMap { id in
            guard let novel = novels[id], seen.insert(id).inserted else { return nil }
            return novel
        }
    }

    /// The reader's own lists as folders, by name, each with its first covers.
    static func folders(_ lists: [ReadingList], novels: [Int: NovelSummary]) -> [Folder] {
        ReadingLists.sorted(ReadingLists.own(lists)).map { list in
            let members = shelf(list, novels: novels)
            return Folder(
                id: list.id,
                name: list.name,
                // The library's count when it knows the series, else the server's.
                count: novels.isEmpty ? list.count : members.count,
                covers: Array(members.compactMap { $0.coverUrl }.filter { !$0.absoluteString.isEmpty }.prefix(collage))
            )
        }
    }

    // MARK: Home's sections (1.18: a summary of the categories)

    /// One of Home's sections, top to bottom; the screen draws each (and
    /// skips one with nothing to show, like an empty New chapters).
    enum Section: Hashable, Identifiable {
        case search
        /// The "Your stats" card, right after Search (only with a card to show).
        case stats
        /// No category holds anything yet: a one-tap "Create your first category".
        case firstCategory
        /// The compact Continue strip (its halves follow Settings › Home).
        case continueStrip
        /// One visible, non-empty category: its name, count, "See all" and a row of covers.
        case category(list: ReadingList, novels: [NovelSummary], count: Int)
        /// The "Manage categories" link under the categories.
        case manageCategories
        case favourites
        case wantToRead
        /// "Reading & listening": everything in progress, as covers.
        case inProgress
        /// 1.16's folder tiles (only in the first-run layout).
        case folders
        case newChapters
        case library

        var key: String {
            switch self {
            case .search: "search"
            case .stats: "stats"
            case .firstCategory: "first-category"
            case .continueStrip: "quick"
            case .category(let list, _, _): "category-\(list.id)"
            case .manageCategories: "manage-categories"
            case .favourites: "favourites"
            case .wantToRead: "want-to-read"
            case .inProgress: "in-progress"
            case .folders: "folders"
            case .newChapters: "new-chapters"
            case .library: "library"
            }
        }

        var id: String { key }
    }

    /// Whether Home keeps 1.16's layout: the server has no categories (an
    /// older server), or none of the reader's categories holds a series yet.
    static func firstRun(_ lists: [ReadingList], listsOffered: Bool, listsLoaded: Bool) -> Bool {
        !listsOffered || (listsLoaded && lists.allSatisfy { $0.novelIds.isEmpty })
    }

    /// Home top to bottom. 1.18's default: Search, the stats card, one
    /// section per visible (not hidden on Home), non-empty category in the
    /// reader's order, "Manage categories", New chapters, the Library button.
    /// Settings › Home can bring back Continue, Favourites, Want to read and
    /// Reading & listening (a built-in shown as its own shelf isn't repeated
    /// as a category). `firstRun` keeps 1.16's layout.
    static func sections(
        lists: [ReadingList],
        novels: [Int: NovelSummary],
        layout: HomeLayout,
        listsOffered: Bool,
        listsLoaded: Bool,
        hasStats: Bool = false,
        wantToReadShelf: Bool = false
    ) -> [Section] {
        var out: [Section] = [.search]
        if hasStats { out.append(.stats) }
        let favourites = listsOffered ? ReadingLists.favourites(lists) : nil
        let want = listsOffered ? ReadingLists.wantToRead(lists) : nil
        let hasWant = want != nil || wantToReadShelf
        if firstRun(lists, listsOffered: listsOffered, listsLoaded: listsLoaded) {
            if listsOffered { out.append(.firstCategory) }
            out.append(.continueStrip)
            if favourites != nil { out.append(.favourites) }
            if hasWant { out.append(.wantToRead) }
            out.append(.inProgress)
            if listsOffered { out.append(.folders) }
        } else {
            if layout.showContinue { out.append(.continueStrip) }
            let showFavourites = layout.showFavourites && favourites != nil
            let showWant = layout.showWantToRead && hasWant
            if showFavourites { out.append(.favourites) }
            if showWant { out.append(.wantToRead) }
            if layout.showInProgress { out.append(.inProgress) }
            // Still loading the categories: nothing in their place yet (rather than a flash of the old layout).
            if listsLoaded {
                for list in ReadingLists.sorted(lists) where !list.hiddenOnHome && !list.novelIds.isEmpty {
                    if showFavourites && list.isFavourites { continue }
                    if showWant && list.isWantToRead { continue }
                    let members = shelf(list, novels: novels)
                    // The library's count when it knows the series, else the server's.
                    out.append(.category(list: list, novels: members, count: novels.isEmpty ? list.count : members.count))
                }
                out.append(.manageCategories)
            }
        }
        out.append(.newChapters)
        out.append(.library)
        return out
    }

    /// Reading, then listening, interleaved by position so neither crowds the other out.
    static func inProgress(reading: [ContinueItem], listening: [AudiobookSummary]) -> [InProgress] {
        let r = reading.map(InProgress.reading)
        let l = listening.filter { $0.started && $0.progress?.finished != true }.map(InProgress.listening)
        var out: [InProgress] = []
        for i in 0..<max(r.count, l.count) {
            if i < r.count { out.append(r[i]) }
            if i < l.count { out.append(l[i]) }
        }
        return out
    }

    /// The compact Continue strip: the latest of each that is switched on.
    static func quickResume(reading: [ContinueItem], listening: [AudiobookSummary],
                            showReading: Bool, showListening: Bool) -> [InProgress] {
        var out: [InProgress] = []
        if showReading, let first = reading.first { out.append(.reading(first)) }
        if showListening, let book = listening.first(where: { $0.progress?.finished != true }) { out.append(.listening(book)) }
        return out
    }

    /// Series of the library matching `query` (title or author; accents,
    /// case and punctuation ignored), best first.
    static func search<C: Collection>(_ query: String, novels: C, limit: Int = 40) -> [NovelSummary]
        where C.Element == NovelSummary {
        let q = normalize(query)
        guard !q.isEmpty else { return [] }
        let words = q.split(separator: " ").map(String.init)
        let scored: [(offset: Int, novel: NovelSummary, score: Int)] = novels.enumerated().compactMap { offset, novel in
            let title = normalize(novel.name)
            let author = normalize(novel.author ?? "")
            let both = "\(title) \(author)"
            let score: Int
            if title == q {
                score = 4
            } else if title.hasPrefix(q) {
                score = 3
            } else if " \(title) ".contains(" \(q)") {
                score = 2
            } else if words.allSatisfy({ both.contains($0) }) {
                score = 1
            } else {
                score = 0
            }
            return score == 0 ? nil : (offset, novel, score)
        }
        return scored.sorted { a, b in
            if a.score != b.score { return a.score > b.score }
            let order = a.novel.name.caseInsensitiveCompare(b.novel.name)
            if order != .orderedSame { return order == .orderedAscending }
            return a.offset < b.offset
        }
        .prefix(limit)
        .map(\.novel)
    }

    /// Lowercased, accents and apostrophes dropped, "&" as "and", anything
    /// but letters and digits a single space (android AutoSearch.normalize).
    static func normalize(_ text: String) -> String {
        let folded = text.replacingOccurrences(of: "&", with: " and ")
            .folding(options: [.diacriticInsensitive], locale: Locale(identifier: "en_US_POSIX"))
            .lowercased()
            .replacingOccurrences(of: "'", with: "")
            .replacingOccurrences(of: "’", with: "")
        return folded.replacingOccurrences(of: "[^\\p{L}\\p{N}]+", with: " ", options: .regularExpression)
            .trimmingCharacters(in: .whitespaces)
    }
}

/// Home's "New chapters" rules. The server groups every chapter created in
/// the last few days, downloaded or not, so a series whose new chapters are
/// all still queued comes with `count: 0` and no `first_chapter`; one whose
/// new chapters were all read comes with a count but no `first_chapter`
/// either. Neither has anything to read here. Mirrors android domain/NewChapters.kt.
enum NewChapters {
    /// The rows Home lists: a series with downloaded new chapters and an unread one to open.
    static func shown(_ items: [NewChaptersItem]) -> [NewChaptersItem] {
        items.filter { $0.count > 0 && $0.firstChapter != nil }
    }

    /// "3 NEW"; nil for zero (no badge).
    static func badge(_ count: Int) -> String? { count > 0 ? "\(count) NEW" : nil }
}

/// Settings › Home: which of 1.16's shelves Home brings back, and the halves
/// of the Continue strip. Kept in UserDefaults (the tab the app starts on is
/// `StartOn.defaultsKey`). Mirrors android store/AppPrefs.kt HomeLayout.
struct HomeLayout: Equatable, Sendable {
    var continueReading = true
    var continueListening = true
    var showContinue = false
    var showFavourites = false
    var showWantToRead = false
    var showInProgress = false

    /// The UserDefaults keys (also used with `@AppStorage`).
    enum Key {
        static let continueReading = "home.continueReading"
        static let continueListening = "home.continueListening"
        static let showContinue = "home.showContinue"
        static let showFavourites = "home.showFavourites"
        static let showWantToRead = "home.showWantToRead"
        static let showInProgress = "home.showInProgress"
    }

    init(continueReading: Bool = true, continueListening: Bool = true, showContinue: Bool = false,
         showFavourites: Bool = false, showWantToRead: Bool = false, showInProgress: Bool = false) {
        self.continueReading = continueReading
        self.continueListening = continueListening
        self.showContinue = showContinue
        self.showFavourites = showFavourites
        self.showWantToRead = showWantToRead
        self.showInProgress = showInProgress
    }

    /// As saved (unset keys take the defaults above).
    init(defaults: UserDefaults) {
        func value(_ key: String, _ fallback: Bool) -> Bool { defaults.object(forKey: key) as? Bool ?? fallback }
        self.init(
            continueReading: value(Key.continueReading, true),
            continueListening: value(Key.continueListening, true),
            showContinue: value(Key.showContinue, false),
            showFavourites: value(Key.showFavourites, false),
            showWantToRead: value(Key.showWantToRead, false),
            showInProgress: value(Key.showInProgress, false)
        )
    }

    func save(to defaults: UserDefaults) {
        defaults.set(continueReading, forKey: Key.continueReading)
        defaults.set(continueListening, forKey: Key.continueListening)
        defaults.set(showContinue, forKey: Key.showContinue)
        defaults.set(showFavourites, forKey: Key.showFavourites)
        defaults.set(showWantToRead, forKey: Key.showWantToRead)
        defaults.set(showInProgress, forKey: Key.showInProgress)
    }
}
