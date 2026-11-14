import Foundation

// Models mirror the API v1 contract. Decoding is deliberately lenient: only
// ids (and names/labels, which have fallbacks) are required, unknown keys are
// ignored and unknown enum strings map to a generic case.

struct ServerMeta: Codable, Sendable, Equatable {
    var app: String
    var apiVersion: Int
    var serverVersion: String?
}

extension ServerMeta {
    private enum CodingKeys: String, CodingKey { case app, apiVersion, serverVersion }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        app = c.lenientString(.app) ?? ""
        apiVersion = c.lenientInt(.apiVersion) ?? 0
        serverVersion = c.lenientString(.serverVersion)
    }
}

enum NovelState: Hashable, Sendable, Codable {
    case active, completed, paused, attention, failed
    case other(String)

    init(raw: String) {
        switch raw.lowercased() {
        case "active": self = .active
        case "completed": self = .completed
        case "paused": self = .paused
        case "attention": self = .attention
        case "failed": self = .failed
        default: self = .other(raw)
        }
    }

    var raw: String {
        switch self {
        case .active: "active"
        case .completed: "completed"
        case .paused: "paused"
        case .attention: "attention"
        case .failed: "failed"
        case .other(let raw): raw
        }
    }

    var label: String {
        switch self {
        case .active: "Ongoing"
        case .completed: "Completed"
        case .paused: "Paused"
        case .attention: "Needs attention"
        case .failed: "Failed"
        case .other(let raw): raw.isEmpty ? "Unknown" : raw.capitalized
        }
    }

    init(from decoder: Decoder) throws {
        let raw = (try? decoder.singleValueContainer().decode(String.self)) ?? ""
        self.init(raw: raw)
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        try c.encode(raw)
    }
}

struct NovelTag: Codable, Hashable, Sendable, Identifiable {
    var id: Int
    var name: String
}

struct NovelSummary: Codable, Hashable, Sendable, Identifiable {
    var id: Int
    var name: String
    var author: String?
    var coverUrl: URL?
    var coverLargeUrl: URL?
    var state: NovelState
    var completed: Bool
    var paused: Bool
    var origin: String?
    var tags: [NovelTag]
    var chaptersTotal: Int
    var chaptersDownloaded: Int
    var chaptersRead: Int
    var chaptersNew: Int
    var readPercent: Int
    var downloadPercent: Int
    var finished: Bool
    var lastReadAt: Date?
    var updatedAt: Date?
    var resumeChapterId: Int?
}

extension NovelSummary {
    enum CodingKeys: String, CodingKey {
        case id, name, author, coverUrl, coverLargeUrl, state, completed, paused, origin, tags
        case chaptersTotal, chaptersDownloaded, chaptersRead, chaptersNew
        case readPercent, downloadPercent, finished, lastReadAt, updatedAt, resumeChapterId
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(
                CodingKeys.id, .init(codingPath: decoder.codingPath, debugDescription: "Novel without id"))
        }
        self.id = id
        name = c.lenientString(.name) ?? "Untitled"
        author = c.lenientString(.author).flatMap { $0.isEmpty ? nil : $0 }
        coverUrl = c.lenientURL(.coverUrl)
        coverLargeUrl = c.lenientURL(.coverLargeUrl)
        state = (try? c.decodeIfPresent(NovelState.self, forKey: .state)) ?? .other("")
        completed = c.lenientBool(.completed) ?? false
        paused = c.lenientBool(.paused) ?? false
        origin = c.lenientString(.origin)
        tags = c.lossyArray(NovelTag.self, .tags)
        chaptersTotal = c.lenientInt(.chaptersTotal) ?? 0
        chaptersDownloaded = c.lenientInt(.chaptersDownloaded) ?? 0
        chaptersRead = c.lenientInt(.chaptersRead) ?? 0
        chaptersNew = c.lenientInt(.chaptersNew) ?? 0
        readPercent = c.lenientInt(.readPercent) ?? 0
        downloadPercent = c.lenientInt(.downloadPercent) ?? 0
        finished = c.lenientBool(.finished) ?? false
        lastReadAt = c.lenientDate(.lastReadAt)
        updatedAt = c.lenientDate(.updatedAt)
        resumeChapterId = c.lenientInt(.resumeChapterId)
    }

    /// The short caption under a cover in the library grid.
    var caption: String {
        if chaptersNew > 0 { return "\(chaptersNew) NEW" }
        if finished { return "FINISHED" }
        if chaptersRead == 0 { return "NOT STARTED" }
        return "\(chaptersRead) / \(chaptersTotal)"
    }

    var readFraction: Double { min(max(Double(readPercent) / 100, 0), 1) }
}

struct ChapterRef: Codable, Hashable, Sendable, Identifiable {
    var id: Int
    var label: String
    var chapter: Double?
    var book: Int?
    var downloaded: Bool?
}

extension ChapterRef {
    enum CodingKeys: String, CodingKey { case id, label, chapter, book, downloaded }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(
                CodingKeys.id, .init(codingPath: decoder.codingPath, debugDescription: "Chapter ref without id"))
        }
        self.id = id
        chapter = c.lenientDouble(.chapter)
        book = c.lenientInt(.book)
        downloaded = c.lenientBool(.downloaded)
        label = c.lenientString(.label) ?? ChapterRef.fallbackLabel(chapter)
    }

    static func fallbackLabel(_ number: Double?) -> String {
        guard let number else { return "Chapter" }
        return number == number.rounded()
            ? "Chapter \(Int(number))"
            : "Chapter \(number.formatted(.number.precision(.fractionLength(0...2))))"
    }
}

struct ContinueItem: Codable, Hashable, Sendable, Identifiable {
    var novel: NovelSummary
    var chapter: ChapterRef
    var id: Int { novel.id }
}

struct NewChaptersItem: Codable, Hashable, Sendable, Identifiable {
    var novel: NovelSummary
    var count: Int
    var firstChapter: ChapterRef?
    var id: Int { novel.id }
}

extension NewChaptersItem {
    enum CodingKeys: String, CodingKey { case novel, count, firstChapter }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        novel = try c.decode(NovelSummary.self, forKey: .novel)
        count = c.lenientInt(.count) ?? novel.chaptersNew
        firstChapter = try? c.decodeIfPresent(ChapterRef.self, forKey: .firstChapter)
    }
}

struct HomeStats: Codable, Hashable, Sendable {
    var novels: Int
    var chaptersDownloaded: Int
    var chaptersQueued: Int
}

extension HomeStats {
    enum CodingKeys: String, CodingKey { case novels, chaptersDownloaded, chaptersQueued }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        novels = c.lenientInt(.novels) ?? 0
        chaptersDownloaded = c.lenientInt(.chaptersDownloaded) ?? 0
        chaptersQueued = c.lenientInt(.chaptersQueued) ?? 0
    }
}

struct HomeFeed: Codable, Hashable, Sendable {
    var continueReading: [ContinueItem]
    var newChapters: [NewChaptersItem]
    var stats: HomeStats?
}

extension HomeFeed {
    enum CodingKeys: String, CodingKey {
        case continueReading = "continue"
        case newChapters, stats
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        continueReading = c.lossyArray(ContinueItem.self, .continueReading)
        newChapters = c.lossyArray(NewChaptersItem.self, .newChapters)
        stats = try? c.decodeIfPresent(HomeStats.self, forKey: .stats)
    }
}

struct SourceUnavailable: Codable, Hashable, Sendable {
    var count: Int
    var message: String
}

extension SourceUnavailable {
    enum CodingKeys: String, CodingKey { case count, message }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        count = c.lenientInt(.count) ?? 0
        message = c.lenientString(.message) ?? ""
    }
}

/// `GET /novels/{id}`: the summary fields plus detail-only extras, flattened
/// in one JSON object.
struct NovelDetail: Codable, Hashable, Sendable, Identifiable {
    var summary: NovelSummary
    var description: String?
    var sourceUrl: URL?
    var altNames: [String]
    var sourceUnavailable: SourceUnavailable?

    var id: Int { summary.id }

    private enum ExtraKeys: String, CodingKey {
        case description, sourceUrl, altNames, sourceUnavailable
    }

    init(summary: NovelSummary, description: String? = nil, sourceUrl: URL? = nil,
         altNames: [String] = [], sourceUnavailable: SourceUnavailable? = nil) {
        self.summary = summary
        self.description = description
        self.sourceUrl = sourceUrl
        self.altNames = altNames
        self.sourceUnavailable = sourceUnavailable
    }

    init(from decoder: Decoder) throws {
        summary = try NovelSummary(from: decoder)
        let c = try decoder.container(keyedBy: ExtraKeys.self)
        description = c.lenientString(.description).flatMap { $0.isEmpty ? nil : $0 }
        sourceUrl = c.lenientURL(.sourceUrl)
        altNames = c.lossyArray(String.self, .altNames)
        sourceUnavailable = try? c.decodeIfPresent(SourceUnavailable.self, forKey: .sourceUnavailable)
    }

    func encode(to encoder: Encoder) throws {
        try summary.encode(to: encoder)
        var c = encoder.container(keyedBy: ExtraKeys.self)
        try c.encodeIfPresent(description, forKey: .description)
        try c.encodeIfPresent(sourceUrl, forKey: .sourceUrl)
        try c.encode(altNames, forKey: .altNames)
        try c.encodeIfPresent(sourceUnavailable, forKey: .sourceUnavailable)
    }
}

struct ChapterListItem: Codable, Hashable, Sendable, Identifiable {
    var id: Int
    var chapter: Double?
    var book: Int?
    var label: String
    var downloaded: Bool
    var read: Bool
    var readProgress: Int?
    var note: Bool
    var state: String?
    var reason: String?
    var downloadedAt: Date?
    var wordCount: Int?
}

extension ChapterListItem {
    enum CodingKeys: String, CodingKey {
        case id, chapter, book, label, downloaded, read, readProgress, note, state, reason
        case downloadedAt, wordCount
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(
                CodingKeys.id, .init(codingPath: decoder.codingPath, debugDescription: "Chapter without id"))
        }
        self.id = id
        chapter = c.lenientDouble(.chapter)
        book = c.lenientInt(.book)
        label = c.lenientString(.label) ?? ChapterRef.fallbackLabel(chapter)
        downloaded = c.lenientBool(.downloaded) ?? false
        read = c.lenientBool(.read) ?? false
        readProgress = c.lenientInt(.readProgress)
        note = c.lenientBool(.note) ?? false
        state = c.lenientString(.state)
        reason = c.lenientString(.reason).flatMap { $0.isEmpty ? nil : $0 }
        downloadedAt = c.lenientDate(.downloadedAt)
        wordCount = c.lenientInt(.wordCount)
    }

    var ref: ChapterRef {
        ChapterRef(id: id, label: label, chapter: chapter, book: book, downloaded: downloaded)
    }
}

struct ChapterContent: Codable, Hashable, Sendable, Identifiable {
    var id: Int
    var novelId: Int
    var novelName: String
    var label: String
    var chapter: Double?
    var book: Int?
    var downloaded: Bool
    var read: Bool
    var readProgress: Int?
    var paragraphs: [String]
    var prev: ChapterRef?
    var next: ChapterRef?
}

extension ChapterContent {
    // `html` is intentionally not decoded: the app renders `paragraphs`, and
    // leaving it out keeps cached chapters small.
    enum CodingKeys: String, CodingKey {
        case id, novelId, novelName, label, chapter, book, downloaded, read, readProgress
        case paragraphs, prev, next
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(
                CodingKeys.id, .init(codingPath: decoder.codingPath, debugDescription: "Chapter without id"))
        }
        self.id = id
        novelId = c.lenientInt(.novelId) ?? 0
        novelName = c.lenientString(.novelName) ?? ""
        chapter = c.lenientDouble(.chapter)
        book = c.lenientInt(.book)
        label = c.lenientString(.label) ?? ChapterRef.fallbackLabel(chapter)
        paragraphs = c.lossyArray(String.self, .paragraphs)
        downloaded = c.lenientBool(.downloaded) ?? !paragraphs.isEmpty
        read = c.lenientBool(.read) ?? false
        readProgress = c.lenientInt(.readProgress)
        prev = try? c.decodeIfPresent(ChapterRef.self, forKey: .prev)
        next = try? c.decodeIfPresent(ChapterRef.self, forKey: .next)
    }
}

// MARK: - Envelopes

struct NovelsEnvelope: Decodable, Sendable {
    var novels: [NovelSummary]
    enum CodingKeys: String, CodingKey { case novels }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard c.contains(.novels) else {
            throw DecodingError.keyNotFound(
                CodingKeys.novels, .init(codingPath: [], debugDescription: "Missing novels"))
        }
        novels = c.lossyArray(NovelSummary.self, .novels)
    }
}

struct NovelEnvelope: Decodable, Sendable {
    var novel: NovelDetail
}

struct ChaptersEnvelope: Decodable, Sendable {
    var chapters: [ChapterListItem]
    enum CodingKeys: String, CodingKey { case chapters }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard c.contains(.chapters) else {
            throw DecodingError.keyNotFound(
                CodingKeys.chapters, .init(codingPath: [], debugDescription: "Missing chapters"))
        }
        chapters = c.lossyArray(ChapterListItem.self, .chapters)
    }
}

struct ChapterEnvelope: Decodable, Sendable {
    var chapter: ChapterContent
}

// MARK: - Writes

struct BulkReadRequest: Codable, Hashable, Sendable {
    enum Scope: String, Codable, Sendable {
        case ids, all
        case upTo = "up_to"
        case from
    }

    var read: Bool
    var scope: Scope
    var ids: [Int]?
    var novelId: Int?
    var anchorId: Int?

    static func all(novelId: Int, read: Bool) -> Self {
        .init(read: read, scope: .all, ids: nil, novelId: novelId, anchorId: nil)
    }

    static func upTo(_ chapterId: Int, novelId: Int, read: Bool) -> Self {
        .init(read: read, scope: .upTo, ids: nil, novelId: novelId, anchorId: chapterId)
    }

    static func from(_ chapterId: Int, novelId: Int, read: Bool) -> Self {
        .init(read: read, scope: .from, ids: nil, novelId: novelId, anchorId: chapterId)
    }
}
