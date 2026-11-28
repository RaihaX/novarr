import Foundation

// Audiobooks (API v1, meta `audiobooks: true`). Only ids are required so an
// older or newer server never breaks decoding. Mirrors android api/AudiobookModels.kt.

/// `audiobook` on a novel payload: the book has an audiobook (nil: none, or an older server).
struct AudiobookRef: Codable, Hashable, Sendable {
    var id: Int
    var durationMs: Int64

    enum CodingKeys: String, CodingKey { case id, durationMs }

    init(id: Int, durationMs: Int64 = 0) {
        self.id = id
        self.durationMs = durationMs
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(CodingKeys.id, .init(codingPath: c.codingPath, debugDescription: "audiobook.id"))
        }
        self.id = id
        durationMs = c.lenientInt64(.durationMs) ?? 0
    }
}

/// Where the reader is in an audiobook (whole-book position).
struct AudiobookProgress: Codable, Hashable, Sendable {
    var positionMs: Int64
    var finished: Bool
    /// ISO 8601; when the server recorded it.
    var updatedAt: String?
    var speed: Double?

    enum CodingKeys: String, CodingKey { case positionMs, finished, updatedAt, speed }

    init(positionMs: Int64 = 0, finished: Bool = false, updatedAt: String? = nil, speed: Double? = nil) {
        self.positionMs = positionMs
        self.finished = finished
        self.updatedAt = updatedAt
        self.speed = speed
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        positionMs = c.lenientInt64(.positionMs) ?? 0
        finished = c.lenientBool(.finished) ?? false
        updatedAt = c.lenientString(.updatedAt)
        speed = c.lenientDouble(.speed)
    }
}

/// One audiobook in a list (`GET /audiobooks`, `/audiobooks/continue`).
struct AudiobookSummary: Codable, Hashable, Sendable, Identifiable {
    var id: Int
    var novelId: Int?
    var title: String
    var author: String?
    var narrator: String?
    var coverUrl: URL?
    var durationMs: Int64
    var progress: AudiobookProgress?
    var series: String?
    var seriesIndex: String?
    var asin: String?

    enum CodingKeys: String, CodingKey {
        case id, novelId, title, author, narrator, coverUrl, durationMs, progress, series, seriesIndex, asin
    }

    init(id: Int, novelId: Int? = nil, title: String = "Untitled", author: String? = nil, narrator: String? = nil,
         coverUrl: URL? = nil, durationMs: Int64 = 0, progress: AudiobookProgress? = nil,
         series: String? = nil, seriesIndex: String? = nil, asin: String? = nil) {
        self.id = id
        self.novelId = novelId
        self.title = title
        self.author = author
        self.narrator = narrator
        self.coverUrl = coverUrl
        self.durationMs = durationMs
        self.progress = progress
        self.series = series
        self.seriesIndex = seriesIndex
        self.asin = asin
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(CodingKeys.id, .init(codingPath: c.codingPath, debugDescription: "audiobook.id"))
        }
        self.id = id
        novelId = c.lenientInt(.novelId)
        title = c.lenientString(.title).flatMap { $0.isEmpty ? nil : $0 } ?? "Untitled"
        author = c.lenientString(.author)
        narrator = c.lenientString(.narrator)
        coverUrl = c.lenientURL(.coverUrl)
        durationMs = c.lenientInt64(.durationMs) ?? 0
        progress = try? c.decodeIfPresent(AudiobookProgress.self, forKey: .progress)
        series = c.lenientString(.series)
        seriesIndex = c.lenientString(.seriesIndex)
        asin = c.lenientString(.asin)
    }

    var authorOrNil: String? { author.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } }
    var narratorOrNil: String? { narrator.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } }
    /// "Series #N" (or just the series); nil without one.
    var seriesLabel: String? { SeriesLabel.make(series: series, index: seriesIndex) }

    /// 0...1 through the book; finished counts as all of it.
    var fraction: Double {
        guard let progress else { return 0 }
        if progress.finished { return 1 }
        guard durationMs > 0 else { return 0 }
        return min(max(Double(progress.positionMs) / Double(durationMs), 0), 1)
    }

    var started: Bool {
        guard let progress else { return false }
        return progress.positionMs > 0 || progress.finished
    }
}

/// A file of the audiobook. `url` is pre-signed (valid 24 h): it plays without headers.
struct AudiobookPart: Codable, Hashable, Sendable {
    var index: Int
    var url: URL?
    var mime: String?
    var durationMs: Int64
    var startMs: Int64
    var sizeBytes: Int64

    enum CodingKeys: String, CodingKey { case index, url, mime, durationMs, startMs, sizeBytes }

    init(index: Int, url: URL? = nil, mime: String? = nil, durationMs: Int64 = 0, startMs: Int64 = 0, sizeBytes: Int64 = 0) {
        self.index = index
        self.url = url
        self.mime = mime
        self.durationMs = durationMs
        self.startMs = startMs
        self.sizeBytes = sizeBytes
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let index = c.lenientInt(.index) else {
            throw DecodingError.keyNotFound(CodingKeys.index, .init(codingPath: c.codingPath, debugDescription: "part.index"))
        }
        self.index = index
        url = c.lenientURL(.url)
        mime = c.lenientString(.mime)
        durationMs = c.lenientInt64(.durationMs) ?? 0
        startMs = c.lenientInt64(.startMs) ?? 0
        sizeBytes = c.lenientInt64(.sizeBytes) ?? 0
    }
}

/// A chapter: positions in the WHOLE book, across parts.
struct AudiobookChapter: Codable, Hashable, Sendable {
    var index: Int
    var title: String
    var startMs: Int64
    var endMs: Int64

    enum CodingKeys: String, CodingKey { case index, title, startMs, endMs }

    init(index: Int, title: String = "", startMs: Int64 = 0, endMs: Int64 = 0) {
        self.index = index
        self.title = title
        self.startMs = startMs
        self.endMs = endMs
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let index = c.lenientInt(.index) else {
            throw DecodingError.keyNotFound(CodingKeys.index, .init(codingPath: c.codingPath, debugDescription: "chapter.index"))
        }
        self.index = index
        title = c.lenientString(.title) ?? ""
        startMs = c.lenientInt64(.startMs) ?? 0
        endMs = c.lenientInt64(.endMs) ?? 0
    }

    var label: String { title.trimmingCharacters(in: .whitespaces).isEmpty ? "Chapter \(index + 1)" : title }
}

struct AudiobookBookmark: Codable, Hashable, Sendable, Identifiable {
    var id: Int
    var positionMs: Int64
    var note: String?
    var createdAt: String?

    enum CodingKeys: String, CodingKey { case id, positionMs, note, createdAt }

    init(id: Int, positionMs: Int64 = 0, note: String? = nil, createdAt: String? = nil) {
        self.id = id
        self.positionMs = positionMs
        self.note = note
        self.createdAt = createdAt
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(CodingKeys.id, .init(codingPath: c.codingPath, debugDescription: "bookmark.id"))
        }
        self.id = id
        positionMs = c.lenientInt64(.positionMs) ?? 0
        note = c.lenientString(.note)
        createdAt = c.lenientString(.createdAt)
    }

    var noteOrNil: String? { note.flatMap { $0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? nil : $0 } }
}

/// `GET /audiobooks/{id}`: the summary plus extras, flattened in one JSON object.
struct AudiobookDetail: Codable, Hashable, Sendable, Identifiable {
    var summary: AudiobookSummary
    var description: String?
    var year: String?
    var sizeBytes: Int64
    /// Sorted by index.
    var parts: [AudiobookPart]
    /// Sorted by start.
    var chapters: [AudiobookChapter]
    var bookmarks: [AudiobookBookmark]

    var id: Int { summary.id }

    private enum ExtraKeys: String, CodingKey { case description, year, sizeBytes, parts, chapters, bookmarks }

    init(summary: AudiobookSummary, description: String? = nil, year: String? = nil, sizeBytes: Int64 = 0,
         parts: [AudiobookPart] = [], chapters: [AudiobookChapter] = [], bookmarks: [AudiobookBookmark] = []) {
        self.summary = summary
        self.description = description
        self.year = year
        self.sizeBytes = sizeBytes
        self.parts = parts.sorted { $0.index < $1.index }
        self.chapters = chapters.sorted { $0.startMs < $1.startMs }
        self.bookmarks = bookmarks
    }

    init(from decoder: Decoder) throws {
        summary = try AudiobookSummary(from: decoder)
        let c = try decoder.container(keyedBy: ExtraKeys.self)
        description = c.lenientString(.description).flatMap { $0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? nil : $0 }
        year = c.lenientString(.year)
        sizeBytes = c.lenientInt64(.sizeBytes) ?? 0
        parts = c.lossyArray(AudiobookPart.self, .parts).sorted { $0.index < $1.index }
        chapters = c.lossyArray(AudiobookChapter.self, .chapters).sorted { $0.startMs < $1.startMs }
        bookmarks = c.lossyArray(AudiobookBookmark.self, .bookmarks)
    }

    func encode(to encoder: Encoder) throws {
        try summary.encode(to: encoder)
        var c = encoder.container(keyedBy: ExtraKeys.self)
        try c.encodeIfPresent(description, forKey: .description)
        try c.encodeIfPresent(year, forKey: .year)
        try c.encode(sizeBytes, forKey: .sizeBytes)
        try c.encode(parts, forKey: .parts)
        try c.encode(chapters, forKey: .chapters)
        try c.encode(bookmarks, forKey: .bookmarks)
    }
}

struct AudiobooksPage: Codable, Hashable, Sendable {
    var audiobooks: [AudiobookSummary]
    var page: Int
    var hasMore: Bool

    enum CodingKeys: String, CodingKey { case audiobooks, page, hasMore }

    init(audiobooks: [AudiobookSummary], page: Int = 1, hasMore: Bool = false) {
        self.audiobooks = audiobooks
        self.page = page
        self.hasMore = hasMore
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard c.contains(.audiobooks) else {
            throw DecodingError.keyNotFound(CodingKeys.audiobooks, .init(codingPath: [], debugDescription: "Missing audiobooks"))
        }
        audiobooks = c.lossyArray(AudiobookSummary.self, .audiobooks)
        page = c.lenientInt(.page) ?? 1
        hasMore = c.lenientBool(.hasMore) ?? false
    }
}

struct AudiobooksEnvelope: Decodable, Sendable {
    var audiobooks: [AudiobookSummary]
    enum CodingKeys: String, CodingKey { case audiobooks }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard c.contains(.audiobooks) else {
            throw DecodingError.keyNotFound(CodingKeys.audiobooks, .init(codingPath: [], debugDescription: "Missing audiobooks"))
        }
        audiobooks = c.lossyArray(AudiobookSummary.self, .audiobooks)
    }
}

struct AudiobookEnvelope: Decodable, Sendable {
    var audiobook: AudiobookDetail
}

/// `PUT /audiobooks/{id}/progress`: what the server now has; `stale` when ours was older and ignored.
struct AudiobookProgressResult: Decodable, Sendable, Equatable {
    var progress: AudiobookProgress?
    var stale: Bool

    enum CodingKeys: String, CodingKey { case progress, stale }

    init(progress: AudiobookProgress? = nil, stale: Bool = false) {
        self.progress = progress
        self.stale = stale
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        progress = try? c.decodeIfPresent(AudiobookProgress.self, forKey: .progress)
        stale = c.lenientBool(.stale) ?? false
    }
}

struct AudiobookBookmarkEnvelope: Decodable, Sendable {
    var bookmark: AudiobookBookmark
}

/// Admin: `GET /audiobooks/inbox`. Every field optional, both lists lossy.
struct AudiobookInbox: Decodable, Sendable, Equatable {
    var pending: [AudiobookInboxEntry]
    var failed: [AudiobookInboxEntry]

    enum CodingKeys: String, CodingKey { case pending, failed }

    init(pending: [AudiobookInboxEntry] = [], failed: [AudiobookInboxEntry] = []) {
        self.pending = pending
        self.failed = failed
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        pending = c.lossyArray(AudiobookInboxEntry.self, .pending)
        failed = c.lossyArray(AudiobookInboxEntry.self, .failed)
    }
}

struct AudiobookInboxEntry: Decodable, Sendable, Equatable, Hashable {
    var name: String?
    var sizeBytes: Int64?
    var status: String?
    var state: String?
    var kind: String?
    var note: String?
    var reason: String?
    var modifiedAt: String?
    var failedAt: String?

    enum CodingKeys: String, CodingKey { case name, sizeBytes, status, state, kind, note, reason, modifiedAt, failedAt }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        name = c.lenientString(.name)
        sizeBytes = c.lenientInt64(.sizeBytes)
        status = c.lenientString(.status)
        state = c.lenientString(.state)
        kind = c.lenientString(.kind)
        note = c.lenientString(.note)
        reason = c.lenientString(.reason)
        modifiedAt = c.lenientString(.modifiedAt)
        failedAt = c.lenientString(.failedAt)
    }
}

/// POST /novels/{id}/audiobook/ask: {asked, message}.
struct AudiobookAskAnswer: Decodable, Sendable {
    var asked: Bool
    var message: String?

    enum CodingKeys: String, CodingKey { case asked, message }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        asked = c.lenientBool(.asked) ?? false
        message = c.lenientString(.message)
    }
}

/// The reader-upload resource (`/audiobook-uploads`, AudiobookUploadController::payload):
/// its id, chunk size, status and which chunks the server already has, per file.
struct AudiobookUploadResource: Codable, Sendable, Equatable, Hashable {
    struct File: Codable, Sendable, Equatable, Hashable {
        var index: Int
        var name: String
        var size: Int64
        /// How many chunks the file is cut into.
        var chunks: Int
        /// Chunk numbers the server has.
        var received: [Int]
        var done: Bool

        enum CodingKeys: String, CodingKey { case index, name, size, chunks, received, done }

        init(index: Int, name: String = "", size: Int64 = 0, chunks: Int = 0, received: [Int] = [], done: Bool = false) {
            self.index = index
            self.name = name
            self.size = size
            self.chunks = chunks
            self.received = received
            self.done = done
        }

        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            guard let index = c.lenientInt(.index) else {
                throw DecodingError.keyNotFound(CodingKeys.index, .init(codingPath: c.codingPath, debugDescription: "file.index"))
            }
            self.index = index
            name = c.lenientString(.name) ?? ""
            size = c.lenientInt64(.size) ?? 0
            chunks = c.lenientInt(.chunks) ?? 0
            received = c.lossyArray(Int.self, .received).filter { $0 >= 0 }
            done = c.lenientBool(.done) ?? false
        }
    }

    var id: Int
    /// uploading | processing | pending | approved | declined | failed | cancelled (anything new kept as text).
    var status: String
    var statusLabel: String?
    var title: String?
    var author: String?
    var durationMs: Int64?
    var novelId: Int?
    var note: String?
    var chunkSize: Int64
    var totalBytes: Int64
    var receivedBytes: Int64
    var files: [File]
    var error: String?
    var declineReason: String?
    var audiobookId: Int?
    var createdAt: String?
    var updatedAt: String?

    enum CodingKeys: String, CodingKey {
        case id, status, statusLabel, title, author, durationMs, novelId, note, chunkSize, totalBytes
        case receivedBytes, files, error, declineReason, audiobookId, createdAt, updatedAt
    }

    init(id: Int, status: String = "uploading", statusLabel: String? = nil, title: String? = nil, author: String? = nil,
         durationMs: Int64? = nil, novelId: Int? = nil, note: String? = nil, chunkSize: Int64 = 8 * 1024 * 1024,
         totalBytes: Int64 = 0, receivedBytes: Int64 = 0, files: [File] = [], error: String? = nil,
         declineReason: String? = nil, audiobookId: Int? = nil, createdAt: String? = nil, updatedAt: String? = nil) {
        self.id = id
        self.status = status
        self.statusLabel = statusLabel
        self.title = title
        self.author = author
        self.durationMs = durationMs
        self.novelId = novelId
        self.note = note
        self.chunkSize = chunkSize
        self.totalBytes = totalBytes
        self.receivedBytes = receivedBytes
        self.files = files
        self.error = error
        self.declineReason = declineReason
        self.audiobookId = audiobookId
        self.createdAt = createdAt
        self.updatedAt = updatedAt
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(CodingKeys.id, .init(codingPath: c.codingPath, debugDescription: "upload.id"))
        }
        self.id = id
        status = c.lenientString(.status) ?? ""
        statusLabel = c.lenientString(.statusLabel)
        title = c.lenientString(.title)
        author = c.lenientString(.author)
        durationMs = c.lenientInt64(.durationMs)
        novelId = c.lenientInt(.novelId)
        note = c.lenientString(.note)
        let chunk = c.lenientInt64(.chunkSize) ?? 0
        chunkSize = chunk > 0 ? chunk : 8 * 1024 * 1024
        totalBytes = c.lenientInt64(.totalBytes) ?? 0
        receivedBytes = c.lenientInt64(.receivedBytes) ?? 0
        files = c.lossyArray(File.self, .files).sorted { $0.index < $1.index }
        error = c.lenientString(.error)
        declineReason = c.lenientString(.declineReason)
        audiobookId = c.lenientInt(.audiobookId)
        createdAt = c.lenientString(.createdAt)
        updatedAt = c.lenientString(.updatedAt)
    }
}

/// `{upload: {...}}`, or the resource at the top level.
struct AudiobookUploadEnvelope: Decodable, Sendable {
    var upload: AudiobookUploadResource?
    enum CodingKeys: String, CodingKey { case upload }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        upload = try? c.decodeIfPresent(AudiobookUploadResource.self, forKey: .upload)
    }
}

/// "Series #N" from a series name and a loose index ("1", 1, 1.5).
enum SeriesLabel {
    static func make(series: String?, index: String?) -> String? {
        guard let name = series?.trimmingCharacters(in: .whitespacesAndNewlines), !name.isEmpty else { return nil }
        guard let raw = index?.trimmingCharacters(in: .whitespacesAndNewlines), !raw.isEmpty else { return name }
        let number: String
        if let value = Double(raw), value.isFinite {
            number = value == value.rounded() ? String(Int64(value)) : String(value)
        } else {
            number = raw
        }
        return "\(name) #\(number)"
    }
}
