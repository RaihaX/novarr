import Foundation

/// What the app says about a check for new chapters.
enum CheckDisplay: Equatable, Sendable {
    /// Running on the server; `found` chapters have been listed so far.
    case checking(found: Int)
    case found(Int)
    case noneYet
    /// The check failed on the server (its message) or couldn't be started.
    case failed(String)
    /// Still running after the app stopped watching (10 minutes).
    case stillRunning

    var isChecking: Bool {
        if case .checking = self { return true }
        return false
    }

    var text: String {
        switch self {
        case .checking(let found):
            found > 0 ? "Checking… \(Self.chapters(found)) so far" : "Checking for new chapters…"
        case .found(let count): Self.chapters(count)
        case .noneYet: "No new chapters yet"
        case .failed(let message): message
        case .stillRunning: "Still checking on the server. Look again in a while."
        }
    }

    static func chapters(_ count: Int) -> String {
        count == 1 ? "1 new chapter" : "\(count.formatted()) new chapters"
    }
}

/// The polling rules for one check, as a pure state machine: feed it each
/// answer from the server with the current time; it says what to show and
/// whether to keep polling. The runner (`ChapterChecks`) does the sleeping.
struct CheckPolling: Equatable, Sendable {
    /// Seconds between GETs while the check runs.
    static let interval: TimeInterval = 15
    /// How long the app keeps watching a running check.
    static let limit: TimeInterval = 600
    /// Request failures in a row before giving up (a blip shouldn't end a check).
    static let maxConsecutiveErrors = 3

    enum Step: Equatable, Sendable {
        case poll(after: TimeInterval)
        case finished
    }

    /// When the app started watching.
    let startedAt: Date
    private(set) var display: CheckDisplay = .checking(found: 0)
    private(set) var lastChaptersNow: Int?
    private(set) var consecutiveErrors = 0
    /// True when the last answer listed a different number of chapters, so
    /// the novel's chapter list should be reloaded.
    private(set) var chaptersChanged = false

    init(startedAt: Date) {
        self.startedAt = startedAt
    }

    /// Whether to POST a new check given the status before starting one: a
    /// check that is already running is joined instead.
    static func shouldStart(after current: NovelCheck?) -> Bool {
        current?.status != .running
    }

    /// Applies one check object (from the POST or a GET).
    mutating func receive(_ check: NovelCheck, at now: Date) -> Step {
        consecutiveErrors = 0
        chaptersChanged = lastChaptersNow.map { $0 != check.chaptersNow } ?? false
        lastChaptersNow = check.chaptersNow

        switch check.status {
        case .done:
            display = check.newChapters > 0 ? .found(check.newChapters) : .noneYet
            chaptersChanged = chaptersChanged || check.newChapters > 0
            return .finished
        case .failed:
            display = .failed(check.message ?? "The check failed on the server.")
            return .finished
        case .idle:
            // The server forgot it (results are kept 24 h): nothing to report.
            display = check.newChapters > 0 ? .found(check.newChapters) : .noneYet
            return .finished
        case .running, .other:
            display = .checking(found: check.newChapters)
            return keepWatching(at: now)
        }
    }

    /// Applies a failed request. Gives up after a few in a row.
    mutating func receive(error message: String, at now: Date) -> Step {
        consecutiveErrors += 1
        chaptersChanged = false
        guard consecutiveErrors < Self.maxConsecutiveErrors else {
            display = .failed(message)
            return .finished
        }
        return keepWatching(at: now)
    }

    private mutating func keepWatching(at now: Date) -> Step {
        if now.timeIntervalSince(startedAt) >= Self.limit {
            display = .stillRunning
            return .finished
        }
        return .poll(after: Self.interval)
    }
}
