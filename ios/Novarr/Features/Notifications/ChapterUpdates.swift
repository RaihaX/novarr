import Foundation

/// The one combined "new chapters" notification, as pure logic: what the
/// background check keeps between runs (`State`), how a server answer is
/// merged into it, what the notification says, and whether it may make a
/// sound. Mirrors android domain/ChapterUpdates.kt.
///
/// Never spam: there is exactly one notification; it is re-posted only when
/// its content changed; it alerts at most once per `alertSpacing` and never
/// in quiet hours (22:00–07:00 local time), when it is posted silently.
enum ChapterUpdates {
    /// At most one sound per 6 hours.
    static let alertSpacing: TimeInterval = 6 * 3600
    /// Expanded lines before "+N more".
    static let maxLines = 6
    /// Names in the collapsed text before "and N more".
    static let maxNames = 3

    /// A local wall-clock time (hours 0–23).
    struct ClockTime: Equatable, Comparable, Sendable {
        var hour: Int
        var minute: Int = 0

        static func < (a: ClockTime, b: ClockTime) -> Bool { (a.hour, a.minute) < (b.hour, b.minute) }

        static func now(_ date: Date = Date(), calendar: Calendar = .current) -> ClockTime {
            let parts = calendar.dateComponents([.hour, .minute], from: date)
            return ClockTime(hour: parts.hour ?? 12, minute: parts.minute ?? 0)
        }
    }

    static let quietStart = ClockTime(hour: 22)
    static let quietEnd = ClockTime(hour: 7)

    /// A series waiting in the notification.
    struct Pending: Codable, Equatable, Sendable {
        var novelId: Int
        var name: String
        var medium: String = Medium.novel
        var newCount: Int
        var chapterId: Int? = nil
        var latestAt: String? = nil
    }

    /// Everything kept between runs, for one server and account (`scope`).
    struct State: Codable, Equatable, Sendable {
        var scope: String = ""
        var cursor: String? = nil
        var pending: [Pending] = []
        /// Seconds since 1970 of the last alert.
        var lastAlertAt: TimeInterval? = nil

        /// For another server or account this state means nothing: start over.
        func forScope(_ current: String) -> State { scope == current ? self : State(scope: current) }

        /// Seen (the app opened, the notification tapped or dismissed): nothing waits any more.
        func cleared() -> State {
            var copy = self
            copy.pending = []
            return copy
        }
    }

    enum Action: Equatable {
        case none
        /// Post (or update) the one notification; `alert` false posts it silently.
        case post(alert: Bool)
    }

    struct Outcome: Equatable {
        var state: State
        var action: Action
    }

    /// Applies one answer from `GET /updates`. The first run (no cursor yet)
    /// only stores the cursor. In the `foreground` the app shows new chapters
    /// itself (Home), so the cursor moves but nothing is added. Otherwise the
    /// series are merged and the notification is posted when that changed
    /// anything, alerting only when allowed and it isn't `showing` already.
    static func receive(_ state: State, answer: UpdatesFeed, now: TimeInterval, localTime: ClockTime,
                        foreground: Bool, showing: Bool = false) -> Outcome {
        let fresh = answer.cursor.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 }
        var next = state
        next.cursor = fresh ?? state.cursor
        if state.cursor == nil || foreground { return Outcome(state: next, action: .none) }
        let merged = merge(state.pending, answer.series)
        if merged == state.pending { return Outcome(state: next, action: .none) }
        let alert = !showing && alertAllowed(lastAlertAt: state.lastAlertAt, now: now, localTime: localTime)
        next.pending = merged
        if alert { next.lastAlertAt = now }
        return Outcome(state: next, action: .post(alert: alert))
    }

    /// Adds the answer's series to those pending; a series already pending is
    /// replaced by the newer answer. Series with nothing new are ignored.
    static func merge(_ pending: [Pending], _ answer: [UpdatesFeed.Series]) -> [Pending] {
        var order = pending.map(\.novelId)
        var byId: [Int: Pending] = [:]
        for item in pending { byId[item.novelId] = item }
        for series in answer where series.newCount > 0 {
            let name = series.name.trimmingCharacters(in: .whitespaces).isEmpty
                ? (byId[series.novelId]?.name ?? "A series") : series.name
            if byId[series.novelId] == nil { order.append(series.novelId) }
            byId[series.novelId] = Pending(novelId: series.novelId, name: name, medium: series.medium,
                                           newCount: series.newCount, chapterId: series.chapterId,
                                           latestAt: series.latestAt)
        }
        var seen = Set<Int>()
        return order.filter { seen.insert($0).inserted }.compactMap { byId[$0] }
    }

    /// Most recent first (by `latest_at`; undated last), then by name.
    static func ordered(_ pending: [Pending]) -> [Pending] {
        pending.sorted { a, b in
            let da = Requests.instant(a.latestAt) ?? .distantPast
            let db = Requests.instant(b.latestAt) ?? .distantPast
            if da != db { return da > db }
            return a.name.localizedCaseInsensitiveCompare(b.name) == .orderedAscending
        }
    }

    /// Not in quiet hours, and not within 6 hours of the last one.
    static func alertAllowed(lastAlertAt: TimeInterval?, now: TimeInterval, localTime: ClockTime) -> Bool {
        if isQuiet(localTime) { return false }
        guard let last = lastAlertAt, now >= last else { return true }
        return now - last >= alertSpacing
    }

    /// 22:00 up to (not including) 07:00.
    static func isQuiet(_ time: ClockTime) -> Bool { time >= quietStart || time < quietEnd }

    // MARK: Text

    /// One series: its name; several: "New chapters in 4 series".
    static func title(_ pending: [Pending]) -> String {
        pending.count == 1 ? pending[0].name : "New chapters in \(pending.count) series"
    }

    /// One series: "3 new chapters"; several: "A, B, C and 1 more" (most recent first).
    static func text(_ pending: [Pending]) -> String {
        if pending.count == 1 { return countText(pending[0].newCount) }
        return names(ordered(pending).map(\.name))
    }

    static func countText(_ count: Int) -> String { count == 1 ? "1 new chapter" : "\(count) new chapters" }

    /// "A and B", "A, B and C", "A, B, C and 4 more".
    static func names(_ names: [String]) -> String {
        switch names.count {
        case 0: return ""
        case 1: return names[0]
        case 2...maxNames: return names.dropLast().joined(separator: ", ") + " and " + names.last!
        default: return names.prefix(maxNames).joined(separator: ", ") + " and \(names.count - maxNames) more"
        }
    }

    /// The expanded lines: "A · 3 new", most recent first, at most `maxLines` then "+N more".
    static func lines(_ pending: [Pending]) -> [String] {
        let sorted = ordered(pending)
        let shown = sorted.prefix(maxLines).map { "\($0.name) · \($0.newCount) new" }
        let rest = sorted.count - shown.count
        return rest > 0 ? shown + ["+\(rest) more"] : Array(shown)
    }

    /// Tapping opens this series (one pending), else Home (nil).
    static func target(_ pending: [Pending]) -> Int? { pending.count == 1 ? pending[0].novelId : nil }
}
