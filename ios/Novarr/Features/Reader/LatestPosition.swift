import Foundation

/// The "read more recently on another device" prompt: the contract's client
/// rule as pure functions, so it can be tested without a reader on screen.
enum LatestPosition {
    /// Same-chapter positions closer than this (in percent points) aren't worth a prompt.
    static let minimumProgressGap = 5

    /// The contract's client rule. `localProgress` is this device's position in
    /// the opened chapter (0–100, nil meaning the top).
    static func shouldOffer(position: ReadingPosition?, deviceID: String, chapterID: Int,
                            localProgress: Int?, localNewer: Bool, declinedAt: Date?,
                            canOpen: Bool) -> Bool {
        // 1. Someone else (a known device) holds the latest position.
        guard let position, let other = position.deviceId, other != deviceID else { return false }
        // 2. Nothing read here since then is still waiting to be sent.
        guard !localNewer else { return false }
        // 3. It's somewhere else. Same chapter without a percentage means "here".
        if position.chapterId == chapterID {
            guard let theirs = position.progress,
                  abs(theirs - (localProgress ?? 0)) >= minimumProgressGap else { return false }
        }
        // 4. Not the exact position the user already turned down (or an older one).
        if let declinedAt {
            guard let at = position.at, at > declinedAt else { return false }
        }
        // 5. We can actually open it.
        return canOpen
    }

    /// Rule 2: any local read-state write for the series recorded fewer than
    /// `ageSeconds` ago is newer than the server's position. Only differences
    /// against this device's clock are used.
    static func localIsNewer(localWriteTimes: [Date], ageSeconds: Int?, now: Date) -> Bool {
        guard let ageSeconds else { return false }
        return localWriteTimes.contains { now.timeIntervalSince($0) < Double(ageSeconds) }
    }

    /// "Chapter 124: The Gate · 40% was read on Kindle 2 hours ago. Go there?"
    static func message(for position: ReadingPosition) -> String {
        let chapter = position.chapterLabel
            ?? position.chapterNumber.map { "Chapter \($0)" }
            ?? "Another chapter"
        let percent = position.progress.flatMap { (1...99).contains($0) ? " · \($0)%" : nil } ?? ""
        let device = position.deviceName ?? "another device"
        let when = position.ageSeconds.map(relativeTime) ?? "recently"
        return "\(chapter)\(percent) was read on \(device) \(when). Go there?"
    }

    /// "just now", "5 minutes ago", "yesterday", "3 weeks ago"…
    static func relativeTime(ageSeconds: Int) -> String {
        let seconds = max(0, ageSeconds)
        let minute = 60, hour = 3_600, day = 86_400, week = 604_800, month = 2_592_000, year = 31_536_000
        switch seconds {
        case ..<minute: return "just now"
        case ..<hour: return ago(seconds / minute, "minute")
        case ..<day: return ago(seconds / hour, "hour")
        case ..<(2 * day): return "yesterday"
        case ..<week: return ago(seconds / day, "day")
        case ..<month: return ago(seconds / week, "week")
        case ..<year: return ago(max(1, seconds / month), "month")
        default: return ago(seconds / year, "year")
        }
    }

    private static func ago(_ count: Int, _ unit: String) -> String {
        "\(count) \(unit)\(count == 1 ? "" : "s") ago"
    }

    /// Paragraph to show for a 0–100 position; nil means the top.
    static func paragraphIndex(progress: Int, paragraphCount: Int) -> Int? {
        guard progress > 0, paragraphCount > 1 else { return nil }
        let fraction = Double(min(progress, 100)) / 100
        return Int((fraction * Double(paragraphCount - 1)).rounded())
    }
}

/// Positions the user answered "Stay here" to, per server and series, so the
/// same one isn't offered again.
struct LatestPositionDeclines {
    static let defaultsKey = "latestPositionDeclined"

    let defaults: UserDefaults
    let server: URL?
    /// The signed-in user; nil with auth off (keeps the keys from before accounts).
    var userId: Int? = nil

    func declinedAt(novelId: Int) -> Date? {
        let stored = defaults.dictionary(forKey: Self.defaultsKey) as? [String: Double]
        return stored?[key(novelId)].map { Date(timeIntervalSince1970: $0) }
    }

    func decline(_ at: Date, novelId: Int) {
        var stored = (defaults.dictionary(forKey: Self.defaultsKey) as? [String: Double]) ?? [:]
        stored[key(novelId)] = at.timeIntervalSince1970
        defaults.set(stored, forKey: Self.defaultsKey)
    }

    private func key(_ novelId: Int) -> String {
        let account = userId.map { "|user-\($0)" } ?? ""
        return "\(server?.absoluteString ?? "")\(account)#\(novelId)"
    }
}

extension PendingWrite.Kind {
    /// Writes that move the series' latest position on the server (bulk
    /// marking and marking unread don't).
    var movesPosition: Bool {
        switch self {
        case .progress(_, let progress, let read): progress != nil || read
        case .setRead(_, let read): read
        case .bulkRead: false
        }
    }
}
