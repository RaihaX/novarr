import Foundation
import Observation
import UIKit

/// Where a tapped notification goes (android MainActivity's intents).
enum NotificationRoute: Equatable, Sendable {
    /// The Requests screen on `section` (over Home), then the series for an available request.
    case requests(RequestsRoute.Section, novelId: Int?)
    /// One series (the new-chapters notification with one series), over Home.
    case series(Int)
    /// Home (the new-chapters notification with several series).
    case home
}

/// App-wide notification state: the request feed and new-chapters syncs, the
/// unseen-requests dot, the Requests search kept per session, and the route a
/// tapped notification asks for (`route`, consumed by the root view).
@MainActor
@Observable
final class NotificationHub {
    static let shared = NotificationHub()

    @ObservationIgnored let prefs: NotificationPrefs
    @ObservationIgnored let requestFeed: RequestFeedSync
    @ObservationIgnored let chapterUpdates: ChapterUpdatesSync

    /// A tapped notification's destination, until the root view handles it (`consumeRoute`).
    private(set) var route: NotificationRoute?
    /// Bumped whenever the unseen count changes, so the badge redraws.
    private(set) var unseenRevision = 0
    /// Bumped when the feed brought news: the Requests screen reloads its lists.
    private(set) var feedRevision = 0
    /// Whether notification alerts are allowed (refreshed by `refreshAuthorization`).
    private(set) var authorized = false
    private(set) var authorizationDetermined = false

    /// The Requests screen is showing: new items need no dot.
    @ObservationIgnored private(set) var requestsScreenVisible = false
    /// The Requests search as last left, for one session (`AppModel.sessionID`).
    @ObservationIgnored var searchMemory: (session: String, memory: Requests.SearchMemory)?

    init(prefs: NotificationPrefs = NotificationPrefs(), notifier: LocalNotifier? = nil) {
        self.prefs = prefs
        let notifier = notifier ?? SystemNotifier.shared
        requestFeed = RequestFeedSync(prefs: prefs, notifier: notifier)
        chapterUpdates = ChapterUpdatesSync(prefs: prefs, notifier: notifier)
    }

    static func scope(_ app: AppModel) -> String? {
        guard let server = app.serverURL else { return nil }
        return NotificationPrefs.scope(server: server, userId: app.account?.user.id)
    }

    /// The dot on the Requests button for this session.
    func unseen(_ app: AppModel) -> Int {
        _ = unseenRevision
        guard let scope = Self.scope(app) else { return 0 }
        return prefs.unseen(scope)
    }

    // MARK: Server offers

    /// A meta answer for `server`: what it offers, saved for the background run.
    func noteMeta(_ meta: ServerMeta, for server: URL) {
        let updatesBefore = prefs.offered(.updates, server: server)
        prefs.setOffered(.requests, server: server, meta.requests)
        prefs.setOffered(.updates, server: server, meta.updates)
        if updatesBefore == true, !meta.updates { Task { await chapterUpdates.reset() } }
        BackgroundRefresh.schedule()
    }

    // MARK: Foreground

    /// Back in the app (or launched): the feed into the dot, the new-chapters
    /// notification seen, its cursor moved on (Home shows what's new). Silent
    /// on failure; the next one tries again.
    func appBecameActive(_ app: AppModel) async {
        await refreshAuthorization()
        await chapterUpdates.clearPending()
        guard let client = app.client, let scope = Self.scope(app), let meta = app.serverMeta else { return }
        if meta.requests { await pollFeed(app) }
        if meta.updates, prefs.newChapterNotifications {
            try? await chapterUpdates.poll(scope: scope, client: client, foreground: true)
        }
    }

    /// Reads the feed now (foreground): new items count for the dot.
    func pollFeed(_ app: AppModel) async {
        guard let client = app.client, let scope = Self.scope(app), app.serverMeta?.requests == true else { return }
        let shown = (try? await requestFeed.poll(scope: scope, client: client, foreground: true,
                                                 screenVisible: requestsScreenVisible)) ?? 0
        if shown > 0 {
            feedRevision += 1
            unseenRevision += 1
        }
    }

    /// The Requests screen appeared (`visible`) or went away: while open it
    /// clears the dot and the request notifications.
    func requestsScreen(visible: Bool, app: AppModel) {
        requestsScreenVisible = visible
        guard visible else { return }
        Task { await SystemNotifier.shared.removeThread(NotificationIds.requestsThread) }
        if let scope = Self.scope(app) {
            prefs.clearUnseen(scope)
            unseenRevision += 1
        }
        Task { await pollFeed(app) }
    }

    // MARK: Switches

    func refreshAuthorization() async {
        let status = await SystemNotifier.shared.authorizationStatus()
        authorizationDetermined = status != .notDetermined
        authorized = await SystemNotifier.shared.canPost()
    }

    /// Settings' "Notify me about requests": on asks for permission (as Android 13+ does).
    func setRequestNotifications(_ on: Bool) async {
        prefs.requestNotifications = on
        if on { await askPermission() }
        BackgroundRefresh.schedule()
    }

    /// Settings' "New chapter notifications": off forgets what was pending and the cursor.
    func setNewChapterNotifications(_ on: Bool) async {
        prefs.newChapterNotifications = on
        if on { await askPermission() } else { await chapterUpdates.reset() }
        BackgroundRefresh.schedule()
    }

    private func askPermission() async {
        await refreshAuthorization()
        if !authorizationDetermined { _ = await SystemNotifier.shared.requestAuthorization() }
        await refreshAuthorization()
    }

    // MARK: Taps

    /// A tapped (or, for new chapters, dismissed) notification.
    func handleResponse(payload: [String: String], identifier: String, dismissed: Bool) {
        if identifier == NotificationIds.newChapters {
            Task { await chapterUpdates.clearPending() }
            if dismissed { return }
        }
        guard !dismissed else { return }
        switch payload[NotificationIds.kindKey] {
        case NotificationIds.kindRequests:
            route = .requests(.from(payload[NotificationIds.sectionKey]),
                              novelId: payload[NotificationIds.novelKey].flatMap(Int.init))
        case NotificationIds.kindSeries:
            route = payload[NotificationIds.novelKey].flatMap(Int.init).map(NotificationRoute.series) ?? .home
        case NotificationIds.kindHome:
            route = .home
        default:
            break
        }
    }

    /// The root view navigated to `route`.
    func consumeRoute() { route = nil }

    #if DEBUG
    /// Debug builds: pretend a notification was tapped (`-debugNotificationRoute`).
    func debugRoute(_ route: NotificationRoute) { self.route = route }
    #endif
}
