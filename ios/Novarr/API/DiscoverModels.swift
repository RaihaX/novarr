import Foundation

// Discover, add a novel and check for new chapters. Same lenient decoding as
// Models.swift: identities are required, everything else has a fallback.

// MARK: - Discover

struct DiscoverList: Codable, Hashable, Sendable, Identifiable {
    var id: String
    var name: String

    /// `search` is driven by the search field, not offered as a list.
    var isSearch: Bool { id == DiscoverSource.searchListId }
}

extension DiscoverList {
    enum CodingKeys: String, CodingKey { case id, name }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientString(.id), !id.isEmpty else {
            throw DecodingError.keyNotFound(
                CodingKeys.id, .init(codingPath: decoder.codingPath, debugDescription: "List without id"))
        }
        self.id = id
        name = c.lenientString(.name) ?? id.capitalized
    }
}

struct DiscoverSource: Codable, Hashable, Sendable, Identifiable {
    static let searchListId = "search"

    var id: String
    var name: String
    var host: String?
    var isDefault: Bool
    var lists: [DiscoverList]

    /// The browsable lists (popular/new/…), in the server's order.
    var browseLists: [DiscoverList] { lists.filter { !$0.isSearch } }
    var offersSearch: Bool { lists.contains(where: \.isSearch) }
}

extension DiscoverSource {
    enum CodingKeys: String, CodingKey {
        case id, name, host, lists
        case isDefault = "default"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientString(.id), !id.isEmpty else {
            throw DecodingError.keyNotFound(
                CodingKeys.id, .init(codingPath: decoder.codingPath, debugDescription: "Source without id"))
        }
        self.id = id
        name = c.lenientString(.name) ?? id
        host = c.lenientString(.host)
        isDefault = c.lenientBool(.isDefault) ?? false
        lists = c.lossyArray(DiscoverList.self, .lists)
    }
}

struct DiscoverSourcesEnvelope: Decodable, Sendable {
    var sources: [DiscoverSource]
    enum CodingKeys: String, CodingKey { case sources }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard c.contains(.sources) else {
            throw DecodingError.keyNotFound(
                CodingKeys.sources, .init(codingPath: [], debugDescription: "Missing sources"))
        }
        sources = c.lossyArray(DiscoverSource.self, .sources)
    }
}

/// One novel on a source's list or in search results.
struct DiscoverItem: Codable, Hashable, Sendable, Identifiable {
    var title: String
    var author: String?
    /// Absolute, on the source's host (not the Novarr server).
    var coverUrl: URL?
    var synopsis: String?
    var status: String?
    var chapters: Int?
    /// The source's novel page: the item's identity and what is sent to add it.
    var url: URL
    var source: String?
    var inLibrary: Bool
    var novelId: Int?

    var id: String { url.absoluteString }

    /// "Ongoing · 3,207 chapters", or nil when the source reports neither.
    var facts: String? {
        var parts: [String] = []
        if let status { parts.append(status) }
        if let chapters { parts.append(chapters == 1 ? "1 chapter" : "\(chapters.formatted()) chapters") }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }
}

extension DiscoverItem {
    enum CodingKeys: String, CodingKey {
        case title, author, coverUrl, status, chapters, url, source, inLibrary, novelId
        case synopsis = "description"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let url = c.lenientURL(.url) else {
            throw DecodingError.keyNotFound(
                CodingKeys.url, .init(codingPath: decoder.codingPath, debugDescription: "Item without url"))
        }
        self.url = url
        title = c.lenientString(.title).flatMap { $0.isEmpty ? nil : $0 } ?? url.lastPathComponent
        author = c.lenientString(.author).flatMap { $0.isEmpty ? nil : $0 }
        coverUrl = c.lenientURL(.coverUrl)
        synopsis = c.lenientString(.synopsis).flatMap { $0.isEmpty ? nil : $0 }
        status = c.lenientString(.status).flatMap { $0.isEmpty ? nil : $0 }
        chapters = c.lenientInt(.chapters)
        source = c.lenientString(.source)
        novelId = c.lenientInt(.novelId)
        inLibrary = c.lenientBool(.inLibrary) ?? (novelId != nil)
    }
}

/// `GET /discover/browse`: one page of a list.
struct DiscoverPage: Decodable, Sendable, Equatable {
    var items: [DiscoverItem]
    var page: Int
    var hasMore: Bool

    enum CodingKeys: String, CodingKey { case items, page, hasMore }

    init(items: [DiscoverItem], page: Int, hasMore: Bool) {
        self.items = items
        self.page = page
        self.hasMore = hasMore
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard c.contains(.items) else {
            throw DecodingError.keyNotFound(
                CodingKeys.items, .init(codingPath: [], debugDescription: "Missing items"))
        }
        items = c.lossyArray(DiscoverItem.self, .items)
        page = c.lenientInt(.page) ?? 1
        hasMore = c.lenientBool(.hasMore) ?? false
    }
}

/// `POST /novels`: 201 (added now) or 200 (already in the library).
struct AddNovelResponse: Decodable, Sendable, Equatable {
    var novel: NovelSummary
    var alreadyInLibrary: Bool

    enum CodingKeys: String, CodingKey { case novel, alreadyInLibrary }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        novel = try c.decode(NovelSummary.self, forKey: .novel)
        alreadyInLibrary = c.lenientBool(.alreadyInLibrary) ?? false
    }
}

// MARK: - Check for new chapters

struct NovelCheck: Codable, Hashable, Sendable {
    enum Status: Hashable, Sendable {
        case idle, running, done, failed
        case other(String)

        init(raw: String) {
            switch raw.lowercased() {
            case "idle": self = .idle
            case "running": self = .running
            case "done": self = .done
            case "failed": self = .failed
            default: self = .other(raw)
            }
        }

        var raw: String {
            switch self {
            case .idle: "idle"
            case .running: "running"
            case .done: "done"
            case .failed: "failed"
            case .other(let raw): raw
            }
        }
    }

    var status: Status
    var startedAt: Date?
    var finishedAt: Date?
    var chaptersBefore: Int?
    var chaptersNow: Int
    var newChapters: Int
    var message: String?
}

extension NovelCheck {
    enum CodingKeys: String, CodingKey {
        case status, startedAt, finishedAt, chaptersBefore, chaptersNow, newChapters, message
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let raw = c.lenientString(.status) else {
            throw DecodingError.keyNotFound(
                CodingKeys.status, .init(codingPath: decoder.codingPath, debugDescription: "Check without status"))
        }
        status = Status(raw: raw)
        startedAt = c.lenientDate(.startedAt)
        finishedAt = c.lenientDate(.finishedAt)
        chaptersBefore = c.lenientInt(.chaptersBefore)
        chaptersNow = c.lenientInt(.chaptersNow) ?? 0
        newChapters = max(0, c.lenientInt(.newChapters) ?? 0)
        message = c.lenientString(.message).flatMap { $0.isEmpty ? nil : $0 }
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encode(status.raw, forKey: .status)
        try c.encodeIfPresent(startedAt, forKey: .startedAt)
        try c.encodeIfPresent(finishedAt, forKey: .finishedAt)
        try c.encodeIfPresent(chaptersBefore, forKey: .chaptersBefore)
        try c.encode(chaptersNow, forKey: .chaptersNow)
        try c.encode(newChapters, forKey: .newChapters)
        try c.encodeIfPresent(message, forKey: .message)
    }
}

struct CheckEnvelope: Decodable, Sendable {
    var check: NovelCheck
}
