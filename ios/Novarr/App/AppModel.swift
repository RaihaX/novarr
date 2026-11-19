import Foundation
import Network
import Observation
import os

/// Result of a read that may have come from the offline cache.
struct Loaded<Value: Sendable>: Sendable {
    var value: Value?
    var fromCache: Bool
    var error: APIError?
}

/// App-wide state: the configured server, who is signed in to it, its client,
/// offline store and write queue, plus connectivity.
///
/// Sessions:
/// - auth off (server has no `auth.required`): no account, today's behaviour.
/// - signed in: a Keychain credential for this server; requests carry its
///   token and the cache/queue live in that user's directory.
/// - needs sign-in: auth is on and there is no token; no client, store or
///   queue (queued writes stay on disk), and the root shows the sign-in screen.
@MainActor
@Observable
final class AppModel {
    nonisolated static let serverDefaultsKey = "serverURL"
    /// `[server storage key: Bool]`: whether the server last said it needs a
    /// sign-in, so an offline launch knows before meta answers.
    nonisolated static let authRequiredDefaultsKey = "serverAuthRequired"
    /// `[server storage key: username]`, to prefill the sign-in form.
    nonisolated static let lastUsernameDefaultsKey = "lastUsername"

    private(set) var serverURL: URL?
    private(set) var client: APIClient?
    private(set) var store: OfflineStore?
    private(set) var queue: WriteQueue?
    private(set) var serverMeta: ServerMeta?

    /// The signed-in user and token; nil with auth off or when signed out.
    private(set) var account: Credential?
    /// Auth is on for this server and nobody is signed in: show sign-in.
    private(set) var needsSignIn = false
    /// Why the sign-in screen is showing (session ended), if not a plain sign-out.
    private(set) var signInNotice: String?

    /// Whether the last request reached the server.
    private(set) var isReachable = true
    private(set) var pendingWrites = 0
    /// Bumped after read-state or library changes so list screens know to reload.
    private(set) var readStateRevision = 0

    let downloads = DownloadManager()
    /// "Check for new chapters" runs, watched app-wide.
    let checks = ChapterChecks()

    /// This install's identity, sent with read-state writes and sign-in.
    let device: DeviceIdentity

    @ObservationIgnored let credentials: CredentialStore
    @ObservationIgnored private let defaults: UserDefaults
    @ObservationIgnored private let session: URLSession
    @ObservationIgnored private let storageBase: URL
    @ObservationIgnored private let monitor = NWPathMonitor()
    @ObservationIgnored private let log = Logger(subsystem: "studio.visns.novarr", category: "App")

    init(defaults: UserDefaults = .standard,
         credentials: CredentialStore = CredentialStore(),
         session: URLSession = .novarr,
         storageBase: URL = OfflineStore.defaultBase,
         refreshMetaOnLaunch: Bool = true) {
        self.defaults = defaults
        self.credentials = credentials
        self.session = session
        self.storageBase = storageBase
        device = DeviceIdentity.loadOrCreate(defaults: defaults, name: DeviceIdentity.currentName())
        checks.onChaptersChanged = { [weak self] _ in self?.libraryChanged() }
        // Also honours `-serverURL http://…` as a launch argument (argument domain).
        if let raw = defaults.string(forKey: Self.serverDefaultsKey), let url = ServerURL.normalize(raw) {
            configure(url)
            // Learn whether the server now wants a sign-in (it may have turned
            // auth on since the last launch).
            if refreshMetaOnLaunch { Task { [weak self] in await self?.refreshMeta() } }
        }
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor in await self?.flushWrites() }
        }
        monitor.start(queue: DispatchQueue(label: "studio.visns.novarr.path"))
    }

    /// Changes whenever the server or the signed-in user does, so screens
    /// (and their models) are rebuilt and never show another account's data.
    var sessionID: String {
        "\(serverURL?.absoluteString ?? "")|\(account.map { String($0.user.id) } ?? "-")"
    }

    // MARK: Server

    /// Saves a server that `APIClient.meta()` already verified.
    func connect(to url: URL, meta: ServerMeta) {
        defaults.set(url.absoluteString, forKey: Self.serverDefaultsKey)
        setAuthRequired(meta.requiresSignIn, for: url)
        signInNotice = nil
        configure(url)
        serverMeta = meta
        isReachable = true
    }

    private func configure(_ url: URL) {
        serverURL = url
        let required = authRequired(for: url)
        if required != false, let credential = credentials.credential(for: url) {
            activate(url, credential: credential)
        } else if required == true {
            requireSignIn(notice: signInNotice)
        } else {
            activate(url, credential: nil)
        }
    }

    /// Starts a session: a client (with the token, if any) and the store and
    /// queue for this server and user.
    private func activate(_ url: URL, credential: Credential?) {
        checks.cancelAll()
        downloads.cancelAll()
        account = credential
        needsSignIn = false
        client = APIClient(baseURL: url, session: session, device: device, token: credential?.token,
                           onUnauthorized: { [weak self] rejected in
                               Task { @MainActor in self?.handleUnauthorized(rejectedToken: rejected) }
                           })
        let directory = OfflineStore.resolveDirectory(for: url, user: credential?.user, base: storageBase)
        store = OfflineStore(root: directory)
        let queue = WriteQueue(fileURL: directory.appending(path: OfflineStore.writeQueueFileName))
        self.queue = queue
        readStateRevision += 1
        Task { [weak self] in
            self?.pendingWrites = await queue.count
            await self?.flushWrites()
        }
    }

    /// Ends the session without touching anything on disk: the queue file
    /// stays where it is and is picked up again after the next sign-in.
    private func requireSignIn(notice: String?) {
        checks.cancelAll()
        downloads.cancelAll()
        if pendingWrites > 0, let notice {
            signInNotice = notice + " Your unsynced reading progress is kept and will be sent after you sign in."
        } else {
            signInNotice = notice
        }
        account = nil
        client = nil
        store = nil
        queue = nil
        pendingWrites = 0
        needsSignIn = true
        readStateRevision += 1
    }

    func refreshMeta() async {
        guard let url = serverURL else { return }
        do {
            // Meta is public, so this works signed out too.
            let meta = try await APIClient(baseURL: url, session: session).meta()
            guard serverURL == url else { return }
            apply(meta, for: url)
            isReachable = true
        } catch {
            noteFailure(APIError.from(error))
            return
        }
        await refreshAccount()
    }

    /// Picks up a changed name or role for the signed-in user. A 401 here
    /// ends the session through the client's handler like any other request.
    private func refreshAccount() async {
        guard let url = serverURL, let client, let current = account,
              let user = try? await client.me() else { return }
        guard serverURL == url, account == current, user.id == current.user.id, user != current.user else { return }
        let updated = Credential(token: current.token, user: user)
        credentials.save(updated, for: url)
        account = updated
    }

    /// Follows the server's auth switch: on → sign in (or keep the session);
    /// off → back to today's behaviour.
    func apply(_ meta: ServerMeta, for url: URL) {
        serverMeta = meta
        setAuthRequired(meta.requiresSignIn, for: url)
        if meta.requiresSignIn {
            guard account == nil, !needsSignIn else { return }
            if let credential = credentials.credential(for: url) {
                activate(url, credential: credential)
            } else {
                requireSignIn(notice: nil)
            }
        } else if account != nil || needsSignIn {
            // The saved sign-in is kept (unused) in case auth comes back on.
            signInNotice = nil
            activate(url, credential: nil)
        }
    }

    // MARK: Sign-in

    /// The name last used to sign in to this server, to prefill the form.
    var lastUsername: String? {
        guard let serverURL else { return nil }
        return (defaults.dictionary(forKey: Self.lastUsernameDefaultsKey) as? [String: String])?[
            ServerURL.storageKey(for: serverURL)]
    }

    /// Password sign-in. Throws `APIError` (422 wrong name or password, 429
    /// throttled, connectivity) for the form to show.
    func signIn(username: String, password: String) async throws {
        guard let url = serverURL else { throw APIError.invalidServerURL }
        let response = try await APIClient(baseURL: url, session: session, device: device)
            .signIn(username: username, password: password, device: device)
        guard serverURL == url else { return }
        let credential = Credential(token: response.token, user: response.user)
        if !credentials.save(credential, for: url) {
            log.error("Signed in, but the token could not be saved; it lasts until the app quits")
        }
        setAuthRequired(true, for: url)
        rememberUsername(username, for: url)
        clearHTTPCache()
        signInNotice = nil
        isReachable = true
        activate(url, credential: credential)
    }

    /// Revokes the token on the server (best effort: offline still signs out
    /// here), forgets it and returns to the sign-in screen. Queued writes stay
    /// in this user's directory for their next sign-in.
    func signOut() async {
        guard let url = serverURL, account != nil else { return }
        if let client { try? await client.signOut() }
        guard serverURL == url else { return }
        credentials.remove(for: url)
        clearHTTPCache()
        requireSignIn(notice: nil)
    }

    /// A request was rejected with 401. Acts once per token: a late 401 from
    /// a token already replaced by a new sign-in is ignored, and with no
    /// client left nothing else is sent, so this can't loop.
    func handleUnauthorized(rejectedToken: String?) {
        guard let url = serverURL, !needsSignIn, rejectedToken == account?.token else { return }
        let wasSignedIn = account != nil
        if wasSignedIn { credentials.remove(for: url) }
        setAuthRequired(true, for: url)
        clearHTTPCache()
        requireSignIn(notice: wasSignedIn
                      ? "You've been signed out. Sign in again to continue."
                      : "This server now asks you to sign in.")
    }

    private func authRequired(for url: URL) -> Bool? {
        (defaults.dictionary(forKey: Self.authRequiredDefaultsKey) as? [String: Bool])?[ServerURL.storageKey(for: url)]
    }

    private func setAuthRequired(_ required: Bool, for url: URL) {
        var stored = (defaults.dictionary(forKey: Self.authRequiredDefaultsKey) as? [String: Bool]) ?? [:]
        stored[ServerURL.storageKey(for: url)] = required
        defaults.set(stored, forKey: Self.authRequiredDefaultsKey)
    }

    private func rememberUsername(_ username: String, for url: URL) {
        var stored = (defaults.dictionary(forKey: Self.lastUsernameDefaultsKey) as? [String: String]) ?? [:]
        stored[ServerURL.storageKey(for: url)] = username
        defaults.set(stored, forKey: Self.lastUsernameDefaultsKey)
    }

    /// Responses carry per-user read state; never revalidate one account's
    /// cached copy for another.
    private func clearHTTPCache() {
        session.configuration.urlCache?.removeAllCachedResponses()
    }

    // MARK: Reads with offline fallback

    /// Fetches from the server and caches the result; if the server can't be
    /// reached, returns the cached copy (marked `fromCache`) with the error.
    func load<Value: Codable & Sendable>(
        _ key: OfflineStore.Key,
        fetch: @Sendable (APIClient) async throws -> Value
    ) async -> Loaded<Value> {
        guard let client, let store else {
            return Loaded(value: nil, fromCache: false, error: .invalidServerURL)
        }
        do {
            let value = try await fetch(client)
            noteSuccess()
            await store.save(value, as: key)
            return Loaded(value: value, fromCache: false, error: nil)
        } catch {
            let apiError = APIError.from(error)
            noteFailure(apiError)
            let cached = await store.load(Value.self, as: key)
            return Loaded(value: cached, fromCache: cached != nil, error: apiError)
        }
    }

    func cached<Value: Codable & Sendable>(_ type: Value.Type, _ key: OfflineStore.Key) async -> Value? {
        await store?.load(type, as: key)
    }

    // MARK: Writes

    /// Queues a read-state write (persisted immediately) and tries to send it.
    func record(_ write: PendingWrite.Kind, novelId: Int? = nil) async {
        guard let queue else { return }
        await queue.enqueue(write, novelId: novelId)
        pendingWrites = await queue.count
        switch write {
        case .progress(_, _, let read) where !read: break
        default: readStateRevision += 1
        }
        await flushWrites()
    }

    func flushWrites() async {
        guard let queue, let client else { return }
        guard await queue.count > 0 else {
            if self.queue === queue { pendingWrites = 0 }
            return
        }
        let outcome = await queue.flush(using: client)
        // The session may have ended (401) while this flush ran.
        guard self.queue === queue else { return }
        pendingWrites = await queue.count
        switch outcome {
        case .drained(let sent, _) where sent > 0:
            noteSuccess()
            readStateRevision += 1
        case .stalled:
            isReachable = false
        case .unauthenticated:
            // The client already reported it; the writes stay queued on disk.
            break
        default:
            break
        }
    }

    /// Read-state writes for a series still waiting to be sent, by when they were recorded.
    func unsentWriteTimes(novelId: Int) async -> [Date] {
        guard let queue else { return [] }
        return await queue.items
            .filter { $0.novelId == novelId && $0.kind.movesPosition }
            .map(\.recordedAt)
    }

    // MARK: Latest position prompt

    private var latestDeclines: LatestPositionDeclines {
        LatestPositionDeclines(defaults: defaults, server: serverURL, userId: account?.user.id)
    }

    func declinedLatestPosition(novelId: Int) -> Date? {
        latestDeclines.declinedAt(novelId: novelId)
    }

    func declineLatestPosition(at: Date, novelId: Int) {
        latestDeclines.decline(at, novelId: novelId)
    }

    // MARK: Library changes

    /// The library gained a novel or chapters: Home and Library reload when
    /// they next appear (they compare this revision).
    func libraryChanged() {
        readStateRevision += 1
    }

    /// Starts (or joins) a check for new chapters on the server.
    func startCheck(novelId: Int) {
        guard let client else { return }
        checks.start(novelId: novelId, client: client)
    }

    // MARK: Connectivity bookkeeping

    func noteSuccess() {
        let wasUnreachable = !isReachable
        isReachable = true
        if wasUnreachable, pendingWrites > 0 {
            Task { await flushWrites() }
        }
    }

    func noteFailure(_ error: APIError) {
        if error.isConnectivity { isReachable = false }
    }

    // MARK: Storage

    func clearCaches() async {
        await store?.clearAll()
        await CoverCache.shared.clear()
        clearHTTPCache()
        downloads.contentChanged()
    }
}
