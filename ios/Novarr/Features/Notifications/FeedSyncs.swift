import Foundation

/// Runs async work one at a time, in order, on the main actor (Android's
/// Mutex around the feed polls): a clear can't be undone by a run that read
/// the state before it, and a feed item is never handled twice.
@MainActor
final class SerialRunner {
    private var tail: Task<Void, Never>?

    func run<T: Sendable>(_ operation: @escaping @MainActor () async throws -> T) async throws -> T {
        let previous = tail
        let task = Task { @MainActor in
            _ = await previous?.result
            return try await operation()
        }
        tail = Task { _ = await task.result }
        return try await task.value
    }
}

/// Reads the request notification feed (GET /notifications?since=) for one
/// server and account: from the background refresh, when the app comes to
/// the foreground and when the Requests screen opens (android
/// app/RequestFeedSync.kt).
///
/// The stored position moves only after the items were shown, so a failed or
/// refused run gets the same items next time and an item is never shown
/// twice. Shown means: in the foreground, counted for the dot on the
/// Requests button (or nothing, with the Requests screen open); in the
/// background, posted as notifications. With the switch off or notifications
/// blocked the position stays put, and the next foreground poll counts them
/// for the dot instead.
@MainActor
final class RequestFeedSync {
    let prefs: NotificationPrefs
    let notifier: LocalNotifier
    private let serial = SerialRunner()

    init(prefs: NotificationPrefs, notifier: LocalNotifier) {
        self.prefs = prefs
        self.notifier = notifier
    }

    /// The number of new items shown. Throws `APIError` (offline, 401…).
    @discardableResult
    func poll(scope: String, client: APIClient, foreground: Bool, screenVisible: Bool) async throws -> Int {
        try await serial.run { [self] in
            var (cursor, last) = prefs.feedPosition(scope)
            var shown = 0
            for _ in 1...RequestFeed.maxPages {
                // In the background a rejected token is left for the app to find in the foreground.
                let feed = try await client.notifications(since: cursor, reportUnauthorized: foreground)
                let fresh = RequestFeed.fresh(feed.items, lastId: last)
                let known = fresh.filter(RequestFeed.known)
                if !known.isEmpty {
                    let ok = await show(scope: scope, items: known, foreground: foreground, screenVisible: screenVisible)
                    if !ok { break }
                }
                cursor = feed.cursor ?? cursor
                last = RequestFeed.lastId(last, fresh)
                prefs.setFeedPosition(scope, cursor: cursor, lastId: last)
                shown += known.count
                if !feed.hasMore || feed.items.isEmpty { break }
            }
            return shown
        }
    }

    private func show(scope: String, items: [FeedItem], foreground: Bool, screenVisible: Bool) async -> Bool {
        if foreground {
            // In the app: a dot on the Requests button instead of a system notification.
            if !screenVisible { prefs.addUnseen(scope, items.count) }
            return true
        }
        guard prefs.requestNotifications, await notifier.canPost() else { return false }
        var posted = 0
        for item in items {
            let target = RequestFeed.target(item)
            var info = [NotificationIds.kindKey: NotificationIds.kindRequests,
                        NotificationIds.sectionKey: target.section.rawValue]
            if let novelId = target.novelId { info[NotificationIds.novelKey] = String(novelId) }
            let note = LocalNote(id: RequestFeed.notificationId(item.id),
                                 title: item.title.trimmingCharacters(in: .whitespaces).isEmpty ? "Requests" : item.title,
                                 body: item.body, thread: NotificationIds.requestsThread, userInfo: info)
            if await notifier.post(note) { posted += 1 }
        }
        guard posted > 0 else { return false }
        // Also the dot, for when the app is opened without tapping the notification.
        prefs.addUnseen(scope, posted)
        return true
    }
}

/// The new-chapters check around `ChapterUpdates` (android
/// app/ChapterUpdatesSync.kt): reads `GET /updates?since=`, keeps the state,
/// and posts, updates or removes the one notification.
@MainActor
final class ChapterUpdatesSync {
    let prefs: NotificationPrefs
    let notifier: LocalNotifier
    private let serial = SerialRunner()
    /// Injectable for tests.
    var now: () -> Date = { Date() }

    init(prefs: NotificationPrefs, notifier: LocalNotifier) {
        self.prefs = prefs
        self.notifier = notifier
    }

    /// One run against `client`: the new cursor stored, the notification posted when its content changed.
    func poll(scope: String, client: APIClient, foreground: Bool) async throws {
        try await serial.run { [self] in
            let state = load().forScope(scope)
            let answer = try await client.updates(since: state.cursor, reportUnauthorized: foreground)
            // The switch may have been turned off while the request ran.
            guard prefs.newChapterNotifications else { return }
            let showing = await notifier.isDelivered(NotificationIds.newChapters)
            let date = now()
            let outcome = ChapterUpdates.receive(state, answer: answer, now: date.timeIntervalSince1970,
                                                 localTime: .now(date), foreground: foreground, showing: showing)
            guard case .post(let alert) = outcome.action else {
                prefs.setChapterUpdatesState(outcome.state)
                return
            }
            let posted = await notifier.post(Self.note(outcome.state.pending, alert: alert))
            // Blocked: nothing alerted, so the 6-hour spacing isn't used up.
            var next = outcome.state
            if !posted, alert { next.lastAlertAt = state.lastAlertAt }
            prefs.setChapterUpdatesState(next)
        }
    }

    /// The one notification for `pending`: one series opens it, several open Home.
    nonisolated static func note(_ pending: [ChapterUpdates.Pending], alert: Bool) -> LocalNote {
        var info = [NotificationIds.kindKey: NotificationIds.kindHome]
        if let target = ChapterUpdates.target(pending) {
            info = [NotificationIds.kindKey: NotificationIds.kindSeries, NotificationIds.novelKey: String(target)]
        }
        return LocalNote(id: NotificationIds.newChapters, title: ChapterUpdates.title(pending),
                         body: ChapterUpdates.text(pending), category: NotificationIds.newChaptersCategory,
                         sound: alert, userInfo: info)
    }

    /// In the app, tapped or dismissed: what was pending is seen. The cursor and the last alert stay.
    func clearPending() async {
        // At once, even while a run is going (it won't post in the foreground).
        notifier.remove([NotificationIds.newChapters])
        _ = try? await serial.run { [self] in
            notifier.remove([NotificationIds.newChapters])
            if let state = prefs.chapterUpdatesState(), !state.pending.isEmpty {
                prefs.setChapterUpdatesState(state.cleared())
            }
        }
    }

    /// The switch turned off (or the server stopped offering it): everything
    /// goes, cursor included, so the next run starts over instead of reporting a backlog.
    func reset() async {
        _ = try? await serial.run { [self] in
            notifier.remove([NotificationIds.newChapters])
            prefs.setChapterUpdatesState(nil)
        }
    }

    private func load() -> ChapterUpdates.State { prefs.chapterUpdatesState() ?? ChapterUpdates.State() }
}
