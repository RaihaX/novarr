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
    /// The second sign-in step after a right password; nil on the password
    /// form. In memory only: the challenge is never written anywhere.
    private(set) var secondStep: SecondStepFlow?
    /// "Link with a code" in progress; nil when it isn't showing.
    private(set) var pairing: PairingFlow?
    /// What the password form says after a sign-in flow sent it back there
    /// (the second step took too long).
    private(set) var signInError: String?

    /// Whether the last request reached the server.
    private(set) var isReachable = true
    private(set) var pendingWrites = 0
    /// Bumped after read-state or library changes so list screens know to reload.
    private(set) var readStateRevision = 0

    let downloads = DownloadManager()
    /// "Check for new chapters" runs, watched app-wide.
    let checks = ChapterChecks()
    /// The reader's categories (reading lists), shared by every screen.
    let lists = ListsModel()
    /// The audiobook player, its downloads and uploads (Listen).
    let listening = ListeningModel()

    /// This install's identity, sent with read-state writes and sign-in.
    let device: DeviceIdentity

    @ObservationIgnored let credentials: CredentialStore
    @ObservationIgnored private let defaults: UserDefaults
    @ObservationIgnored private let session: URLSession
    @ObservationIgnored private let storageBase: URL
    @ObservationIgnored private let monitor = NWPathMonitor()
    @ObservationIgnored private let log = Logger(subsystem: "studio.visns.novarr", category: "App")
    /// The one poll loop for "Link with a code"; owned here so a re-created
    /// sign-in screen never asks for a second code.
    @ObservationIgnored private var pairingTask: Task<Void, Never>?
    /// Injectable for tests: the time, and the wait between two polls.
    @ObservationIgnored var currentDate: @Sendable () -> Date = { Date() }
    @ObservationIgnored var sleep: @Sendable (TimeInterval) async throws -> Void = { seconds in
        try await Task.sleep(for: .seconds(seconds))
    }

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
        lists.attach(self)
        listening.attach(self)
        // Also honours `-serverURL http://…` as a launch argument (argument domain).
        if let raw = defaults.string(forKey: Self.serverDefaultsKey), let url = ServerURL.normalize(raw) {
            configure(url)
            // Learn whether the server now wants a sign-in (it may have turned
            // auth on since the last launch).
            if refreshMetaOnLaunch { Task { [weak self] in await self?.refreshMeta() } }
        }
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor in await self?.networkBecameAvailable() }
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
        resetSignInFlows()
        configure(url)
        serverMeta = meta
        isReachable = true
        listening.audiobooksOffered(meta.audiobooks)
        // What it offers, for the notifications' background refresh.
        NotificationHub.shared.noteMeta(meta, for: url)
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
        lists.reset()
        resetSignInFlows()
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
        listening.sessionChanged()
    }

    /// Ends the session without touching anything on disk: the queue file
    /// stays where it is and is picked up again after the next sign-in.
    private func requireSignIn(notice: String?) {
        checks.cancelAll()
        downloads.cancelAll()
        lists.reset()
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
        listening.sessionChanged()
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
        listening.audiobooksOffered(meta.audiobooks)
        // What it offers, for the notifications' background refresh.
        NotificationHub.shared.noteMeta(meta, for: url)
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

    /// The name typed before a second step that was cancelled or ran out.
    @ObservationIgnored private var interruptedUsername: String?

    /// The name last used to sign in to this server, to prefill the form.
    var lastUsername: String? {
        guard let serverURL else { return nil }
        if let interruptedUsername { return interruptedUsername }
        return (defaults.dictionary(forKey: Self.lastUsernameDefaultsKey) as? [String: String])?[
            ServerURL.storageKey(for: serverURL)]
    }

    /// Password sign-in. Throws `APIError` (422 wrong name or password, 429
    /// throttled, 403 `.secondStepUnavailable`, connectivity) for the form to
    /// show. When the server asks for a sign-in code, `secondStep` is set and
    /// the sign-in finishes in `submitSecondStep`; the password isn't kept.
    func signIn(username: String, password: String) async throws {
        guard let url = serverURL else { throw APIError.invalidServerURL }
        signInError = nil
        let outcome = try await APIClient(baseURL: url, session: session, device: device)
            .signIn(username: username, password: password, device: device)
        guard serverURL == url else { return }
        isReachable = true
        switch outcome {
        case .signedIn(let response):
            completeSignIn(response, username: username, url: url)
        case .secondStepRequired(let challenge):
            cancelPairing()
            secondStep = SecondStepFlow(server: url, username: username, challenge: challenge)
        }
    }

    /// Keeps the token and starts the session: the end of every sign-in
    /// (password, second step or link code).
    private func completeSignIn(_ response: AuthTokenResponse, username: String, url: URL) {
        let credential = Credential(token: response.token, user: response.user)
        if !credentials.save(credential, for: url) {
            log.error("Signed in, but the token could not be saved; it lasts until the app quits")
        }
        setAuthRequired(true, for: url)
        if !username.isEmpty { rememberUsername(username, for: url) }
        clearHTTPCache()
        signInNotice = nil
        signInError = nil
        isReachable = true
        activate(url, credential: credential)
    }

    // MARK: Second step

    func selectSecondStepMethod(_ method: SecondStepMethod) {
        secondStep?.select(method)
    }

    /// "Email me a code" / "Send again". Never sent without a tap.
    func requestEmailCode() async {
        guard let flow = secondStep, serverURL == flow.server, flow.canSendEmail(at: currentDate()) else { return }
        do {
            try await APIClient(baseURL: flow.server, session: session)
                .requestEmailCode(challenge: flow.challenge.challenge, device: device)
            guard secondStep?.challenge == flow.challenge else { return }
            secondStep?.emailSent(at: currentDate())
        } catch {
            guard secondStep?.challenge == flow.challenge else { return }
            secondStepFailed(APIError.from(error))
        }
    }

    /// Sends the code with the method in use; on 200 signs in like a password
    /// sign-in, else `secondStep.errorMessage` says why (or, the challenge
    /// gone, the password form does).
    func submitSecondStep(code: String) async {
        guard let flow = secondStep, serverURL == flow.server else { return }
        let method = flow.method
        do {
            let response = try await APIClient(baseURL: flow.server, session: session)
                .submitSecondStep(challenge: flow.challenge.challenge, method: method,
                                  code: SecondStepFlow.normalize(code, for: method), device: device)
            guard secondStep?.challenge == flow.challenge, serverURL == flow.server else { return }
            completeSignIn(response, username: flow.username, url: flow.server)
        } catch {
            guard secondStep?.challenge == flow.challenge else { return }
            secondStepFailed(APIError.from(error))
        }
    }

    /// Back to the password form.
    func cancelSecondStep() {
        interruptedUsername = secondStep?.username
        secondStep = nil
    }

    private func secondStepFailed(_ error: APIError) {
        guard var flow = secondStep else { return }
        switch flow.receive(error) {
        case .retry:
            secondStep = flow
        case .restart(let message):
            interruptedUsername = flow.username
            secondStep = nil
            signInError = message
        }
    }

    // MARK: Link with a code

    /// Shows a link code and polls it. Does nothing while one is already being
    /// asked for or shown, so the screen appearing again never asks twice.
    func startPairing() {
        guard let url = serverURL else { return }
        if let pairing, pairing.server == url, pairing.isActive { return }
        newPairingCode()
    }

    /// "Get a new code" (after the last one ran out or failed), or the first one.
    func newPairingCode() {
        guard let url = serverURL else { return }
        pairingTask?.cancel()
        secondStep = nil
        signInError = nil
        pairing = PairingFlow(server: url)
        pairingTask = Task { [weak self] in await self?.runPairing(url: url) }
    }

    /// Cancel: stops polling; nothing more is sent.
    func cancelPairing() {
        pairingTask?.cancel()
        pairingTask = nil
        pairing = nil
    }

    private func runPairing(url: URL) async {
        let client = APIClient(baseURL: url, session: session)
        var step: PairingFlow.Step
        do {
            let start = try await client.startPairing(device: device)
            guard !Task.isCancelled, var flow = pairing, flow.server == url else { return }
            step = flow.started(start, at: currentDate())
            pairing = flow
        } catch {
            guard !Task.isCancelled, var flow = pairing, flow.server == url else { return }
            _ = flow.startFailed(APIError.from(error))
            pairing = flow
            return
        }
        while case .poll(let delay) = step {
            do { try await sleep(delay) } catch { return } // cancelled
            guard !Task.isCancelled, var flow = pairing, flow.server == url, let secret = flow.secret else { return }
            if flow.expireIfDue(at: currentDate()) {
                pairing = flow
                return
            }
            let answer: Result<PairingPoll, APIError>
            do {
                answer = .success(try await client.pollPairing(secret: secret))
            } catch {
                answer = .failure(APIError.from(error))
            }
            guard !Task.isCancelled, var current = pairing, current.server == url, serverURL == url else { return }
            switch answer {
            case .success(let poll):
                step = current.received(poll, at: currentDate())
                pairing = current
                if case .linked(let response) = poll {
                    completeSignIn(response, username: response.user.username, url: url)
                    return
                }
            case .failure(let error):
                step = current.pollFailed(error, at: currentDate())
                pairing = current
            }
        }
    }

    private func resetSignInFlows() {
        interruptedUsername = nil
        secondStep = nil
        signInError = nil
        pairingTask?.cancel()
        pairingTask = nil
        pairing = nil
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
        case .highlight: break // not read state: lists needn't reload
        default: readStateRevision += 1
        }
        await flushWrites()
    }

    /// One series' writes not sent yet, oldest first (the local resume rule, `ResumeRule`).
    func unsentWrites(novelId: Int) async -> [PendingWrite] {
        guard let queue else { return [] }
        return await queue.items.filter { $0.novelId == novelId }
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

    /// Whether a progress or read/unread write for this chapter is still waiting to be sent.
    func hasUnsentWrite(chapterId: Int) async -> Bool {
        guard let queue else { return false }
        return PendingWrite.containsChapterWrite(await queue.items, chapterId: chapterId)
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

    /// The network path came back: send queued writes and, if the server
    /// was marked unreachable, ask it again (meta is cheap and public) so
    /// `isReachable` recovers without waiting for some other request.
    func networkBecameAvailable() async {
        if !isReachable { await refreshMeta() }
        await flushWrites()
        await listening.flush()
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
