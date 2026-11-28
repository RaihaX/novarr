import Foundation
import Testing
@testable import Novarr

/// Port of android ChapterUpdatesTest.kt: the one combined "new chapters" notification.
@Suite("New chapters notification")
struct ChapterUpdatesTests {
    typealias Pending = ChapterUpdates.Pending
    typealias State = ChapterUpdates.State
    private let hour: TimeInterval = 3600
    private let noon = ChapterUpdates.ClockTime(hour: 12)
    private let t0: TimeInterval = 1_800_000_000

    private func series(_ id: Int, _ name: String, _ count: Int = 1, chapter: Int? = nil,
                        at: String? = "2026-11-20T10:00:00Z") -> UpdatesFeed.Series {
        UpdatesFeed.Series(novelId: id, name: name, newCount: count, chapterId: chapter ?? id * 100,
                           chapterLabel: "Chapter \(chapter ?? id * 100)", latestAt: at)
    }

    private func feed(_ cursor: String, _ series: UpdatesFeed.Series...) -> UpdatesFeed {
        UpdatesFeed(cursor: cursor, series: series)
    }

    private func pending(_ id: Int, _ name: String, _ count: Int = 1, at: String? = "2026-11-20T10:00:00Z") -> Pending {
        Pending(novelId: id, name: name, newCount: count, chapterId: id * 100, latestAt: at)
    }

    private var running: State { State(scope: "s", cursor: "c1") }

    private func receive(_ state: State, _ answer: UpdatesFeed, _ now: TimeInterval,
                         _ time: ChapterUpdates.ClockTime? = nil, foreground: Bool = false,
                         showing: Bool = false) -> ChapterUpdates.Outcome {
        ChapterUpdates.receive(state, answer: answer, now: now, localTime: time ?? noon, foreground: foreground, showing: showing)
    }

    @Test func decodesTheContractSample() throws {
        let decoded = try Fixture.decode(UpdatesFeed.self, json: #"{"cursor":"abc","series":[{"novel_id":12,"name":"Lord of Mysteries","medium":"novel","new_count":3,"chapter_id":4567,"chapter_label":"Chapter 101","latest_at":"2026-11-20T10:00:00Z"},{"name":"no id"}]}"#)
        #expect(decoded.cursor == "abc")
        #expect(decoded.series == [UpdatesFeed.Series(novelId: 12, name: "Lord of Mysteries", newCount: 3, chapterId: 4567,
                                                       chapterLabel: "Chapter 101", latestAt: "2026-11-20T10:00:00Z")])
    }

    @Test func stateSurvivesStorage() throws {
        let state = State(scope: "s", cursor: "c9", pending: [pending(1, "A", 2)], lastAlertAt: t0)
        let data = try JSONEncoder().encode(state)
        #expect(try JSONDecoder().decode(State.self, from: data) == state)
        let defaults = UserDefaults(suiteName: "chapter-updates-\(UUID().uuidString)")!
        let prefs = NotificationPrefs(defaults: defaults)
        prefs.setChapterUpdatesState(state)
        #expect(prefs.chapterUpdatesState() == state)
        prefs.setChapterUpdatesState(nil)
        #expect(prefs.chapterUpdatesState() == nil)
    }

    @Test func firstRunOnlyStoresTheCursor() {
        let out = receive(State(scope: "s"), feed("c1", series(1, "A")), t0)
        #expect(out.state.cursor == "c1")
        #expect(out.state.pending.isEmpty)
        #expect(out.action == .none)
        #expect(out.state.lastAlertAt == nil)
    }

    @Test func emptyAnswerMovesTheCursorAndPostsNothing() {
        let state = modified(running) { $0.pending = [pending(1, "A")] }
        let out = receive(state, feed("c2"), t0)
        #expect(out.state.cursor == "c2")
        #expect(out.state.pending == state.pending)
        #expect(out.action == .none)
    }

    @Test func aMissingCursorKeepsTheOldOne() {
        #expect(receive(running, UpdatesFeed(cursor: nil, series: []), t0).state.cursor == "c1")
        #expect(receive(running, UpdatesFeed(cursor: "  ", series: []), t0).state.cursor == "c1")
    }

    @Test func newSeriesPostAndAlertInDaytime() {
        let out = receive(running, feed("c2", series(1, "A", 3)), t0)
        #expect(out.action == .post(alert: true))
        #expect(out.state.pending == [Pending(novelId: 1, name: "A", newCount: 3, chapterId: 100, latestAt: "2026-11-20T10:00:00Z")])
        #expect(out.state.lastAlertAt == t0)
    }

    @Test func inTheForegroundNothingIsAdded() {
        let out = receive(running, feed("c2", series(1, "A", 3)), t0, foreground: true)
        #expect(out.state.cursor == "c2")
        #expect(out.state.pending.isEmpty)
        #expect(out.action == .none)
    }

    @Test func theSameAnswerAgainDoesNotRepost() {
        let state = modified(running) { $0.pending = [Pending(novelId: 1, name: "A", newCount: 3, chapterId: 100, latestAt: "2026-11-20T10:00:00Z")] }
        #expect(receive(state, feed("c2", series(1, "A", 3)), t0).action == .none)
    }

    @Test func anUpdateToANotificationOnScreenIsSilent() {
        let state = modified(running) { $0.pending = [pending(1, "A")] }
        let out = receive(state, feed("c2", series(2, "B")), t0, showing: true)
        #expect(out.action == .post(alert: false))
        #expect(out.state.lastAlertAt == nil)
    }

    @Test func mergeAddsAndReplaces() {
        let merged = ChapterUpdates.merge([pending(1, "A", 2), pending(2, "B", 1)],
                                          [series(2, "B", 5, chapter: 999, at: "2026-11-21T00:00:00Z"), series(3, "C", 1)])
        #expect(merged.map(\.novelId) == [1, 2, 3])
        #expect(merged[0].newCount == 2)
        #expect(merged[1].newCount == 5)
        #expect(merged[1].chapterId == 999)
        #expect(merged[1].latestAt == "2026-11-21T00:00:00Z")
    }

    @Test func mergeIgnoresZeroCountsAndKeepsANameWhenTheAnswerHasNone() {
        let merged = ChapterUpdates.merge([pending(1, "A")], [series(1, "", 4), series(2, "B", 0)])
        #expect(merged.map(\.novelId) == [1])
        #expect(merged.first?.name == "A")
        #expect(merged.first?.newCount == 4)
    }

    @Test func clearedThenNew() {
        let state = State(scope: "s", cursor: "c1", pending: [pending(1, "A"), pending(2, "B")], lastAlertAt: t0)
        let cleared = state.cleared()
        #expect(cleared.pending.isEmpty)
        #expect(cleared.cursor == "c1")
        #expect(cleared.lastAlertAt == t0)
        let soon = receive(cleared, feed("c2", series(3, "C")), t0 + hour)
        #expect(soon.state.pending.map(\.novelId) == [3])
        #expect(soon.action == .post(alert: false))
        let later = receive(soon.state.cleared(), feed("c3", series(4, "D")), t0 + 6 * hour)
        #expect(later.state.pending.map(\.novelId) == [4])
        #expect(later.action == .post(alert: true))
        #expect(later.state.lastAlertAt == t0 + 6 * hour)
    }

    @Test func anotherScopeStartsOver() {
        let state = State(scope: "server-a|1", cursor: "c1", pending: [pending(1, "A")], lastAlertAt: t0)
        #expect(state.forScope("server-a|1") == state)
        #expect(state.forScope("server-b|1") == State(scope: "server-b|1"))
    }

    @Test func quietHoursEdges() {
        typealias T = ChapterUpdates.ClockTime
        #expect(!ChapterUpdates.isQuiet(T(hour: 21, minute: 59)))
        #expect(ChapterUpdates.isQuiet(T(hour: 22)))
        #expect(ChapterUpdates.isQuiet(T(hour: 0)))
        #expect(ChapterUpdates.isQuiet(T(hour: 6, minute: 59)))
        #expect(!ChapterUpdates.isQuiet(T(hour: 7)))
        #expect(ChapterUpdates.alertAllowed(lastAlertAt: nil, now: t0, localTime: T(hour: 21, minute: 59)))
        #expect(!ChapterUpdates.alertAllowed(lastAlertAt: nil, now: t0, localTime: T(hour: 22)))
        #expect(!ChapterUpdates.alertAllowed(lastAlertAt: nil, now: t0, localTime: T(hour: 6, minute: 59)))
        #expect(ChapterUpdates.alertAllowed(lastAlertAt: nil, now: t0, localTime: T(hour: 7)))
    }

    @Test func sixHourRule() {
        #expect(ChapterUpdates.alertAllowed(lastAlertAt: nil, now: t0, localTime: noon))
        #expect(!ChapterUpdates.alertAllowed(lastAlertAt: t0, now: t0 + 6 * hour - 1, localTime: noon))
        #expect(ChapterUpdates.alertAllowed(lastAlertAt: t0, now: t0 + 6 * hour, localTime: noon))
        #expect(ChapterUpdates.alertAllowed(lastAlertAt: t0, now: t0 - hour, localTime: noon))
    }

    @Test func atNightItPostsSilentlyAndTheMorningDoesNotReAlertTheSameContent() {
        let night = receive(running, feed("c2", series(1, "A", 2)), t0, .init(hour: 23, minute: 30))
        #expect(night.action == .post(alert: false))
        #expect(night.state.lastAlertAt == nil)
        let morning = ChapterUpdates.ClockTime(hour: 7, minute: 30)
        #expect(receive(night.state, feed("c3"), t0 + 8 * hour, morning).action == .none)
        #expect(receive(night.state, feed("c3", series(1, "A", 2)), t0 + 8 * hour, morning).action == .none)
        #expect(receive(night.state, feed("c3", series(2, "B")), t0 + 8 * hour, morning).action == .post(alert: true))
    }

    @Test func secondNewsWithinSixHoursIsSilent() {
        let first = receive(running, feed("c2", series(1, "A")), t0)
        #expect(first.action == .post(alert: true))
        let second = receive(first.state, feed("c3", series(2, "B")), t0 + 2 * hour)
        #expect(second.action == .post(alert: false))
        #expect(second.state.lastAlertAt == t0)
    }

    @Test func oneSeriesSingularAndPlural() {
        #expect(ChapterUpdates.title([pending(1, "A", 3)]) == "A")
        #expect(ChapterUpdates.text([pending(1, "A", 3)]) == "3 new chapters")
        #expect(ChapterUpdates.text([pending(1, "A", 1)]) == "1 new chapter")
        #expect(ChapterUpdates.text([Pending(novelId: 1, name: "M", medium: "manga", newCount: 2)]) == "2 new chapters")
        #expect(ChapterUpdates.target([pending(1, "A")]) == 1)
    }

    @Test func twoThreeAndSevenSeries() {
        let two = [pending(1, "A", at: "2026-11-20T09:00:00Z"), pending(2, "B", at: "2026-11-20T10:00:00Z")]
        #expect(ChapterUpdates.title(two) == "New chapters in 2 series")
        #expect(ChapterUpdates.text(two) == "B and A")
        #expect(ChapterUpdates.target(two) == nil)
        let three = [pending(1, "A", at: "2026-11-20T03:00:00Z"), pending(2, "B", at: "2026-11-20T02:00:00Z"), pending(3, "C", at: "2026-11-20T01:00:00Z")]
        #expect(ChapterUpdates.title(three) == "New chapters in 3 series")
        #expect(ChapterUpdates.text(three) == "A, B and C")
        let seven = (1...7).map { pending($0, "S\($0)", $0, at: "2026-11-20T0\($0):00:00Z") }
        #expect(ChapterUpdates.title(seven) == "New chapters in 7 series")
        #expect(ChapterUpdates.text(seven) == "S7, S6, S5 and 4 more")
        #expect(ChapterUpdates.lines(seven) == ["S7 · 7 new", "S6 · 6 new", "S5 · 5 new", "S4 · 4 new", "S3 · 3 new", "S2 · 2 new", "+1 more"])
    }

    @Test func fourSeriesTextAndLines() {
        let four = [pending(1, "A", at: "2026-11-20T04:00:00Z"), pending(2, "B", at: "2026-11-20T03:00:00Z"),
                    pending(3, "C", at: "2026-11-20T02:00:00Z"), pending(4, "D", at: nil)]
        #expect(ChapterUpdates.title(four) == "New chapters in 4 series")
        #expect(ChapterUpdates.text(four) == "A, B, C and 1 more")
        #expect(ChapterUpdates.lines(four) == ["A · 1 new", "B · 1 new", "C · 1 new", "D · 1 new"])
    }

    @Test func namesJoin() {
        #expect(ChapterUpdates.names([]) == "")
        #expect(ChapterUpdates.names(["A"]) == "A")
        #expect(ChapterUpdates.names(["A", "B"]) == "A and B")
        #expect(ChapterUpdates.names(["A", "B", "C"]) == "A, B and C")
        #expect(ChapterUpdates.names(["A", "B", "C", "D"]) == "A, B, C and 1 more")
    }

    @Test func theNotificationRoutesLikeAndroid() {
        let one = ChapterUpdatesSync.note([pending(5, "A", 2)], alert: true)
        #expect(one.id == NotificationIds.newChapters)
        #expect(one.title == "A")
        #expect(one.body == "2 new chapters")
        #expect(one.sound)
        #expect(one.userInfo == [NotificationIds.kindKey: NotificationIds.kindSeries, NotificationIds.novelKey: "5"])
        let several = ChapterUpdatesSync.note([pending(5, "A"), pending(6, "B")], alert: false)
        #expect(!several.sound)
        #expect(several.userInfo == [NotificationIds.kindKey: NotificationIds.kindHome])
    }
}
