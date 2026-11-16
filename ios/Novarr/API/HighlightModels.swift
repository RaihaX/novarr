import Foundation

/// A saved excerpt (the website's "bookmark") with an optional note.
struct Highlight: Codable, Hashable, Sendable, Identifiable {
    var id: Int
    var novelId: Int
    var novelName: String
    var chapterId: Int
    var chapterLabel: String
    var excerpt: String
    var note: String?
    var createdAt: Date?
}

extension Highlight {
    enum CodingKeys: String, CodingKey {
        case id, novelId, novelName, chapterId, chapterLabel, excerpt, note, createdAt
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id), let chapterId = c.lenientInt(.chapterId) else {
            throw DecodingError.keyNotFound(
                CodingKeys.id, .init(codingPath: decoder.codingPath, debugDescription: "Highlight without id"))
        }
        self.id = id
        self.chapterId = chapterId
        novelId = c.lenientInt(.novelId) ?? 0
        novelName = c.lenientString(.novelName) ?? ""
        chapterLabel = c.lenientString(.chapterLabel) ?? "Chapter"
        excerpt = c.lenientString(.excerpt) ?? ""
        note = c.lenientString(.note).flatMap {
            $0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? nil : $0
        }
        createdAt = c.lenientDate(.createdAt)
    }
}

/// `GET /highlights`: one page, newest first.
struct HighlightsPage: Decodable, Sendable, Equatable {
    var highlights: [Highlight]
    var page: Int
    var hasMore: Bool

    enum CodingKeys: String, CodingKey { case highlights, page, hasMore }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard c.contains(.highlights) else {
            throw DecodingError.keyNotFound(
                CodingKeys.highlights, .init(codingPath: [], debugDescription: "Missing highlights"))
        }
        highlights = c.lossyArray(Highlight.self, .highlights)
        page = c.lenientInt(.page) ?? 1
        hasMore = c.lenientBool(.hasMore) ?? false
    }
}
