import Foundation

/// The readers' progress-write rules, without timers so they can be tested
/// (android domain/ProgressTracker.kt; the hold itself lives in `PositionHold`):
/// - positions are sent at most once every `interval` while reading; the
///   first one after a quiet spell goes straight away, later ones wait out
///   the rest of the interval (one pending send at a time, carrying the newest);
/// - an unchanged position is never re-sent;
/// - 100 is reserved for actually reaching the end, which marks the chapter
///   read once and cancels any pending position;
/// - while holding (a latest-position check or its prompt is open, or a
///   look-up), positions are still tracked but not sent, and they don't date
///   local reading (`pendingSince`): that would hide the very prompt the hold
///   waits for. A position from before the hold still counts.
struct ProgressTracker: Equatable {
    enum Decision: Equatable {
        case sendNow
        case sendAfter(TimeInterval)
        /// A send is already scheduled; it will carry the newest position.
        case alreadyScheduled
        /// Writes are held: the position is kept but nothing is scheduled.
        case held
    }

    /// Minimum seconds between progress writes while reading.
    static let defaultInterval: TimeInterval = 4

    let interval: TimeInterval
    private(set) var pending: Int?
    private(set) var lastSent: Int?
    private(set) var markedRead = false
    /// When the oldest still-unsent position was taken (not counting held ones).
    private(set) var pendingSince: Date?
    private var lastSentAt = Date.distantPast
    private var scheduled = false

    init(interval: TimeInterval = ProgressTracker.defaultInterval) {
        self.interval = interval
    }

    /// Starts tracking a freshly opened chapter with what the server (or cache) knows.
    mutating func reset(read: Bool, progress: Int?) {
        pending = nil
        pendingSince = nil
        lastSent = progress
        markedRead = read
        scheduled = false
    }

    /// This device's position in the open chapter, 0–100.
    var current: Int? { pending ?? lastSent }

    /// A new position (0–99) while reading.
    mutating func moved(_ progress: Int, now: Date, holding: Bool = false) -> Decision {
        pending = min(max(progress, 0), 99)
        if holding { return .held }
        if pendingSince == nil { pendingSince = now }
        let elapsed = now.timeIntervalSince(lastSentAt)
        if elapsed >= interval {
            scheduled = false
            return .sendNow
        }
        if scheduled { return .alreadyScheduled }
        scheduled = true
        return .sendAfter(interval - elapsed)
    }

    /// The position to write now, or nil when there is nothing new (or writes are held).
    mutating func takePending(now: Date, holding: Bool = false) -> Int? {
        scheduled = false
        guard !holding, let progress = pending else { return nil }
        pending = nil
        pendingSince = nil
        guard progress != lastSent else { return nil }
        lastSent = progress
        lastSentAt = now
        return progress
    }

    /// The end of the chapter is on screen (or the reader moved on to the
    /// next one). True when the read mark must be written (once).
    mutating func reachedEnd(now: Date) -> Bool {
        pending = nil
        pendingSince = nil
        scheduled = false
        guard !markedRead else { return false }
        markedRead = true
        lastSent = 100
        lastSentAt = now
        return true
    }

    /// Drops the unsent position (held or not), e.g. after "Go to latest".
    mutating func discardPending() {
        pending = nil
        pendingSince = nil
        scheduled = false
    }

    /// Position of item `index` of `count` as 0–99 (100 is "finished").
    static func progress(index: Int, count: Int) -> Int {
        guard count > 1 else { return 0 }
        let fraction = Double(index) / Double(count - 1)
        return min(99, max(0, Int((fraction * 100).rounded())))
    }
}

/// When the readers count a chapter as finished (the read mark, `read: true`
/// on the progress write), kept apart from the screens so it can be tested
/// (android domain/ReadMark.kt):
/// - the end of the chapter is on screen (the last page, the end-of-chapter footer);
/// - in a scrolled text chapter, the bottom of at least `textEndFraction` of
///   its paragraphs has been on screen (a long last paragraph or a tall footer
///   must not keep a chapter that was read to its last lines unread);
/// - the reader moves on to the chapter's next chapter (Next chapter in the
///   bars or on the end page, a tap or swipe past the end): moving forward
///   says "done with this one", as the web reader and KOReader treat it.
///
/// The server marks a chapter read too when a position of
/// `serverFinishedProgress` or more arrives, so a dropped read flag still ends up read.
enum ReadMark {
    /// Share of a scrolled chapter's paragraphs that must have been on screen.
    static let textEndFraction = 0.95

    /// Mirrors the server's ReadState::FINISHED_PROGRESS (documentation and tests only).
    static let serverFinishedProgress = 98

    /// Whether a scrolled text chapter counts as read: `lastFullyVisible` is
    /// the index (0-based) of the last paragraph whose bottom is on screen,
    /// or -1 when none is.
    static func textEndReached(lastFullyVisible: Int, paragraphCount: Int) -> Bool {
        guard paragraphCount > 0, lastFullyVisible >= 0 else { return false }
        let needed = min(max(Int((Double(paragraphCount) * textEndFraction).rounded(.up)), 1), paragraphCount)
        return lastFullyVisible + 1 >= needed
    }

    /// The first paragraph (0-based) whose bottom on screen can finish the chapter.
    static func firstFinishingParagraph(paragraphCount: Int) -> Int {
        guard paragraphCount > 0 else { return 0 }
        return min(max(Int((Double(paragraphCount) * textEndFraction).rounded(.up)), 1), paragraphCount) - 1
    }

    /// Opening `target` from a loaded chapter whose next chapter is `next`
    /// finishes the chapter being left (true): it is marked read first.
    /// Going back, or jumping elsewhere (Go to latest, the chapter list), doesn't.
    static func finishesOnOpen(target: Int, next: Int?, loaded: Bool) -> Bool {
        loaded && next != nil && target == next
    }
}
