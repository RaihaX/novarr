import Foundation

/// How a manga chapter is laid out, from the series' `reading_mode`
/// (the server's Novel::READING_MODES: rtl, ltr, vertical). Anything else
/// reads as left to right, which is also the server's own fallback.
/// Same rules as Android's `ReadingMode`.
enum MangaReadingMode: String, CaseIterable, Sendable {
    case leftToRight, rightToLeft, vertical

    init(raw: String?) {
        switch raw?.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() {
        case "rtl": self = .rightToLeft
        case "vertical": self = .vertical
        default: self = .leftToRight
        }
    }

    /// One page per screen (ltr, rtl), as opposed to a vertical strip.
    var isPaged: Bool { self != .vertical }
    var isRightToLeft: Bool { self == .rightToLeft }

    /// The reader's subtitle for the mode in use.
    var title: String {
        switch self {
        case .leftToRight: "Left to right"
        case .rightToLeft: "Right to left"
        case .vertical: "Vertical scroll"
        }
    }

    /// The series' own mode, under "Follow series" in the menu.
    var seriesTitle: String {
        switch self {
        case .leftToRight: "Pages, left to right"
        case .rightToLeft: "Pages, right to left"
        case .vertical: "Vertical scroll"
        }
    }
}

/// This device's choice for a manga series, over the series' own mode.
/// Never sent to the server (Android's `MangaModeOverride`).
enum MangaModeOverride: String, CaseIterable, Identifiable, Sendable {
    case follow, pages, scroll
    var id: String { rawValue }

    var title: String {
        switch self {
        case .follow: "Follow series"
        case .pages: "Pages"
        case .scroll: "Scroll"
        }
    }

    /// The layout to use for a series whose server mode is `series`.
    func resolve(_ series: MangaReadingMode) -> MangaReadingMode {
        switch self {
        case .follow: series
        case .scroll: .vertical
        // Paged keeps the series' direction; a vertical series pages left to right.
        case .pages: series.isPaged ? series : .leftToRight
        }
    }

    // MARK: Per series, per server

    static let defaultsKey = "manga.modeOverrides"

    /// The choice stored for a series on a server; `.follow` when none.
    static func stored(defaults: UserDefaults = .standard, server: URL?, novelId: Int) -> MangaModeOverride {
        let all = defaults.dictionary(forKey: defaultsKey) as? [String: String]
        return all?[key(server: server, novelId: novelId)].flatMap(MangaModeOverride.init(rawValue:)) ?? .follow
    }

    /// Remembers the choice; "Follow series" removes the entry.
    func store(defaults: UserDefaults = .standard, server: URL?, novelId: Int) {
        var all = (defaults.dictionary(forKey: Self.defaultsKey) as? [String: String]) ?? [:]
        let key = Self.key(server: server, novelId: novelId)
        all[key] = self == .follow ? nil : rawValue
        defaults.set(all, forKey: Self.defaultsKey)
    }

    private static func key(server: URL?, novelId: Int) -> String {
        "\(server.map(ServerURL.storageKey(for:)) ?? "")#\(novelId)"
    }
}

/// Page index ↔ progress for manga, the text reader's rules applied to pages.
enum MangaProgress {
    /// 0–99 for page `index` of `count` (100 is reserved for reaching the
    /// last page, which is the read mark).
    static func progress(page index: Int, count: Int) -> Int {
        guard count > 1 else { return 0 }
        let fraction = Double(index) / Double(count - 1)
        return min(99, max(0, Int((fraction * 100).rounded())))
    }

    /// The page to open for a saved 0–100 position; nil means the first page.
    static func page(progress: Int, count: Int) -> Int? {
        LatestPosition.paragraphIndex(progress: progress, paragraphCount: count)
    }
}
