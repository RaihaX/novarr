import Foundation
import Testing
@testable import Novarr

// MARK: - Helpers

/// The Keychain stand-in: a dictionary behind a lock.
final class InMemorySecureStore: SecureStore, @unchecked Sendable {
    private let lock = NSLock()
    private var items: [String: Data] = [:]

    var keys: Set<String> { lock.withLock { Set(items.keys) } }

    func data(for key: String) throws -> Data? { lock.withLock { items[key] } }
    func set(_ data: Data, for key: String) throws { lock.withLock { items[key] = data } }
    func remove(for key: String) throws { _ = lock.withLock { items.removeValue(forKey: key) } }
}

/// Every request a stub server saw.
final class RequestLog: @unchecked Sendable {
    private let lock = NSLock()
    private var _requests: [(request: URLRequest, body: Data?)] = []

    func append(_ request: URLRequest, _ body: Data?) { lock.withLock { _requests.append((request, body)) } }
    var requests: [(request: URLRequest, body: Data?)] { lock.withLock { _requests } }
    var count: Int { lock.withLock { _requests.count } }
    var last: URLRequest? { lock.withLock { _requests.last?.request } }
}

private let unauthenticatedBody = Data(#"{"message":"Unauthenticated.","code":"unauthenticated"}"#.utf8)

private func temporaryDirectory() -> URL {
    FileManager.default.temporaryDirectory
        .appending(path: "novarr-tests-\(UUID().uuidString)", directoryHint: .isDirectory)
}

private func testDefaults(server: URL?, authRequired: Bool?) -> UserDefaults {
    let defaults = UserDefaults(suiteName: "novarr-tests-\(UUID().uuidString)") ?? .standard
    if let server {
        defaults.set(server.absoluteString, forKey: AppModel.serverDefaultsKey)
        if let authRequired {
            defaults.set([ServerURL.storageKey(for: server): authRequired], forKey: AppModel.authRequiredDefaultsKey)
        }
    }
    return defaults
}

/// Polls until `condition` holds (the 401 handler hops to the main actor).
@MainActor
private func eventually(_ what: Comment = "condition", timeout: Duration = .seconds(3),
                        _ condition: @MainActor () async -> Bool) async throws {
    let deadline = ContinuousClock.now + timeout
    while !(await condition()) {
        if ContinuousClock.now > deadline {
            Issue.record("Timed out waiting for \(what)")
            return
        }
        try await Task.sleep(for: .milliseconds(10))
    }
}

private let owner = AuthUser(id: 1, name: "Reyhan", username: "reyhan", role: .admin)
private let reader = AuthUser(id: 2, name: "Sam", username: "sam", role: .reader)

// MARK: - Meta

@Suite("Server meta: auth")
struct MetaAuthDecodingTests {
    @Test func absentMeansNoSignIn() throws {
        let meta = try Fixture.decode(ServerMeta.self, "meta")
        #expect(meta.auth == nil)
        #expect(meta.requiresSignIn == false)
    }

    @Test func requiredFalse() throws {
        let meta = try Fixture.decode(ServerMeta.self, json: """
            {"app":"novarr","api_version":1,"auth":{"required":false,"methods":["password","pairing"]}}
            """)
        #expect(meta.auth == ServerAuth(required: false, methods: ["password", "pairing"]))
        #expect(meta.requiresSignIn == false)
    }

    @Test func requiredTrue() throws {
        let meta = try Fixture.decode(ServerMeta.self, json: """
            {"app":"novarr","api_version":1,"server_version":"abc","auth":{"required":true,"methods":["password"]}}
            """)
        #expect(meta.auth?.methods == ["password"])
        #expect(meta.requiresSignIn)
    }

    @Test func malformedAuthIsIgnoredNotFatal() throws {
        let meta = try Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1,"auth":"yes"}"#)
        #expect(meta.app == "novarr")
        #expect(meta.requiresSignIn == false)
    }

    @Test func signInResponseAndRoles() throws {
        let response = try Fixture.decode(AuthTokenResponse.self, json: """
            {"token":"abc|123","user":{"id":1,"name":"Reyhan","username":"reyhan","role":"admin"}}
            """)
        #expect(response.token == "abc|123")
        #expect(response.user == owner)
        let other = try Fixture.decode(AuthUserEnvelope.self, json: #"{"user":{"id":"9","username":"x","role":"guest"}}"#)
        #expect(other.user.id == 9)
        #expect(other.user.name == "x")
        #expect(other.user.role == .other("guest"))
    }
}

// MARK: - Request building

@Suite("Requests carry the token")
struct AuthorizationHeaderTests {
    let base = URL(string: "http://novarr.local:8080")!

    @Test func addsBearerWhenSignedIn() {
        let client = APIClient(baseURL: base, token: "tok123")
        let request = client.makeRequest("GET", "novels")
        #expect(request.value(forHTTPHeaderField: "Authorization") == "Bearer tok123")
        let write = client.makeRequest("POST", "chapters/1/read", headers: ["X-Novarr-Device": "i_abc"])
        #expect(write.value(forHTTPHeaderField: "Authorization") == "Bearer tok123")
        #expect(write.value(forHTTPHeaderField: "X-Novarr-Device") == "i_abc")
        #expect(write.httpMethod == "POST")
    }

    @Test func omitsWithoutTokenOrForPublicEndpoints() {
        #expect(APIClient(baseURL: base).makeRequest("GET", "novels")
            .value(forHTTPHeaderField: "Authorization") == nil)
        #expect(APIClient(baseURL: base, token: "").makeRequest("GET", "novels")
            .value(forHTTPHeaderField: "Authorization") == nil)
        #expect(APIClient(baseURL: base, token: "tok").makeRequest("GET", "meta", authenticated: false)
            .value(forHTTPHeaderField: "Authorization") == nil)
    }

    @Test func liveRequestsCarryHeaderExceptMetaAndSignIn() async throws {
        let log = RequestLog()
        let device = DeviceIdentity(id: "i_testdevice01", name: "iPhone")
        let client = StubURLProtocol.client(device: device, token: "tok") { request, body in
            log.append(request, body)
            switch request.url?.path {
            case "/api/v1/meta": return (200, try Fixture.data("meta"))
            case "/api/v1/novels": return (200, Data(#"{"novels":[]}"#.utf8))
            case "/api/v1/auth/token":
                return (200, Data(#"{"token":"new","user":{"id":1,"name":"R","username":"r","role":"admin"}}"#.utf8))
            default: return (204, Data())
            }
        }
        _ = try await client.meta()
        _ = try await client.novels()
        try await client.send(.setRead(chapterId: 3, read: true), age: 0)
        _ = try await client.signIn(username: "reyhan", password: "secret", device: device)

        let requests = log.requests
        #expect(requests.count == 4)
        #expect(requests[0].request.value(forHTTPHeaderField: "Authorization") == nil) // meta
        #expect(requests[1].request.value(forHTTPHeaderField: "Authorization") == "Bearer tok")
        #expect(requests[2].request.value(forHTTPHeaderField: "Authorization") == "Bearer tok")
        #expect(requests[2].request.value(forHTTPHeaderField: DeviceIdentity.idHeader) == "i_testdevice01")
        #expect(requests[3].request.value(forHTTPHeaderField: "Authorization") == nil) // sign-in
        #expect(requests[3].request.httpMethod == "POST")
        let body = try #require(requests[3].body)
        let json = try #require(try JSONSerialization.jsonObject(with: body) as? [String: String])
        #expect(json == ["username": "reyhan", "password": "secret",
                         "device_id": "i_testdevice01", "device_name": "iPhone"])
    }

    @Test func reportsRejectedTokenOn401ButNotForSignOut() async throws {
        let rejected = RequestLog()
        let client = StubURLProtocol.client(token: "tok", onUnauthorized: { token in
            var request = URLRequest(url: URL(string: "http://x")!)
            request.setValue(token, forHTTPHeaderField: "Rejected")
            rejected.append(request, nil)
        }) { _, _ in (401, unauthenticatedBody) }

        await #expect(throws: APIError.http(status: 401, message: "Unauthenticated.")) {
            _ = try await client.novels()
        }
        #expect(rejected.count == 1)
        #expect(rejected.last?.value(forHTTPHeaderField: "Rejected") == "tok")

        try? await client.signOut()
        #expect(rejected.count == 1)
    }

    @Test func forbiddenShowsTheServersMessage() {
        let error = APIError.http(status: 403, message: "Readers can't add series.")
        #expect(error.isForbidden)
        #expect(error.localizedDescription == "Readers can't add series.")
        #expect(APIError.http(status: 403, message: nil).localizedDescription == "Your account isn't allowed to do that.")
    }

    @Test func signInMessages() {
        #expect(SignInMessages.message(for: .http(status: 422, message: "Wrong name or password.")) == "Wrong name or password.")
        #expect(SignInMessages.message(for: .http(status: 422, message: nil)) == "Wrong name or password.")
        #expect(SignInMessages.message(for: .http(status: 429, message: "Too many attempts. Try again in 60 seconds."))
                == "Too many attempts. Try again in 60 seconds.")
        #expect(SignInMessages.message(for: .http(status: 429, message: nil)).contains("Too many"))
        #expect(SignInMessages.message(for: .unreachable("offline")).contains("Couldn't reach"))
    }
}

// MARK: - 401 handling

@Suite("Losing the session")
@MainActor
struct UnauthorizedTests {
    @Test func writeQueueKeepsWritesOn401() async {
        let queue = WriteQueue(fileURL: temporaryFile())
        await queue.enqueue(.setRead(chapterId: 1, read: true))
        await queue.enqueue(.setRead(chapterId: 2, read: true))
        let outcome = await queue.flush { _, _ in throw APIError.http(status: 401, message: nil) }
        #expect(outcome == .unauthenticated(sent: 0, remaining: 2))
        #expect(await queue.count == 2)
    }

    @Test func unauthorizedForgetsTokenKeepsQueueAndDoesNotLoop() async throws {
        let log = RequestLog()
        let (base, session) = StubURLProtocol.register { request, body in
            log.append(request, body)
            if request.url?.path == "/api/v1/auth/token" {
                return (200, Data(#"{"token":"new-token","user":{"id":1,"name":"Reyhan","username":"reyhan","role":"admin"}}"#.utf8))
            }
            if request.value(forHTTPHeaderField: "Authorization") == "Bearer new-token" { return (204, Data()) }
            return (401, unauthenticatedBody)
        }
        let credentials = CredentialStore(secure: InMemorySecureStore())
        credentials.save(Credential(token: "old-token", user: owner), for: base)
        let storage = temporaryDirectory()
        let app = AppModel(defaults: testDefaults(server: base, authRequired: true), credentials: credentials,
                           session: session, storageBase: storage, refreshMetaOnLaunch: false)
        #expect(app.account?.token == "old-token")
        #expect(app.needsSignIn == false)

        await app.record(.setRead(chapterId: 5, read: true))
        try await eventually("sign-in screen") { app.needsSignIn }

        #expect(app.account == nil)
        #expect(app.client == nil)
        #expect(credentials.credential(for: base) == nil)
        #expect(app.signInNotice?.contains("signed out") == true)
        // The queued write is still on disk in this user's directory.
        let directory = OfflineStore.directory(for: base, userId: owner.id, base: storage)
        let onDisk = WriteQueue(fileURL: directory.appending(path: OfflineStore.writeQueueFileName))
        #expect(await onDisk.items.map(\.kind) == [.setRead(chapterId: 5, read: true)])

        // No loop: with the session gone nothing more is sent.
        let sent = log.count
        await app.flushWrites()
        app.handleUnauthorized(rejectedToken: "old-token")
        try await Task.sleep(for: .milliseconds(50))
        #expect(log.count == sent)

        // Signing in again sends the kept write with the new token.
        try await app.signIn(username: "reyhan", password: "pw")
        #expect(app.account?.token == "new-token")
        #expect(credentials.credential(for: base)?.token == "new-token")
        try await eventually("queue flushed") { await app.queue?.count == 0 }
        let last = try #require(log.last)
        #expect(last.url?.path == "/api/v1/chapters/5/read")
        #expect(last.value(forHTTPHeaderField: "Authorization") == "Bearer new-token")

        // A late 401 for the replaced token doesn't end the new session.
        app.handleUnauthorized(rejectedToken: "old-token")
        #expect(app.needsSignIn == false)
        #expect(app.account?.token == "new-token")
    }

    @Test func authTurnedOnUnderAnOpenSessionAsksToSignIn() async throws {
        let (base, session) = StubURLProtocol.register { _, _ in (401, unauthenticatedBody) }
        let app = AppModel(defaults: testDefaults(server: base, authRequired: nil),
                           credentials: CredentialStore(secure: InMemorySecureStore()),
                           session: session, storageBase: temporaryDirectory(), refreshMetaOnLaunch: false)
        #expect(app.needsSignIn == false)
        _ = await app.load(.novels) { try await $0.novels() }
        try await eventually("sign-in screen") { app.needsSignIn }
        #expect(app.signInNotice == "This server now asks you to sign in.")
    }

    @Test func signOutRevokesForgetsAndShowsSignIn() async throws {
        let log = RequestLog()
        let (base, session) = StubURLProtocol.register { request, body in
            log.append(request, body)
            return (204, Data())
        }
        let secure = InMemorySecureStore()
        let credentials = CredentialStore(secure: secure)
        credentials.save(Credential(token: "tok", user: reader), for: base)
        let app = AppModel(defaults: testDefaults(server: base, authRequired: true), credentials: credentials,
                           session: session, storageBase: temporaryDirectory(), refreshMetaOnLaunch: false)
        await app.signOut()
        let delete = try #require(log.requests.first { $0.request.httpMethod == "DELETE" }?.request)
        #expect(delete.url?.path == "/api/v1/auth/token")
        #expect(delete.value(forHTTPHeaderField: "Authorization") == "Bearer tok")
        #expect(secure.keys.isEmpty)
        #expect(app.needsSignIn)
        #expect(app.signInNotice == nil)
    }

    @Test func signOutWorksOffline() async throws {
        let (base, session) = StubURLProtocol.register { _, _ in throw URLError(.notConnectedToInternet) }
        let credentials = CredentialStore(secure: InMemorySecureStore())
        credentials.save(Credential(token: "tok", user: owner), for: base)
        let app = AppModel(defaults: testDefaults(server: base, authRequired: true), credentials: credentials,
                           session: session, storageBase: temporaryDirectory(), refreshMetaOnLaunch: false)
        await app.signOut()
        #expect(app.needsSignIn)
        #expect(credentials.credential(for: base) == nil)
    }

    @Test func metaSwitchesBetweenSignInAndOpen() async throws {
        let (base, session) = StubURLProtocol.register { _, _ in (200, Data(#"{"novels":[]}"#.utf8)) }
        let app = AppModel(defaults: testDefaults(server: base, authRequired: nil),
                           credentials: CredentialStore(secure: InMemorySecureStore()),
                           session: session, storageBase: temporaryDirectory(), refreshMetaOnLaunch: false)
        let on = ServerMeta(app: "novarr", apiVersion: 1, serverVersion: nil, auth: ServerAuth(required: true))
        let off = ServerMeta(app: "novarr", apiVersion: 1, serverVersion: nil, auth: ServerAuth(required: false))
        app.apply(on, for: base)
        #expect(app.needsSignIn)
        app.apply(off, for: base)
        #expect(app.needsSignIn == false)
        #expect(app.client?.token == nil)
        #expect(app.permissions == .owner)
    }
}

// MARK: - Keychain storage

@Suite("Credentials in the Keychain")
struct CredentialStoreTests {
    @Test func keyedPerServer() {
        let secure = InMemorySecureStore()
        let store = CredentialStore(secure: secure)
        let home = URL(string: "http://192.168.1.5:8080")!
        let tailnet = URL(string: "https://novarr.tail1234.ts.net")!
        store.save(Credential(token: "a", user: owner), for: home)
        store.save(Credential(token: "b", user: reader), for: tailnet)

        #expect(secure.keys.count == 2)
        #expect(store.credential(for: home) == Credential(token: "a", user: owner))
        #expect(store.credential(for: tailnet)?.user.role == .reader)

        store.remove(for: home)
        #expect(store.credential(for: home) == nil)
        #expect(store.credential(for: tailnet)?.token == "b")
    }

    @Test func savingAgainReplaces() {
        let store = CredentialStore(secure: InMemorySecureStore())
        let server = URL(string: "http://novarr.local")!
        store.save(Credential(token: "a", user: owner), for: server)
        store.save(Credential(token: "b", user: owner), for: server)
        #expect(store.credential(for: server)?.token == "b")
    }

    @Test func unreadableItemIsTreatedAsSignedOut() throws {
        let secure = InMemorySecureStore()
        let server = URL(string: "http://novarr.local")!
        try secure.set(Data("garbage".utf8), for: CredentialStore.key(for: server))
        #expect(CredentialStore(secure: secure).credential(for: server) == nil)
    }
}

// MARK: - Store directories

@Suite("Offline store per server and user")
struct StoreDirectoryTests {
    let server = URL(string: "http://192.168.1.5:8080")!

    private func seedShared(_ base: URL) throws -> URL {
        let shared = OfflineStore.directory(for: server, base: base)
        try FileManager.default.createDirectory(at: shared, withIntermediateDirectories: true)
        try Data("[]".utf8).write(to: shared.appending(path: OfflineStore.writeQueueFileName))
        return shared
    }

    @Test func authOffUsesTheServerDirectory() {
        let base = temporaryDirectory()
        #expect(OfflineStore.resolveDirectory(for: server, user: nil, base: base)
                == OfflineStore.directory(for: server, base: base))
    }

    @Test func usersGetSeparateDirectories() {
        let base = temporaryDirectory()
        let a = OfflineStore.resolveDirectory(for: server, user: owner, base: base)
        let b = OfflineStore.resolveDirectory(for: server, user: reader, base: base)
        #expect(a != b)
        #expect(a == OfflineStore.directory(for: server, userId: owner.id, base: base))
        #expect(a != OfflineStore.directory(for: server, base: base))
        // Another server never shares a user's directory either.
        let other = OfflineStore.resolveDirectory(for: URL(string: "http://other.local")!, user: owner, base: base)
        #expect(other != a)
    }

    @Test func firstOwnerAdoptsExistingData() throws {
        let base = temporaryDirectory()
        let shared = try seedShared(base)

        #expect(OfflineStore.resolveDirectory(for: server, user: owner, base: base) == shared)
        let marker = try String(contentsOf: shared.appending(path: OfflineStore.ownerMarkerFileName), encoding: .utf8)
        #expect(marker == "1")
        // Stable on the next sign-in, and auth off still uses it too.
        #expect(OfflineStore.resolveDirectory(for: server, user: owner, base: base) == shared)
        #expect(OfflineStore.resolveDirectory(for: server, user: nil, base: base) == shared)
        // Nobody else gets it.
        let secondAdmin = AuthUser(id: 3, name: "B", username: "b", role: .admin)
        #expect(OfflineStore.resolveDirectory(for: server, user: secondAdmin, base: base)
                == OfflineStore.directory(for: server, userId: 3, base: base))
    }

    @Test func readersNeverAdopt() throws {
        let base = temporaryDirectory()
        let shared = try seedShared(base)
        #expect(OfflineStore.resolveDirectory(for: server, user: reader, base: base)
                == OfflineStore.directory(for: server, userId: reader.id, base: base))
        #expect(!FileManager.default.fileExists(atPath: shared.appending(path: OfflineStore.ownerMarkerFileName).path))
        // The owner can still adopt it afterwards.
        #expect(OfflineStore.resolveDirectory(for: server, user: owner, base: base) == shared)
    }

    @Test func noAdoptionWithoutDataOrOnceTheOwnerHasADirectory() throws {
        let base = temporaryDirectory()
        // Nothing from before accounts: the owner starts in their own directory.
        let own = OfflineStore.resolveDirectory(for: server, user: owner, base: base)
        #expect(own == OfflineStore.directory(for: server, userId: owner.id, base: base))
        try FileManager.default.createDirectory(at: own, withIntermediateDirectories: true)
        // Data appearing later in the shared directory doesn't move them.
        _ = try seedShared(base)
        #expect(OfflineStore.resolveDirectory(for: server, user: owner, base: base) == own)
    }

    @Test func clearingKeepsQueueAndOwnerMarker() async throws {
        let base = temporaryDirectory()
        let shared = try seedShared(base)
        _ = OfflineStore.resolveDirectory(for: server, user: owner, base: base)
        let store = OfflineStore(root: shared)
        await store.save([makeNovel(id: 1, name: "A")], as: .novels)
        await store.clearAll()
        let names = Set(try FileManager.default.contentsOfDirectory(atPath: shared.path))
        #expect(names == [OfflineStore.writeQueueFileName, OfflineStore.ownerMarkerFileName])
    }

    @Test func latestPositionDeclinesArePerUser() {
        let defaults = testDefaults(server: nil, authRequired: nil)
        let date = Date(timeIntervalSince1970: 1_800_000_000)
        LatestPositionDeclines(defaults: defaults, server: server, userId: 1).decline(date, novelId: 4)
        #expect(LatestPositionDeclines(defaults: defaults, server: server, userId: 1).declinedAt(novelId: 4) == date)
        #expect(LatestPositionDeclines(defaults: defaults, server: server, userId: 2).declinedAt(novelId: 4) == nil)
        #expect(LatestPositionDeclines(defaults: defaults, server: server).declinedAt(novelId: 4) == nil)
    }
}

// MARK: - Roles

@Suite("Role gating")
@MainActor
struct RoleGatingTests {
    @Test func permissionsByRole() {
        #expect(Permissions.owner.canManageLibrary)
        #expect(Permissions(role: .admin).canManageLibrary)
        #expect(!Permissions(role: .reader).canManageLibrary)
        #expect(!Permissions(role: .other("guest")).canManageLibrary)
        // Readers keep everything about reading.
        let readerPermissions = Permissions(role: .reader)
        #expect(readerPermissions.canRead)
        #expect(readerPermissions.canCheckForNewChapters)
    }

    @Test func roleDecoding() throws {
        #expect(UserRole(raw: "ADMIN") == .admin)
        #expect(UserRole(raw: "reader") == .reader)
        #expect(UserRole(raw: "") == .other(""))
    }

    @Test func appPermissionsFollowTheSession() async {
        let (base, session) = StubURLProtocol.register { _, _ in (204, Data()) }
        let open = AppModel(defaults: testDefaults(server: base, authRequired: false),
                            credentials: CredentialStore(secure: InMemorySecureStore()),
                            session: session, storageBase: temporaryDirectory(), refreshMetaOnLaunch: false)
        #expect(open.permissions.canManageLibrary) // auth off: the owner's UI

        let credentials = CredentialStore(secure: InMemorySecureStore())
        credentials.save(Credential(token: "t", user: reader), for: base)
        let signedIn = AppModel(defaults: testDefaults(server: base, authRequired: true), credentials: credentials,
                                session: session, storageBase: temporaryDirectory(), refreshMetaOnLaunch: false)
        #expect(signedIn.account?.user == reader)
        #expect(!signedIn.permissions.canManageLibrary)
    }

    @Test func discoverAddRefusedForReaders() async throws {
        let log = RequestLog()
        let (base, session) = StubURLProtocol.register { request, body in
            log.append(request, body)
            return (500, Data())
        }
        let credentials = CredentialStore(secure: InMemorySecureStore())
        credentials.save(Credential(token: "t", user: reader), for: base)
        let app = AppModel(defaults: testDefaults(server: base, authRequired: true), credentials: credentials,
                           session: session, storageBase: temporaryDirectory(), refreshMetaOnLaunch: false)
        let item = try Fixture.decode(DiscoverItem.self, json: """
            {"title":"T","url":"https://example.com/n/1","in_library":false}
            """)
        let model = DiscoverModel()
        await model.add(item, app: app)
        #expect(!log.requests.contains { $0.request.httpMethod == "POST" && $0.request.url?.path == "/api/v1/novels" })
        #expect(model.addStates[item.id] == nil)
    }
}
