import Foundation

/// Reading lists ("categories") as pure logic: name checks that mirror the
/// server's limits (most mistakes are caught before a round trip; the
/// server's 422 message still wins), optimistic membership changes with
/// rollback, ordering and the library's list filter. Mirrors android
/// domain/ReadingLists.kt.
enum ReadingLists {
    /// The server's limits: names up to 40 characters, at most 30 lists per reader.
    static let nameMax = 40
    static let maxLists = 30

    /// One-tap lists offered while the reader has none ("Favourites" is built in).
    static let starterNames = ["To read", "Finished"]

    /// The built-in lists' names (reserved).
    static let wantToReadName = "Want to read"
    static let favouritesName = "Favourites"

    /// The reader's own lists: everything but the built-ins.
    static func own(_ lists: [ReadingList]) -> [ReadingList] { lists.filter { !$0.isBuiltIn } }

    static func wantToRead(_ lists: [ReadingList]) -> ReadingList? { lists.first { $0.isWantToRead } }
    static func favourites(_ lists: [ReadingList]) -> ReadingList? { lists.first { $0.isFavourites } }

    /// Whether `novelId` is a favourite (false while the list is unknown).
    static func isFavourite(_ lists: [ReadingList], novelId: Int) -> Bool {
        favourites(lists)?.contains(novelId) ?? false
    }

    /// Whether `novelId` is on "Want to read" (false while the list is unknown).
    static func isWanted(_ lists: [ReadingList], novelId: Int) -> Bool {
        wantToRead(lists)?.contains(novelId) ?? false
    }

    // MARK: Names

    /// Trimmed (what is sent; Laravel trims input too).
    static func clean(_ name: String) -> String { name.trimmingCharacters(in: .whitespacesAndNewlines) }

    /// Characters as the server counts them (code points, so an emoji is one).
    static func length(_ name: String) -> Int { clean(name).unicodeScalars.count }

    /// Why `name` can't be used, or nil when it can. `renaming` is the list
    /// being renamed (its own name is no duplicate); a new list also needs
    /// room under `maxLists`.
    static func nameProblem(_ name: String, lists: [ReadingList], renaming: Int? = nil) -> String? {
        let cleaned = clean(name)
        if cleaned.isEmpty { return "Enter a name." }
        if length(cleaned) > nameMax { return "Names can be up to \(nameMax) characters." }
        let key = fold(cleaned)
        if key == fold(wantToReadName) { return "“\(wantToReadName)” is already one of your categories." }
        if key == fold(favouritesName), lists.contains(where: \.isFavourites) {
            return "“\(favouritesName)” is already one of your categories."
        }
        if lists.contains(where: { $0.id != renaming && fold($0.name) == key }) {
            return "You already have a category called “\(cleaned)”."
        }
        if renaming == nil, own(lists).count >= maxLists { return "You can have up to \(maxLists) categories." }
        return nil
    }

    /// The starters not already taken, so a tap can't hit a duplicate.
    static func starters(_ lists: [ReadingList]) -> [String] {
        starterNames.filter { starter in !own(lists).contains { fold($0.name) == fold(starter) } }
    }

    private static func fold(_ name: String) -> String {
        clean(name).lowercased().replacingOccurrences(of: "\\s+", with: " ", options: .regularExpression)
    }

    // MARK: Membership, optimistic

    /// A membership change sent and not answered yet.
    struct Toggle: Hashable, Sendable {
        var listId: Int
        var novelId: Int
        var add: Bool
    }

    /// The lists as shown, with the changes still on their way.
    struct State: Equatable, Sendable {
        var lists: [ReadingList] = []
        var inFlight: Set<Toggle> = []

        func busy(listId: Int, novelId: Int) -> Bool {
            inFlight.contains { $0.listId == listId && $0.novelId == novelId }
        }
    }

    /// Shows `novelId` in or out of `listId` straight away. Nil when that
    /// change can't start: the list is unknown, it is already as asked, or a
    /// change for the same list and series is still on its way.
    static func begin(_ state: State, listId: Int, novelId: Int, add: Bool) -> State? {
        guard let list = state.lists.first(where: { $0.id == listId }) else { return nil }
        if state.busy(listId: listId, novelId: novelId) || list.contains(novelId) == add { return nil }
        let toggle = Toggle(listId: listId, novelId: novelId, add: add)
        return State(lists: apply(state.lists, toggle), inFlight: state.inFlight.union([toggle]))
    }

    /// The server answered `toggle` with its copy of the list: that copy
    /// replaces ours, with any other change to the same list still on its way on top.
    static func succeed(_ state: State, toggle: Toggle, answer: ReadingList) -> State {
        let others = state.inFlight.subtracting([toggle])
        var lists = replace(state.lists, answer)
        for other in others where other.listId == answer.id { lists = apply(lists, other) }
        return State(lists: lists, inFlight: others)
    }

    /// `toggle` failed: undone (whatever else changed meanwhile stays).
    static func fail(_ state: State, toggle: Toggle) -> State {
        var undo = toggle
        undo.add.toggle()
        return State(lists: apply(state.lists, undo), inFlight: state.inFlight.subtracting([toggle]))
    }

    /// Adds or removes a series in one list, keeping its count in step (the
    /// built-ins are newest first: added at the front).
    static func apply(_ lists: [ReadingList], _ toggle: Toggle) -> [ReadingList] {
        lists.map { list in
            guard list.id == toggle.listId else { return list }
            var list = list
            let has = list.contains(toggle.novelId)
            if toggle.add, !has {
                list.novelIds = list.isBuiltIn ? [toggle.novelId] + list.novelIds : list.novelIds + [toggle.novelId]
                list.count += 1
            } else if !toggle.add, has {
                list.novelIds.removeAll { $0 == toggle.novelId }
                list.count = max(list.count - 1, 0)
            }
            return list
        }
    }

    /// Applies every change still on its way to a fresh copy from the server.
    static func applyingInFlight(_ lists: [ReadingList], _ inFlight: Set<Toggle>) -> [ReadingList] {
        inFlight.reduce(lists) { apply($0, $1) }
    }

    /// A list from the server replaces ours (or is added at the end).
    static func replace(_ lists: [ReadingList], _ answer: ReadingList) -> [ReadingList] {
        if lists.contains(where: { $0.id == answer.id }) {
            return lists.map { $0.id == answer.id ? answer : $0 }
        }
        return lists + [answer]
    }

    /// The reader's order (`position`, set in Manage categories). A server
    /// from before positions sends none (all 0): then "Want to read" first,
    /// then "Favourites", then in name order.
    static func sorted(_ lists: [ReadingList]) -> [ReadingList] {
        if lists.contains(where: { $0.position != 0 }) {
            return lists.sorted { ($0.position, $0.id) < ($1.position, $1.id) }
        }
        func rank(_ list: ReadingList) -> Int { list.isWantToRead ? 0 : (list.isBuiltIn ? 1 : 2) }
        return lists.sorted { a, b in
            if rank(a) != rank(b) { return rank(a) < rank(b) }
            let order = a.name.localizedCaseInsensitiveCompare(b.name)
            if order != .orderedSame { return order == .orderedAscending }
            return a.id < b.id
        }
    }

    /// The lists `novelId` is in.
    static func containing(_ lists: [ReadingList], novelId: Int) -> [ReadingList] {
        lists.filter { $0.contains(novelId) }
    }

    // MARK: Library filter

    /// The series of `list` among `novels` (one the library doesn't have is skipped).
    static func filter(_ novels: [NovelSummary], list: ReadingList?) -> [NovelSummary] {
        guard let list else { return novels }
        let ids = Set(list.novelIds)
        return novels.filter { ids.contains($0.id) }
    }

    /// Why a list filter shows nothing on this shelf.
    enum Empty: Equatable {
        /// Nothing in the list at all (that the library has).
        case noSeries
        /// Its series are all on other shelves (`count` of them).
        case otherShelf(Int)
        /// Some are on this shelf, but a filter hides them.
        case filtered
    }

    static func emptyReason(_ list: ReadingList, library: [NovelSummary], shelf: [NovelSummary]) -> Empty {
        let inLibrary = filter(library, list: list).count
        let onShelf = filter(shelf, list: list).count
        if inLibrary == 0 { return .noSeries }
        if onShelf == 0 { return .otherShelf(inLibrary) }
        return .filtered
    }

    // MARK: Want to read

    /// The server's items as shown, given the local copy of the list: a
    /// series taken off since goes at once; with no local copy, as they are.
    static func shown(_ items: [WantToReadItem], list: ReadingList?) -> [WantToReadItem] {
        guard let list else { return items }
        let ids = Set(list.novelIds)
        return items.filter { ids.contains($0.novel.id) }
    }

    /// Whether the local list holds a series the server's items (its first
    /// `limit`) don't have yet, so they need fetching again to show it.
    static func needsRefetch(_ items: [WantToReadItem], list: ReadingList?, limit: Int = .max) -> Bool {
        guard let list else { return false }
        let have = Set(items.map(\.novel.id))
        return list.novelIds.prefix(limit).contains { !have.contains($0) }
    }

    /// "Started" under a cover: the server's word, or reading seen here since.
    static func started(_ item: WantToReadItem) -> Bool { item.started || item.novel.chaptersRead > 0 }

    /// The remembered list choice, if that list still exists.
    static func selected(_ lists: [ReadingList], listId: Int?) -> ReadingList? {
        guard let listId else { return nil }
        return lists.first { $0.id == listId }
    }
}
