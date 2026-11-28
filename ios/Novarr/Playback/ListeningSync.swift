import Foundation

/// A whole-book position as recorded on this device. `recordedAt` is epoch ms (sent as recorded_at).
struct ListeningRecord: Codable, Sendable, Equatable, Hashable {
    var audiobookId: Int
    var positionMs: Int64
    var speed: Double = 1
    var finished = false
    var recordedAt: Int64
}

/// The rules for listening progress, pure: last write wins by when a position
/// was recorded, on this device and between this device and the server.
/// Mirrors android domain/ListeningSync.kt.
enum ListeningSync {
    /// Where to resume, and whose position that was.
    struct Resolved: Sendable, Equatable {
        var positionMs: Int64
        var finished: Bool
        var fromLocal: Bool
    }

    /// Within this much of the end, a book counts as listened to: playing again starts over.
    static let endSlackMs: Int64 = 5_000

    /// While playing, a position is recorded (and sent) this often.
    static let syncIntervalMs: Int64 = 15_000

    /// The newer of this device's last position and the server's (by recorded
    /// time). A server time that can't be read loses to a local position; a tie
    /// goes to this device (it may not be sent yet).
    static func resolve(_ local: ListeningRecord?, _ server: AudiobookProgress?) -> Resolved {
        let serverAt = server?.updatedAt.flatMap(parseInstant)
        switch (local, server) {
        case (nil, nil):
            return Resolved(positionMs: 0, finished: false, fromLocal: false)
        case (let local?, nil):
            return Resolved(positionMs: local.positionMs, finished: local.finished, fromLocal: true)
        case (nil, let server?):
            return Resolved(positionMs: server.positionMs, finished: server.finished, fromLocal: false)
        case (let local?, let server?):
            if let serverAt, local.recordedAt < serverAt {
                return Resolved(positionMs: server.positionMs, finished: server.finished, fromLocal: false)
            }
            return Resolved(positionMs: local.positionMs, finished: local.finished, fromLocal: true)
        }
    }

    /// Where playback starts: the resolved position, or the beginning for a finished book.
    static func startPosition(_ resolved: Resolved, totalMs: Int64) -> Int64 {
        if resolved.finished { return 0 }
        if totalMs > 0, resolved.positionMs >= totalMs - endSlackMs { return 0 }
        return min(max(resolved.positionMs, 0), totalMs > 0 ? totalMs : Int64.max)
    }

    /// Whether `incoming` should replace `current` as an audiobook's position on
    /// this device: only a position recorded at the same time or later, so a
    /// late, older write never moves it back.
    static func supersedes(_ incoming: ListeningRecord, _ current: ListeningRecord?) -> Bool {
        guard let current else { return true }
        return incoming.recordedAt >= current.recordedAt
    }

    static func nowMs() -> Int64 { Int64((Date().timeIntervalSince1970 * 1000).rounded()) }

    /// ISO 8601 in UTC with a Z, as Java's Instant.toString(): milliseconds only when there are some.
    static func isoOf(_ epochMs: Int64) -> String {
        let date = Date(timeIntervalSince1970: Double(epochMs) / 1000)
        let formatter = ISO8601DateFormatter()
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        formatter.formatOptions = epochMs % 1000 == 0 ? [.withInternetDateTime] : [.withInternetDateTime, .withFractionalSeconds]
        return formatter.string(from: date)
    }

    /// Epoch ms from an ISO 8601 time with a zone (Z or an offset), with or without fractions.
    static func parseInstant(_ iso: String) -> Int64? {
        let text = iso.trimmingCharacters(in: .whitespaces)
        let plain = ISO8601DateFormatter()
        plain.formatOptions = [.withInternetDateTime]
        let fractional = ISO8601DateFormatter()
        fractional.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        guard let date = fractional.date(from: text) ?? plain.date(from: text) else { return nil }
        return Int64((date.timeIntervalSince1970 * 1000).rounded())
    }

    /// "1:02:03" / "2:03" for a duration or position.
    static func clock(_ ms: Int64) -> String {
        let total = max(ms, 0) / 1000
        let h = total / 3600, m = (total % 3600) / 60, s = total % 60
        return h > 0 ? String(format: "%lld:%02lld:%02lld", h, m, s) : String(format: "%lld:%02lld", m, s)
    }

    /// "11 h 32 min" / "45 min" for a book's length.
    static func length(_ ms: Int64) -> String {
        let minutes = (max(ms, 0) + 30_000) / 60_000
        let h = minutes / 60, m = minutes % 60
        if h == 0 { return "\(m) min" }
        if m == 0 { return "\(h) h" }
        return "\(h) h \(m) min"
    }

    /// "1.25×" (two decimals at most, trailing zeros dropped).
    static func speedLabel(_ speed: Double) -> String {
        var text = String(format: "%.2f", locale: Locale(identifier: "en_US_POSIX"), speed)
        while text.hasSuffix("0") { text.removeLast() }
        if text.hasSuffix(".") { text.removeLast() }
        return "\(text)×"
    }
}

/// Settings › Listening (global, like Android's AppPrefs.listening).
struct ListeningPrefs: Sendable, Equatable {
    static let defaultSkipBack = 15
    static let defaultSkipForward = 30
    static let skipChoices = [10, 15, 30, 45, 60]
    static let minSpeed = 0.5
    static let maxSpeed = 3.0
    static let speedStep = 0.05
    static let speedPresets: [Double] = [0.75, 1, 1.25, 1.5, 1.75, 2, 2.5, 3]

    var skipBackSeconds = ListeningPrefs.defaultSkipBack
    var skipForwardSeconds = ListeningPrefs.defaultSkipForward
    var speed: Double = 1
    /// Audiobook downloads wait for Wi-Fi (a network that isn't expensive).
    var wifiOnlyDownloads = true
    /// Audiobook uploads wait for Wi-Fi.
    var wifiOnlyUploads = true

    static func validSkip(_ seconds: Int?, default fallback: Int) -> Int {
        guard let seconds, skipChoices.contains(seconds) else { return fallback }
        return seconds
    }

    /// Clamped to 0.5–3.0 and snapped to 0.05 steps.
    static func validSpeed(_ speed: Double) -> Double {
        guard speed.isFinite else { return 1 }
        let clamped = min(max(speed, minSpeed), maxSpeed)
        let snapped = (clamped / speedStep).rounded() * speedStep
        return (snapped * 100).rounded() / 100
    }

    private enum Key {
        static let skipBack = "listening.skipBack"
        static let skipForward = "listening.skipForward"
        static let speed = "listening.speed"
        static let wifiOnlyDownloads = "listening.wifiOnlyDownloads"
        static let wifiOnlyUploads = "listening.wifiOnlyUploads"
    }

    static func load(_ defaults: UserDefaults = .standard) -> ListeningPrefs {
        var prefs = ListeningPrefs()
        prefs.skipBackSeconds = validSkip(defaults.object(forKey: Key.skipBack) as? Int, default: defaultSkipBack)
        prefs.skipForwardSeconds = validSkip(defaults.object(forKey: Key.skipForward) as? Int, default: defaultSkipForward)
        if let speed = defaults.object(forKey: Key.speed) as? Double { prefs.speed = validSpeed(speed) }
        if let on = defaults.object(forKey: Key.wifiOnlyDownloads) as? Bool { prefs.wifiOnlyDownloads = on }
        if let on = defaults.object(forKey: Key.wifiOnlyUploads) as? Bool { prefs.wifiOnlyUploads = on }
        return prefs
    }

    func save(_ defaults: UserDefaults = .standard) {
        defaults.set(skipBackSeconds, forKey: Key.skipBack)
        defaults.set(skipForwardSeconds, forKey: Key.skipForward)
        defaults.set(speed, forKey: Key.speed)
        defaults.set(wifiOnlyDownloads, forKey: Key.wifiOnlyDownloads)
        defaults.set(wifiOnlyUploads, forKey: Key.wifiOnlyUploads)
    }
}
