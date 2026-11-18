import Foundation

/// `GET novels/{id}/position`: where this series was last read, on any device.
struct PositionEnvelope: Decodable, Sendable {
    var position: ReadingPosition?
}

struct ReadingPosition: Decodable, Sendable, Hashable {
    var chapterId: Int
    var chapterLabel: String?
    var chapterNumber: String?
    /// 0–100; null when the server only knows the chapter.
    var progress: Int?
    var downloaded: Bool?
    var deviceId: String?
    var deviceName: String?
    var at: Date?
    /// Seconds since `at` by the server's clock; compare against this, not wall clocks.
    var ageSeconds: Int?

    enum CodingKeys: String, CodingKey {
        case chapterId, chapterLabel, chapterNumber, progress, downloaded
        case deviceId, deviceName, at, ageSeconds
    }

    init(chapterId: Int, chapterLabel: String? = nil, chapterNumber: String? = nil, progress: Int? = nil,
         downloaded: Bool? = nil, deviceId: String? = nil, deviceName: String? = nil,
         at: Date? = nil, ageSeconds: Int? = nil) {
        self.chapterId = chapterId
        self.chapterLabel = chapterLabel
        self.chapterNumber = chapterNumber
        self.progress = progress
        self.downloaded = downloaded
        self.deviceId = deviceId
        self.deviceName = deviceName
        self.at = at
        self.ageSeconds = ageSeconds
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let chapterId = c.lenientInt(.chapterId) else {
            throw DecodingError.keyNotFound(
                CodingKeys.chapterId, .init(codingPath: decoder.codingPath, debugDescription: "Position without chapter_id"))
        }
        self.chapterId = chapterId
        chapterLabel = c.lenientString(.chapterLabel).flatMap { $0.isEmpty ? nil : $0 }
        chapterNumber = c.lenientString(.chapterNumber).flatMap { $0.isEmpty ? nil : $0 }
        progress = c.lenientInt(.progress)
        downloaded = c.lenientBool(.downloaded)
        deviceId = c.lenientString(.deviceId).flatMap { $0.isEmpty ? nil : $0 }
        deviceName = c.lenientString(.deviceName).flatMap { $0.isEmpty ? nil : $0 }
        at = c.lenientDate(.at)
        ageSeconds = c.lenientInt(.ageSeconds)
    }
}
