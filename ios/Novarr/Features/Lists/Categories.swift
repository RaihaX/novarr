import Foundation

/// Categories (Mihon style; the server's reading lists) as pure logic: the
/// long-press multi-select, the tri-state "Set categories" sheet and the one
/// memberships call it makes, the Library's category tabs, and how a Browse
/// card shows that it was already put somewhere. Mirrors android domain/Categories.kt.
enum Categories {
    /// How long "Mark as read/unread" waits for Undo before it is sent.
    static let undoSeconds: TimeInterval = 4.5

    /// The server takes at most this many series in one bulk call.
    static let bulkMax = 500

    // MARK: Selection

    /// A long-press multi-select over items keyed by `Key`. Active while
    /// anything is selected: taking the last item out ends it, as in Mihon.
    struct Selection<Key: Hashable>: Equatable {
        var keys: Set<Key> = []

        var active: Bool { !keys.isEmpty }
        var count: Int { keys.count }
        func contains(_ key: Key) -> Bool { keys.contains(key) }

        /// A long-press: starts selecting with `key`, or (already selecting) toggles it.
        func longPress(_ key: Key) -> Selection { active ? toggle(key) : Selection(keys: [key]) }

        /// A tap: while selecting it toggles `key` (nil: not selecting, so the caller opens the item as usual).
        func tap(_ key: Key) -> Selection? { active ? toggle(key) : nil }

        func toggle(_ key: Key) -> Selection {
            var keys = keys
            if keys.contains(key) { keys.remove(key) } else { keys.insert(key) }
            return Selection(keys: keys)
        }

        /// Everything shown is selected (not selecting: unchanged).
        func selectAll<C: Collection>(_ shown: C) -> Selection where C.Element == Key {
            active ? Selection(keys: keys.union(shown)) : self
        }

        /// Whether "Select all" would add nothing.
        func allSelected<C: Collection>(_ shown: C) -> Bool where C.Element == Key { keys.isSuperset(of: shown) }

        /// Keeps only what is still there (after a refresh, a filter or a tab change).
        func retain<C: Collection>(_ available: C) -> Selection where C.Element == Key {
            let set = Set(available)
            return keys.isSubset(of: set) ? self : Selection(keys: keys.intersection(set))
        }

        func exit() -> Selection { Selection() }
    }

    // MARK: The tri-state sheet

    /// A category's box in "Set categories": every selected series in it, none, or some.
    enum Tri: Equatable, Sendable { case on, off, mixed }

    /// Each category's box for `novelIds`: on when all are in it, off when none, mixed otherwise.
    static func initial<C: Collection>(_ lists: [ReadingList], novelIds: C) -> [Int: Tri] where C.Element == Int {
        var boxes: [Int: Tri] = [:]
        for list in lists {
            let inside = novelIds.filter { list.contains($0) }.count
            boxes[list.id] = novelIds.isEmpty || inside == 0 ? .off : (inside == novelIds.count ? .on : .mixed)
        }
        return boxes
    }

    /// A tap on a box: mixed becomes ticked (Mihon's order), ticked becomes
    /// clear, and clear ticked; a box that started mixed can go back to mixed after clear.
    static func next(_ current: Tri, started: Tri) -> Tri {
        switch current {
        case .mixed: .on
        case .on: .off
        case .off: started == .mixed ? .mixed : .on
        }
    }

    /// What Apply sends: the categories whose box changed to ticked (add) or to clear (remove).
    struct Diff: Equatable {
        var add: [Int]
        var remove: [Int]
        var isEmpty: Bool { add.isEmpty && remove.isEmpty }
    }

    /// Only the changed boxes; a box still (or again) mixed changes nothing.
    /// Ids not in `initial` (a new category) count as clear before.
    static func diff(initial: [Int: Tri], current: [Int: Tri]) -> Diff {
        var add: [Int] = []
        var remove: [Int] = []
        for (id, state) in current {
            let before = initial[id] ?? .off
            guard state != before else { continue }
            switch state {
            case .on: add.append(id)
            case .off: remove.append(id)
            case .mixed: break
            }
        }
        return Diff(add: add.sorted(), remove: remove.sorted())
    }

    /// The lists as they will be after the memberships call, so the screens
    /// show it at once. Built-ins are newest first: additions go to the front.
    static func applyMemberships(_ lists: [ReadingList], novelIds: [Int], add: [Int], remove: [Int]) -> [ReadingList] {
        let addSet = Set(add)
        let removeSet = Set(remove)
        return lists.map { list in
            var list = list
            if addSet.contains(list.id) {
                var seen = Set<Int>()
                let missing = novelIds.filter { !list.contains($0) && seen.insert($0).inserted }
                guard !missing.isEmpty else { return list }
                list.novelIds = list.isBuiltIn ? missing + list.novelIds : list.novelIds + missing
                list.count += missing.count
            } else if removeSet.contains(list.id) {
                let gone = Set(novelIds.filter { list.contains($0) })
                guard !gone.isEmpty else { return list }
                list.novelIds.removeAll { gone.contains($0) }
                list.count = max(list.count - gone.count, 0)
            }
            return list
        }
    }

    // MARK: Mark as read / unread

    /// How a series looks once marked read or unread as a whole, until the library is fetched again.
    static func marked(_ novel: NovelSummary, read: Bool) -> NovelSummary {
        var novel = novel
        if read {
            novel.chaptersRead = novel.chaptersTotal
            novel.chaptersNew = 0
            novel.readPercent = 100
            novel.finished = novel.chaptersTotal > 0
        } else {
            novel.chaptersRead = 0
            novel.readPercent = 0
            novel.finished = false
        }
        return novel
    }

    /// The toast line: "Marked 3 series as read".
    static func markedLabel(count: Int, read: Bool) -> String {
        "Marked \(count == 1 ? "1 series" : "\(count) series") as \(read ? "read" : "unread")"
    }

    /// Chapters still unread (the cover's badge).
    static func unread(_ novel: NovelSummary) -> Int { max(novel.chaptersTotal - novel.chaptersRead, 0) }

    // MARK: Library tabs

    /// One of the Library's category tabs: All, a category, or the series in none.
    enum Tab: Hashable, Identifiable {
        case all
        case uncategorised
        case category(ReadingList)

        /// What is remembered for the tab ("all", "none" or the list id).
        var key: String {
            switch self {
            case .all: "all"
            case .uncategorised: "none"
            case .category(let list): String(list.id)
            }
        }

        var id: String { key }

        /// A remembered key back to a tab: "" and unknown keys are All; a deleted category is nil.
        static func from(_ key: String?, lists: [ReadingList]) -> Tab? {
            switch key {
            case nil, "", "all": return .all
            case "none": return .uncategorised
            default:
                guard let id = key.flatMap(Int.init), let list = lists.first(where: { $0.id == id }) else { return nil }
                return .category(list)
            }
        }
    }

    /// The tab bar: All, each category in the reader's order (hidden-on-Home
    /// ones too: that only concerns Home), then Uncategorised.
    static func tabs(_ lists: [ReadingList]) -> [Tab] {
        [.all] + ReadingLists.sorted(lists).map { .category($0) } + [.uncategorised]
    }

    /// The id used for the synthetic "Uncategorised" list (never a server id).
    static let uncategorisedId = -1
    static let uncategorisedName = "Uncategorised"

    /// The tab as the list the Library's filter takes: nil for All, the
    /// category, or a synthetic list of the series in no category.
    static func asList(_ tab: Tab, lists: [ReadingList], novels: [NovelSummary]) -> ReadingList? {
        switch tab {
        case .all: return nil
        case .category(let list): return list
        case .uncategorised:
            let inSome = Set(lists.flatMap(\.novelIds))
            let ids = novels.map(\.id).filter { !inSome.contains($0) }
            return ReadingList(id: uncategorisedId, name: uncategorisedName, count: ids.count, novelIds: ids)
        }
    }

    static func title(_ tab: Tab) -> String {
        switch tab {
        case .all: "All"
        case .uncategorised: uncategorisedName
        case .category(let list): list.name
        }
    }

    // MARK: Browse / Discover

    /// A Browse card already put somewhere: shown greyed, with a short badge.
    struct Mark: Equatable { var badge: String }

    /// "In Action" when the library has it in a category, "Requested · Action"
    /// when the reader's open request carries one, "Requested" for the
    /// reader's own request without; nil for a card nothing was done with yet.
    static func mark(_ result: SearchResult, lists: [ReadingList]) -> Mark? {
        func names(_ ids: [Int]) -> String? {
            let known = ids.compactMap { id in lists.first { $0.id == id } }
            guard !known.isEmpty else { return nil }
            let sorted = ReadingLists.sorted(known)
            return sorted[0].name + (sorted.count > 1 ? " +\(sorted.count - 1)" : "")
        }
        if let inside = names(result.inCategories) { return Mark(badge: "In \(inside)") }
        if let requested = names(result.requestedCategories) { return Mark(badge: "Requested · \(requested)") }
        if result.requested?.byMe == true, !result.inLibrary, result.novelId == nil { return Mark(badge: "Requested") }
        return nil
    }

    /// The Browse sheet's boxes to start with: where the series is, or where the reader asked for it to go.
    static func preTicked(_ result: SearchResult) -> Set<Int> {
        Set(result.inCategories + result.requestedCategories)
    }

    /// What confirming the Browse sheet does.
    enum BrowseAction: Equatable {
        /// In the library: one memberships call for this series.
        case memberships(novelId: Int, add: [Int], remove: [Int])
        /// Not in the library: request it (or join, or update the reader's own request) with these categories.
        case request(categories: [Int])
        /// Nothing changed.
        case nothing
    }

    static func browseAction(_ result: SearchResult, chosen: Set<Int>) -> BrowseAction {
        if result.inLibrary || result.novelId != nil {
            guard let novelId = result.novelId else { return .nothing }
            let before = Set(result.inCategories)
            let add = chosen.subtracting(before).sorted()
            let remove = before.subtracting(chosen).sorted()
            return add.isEmpty && remove.isEmpty ? .nothing : .memberships(novelId: novelId, add: add, remove: remove)
        }
        let before = Set(result.requestedCategories)
        // A new request needs a category (the sheet is "Add to category"); the reader's own one can be changed, cleared too.
        let mine = result.requested?.byMe == true
        if chosen == before, mine || chosen.isEmpty { return .nothing }
        if chosen.isEmpty, !mine { return .nothing }
        return .request(categories: chosen.sorted())
    }

    /// The card after the action went through.
    static func afterBrowse(_ result: SearchResult, action: BrowseAction) -> SearchResult {
        var result = result
        switch action {
        case .memberships(_, let add, let remove):
            result.inCategories = Set(result.inCategories).union(add).subtracting(remove).sorted()
        case .request(let categories):
            result.requestedCategories = categories
            // Made or joined ("Me too"): either way the reader's now.
            result.requested = ResultRequested(status: result.requested?.status ?? "pending", byMe: true)
        case .nothing:
            break
        }
        return result
    }
}
