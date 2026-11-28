import Foundation

// Reading lists, called "categories" in the app (GET/POST /lists,
// PATCH/DELETE /lists/{id}, PUT/DELETE /lists/{id}/novels/{novelId},
// PATCH /lists/order, PUT /lists/memberships). A list is the reader's own;
// a single change answers the whole list, which replaces the local copy;
// the bulk ones (order, memberships) answer the whole index. Mirrors
// android api/ListModels.kt.

struct ReadingList: Codable, Hashable, Sendable, Identifiable {
    static let kindWantToRead = "want_to_read"
    static let kindFavourites = "favourites"

    var id: Int
    var name: String
    var count: Int
    var novelIds: [Int]
    /// nil: the reader's own list; "want_to_read" / "favourites": the built-in ones.
    var kind: String?
    /// The reader's order (0-based; the index comes sorted by it). 0 on an older server.
    var position: Int
    /// Left off Home's category sections (built-ins too). False on an older server.
    var hiddenOnHome: Bool

    init(id: Int, name: String, count: Int = 0, novelIds: [Int] = [], kind: String? = nil,
         position: Int = 0, hiddenOnHome: Bool = false) {
        self.id = id
        self.name = name
        self.count = count
        self.novelIds = novelIds
        self.kind = kind
        self.position = position
        self.hiddenOnHome = hiddenOnHome
    }

    func contains(_ novelId: Int) -> Bool { novelIds.contains(novelId) }
    var isWantToRead: Bool { kind == Self.kindWantToRead }
    var isFavourites: Bool { kind == Self.kindFavourites }
    /// A built-in list (any kind, including one a newer server adds): not the reader's own.
    var isBuiltIn: Bool { kind != nil }

    enum CodingKeys: String, CodingKey { case id, name, count, novelIds, kind, position, hiddenOnHome }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(CodingKeys.id, .init(codingPath: c.codingPath, debugDescription: "list.id"))
        }
        self.id = id
        name = c.lenientString(.name) ?? ""
        count = c.lenientInt(.count) ?? 0
        novelIds = c.lossyArray(Int.self, .novelIds)
        kind = c.lenientString(.kind).flatMap { $0.isEmpty ? nil : $0 }
        position = c.lenientInt(.position) ?? 0
        hiddenOnHome = c.lenientBool(.hiddenOnHome) ?? false
    }
}

/// One series on "Want to read" (GET /want-to-read, Home's shelf).
struct WantToReadItem: Codable, Hashable, Sendable, Identifiable {
    var novel: NovelSummary
    var addedAt: String?
    var started: Bool
    var id: Int { novel.id }

    enum CodingKeys: String, CodingKey { case novel, addedAt, started }

    init(novel: NovelSummary, addedAt: String? = nil, started: Bool = false) {
        self.novel = novel
        self.addedAt = addedAt
        self.started = started
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        novel = try c.decode(NovelSummary.self, forKey: .novel)
        addedAt = c.lenientString(.addedAt)
        started = c.lenientBool(.started) ?? false
    }
}

struct WantToReadEnvelope: Codable, Hashable, Sendable {
    var list: ReadingList?
    var items: [WantToReadItem]

    enum CodingKeys: String, CodingKey { case list, items }

    init(list: ReadingList? = nil, items: [WantToReadItem] = []) {
        self.list = list
        self.items = items
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        list = try? c.decodeIfPresent(ReadingList.self, forKey: .list)
        items = c.lossyArray(WantToReadItem.self, .items)
    }
}

struct ListsEnvelope: Decodable, Sendable {
    var lists: [ReadingList]
    enum CodingKeys: String, CodingKey { case lists }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard c.contains(.lists) else {
            throw DecodingError.keyNotFound(CodingKeys.lists, .init(codingPath: [], debugDescription: "Missing lists"))
        }
        lists = c.lossyArray(ReadingList.self, .lists)
    }
}

struct ListEnvelope: Decodable, Sendable {
    var list: ReadingList
}

/// POST /novels/bulk-read: how many series, and how many chapter rows changed.
struct BulkReadAnswer: Decodable, Sendable, Equatable {
    var success: Bool
    var count: Int
    var chapters: Int

    enum CodingKeys: String, CodingKey { case success, count, chapters }

    init(success: Bool = false, count: Int = 0, chapters: Int = 0) {
        self.success = success
        self.count = count
        self.chapters = chapters
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        success = c.lenientBool(.success) ?? false
        count = c.lenientInt(.count) ?? 0
        chapters = c.lenientInt(.chapters) ?? 0
    }
}
