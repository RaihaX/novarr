import Foundation
import Testing
@testable import Novarr

/// A notification centre that records what it was asked to show.
@MainActor
final class FakeNotifier: LocalNotifier {
    var allowed = true
    var posted: [LocalNote] = []
    var removed: [String] = []

    func canPost() async -> Bool { allowed }
    func post(_ note: LocalNote) async -> Bool {
        guard allowed else { return false }
        posted.removeAll { $0.id == note.id }
        posted.append(note)
        return true
    }
    func isDelivered(_ id: String) async -> Bool { posted.contains { $0.id == id } }
    func remove(_ ids: [String]) {
        removed += ids
        posted.removeAll { ids.contains($0.id) }
    }
    func removeThread(_ thread: String) async { posted.removeAll { $0.thread == thread } }
}

/// The feed syncs around the pure rules: an item is never handled twice,
/// and the position stays put when nothing could be shown.
@MainActor
@Suite("Notification feed syncs")
struct NotificationSyncTests {
    private func prefs() -> NotificationPrefs {
        NotificationPrefs(defaults: UserDefaults(suiteName: "notify-\(UUID().uuidString)")!)
    }

    nonisolated private static let page = """
    {"cursor":"c2","has_more":false,"items":[
      {"id":11,"kind":"request_new","title":"New request","body":"Ana requested Solo Leveling.","request_id":5},
      {"id":12,"kind":"request_available","title":"Request available","body":"Solo Leveling is in the library.","request_id":5,"novel_id":42},
      {"id":13,"kind":"request_from_the_future","title":"?","body":"?"}]}
    """

    private func feedClient(_ queries: RequestsCallLog) -> APIClient {
        StubURLProtocol.client(token: "t") { request, _ in
            queries.append(request.url?.query ?? "")
            return (200, Data(Self.page.utf8))
        }
    }

    @Test func backgroundPostsEachKnownItemOnceAndMovesThePosition() async throws {
        let prefs = prefs()
        let notifier = FakeNotifier()
        let sync = RequestFeedSync(prefs: prefs, notifier: notifier)
        let queries = RequestsCallLog()
        let shown = try await sync.poll(scope: "s", client: feedClient(queries), foreground: false, screenVisible: false)
        #expect(shown == 2)
        #expect(notifier.posted.map(\.id) == ["request-11", "request-12"])
        #expect(notifier.posted[0].userInfo[NotificationIds.sectionKey] == "review")
        #expect(notifier.posted[1].userInfo[NotificationIds.sectionKey] == "mine")
        #expect(notifier.posted[1].userInfo[NotificationIds.novelKey] == "42")
        #expect(prefs.feedPosition("s").cursor == "c2")
        #expect(prefs.feedPosition("s").lastId == 13)
        #expect(prefs.unseen("s") == 2)
        // The same items again (the server repeats them): nothing new.
        notifier.posted = []
        let again = try await sync.poll(scope: "s", client: feedClient(queries), foreground: false, screenVisible: false)
        #expect(again == 0)
        #expect(notifier.posted.isEmpty)
        #expect(queries.values.last == "since=c2")
    }

    @Test func blockedOrSwitchedOffKeepsThePositionForTheDot() async throws {
        let prefs = prefs()
        let notifier = FakeNotifier()
        notifier.allowed = false
        let sync = RequestFeedSync(prefs: prefs, notifier: notifier)
        let queries = RequestsCallLog()
        #expect(try await sync.poll(scope: "s", client: feedClient(queries), foreground: false, screenVisible: false) == 0)
        #expect(prefs.feedPosition("s").cursor == nil)
        #expect(prefs.feedPosition("s").lastId == nil)
        // Back in the app: the same items count for the dot.
        #expect(try await sync.poll(scope: "s", client: feedClient(queries), foreground: true, screenVisible: false) == 2)
        #expect(prefs.unseen("s") == 2)
        #expect(prefs.feedPosition("s").lastId == 13)
        prefs.requestNotifications = false
        notifier.allowed = true
        #expect(try await sync.poll(scope: "other", client: feedClient(queries), foreground: false, screenVisible: false) == 0)
        #expect(notifier.posted.isEmpty)
    }

    @Test func withTheScreenOpenNothingCountsForTheDot() async throws {
        let prefs = prefs()
        let sync = RequestFeedSync(prefs: prefs, notifier: FakeNotifier())
        #expect(try await sync.poll(scope: "s", client: feedClient(RequestsCallLog()), foreground: true, screenVisible: true) == 2)
        #expect(prefs.unseen("s") == 0)
    }

    @Test func newChaptersFirstRunThenOneNotification() async throws {
        let prefs = prefs()
        let notifier = FakeNotifier()
        let sync = ChapterUpdatesSync(prefs: prefs, notifier: notifier)
        sync.now = { Calendar.current.date(bySettingHour: 12, minute: 0, second: 0, of: Date())! }
        let answers = [
            #"{"cursor":"c1","series":[]}"#,
            #"{"cursor":"c2","series":[{"novel_id":12,"name":"Lord of Mysteries","medium":"novel","new_count":3,"chapter_id":4567}]}"#,
        ]
        let calls = RequestsCallLog()
        let client = StubURLProtocol.client(token: "t") { request, _ in
            calls.append(request.url?.query ?? "")
            return (200, Data(answers[min(calls.values.count - 1, 1)].utf8))
        }
        try await sync.poll(scope: "s", client: client, foreground: false)
        #expect(notifier.posted.isEmpty)
        #expect(prefs.chapterUpdatesState()?.cursor == "c1")
        try await sync.poll(scope: "s", client: client, foreground: false)
        #expect(calls.values == ["", "since=c1"])
        let note = try #require(notifier.posted.first)
        #expect(note.title == "Lord of Mysteries")
        #expect(note.body == "3 new chapters")
        #expect(note.sound)
        // Tapped / seen: the notification goes, the cursor stays.
        await sync.clearPending()
        #expect(notifier.posted.isEmpty)
        #expect(prefs.chapterUpdatesState()?.pending.isEmpty == true)
        #expect(prefs.chapterUpdatesState()?.cursor == "c2")
        // Switched off: everything goes.
        await sync.reset()
        #expect(prefs.chapterUpdatesState() == nil)
    }

    @Test func tapsRouteLikeAndroid() {
        let hub = NotificationHub(prefs: prefs(), notifier: FakeNotifier())
        hub.handleResponse(payload: [NotificationIds.kindKey: "requests", NotificationIds.sectionKey: "review"],
                           identifier: "request-1", dismissed: false)
        #expect(hub.route == .requests(.review, novelId: nil))
        hub.consumeRoute()
        hub.handleResponse(payload: [NotificationIds.kindKey: "requests", NotificationIds.sectionKey: "mine", NotificationIds.novelKey: "42"],
                           identifier: "request-2", dismissed: false)
        #expect(hub.route == .requests(.mine, novelId: 42))
        hub.handleResponse(payload: [NotificationIds.kindKey: "series", NotificationIds.novelKey: "7"],
                           identifier: NotificationIds.newChapters, dismissed: false)
        #expect(hub.route == .series(7))
        hub.consumeRoute()
        hub.handleResponse(payload: [NotificationIds.kindKey: "home"], identifier: NotificationIds.newChapters, dismissed: true)
        #expect(hub.route == nil)
    }
}

final class RequestsCallLog: @unchecked Sendable {
    private let lock = NSLock()
    private var items: [String] = []
    var values: [String] { lock.withLock { items } }
    func append(_ value: String) { lock.withLock { items.append(value) } }
}
