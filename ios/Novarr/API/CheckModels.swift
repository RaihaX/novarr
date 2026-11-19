import Foundation

// Check for new chapters. Same lenient decoding as Models.swift: identities
// are required, everything else has a fallback.

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
