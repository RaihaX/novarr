import Foundation

/// The notification switches and the feeds' positions, in UserDefaults
/// (android store/AppPrefs.kt). Feed positions, the unseen count and the
/// new-chapters state are per server and account (`scope`).
struct NotificationPrefs: @unchecked Sendable {
    let defaults: UserDefaults

    init(defaults: UserDefaults = .standard) { self.defaults = defaults }

    /// The feed position, the dot and the cursor belong to one server and account.
    static func scope(server: URL, userId: Int?) -> String {
        ServerURL.storageKey(for: server) + "|" + (userId.map(String.init) ?? "-")
    }

    // MARK: Switches (default on, as Android)

    var requestNotifications: Bool {
        get { defaults.object(forKey: Keys.requestsNotify) as? Bool ?? true }
        nonmutating set { defaults.set(newValue, forKey: Keys.requestsNotify) }
    }

    var newChapterNotifications: Bool {
        get { defaults.object(forKey: Keys.newChaptersNotify) as? Bool ?? true }
        nonmutating set { defaults.set(newValue, forKey: Keys.newChaptersNotify) }
    }

    // MARK: What the server last said it offers (for the background run)

    enum Feature: String { case requests, updates }

    func offered(_ feature: Feature, server: URL) -> Bool? {
        defaults.object(forKey: "featureOffered.\(feature.rawValue):\(ServerURL.storageKey(for: server))") as? Bool
    }

    func setOffered(_ feature: Feature, server: URL, _ offered: Bool) {
        defaults.set(offered, forKey: "featureOffered.\(feature.rawValue):\(ServerURL.storageKey(for: server))")
    }

    // MARK: The request feed

    /// Where the feed was read up to: the server's cursor and the newest item id handled.
    func feedPosition(_ scope: String) -> (cursor: String?, lastId: Int64?) {
        let last = (defaults.object(forKey: "requests.feedLastId:\(scope)") as? NSNumber)?.int64Value
        return (defaults.string(forKey: "requests.feedCursor:\(scope)"), last)
    }

    func setFeedPosition(_ scope: String, cursor: String?, lastId: Int64?) {
        if let cursor { defaults.set(cursor, forKey: "requests.feedCursor:\(scope)") }
        if let lastId { defaults.set(NSNumber(value: lastId), forKey: "requests.feedLastId:\(scope)") }
    }

    /// Feed items not seen in the app yet (the dot on the Requests button).
    func unseen(_ scope: String) -> Int { defaults.integer(forKey: "requests.unseen:\(scope)") }
    func addUnseen(_ scope: String, _ count: Int) {
        defaults.set(unseen(scope) + count, forKey: "requests.unseen:\(scope)")
    }
    func clearUnseen(_ scope: String) { defaults.removeObject(forKey: "requests.unseen:\(scope)") }

    // MARK: New chapters

    func chapterUpdatesState() -> ChapterUpdates.State? {
        guard let data = defaults.data(forKey: Keys.chapterUpdatesState) else { return nil }
        return try? JSONDecoder().decode(ChapterUpdates.State.self, from: data)
    }

    func setChapterUpdatesState(_ state: ChapterUpdates.State?) {
        if let state, let data = try? JSONEncoder().encode(state) {
            defaults.set(data, forKey: Keys.chapterUpdatesState)
        } else {
            defaults.removeObject(forKey: Keys.chapterUpdatesState)
        }
    }

    // MARK: Background checks

    func lastRun(_ check: String) -> Date? { defaults.object(forKey: "checks.lastRun.\(check)") as? Date }
    func setLastRun(_ check: String, _ date: Date) { defaults.set(date, forKey: "checks.lastRun.\(check)") }

    // MARK: Requests screen

    /// The search picker's last medium on this device.
    var requestMedium: String? {
        get { defaults.string(forKey: Keys.requestMedium) }
        nonmutating set { defaults.set(newValue, forKey: Keys.requestMedium) }
    }

    private enum Keys {
        static let requestsNotify = "requests.notify"
        static let newChaptersNotify = "newChapters.notify"
        static let chapterUpdatesState = "chapterUpdates.state"
        static let requestMedium = "requests.medium"
    }
}
