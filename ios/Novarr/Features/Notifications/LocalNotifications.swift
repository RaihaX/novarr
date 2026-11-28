import Foundation
import UserNotifications

/// Posts and removes the app's local notifications (there is no push):
/// request feed items, one per item, grouped in the "requests" thread; and
/// the one combined "new chapters" notification, replaced in place. Behind a
/// protocol so the feed logic is tested without the system.
@MainActor
protocol LocalNotifier: AnyObject {
    /// Whether a notification would show now (authorised, alerts not off).
    func canPost() async -> Bool
    /// Adds (or replaces, by `id`) a notification; false when it couldn't.
    func post(_ note: LocalNote) async -> Bool
    func isDelivered(_ id: String) async -> Bool
    func remove(_ ids: [String])
    func removeThread(_ thread: String) async
}

/// One notification's content. `userInfo` holds only strings (where a tap goes).
struct LocalNote: Equatable, Sendable {
    var id: String
    var title: String
    var body: String
    var thread: String? = nil
    var category: String? = nil
    var sound: Bool = true
    var userInfo: [String: String] = [:]
}

enum NotificationIds {
    static let newChapters = "new-chapters"
    static let requestsThread = "requests"
    static let newChaptersCategory = "new-chapters"

    // Payload keys (what a tap opens).
    static let kindKey = "kind"
    static let sectionKey = "section"
    static let novelKey = "novelId"
    static let kindRequests = "requests"
    static let kindSeries = "series"
    static let kindHome = "home"
}

/// The system notification centre.
@MainActor
final class SystemNotifier: LocalNotifier {
    static let shared = SystemNotifier()

    private var center: UNUserNotificationCenter { .current() }

    func canPost() async -> Bool {
        let settings = await center.notificationSettings()
        switch settings.authorizationStatus {
        case .authorized, .provisional, .ephemeral: return settings.alertSetting != .disabled || settings.notificationCenterSetting == .enabled
        default: return false
        }
    }

    func authorizationStatus() async -> UNAuthorizationStatus {
        await center.notificationSettings().authorizationStatus
    }

    /// Asks once (the system shows its prompt only the first time); true when allowed.
    func requestAuthorization() async -> Bool {
        (try? await center.requestAuthorization(options: [.alert, .sound, .badge])) ?? false
    }

    func post(_ note: LocalNote) async -> Bool {
        guard await canPost() else { return false }
        let content = UNMutableNotificationContent()
        content.title = note.title
        content.body = note.body
        if let thread = note.thread { content.threadIdentifier = thread }
        if let category = note.category { content.categoryIdentifier = category }
        content.sound = note.sound ? .default : nil
        content.interruptionLevel = note.sound ? .active : .passive
        content.userInfo = note.userInfo
        let request = UNNotificationRequest(identifier: note.id, content: content, trigger: nil)
        do {
            try await center.add(request)
            return true
        } catch {
            return false
        }
    }

    func isDelivered(_ id: String) async -> Bool {
        await center.deliveredNotifications().contains { $0.request.identifier == id }
    }

    func remove(_ ids: [String]) {
        center.removeDeliveredNotifications(withIdentifiers: ids)
        center.removePendingNotificationRequests(withIdentifiers: ids)
    }

    func removeThread(_ thread: String) async {
        let ids = await center.deliveredNotifications()
            .filter { $0.request.content.threadIdentifier == thread }
            .map(\.request.identifier)
        if !ids.isEmpty { center.removeDeliveredNotifications(withIdentifiers: ids) }
    }

    /// The new-chapters category asks for dismiss callbacks (a swipe-away clears what was pending).
    nonisolated static func registerCategories() {
        let newChapters = UNNotificationCategory(identifier: NotificationIds.newChaptersCategory, actions: [],
                                                 intentIdentifiers: [], options: [.customDismissAction])
        UNUserNotificationCenter.current().setNotificationCategories([newChapters])
    }
}

/// Receives taps and dismissals. Installed at launch (before the app finishes
/// launching), so a tap that launched the app is not missed.
final class NotificationDelegate: NSObject, UNUserNotificationCenterDelegate, Sendable {
    static let shared = NotificationDelegate()

    static func install() {
        UNUserNotificationCenter.current().delegate = shared
        SystemNotifier.registerCategories()
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification)
        async -> UNNotificationPresentationOptions {
        // The app posts nothing in the foreground; one that slipped through still shows.
        [.banner, .list]
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse) async {
        var payload: [String: String] = [:]
        for (key, value) in response.notification.request.content.userInfo {
            if let key = key as? String, let value = value as? String { payload[key] = value }
        }
        let identifier = response.notification.request.identifier
        let dismissed = response.actionIdentifier == UNNotificationDismissActionIdentifier
        await MainActor.run {
            NotificationHub.shared.handleResponse(payload: payload, identifier: identifier, dismissed: dismissed)
        }
    }
}
