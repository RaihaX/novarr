import BackgroundTasks
import Foundation
import os

/// The app-refresh task (`au.com.atomicinsights.novarr.refresh`): iOS's stand-in for
/// Android's periodic WorkManager worker. It reads the request feed (hourly)
/// and the new-chapters feed (every 2 hours) for the saved server and
/// account and posts local notifications. iOS decides when it actually runs;
/// each check keeps its own spacing (`BackgroundSchedule`).
enum BackgroundRefresh {
    static let identifier = "au.com.atomicinsights.novarr.refresh"
    static let requestsCheck = "requests"
    static let newChaptersCheck = "newChapters"
    private static let log = Logger(subsystem: "au.com.atomicinsights.novarr", category: "Background")

    /// At launch, before the app finishes launching.
    static func register() {
        BGTaskScheduler.shared.register(forTaskWithIdentifier: identifier, using: nil) { task in
            guard let refresh = task as? BGAppRefreshTask else {
                task.setTaskCompleted(success: false)
                return
            }
            handle(TaskBox(task: refresh))
        }
    }

    /// The checks as the schedule sees them, for the saved server.
    static func specs(prefs: NotificationPrefs = NotificationPrefs(), server: URL?) -> [BackgroundSchedule.Spec] {
        let requests = server.map { prefs.requestNotifications && prefs.offered(.requests, server: $0) == true } ?? false
        let chapters = server.map { prefs.newChapterNotifications && prefs.offered(.updates, server: $0) == true } ?? false
        return [
            BackgroundSchedule.Spec(key: requestsCheck, enabled: requests, period: BackgroundSchedule.hour,
                                    lastRun: prefs.lastRun(requestsCheck)),
            BackgroundSchedule.Spec(key: newChaptersCheck, enabled: chapters, period: 2 * BackgroundSchedule.hour,
                                    lastRun: prefs.lastRun(newChaptersCheck)),
        ]
    }

    static func savedServer(_ defaults: UserDefaults = .standard) -> URL? {
        defaults.string(forKey: AppModel.serverDefaultsKey).flatMap(ServerURL.normalize)
    }

    /// Asks iOS for the next run at the shortest enabled period; cancels it when nothing is on.
    static func schedule() {
        guard let interval = BackgroundSchedule.interval(specs(server: savedServer())) else {
            BGTaskScheduler.shared.cancel(taskRequestWithIdentifier: identifier)
            return
        }
        let request = BGAppRefreshTaskRequest(identifier: identifier)
        request.earliestBeginDate = Date(timeIntervalSinceNow: interval)
        do {
            try BGTaskScheduler.shared.submit(request)
        } catch {
            // The simulator and some states refuse it; the next foreground schedules again.
            log.info("Couldn't schedule the background refresh: \(error.localizedDescription, privacy: .public)")
        }
    }

    private static func handle(_ box: TaskBox) {
        schedule()
        let work = Task { @MainActor in
            await runDueChecks()
            box.task.setTaskCompleted(success: true)
        }
        box.task.expirationHandler = { work.cancel() }
    }

    /// Runs every enabled check that is due against the saved server. A
    /// failed check waits for the next run (its last-run time is stored only on success).
    @MainActor
    static func runDueChecks(now: Date = Date()) async {
        let hub = NotificationHub.shared
        let prefs = hub.prefs
        guard let server = savedServer() else { return }
        let due = BackgroundSchedule.dueChecks(specs(prefs: prefs, server: server), now: now)
        guard !due.isEmpty else { return }
        do {
            // Public meta: the server may have turned requests or updates off since.
            let meta = try await APIClient(baseURL: server).meta()
            hub.noteMeta(meta, for: server)
            let credential = CredentialStore().credential(for: server)
            if meta.requiresSignIn, credential == nil { return }
            // Its own client without the 401 handler: a rejected token is left for the app to find.
            let client = APIClient(baseURL: server, token: credential?.token)
            let scope = NotificationPrefs.scope(server: server, userId: credential?.user.id)
            for check in due {
                if Task.isCancelled { return }
                do {
                    switch check {
                    case requestsCheck where meta.requests:
                        try await hub.requestFeed.poll(scope: scope, client: client, foreground: false, screenVisible: false)
                    case newChaptersCheck where meta.updates:
                        try await hub.chapterUpdates.poll(scope: scope, client: client, foreground: false)
                    default:
                        break
                    }
                    prefs.setLastRun(check, now)
                } catch {
                    log.info("\(check, privacy: .public) check failed; trying again next time")
                }
            }
        } catch {
            log.info("Background refresh couldn't reach the server; trying again next time")
        }
    }

    /// BGTask isn't Sendable; it is only touched from its handler and the work's end.
    private struct TaskBox: @unchecked Sendable { let task: BGAppRefreshTask }
}
