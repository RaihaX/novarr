import Foundation

/// The player's sleep timer, as pure state: off, a duration (counted on a
/// monotonic clock, so a clock change can't end it early), or the end of the
/// chapter playing when it was set. The player ticks it (`decide`) about
/// once a second. Mirrors android domain/SleepTimer.kt.
enum SleepTimer: Sendable, Equatable {
    case off
    /// Stops at `endsAt` (monotonic ms). Counted while paused too, like most players.
    case duration(endsAt: Int64, minutes: Int)
    /// Stops when playback reaches `chapterEndMs` (whole-book position).
    case endOfChapter(chapterEndMs: Int64)

    /// What a tick says to do.
    enum Decision: Sendable, Equatable {
        case keep
        /// Lower the volume to this (0...1): the last seconds fade out.
        case fade(Float)
        /// Pause now, at full volume again, and switch the timer off.
        case stop
    }

    static let minMinutes = 5
    static let maxMinutes = 120
    /// The choices in the player's menu.
    static let presetMinutes = [5, 10, 15, 30, 45, 60, 90, 120]
    /// The fade before a duration timer stops.
    static let fadeMs: Int64 = 10_000
    /// A chapter end this close counts as reached (ticks are a second apart).
    static let chapterEndSlackMs: Int64 = 400

    /// Milliseconds on a monotonic clock (uptime, not wall time).
    static func monotonicNow() -> Int64 { Int64((ProcessInfo.processInfo.systemUptime * 1000).rounded()) }

    static func minutes(_ minutes: Int, now: Int64) -> SleepTimer {
        let m = min(max(minutes, minMinutes), maxMinutes)
        return .duration(endsAt: now + Int64(m) * 60_000, minutes: m)
    }

    static func endOfChapter(_ timeline: AudiobookTimeline, positionMs: Int64) -> SleepTimer {
        .endOfChapter(chapterEndMs: timeline.chapterEndAt(positionMs))
    }

    var isOff: Bool { self == .off }

    var durationMinutes: Int? {
        if case .duration(_, let minutes) = self { return minutes }
        return nil
    }

    var endsAt: Int64? {
        if case .duration(let endsAt, _) = self { return endsAt }
        return nil
    }

    var chapterEndMs: Int64? {
        if case .endOfChapter(let end) = self { return end }
        return nil
    }

    /// Milliseconds left on a duration timer (never negative); nil for the others.
    static func remaining(_ timer: SleepTimer, now: Int64) -> Int64? {
        guard case .duration(let endsAt, _) = timer else { return nil }
        return max(endsAt - now, 0)
    }

    /// "+5 min": a duration timer gets `minutes` more (from now when it had run out), capped.
    static func extend(_ timer: SleepTimer, minutes: Int, now: Int64) -> SleepTimer {
        guard case .duration(let endsAt, let original) = timer else { return timer }
        let base = max(endsAt, now)
        let cap = now + Int64(maxMinutes) * 60_000
        return .duration(endsAt: min(base + Int64(minutes) * 60_000, cap), minutes: original)
    }

    /// After a seek: an end-of-chapter timer follows the chapter now playing.
    static func retarget(_ timer: SleepTimer, _ timeline: AudiobookTimeline, positionMs: Int64) -> SleepTimer {
        if case .endOfChapter = timer { return endOfChapter(timeline, positionMs: positionMs) }
        return timer
    }

    /// One tick: `now` on the monotonic clock, `positionMs` the whole-book position.
    static func decide(_ timer: SleepTimer, now: Int64, positionMs: Int64) -> Decision {
        switch timer {
        case .off:
            return .keep
        case .duration(let endsAt, _):
            let left = endsAt - now
            if left <= 0 { return .stop }
            if left < fadeMs { return .fade(min(max(Float(left) / Float(fadeMs), 0.05), 1)) }
            return .keep
        case .endOfChapter(let end):
            return positionMs >= end - chapterEndSlackMs ? .stop : .keep
        }
    }

    /// "12 min", "45 s", "End of chapter", or nil when off.
    static func label(_ timer: SleepTimer, now: Int64) -> String? {
        switch timer {
        case .off: return nil
        case .endOfChapter: return "End of chapter"
        case .duration:
            let left = remaining(timer, now: now) ?? 0
            let minutes = (left + 59_999) / 60_000
            return minutes <= 1 ? "\((left + 999) / 1000) s" : "\(minutes) min"
        }
    }
}
