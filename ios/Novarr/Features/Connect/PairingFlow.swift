import Foundation

/// "Link with a code instead", as a pure state machine (the KOReader plugin's
/// `Auth.Pairing` is the reference): ask for a code, show it, poll its secret
/// every `interval` seconds until it is linked or runs out. A poll that gets
/// no real answer (offline, 5xx, 429) just waits for the next one. The runner
/// (`AppModel`) does the requests and the sleeping.
struct PairingFlow: Equatable, Sendable {
    static let defaultInterval: TimeInterval = 3
    static let intervalRange: ClosedRange<TimeInterval> = 2...30
    /// Seconds a code lives when the server gives no number.
    static let defaultExpiresIn: TimeInterval = 600

    enum Phase: Equatable, Sendable {
        /// Asking the server for a code.
        case starting
        /// The code is on screen; polling.
        case waiting
        /// The code ran out (or the server forgot it): offer a new one.
        case expired
        /// Asking for a code failed, or a poll got an answer that ends it.
        case failed(String)
        /// Someone entered the code; the token was handed over.
        case linked
    }

    enum Step: Equatable, Sendable {
        case poll(after: TimeInterval)
        case finished
    }

    /// The server the code was asked from.
    let server: URL
    private(set) var phase: Phase = .starting
    private(set) var secret: String?
    /// The code as shown: "K7M 4QX".
    private(set) var code = ""
    private(set) var interval = Self.defaultInterval
    private(set) var expiresAt: Date?

    init(server: URL) {
        self.server = server
    }

    /// Starting or waiting: a code is being asked for or is on screen.
    var isActive: Bool { phase == .starting || phase == .waiting }

    static func clampInterval(_ seconds: Int?) -> TimeInterval {
        guard let seconds else { return defaultInterval }
        return min(max(TimeInterval(seconds), intervalRange.lowerBound), intervalRange.upperBound)
    }

    /// "k7m4qx" → "K7M 4QX"; any other length as given, upper-cased.
    static func formatCode(_ raw: String) -> String {
        let code = raw.uppercased().filter { !$0.isWhitespace }
        guard code.count == 6 else { return code }
        return String(code.prefix(3)) + " " + String(code.suffix(3))
    }

    mutating func started(_ start: PairingStart, at now: Date) -> Step {
        guard phase == .starting else { return .finished }
        secret = start.secret
        code = Self.formatCode(start.code)
        interval = Self.clampInterval(start.interval)
        let lifetime = start.expiresIn.flatMap { $0 > 0 ? TimeInterval($0) : nil } ?? Self.defaultExpiresIn
        expiresAt = now.addingTimeInterval(lifetime)
        phase = .waiting
        return .poll(after: nextDelay(at: now))
    }

    mutating func startFailed(_ error: APIError) -> Step {
        guard phase == .starting else { return .finished }
        phase = .failed(Self.startMessage(for: error))
        return .finished
    }

    /// Ends the wait when the code's time is up; true if it did.
    mutating func expireIfDue(at now: Date) -> Bool {
        guard phase == .waiting, let expiresAt, now >= expiresAt else { return false }
        phase = .expired
        return true
    }

    mutating func received(_ poll: PairingPoll, at now: Date) -> Step {
        guard phase == .waiting else { return .finished }
        switch poll {
        case .linked:
            phase = .linked
            return .finished
        case .expired:
            phase = .expired
            return .finished
        case .pending:
            return keepWaiting(at: now)
        }
    }

    mutating func pollFailed(_ error: APIError, at now: Date) -> Step {
        guard phase == .waiting else { return .finished }
        // Offline, 5xx, 408 and 429 are no answer this time: keep polling.
        guard error.isRetryable else {
            phase = .failed(error.localizedDescription)
            return .finished
        }
        return keepWaiting(at: now)
    }

    private mutating func keepWaiting(at now: Date) -> Step {
        if expireIfDue(at: now) { return .finished }
        return .poll(after: nextDelay(at: now))
    }

    /// The poll interval, cut short so the code's expiry shows on time.
    func nextDelay(at now: Date) -> TimeInterval {
        guard let expiresAt else { return interval }
        return max(0, min(interval, expiresAt.timeIntervalSince(now)))
    }

    static func startMessage(for error: APIError) -> String {
        switch error {
        case .unreachable, .timedOut:
            "No connection. Try again."
        case .http(429, let message):
            message.flatMap { $0.isEmpty ? nil : $0 } ?? "Too many codes were asked for. Wait a while and try again."
        case .http(404, _):
            "This server can't link devices with a code. Update the server."
        default:
            error.localizedDescription
        }
    }

    // MARK: Screen texts

    /// The server as the person types it into a browser: host, and port if any.
    static func serverName(_ url: URL) -> String {
        let host = url.host(percentEncoded: false) ?? url.absoluteString
        return url.port.map { "\(host):\($0)" } ?? host
    }

    var instructions: String {
        "On a phone or computer where you are signed in, open \(Self.serverName(server)) → Account → Link a device and enter this code."
    }

    func validUntilText(timeZone: TimeZone = .current, locale: Locale = .current) -> String? {
        guard let expiresAt else { return nil }
        var style = Date.FormatStyle(date: .omitted, time: .shortened)
        style.timeZone = timeZone
        style.locale = locale
        return "This code works until \(expiresAt.formatted(style))."
    }
}
