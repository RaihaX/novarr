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

/// A highlight made in the reader, queued (`PendingWrite.Kind.highlight`)
/// until the server has it: `POST /highlights`.
struct HighlightDraft: Codable, Hashable, Sendable {
    var chapterId: Int
    var excerpt: String
    var note: String?

    /// The server's limit for both the excerpt and the note
    /// (HighlightController::MAX_LENGTH), in characters as Laravel counts them.
    static let maxLength = 2000

    /// Trimmed; nil when the excerpt is empty or either text is over the limit.
    init?(chapterId: Int, excerpt: String, note: String?) {
        let excerpt = excerpt.trimmingCharacters(in: .whitespacesAndNewlines)
        let note = note?.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !excerpt.isEmpty, Self.length(excerpt) <= Self.maxLength,
              Self.length(note ?? "") <= Self.maxLength else { return nil }
        self.chapterId = chapterId
        self.excerpt = excerpt
        self.note = note?.isEmpty == false ? note : nil
    }

    /// Laravel's `max:` on a string counts code points (mb_strlen).
    static func length(_ text: String) -> Int { text.unicodeScalars.count }

    /// Shown in the reader until the server's copy replaces it; ids below
    /// zero never reach the server.
    func preview(id: Int, novelId: Int, novelName: String, chapterLabel: String) -> Highlight {
        Highlight(id: id, novelId: novelId, novelName: novelName, chapterId: chapterId,
                  chapterLabel: chapterLabel, excerpt: excerpt, note: note, createdAt: nil)
    }
}
