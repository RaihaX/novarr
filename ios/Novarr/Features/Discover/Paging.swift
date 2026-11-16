import Foundation

/// A list loaded page by page (Discover results, highlights). Pure value type
/// so the merge rules are unit-tested without a server.
struct PagedList<Item: Identifiable> where Item.ID: Hashable {
    private(set) var items: [Item] = []
    /// The last page applied; 0 before the first one.
    private(set) var loadedPages = 0
    private(set) var hasMore = true

    var nextPage: Int { loadedPages + 1 }
    var hasLoaded: Bool { loadedPages > 0 }
    var canLoadMore: Bool { hasLoaded && hasMore }

    init() {}

    /// Applies the answer for `page`. Out-of-order or repeated pages are ignored
    /// (returns false), items already shown are skipped (a list can shift
    /// between requests), and an empty page ends the list even if the server
    /// claims more, so paging can never spin.
    @discardableResult
    mutating func apply(page: Int, items newItems: [Item], hasMore: Bool) -> Bool {
        guard page == nextPage else { return false }
        var seen = Set(items.map(\.id))
        for item in newItems where seen.insert(item.id).inserted {
            items.append(item)
        }
        loadedPages = page
        self.hasMore = hasMore && !newItems.isEmpty
        return true
    }

    mutating func reset() {
        self = PagedList()
    }

    mutating func update(id: Item.ID, _ transform: (inout Item) -> Void) {
        guard let index = items.firstIndex(where: { $0.id == id }) else { return }
        transform(&items[index])
    }

    /// Removes an item, returning where it was so a failed delete can put it back.
    @discardableResult
    mutating func remove(id: Item.ID) -> (index: Int, item: Item)? {
        guard let index = items.firstIndex(where: { $0.id == id }) else { return nil }
        return (index, items.remove(at: index))
    }

    mutating func restore(_ item: Item, at index: Int) {
        guard !items.contains(where: { $0.id == item.id }) else { return }
        items.insert(item, at: min(max(index, 0), items.count))
    }
}

extension PagedList: Equatable where Item: Equatable {}
extension PagedList: Sendable where Item: Sendable {}

/// What the Discover screen is showing: a source's list, or a search on it.
struct DiscoverQuery: Hashable, Sendable {
    static let maxSearchLength = 100

    var sourceId: String?
    /// `popular`, `new`, `random`, `completed` or `search`.
    var type: String
    var text: String?

    var isSearch: Bool { type == DiscoverSource.searchListId }

    /// A search when there is search text, otherwise the chosen list. Nil when
    /// there is nothing to load (a search-only source with no text yet).
    static func make(source: DiscoverSource?, listId: String?, searchText: String) -> DiscoverQuery? {
        let text = String(searchText.trimmingCharacters(in: .whitespacesAndNewlines).prefix(maxSearchLength))
        if !text.isEmpty {
            if let source, !source.offersSearch { return nil }
            return DiscoverQuery(sourceId: source?.id, type: DiscoverSource.searchListId, text: text)
        }
        let lists = source?.browseLists ?? []
        guard let list = lists.first(where: { $0.id == listId }) ?? lists.first else { return nil }
        return DiscoverQuery(sourceId: source?.id, type: list.id, text: nil)
    }
}
