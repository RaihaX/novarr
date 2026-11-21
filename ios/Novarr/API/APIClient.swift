import Foundation

/// The single client for the Novarr API v1. Stateless and Sendable: a new one
/// is made whenever the server URL changes.
struct APIClient: Sendable {
    static let minimumAPIVersion = 1

    let baseURL: URL
    let session: URLSession
    /// Identifies this install on read-state writes; nil sends no device headers.
    let device: DeviceIdentity?
    /// Bearer token for servers with sign-in on; nil with auth off (or before sign-in).
    let token: String?
    /// Called with the token that was rejected when an authenticated request
    /// gets 401, so the app can forget it and ask for a sign-in.
    let onUnauthorized: (@Sendable (_ rejectedToken: String?) -> Void)?

    static let authorizationHeader = "Authorization"

    init(baseURL: URL, session: URLSession = .novarr, device: DeviceIdentity? = nil,
         token: String? = nil, onUnauthorized: (@Sendable (_ rejectedToken: String?) -> Void)? = nil) {
        self.baseURL = baseURL
        self.session = session
        self.device = device
        self.token = token.flatMap { $0.isEmpty ? nil : $0 }
        self.onUnauthorized = onUnauthorized
    }

    // MARK: Reads

    /// Verifies the server: it must say it is Novarr and speak a supported API version.
    func meta() async throws -> ServerMeta {
        let meta: ServerMeta
        do {
            // Public: never carries the token.
            meta = try await get("meta", as: ServerMeta.self, timeout: 10, authenticated: false)
        } catch let error as APIError {
            switch error {
            // An HTML page, a 404 or anything unparseable means "not Novarr".
            case .decoding, .http(404, _): throw APIError.notNovarr
            default: throw error
            }
        }
        guard meta.app.lowercased() == "novarr" else { throw APIError.notNovarr }
        guard meta.apiVersion >= Self.minimumAPIVersion else {
            throw APIError.apiTooOld(serverVersion: meta.apiVersion, required: Self.minimumAPIVersion)
        }
        return meta
    }

    /// Novels and manga together (`medium=all`); the app separates them
    /// itself. Servers before manga ignore the parameter.
    func novels() async throws -> [NovelSummary] {
        try await get("novels", query: Self.allMedia, as: NovelsEnvelope.self).novels
    }

    func home() async throws -> HomeFeed {
        try await get("home", query: Self.allMedia, as: HomeFeed.self)
    }

    private static let allMedia = [URLQueryItem(name: "medium", value: "all")]

    func novel(id: Int) async throws -> NovelDetail {
        try await get("novels/\(id)", as: NovelEnvelope.self).novel
    }

    func chapters(novelId: Int) async throws -> [ChapterListItem] {
        try await get("novels/\(novelId)/chapters", as: ChaptersEnvelope.self, timeout: 60).chapters
    }

    /// A text chapter. A manga chapter answers 422 with `medium: manga`,
    /// thrown as `APIError.mangaChapter`.
    func chapter(id: Int) async throws -> ChapterContent {
        try await get("chapters/\(id)", as: ChapterEnvelope.self, timeout: 30).chapter
    }

    /// A manga chapter's pages (pre-signed image URLs).
    func mangaPages(id: Int) async throws -> MangaPages {
        try await get("chapters/\(id)/pages", as: MangaPages.self, timeout: 30)
    }

    /// Where the series was last read, on any device; nil when nobody has
    /// read it yet or the server predates the endpoint (404). Short timeout:
    /// the reader opens regardless and only prompts if this answers in time.
    func latestPosition(novelId: Int) async throws -> ReadingPosition? {
        do {
            return try await get("novels/\(novelId)/position", as: PositionEnvelope.self,
                                 timeout: 3, fresh: true).position
        } catch APIError.http(404, _) {
            return nil
        }
    }

    // MARK: Writes

    /// Sends one queued write. `age` is how many milliseconds ago it was recorded.
    /// Carries the device headers so the server can record who read where.
    func send(_ write: PendingWrite.Kind, age: Int) async throws {
        let headers = device?.headers ?? [:]
        switch write {
        case .progress(let chapterId, let progress, let read):
            try await post("chapters/\(chapterId)/progress",
                           body: ProgressBody(progress: progress, read: read ? true : nil, age: age), headers: headers)
        case .lookupRead(let chapterId):
            // A look-up's read mark: the chapter is read, but the series'
            // latest position stays where it was.
            try await post("chapters/\(chapterId)/progress",
                           body: ProgressBody(progress: 100, read: true, lookup: true, age: age), headers: headers)
        case .setRead(let chapterId, let read):
            try await post("chapters/\(chapterId)/read", body: ReadBody(read: read, age: age), headers: headers)
        case .bulkRead(let request):
            try await post("chapters/bulk-read", body: BulkBody(request: request, age: age), headers: headers)
        case .highlight(let draft):
            // 201 new, 200 when the chapter already has this excerpt; no `age`:
            // a highlight isn't a position. Without a note the key is left out,
            // so a duplicate keeps the note it has.
            try await post("highlights", body: HighlightBody(chapterId: draft.chapterId, excerpt: draft.excerpt,
                                                             note: draft.note), headers: headers)
        }
    }

    // MARK: Checks

    func check(novelId: Int) async throws -> NovelCheck {
        try await get("novels/\(novelId)/check", as: CheckEnvelope.self).check
    }

    /// Starts a check, or joins the one already running (202 either way).
    func startCheck(novelId: Int) async throws -> NovelCheck {
        let data = try await send("POST", "novels/\(novelId)/check", body: Optional<EmptyBody>.none, timeout: 60)
        return try decode(CheckEnvelope.self, from: data).check
    }

    // MARK: Highlights

    func highlights(novelId: Int? = nil, page: Int = 1, perPage: Int = 50) async throws -> HighlightsPage {
        var items: [URLQueryItem] = []
        if let novelId { items.append(URLQueryItem(name: "novel_id", value: String(novelId))) }
        items.append(URLQueryItem(name: "page", value: String(page)))
        items.append(URLQueryItem(name: "per_page", value: String(perPage)))
        return try await get("highlights", query: items, as: HighlightsPage.self)
    }

    /// 204 even when it was already gone; a 404 means the same thing here.
    func deleteHighlight(id: Int) async throws {
        do {
            _ = try await send("DELETE", "highlights/\(id)", body: Optional<EmptyBody>.none)
        } catch APIError.http(404, _) {
            return
        }
    }

    // MARK: Accounts

    /// Password sign-in: a token, or (401 `second_step_required`) a challenge
    /// for the sign-in code. 403 `second_step_unavailable` throws
    /// `.secondStepUnavailable`; 422 (wrong name or password), 429 (throttled)
    /// and anything else throw `.http` with the server's message.
    func signIn(username: String, password: String, device: DeviceIdentity) async throws -> SignInOutcome {
        let body = SignInBody(username: username, password: password, deviceId: device.id, deviceName: device.name)
        let (status, data) = try await exchange("POST", "auth/token", body: body)
        if (200..<300).contains(status) { return .signedIn(try decode(AuthTokenResponse.self, from: data)) }
        let error = Self.authError(data)
        if status == 401, let challenge = error.secondStepChallenge { return .secondStepRequired(challenge) }
        if status == 403, error.code == AuthErrorBody.secondStepUnavailable {
            throw APIError.secondStepUnavailable(error.message)
        }
        throw APIError.http(status: status, message: error.message)
    }

    /// Emails a sign-in code for the challenge (204). 404 throws
    /// `.challengeExpired`; 429 and the rest throw `.http`.
    func requestEmailCode(challenge: String) async throws {
        let (status, data) = try await exchange("POST", "auth/token/email-code", body: ChallengeBody(challenge: challenge))
        if (200..<300).contains(status) { return }
        let error = Self.authError(data)
        if status == 404 { throw APIError.challengeExpired(error.message) }
        throw APIError.http(status: status, message: error.message)
    }

    /// Finishes a sign-in with a code. 422 throws `.wrongCode` (with the tries
    /// left), 404 `.challengeExpired`, 429 and the rest `.http`.
    func submitSecondStep(challenge: String, method: SecondStepMethod, code: String) async throws -> AuthTokenResponse {
        let body = SecondStepBody(challenge: challenge, method: method.rawValue, code: code)
        let (status, data) = try await exchange("POST", "auth/token/second-step", body: body)
        if (200..<300).contains(status) { return try decode(AuthTokenResponse.self, from: data) }
        let error = Self.authError(data)
        switch status {
        case 422 where error.code == AuthErrorBody.wrongCode:
            throw APIError.wrongCode(message: error.message, triesLeft: error.triesLeft)
        case 404: throw APIError.challengeExpired(error.message)
        default: throw APIError.http(status: status, message: error.message)
        }
    }

    /// Asks for a link code (201). 429 when too many were asked for.
    func startPairing(device: DeviceIdentity) async throws -> PairingStart {
        let body = PairingBody(deviceId: device.id, deviceName: device.name)
        let (status, data) = try await exchange("POST", "auth/pairings", body: body)
        guard (200..<300).contains(status) else {
            throw APIError.http(status: status, message: Self.authError(data).message)
        }
        return try decode(PairingStart.self, from: data)
    }

    /// One look at a link code: pending, linked (with the token) or expired (404).
    func pollPairing(secret: String) async throws -> PairingPoll {
        let (status, data) = try await exchange("GET", "auth/pairings/\(secret)", body: Optional<EmptyBody>.none,
                                                timeout: 15)
        if status == 404 { return .expired }
        guard (200..<300).contains(status) else {
            throw APIError.http(status: status, message: Self.authError(data).message)
        }
        let body = try decode(PairingStatusBody.self, from: data)
        switch body.status.lowercased() {
        case "pending": return .pending
        case "linked":
            guard let token = body.token, !token.isEmpty, let user = body.user else {
                throw APIError.decoding("linked without a token or user")
            }
            return .linked(AuthTokenResponse(token: token, user: user))
        default:
            throw APIError.decoding("unknown pairing status \(body.status)")
        }
    }

    func me() async throws -> AuthUser {
        try await get("auth/me", as: AuthUserEnvelope.self, fresh: true).user
    }

    /// Revokes the token in use. A 401 here means it is already gone, which
    /// is what signing out wants, so it is not reported as a lost session.
    func signOut() async throws {
        _ = try await send("DELETE", "auth/token", body: Optional<EmptyBody>.none, timeout: 10,
                           reportUnauthorized: false)
    }

    // MARK: Plumbing

    func url(for path: String) -> URL {
        baseURL.appending(path: "api/v1/" + path)
    }

    func url(for path: String, query: [URLQueryItem]) -> URL {
        let base = url(for: path)
        return query.isEmpty ? base : base.appending(queryItems: query)
    }

    /// Every request to the server is built here, so the bearer token is
    /// added in exactly one place. `authenticated: false` is for the public
    /// endpoints (meta, password sign-in).
    func makeRequest(_ method: String = "GET", _ path: String, query: [URLQueryItem] = [],
                     timeout: TimeInterval = 20, fresh: Bool = false, authenticated: Bool = true,
                     headers: [String: String] = [:]) -> URLRequest {
        var request = URLRequest(url: url(for: path, query: query),
                                 cachePolicy: fresh ? .reloadIgnoringLocalCacheData : .useProtocolCachePolicy,
                                 timeoutInterval: timeout)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        for (field, value) in headers { request.setValue(value, forHTTPHeaderField: field) }
        if authenticated, let token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: Self.authorizationHeader)
        }
        return request
    }

    private func get<T: Decodable>(_ path: String, query: [URLQueryItem] = [], as type: T.Type,
                                   timeout: TimeInterval = 20, fresh: Bool = false,
                                   authenticated: Bool = true) async throws -> T {
        let request = makeRequest("GET", path, query: query, timeout: timeout, fresh: fresh,
                                  authenticated: authenticated)
        let data = try await perform(request, reportUnauthorized: authenticated)
        return try decode(T.self, from: data)
    }

    private func decode<T: Decodable>(_ type: T.Type, from data: Data) throws -> T {
        do {
            return try JSONCoding.decoder().decode(T.self, from: data)
        } catch {
            throw APIError.decoding(String(describing: error))
        }
    }

    private func post<Body: Encodable>(_ path: String, body: Body, headers: [String: String] = [:]) async throws {
        _ = try await send("POST", path, body: body, headers: headers)
    }

    /// A write with an optional JSON body; returns the response body.
    private func send<Body: Encodable>(_ method: String, _ path: String, body: Body?,
                                       timeout: TimeInterval = 20,
                                       headers: [String: String] = [:],
                                       authenticated: Bool = true,
                                       reportUnauthorized: Bool = true) async throws -> Data {
        var request = makeRequest(method, path, timeout: timeout, authenticated: authenticated, headers: headers)
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try JSONCoding.encoder().encode(body)
        }
        return try await perform(request, reportUnauthorized: authenticated && reportUnauthorized)
    }

    /// A public auth call: never carries the token and never reports a 401
    /// as a lost session. Returns the status and body of any HTTP answer, so
    /// the caller reads the error codes; only "no answer" throws.
    private func exchange<Body: Encodable>(_ method: String, _ path: String, body: Body?,
                                           timeout: TimeInterval = 20) async throws -> (Int, Data) {
        var request = makeRequest(method, path, timeout: timeout, fresh: true, authenticated: false)
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try JSONCoding.encoder().encode(body)
        }
        return try await load(request)
    }

    private static func authError(_ data: Data) -> AuthErrorBody {
        (try? JSONCoding.decoder().decode(AuthErrorBody.self, from: data)) ?? AuthErrorBody()
    }

    /// Sends a request; throws only when no HTTP answer came back.
    private func load(_ request: URLRequest) async throws -> (Int, Data) {
        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: request)
        } catch {
            // Let callers tell "the user moved on" apart from "the server is down".
            if Task.isCancelled { throw CancellationError() }
            throw APIError.from(error)
        }
        guard let http = response as? HTTPURLResponse else {
            throw APIError.unreachable("No HTTP response.")
        }
        return (http.statusCode, data)
    }

    private func perform(_ request: URLRequest, reportUnauthorized: Bool) async throws -> Data {
        let (status, data) = try await load(request)
        guard (200..<300).contains(status) else {
            let body = try? JSONDecoder().decode(ErrorBody.self, from: data)
            if status == 401, reportUnauthorized { onUnauthorized?(token) }
            // The text endpoint's answer for a chapter that is page images.
            if status == 422, body?.medium?.lowercased() == Medium.manga { throw APIError.mangaChapter }
            throw APIError.http(status: status, message: body?.message)
        }
        return data
    }

    private struct ErrorBody: Decodable {
        var message: String?
        var medium: String?
    }

    private struct EmptyBody: Encodable {}

    private struct SignInBody: Encodable {
        var username: String
        var password: String
        var deviceId: String
        var deviceName: String
    }

    private struct ChallengeBody: Encodable {
        var challenge: String
    }

    private struct SecondStepBody: Encodable {
        var challenge: String
        var method: String
        var code: String
    }

    private struct PairingBody: Encodable {
        var deviceId: String
        var deviceName: String
    }

    private struct ProgressBody: Encodable {
        var progress: Int?
        var read: Bool?
        /// Only sent (true) for a look-up's read mark.
        var lookup: Bool? = nil
        var age: Int
    }

    private struct HighlightBody: Encodable {
        var chapterId: Int
        var excerpt: String
        var note: String?
    }

    private struct ReadBody: Encodable {
        var read: Bool
        var age: Int
    }

    private struct BulkBody: Encodable {
        var request: BulkReadRequest
        var age: Int

        enum CodingKeys: String, CodingKey { case read, scope, ids, novelId, anchorId, age }

        func encode(to encoder: Encoder) throws {
            var c = encoder.container(keyedBy: CodingKeys.self)
            try c.encode(request.read, forKey: .read)
            try c.encode(request.scope, forKey: .scope)
            try c.encodeIfPresent(request.ids, forKey: .ids)
            try c.encodeIfPresent(request.novelId, forKey: .novelId)
            try c.encodeIfPresent(request.anchorId, forKey: .anchorId)
            try c.encode(age, forKey: .age)
        }
    }
}

extension URLSession {
    /// Shared session with a roomy HTTP cache so ETag revalidation (304) works
    /// for the large library and chapter-list responses.
    static let novarr: URLSession = {
        let config = URLSessionConfiguration.default
        config.urlCache = URLCache(memoryCapacity: 16 << 20, diskCapacity: 128 << 20)
        config.requestCachePolicy = .useProtocolCachePolicy
        config.timeoutIntervalForRequest = 20
        config.waitsForConnectivity = false
        config.httpMaximumConnectionsPerHost = 6
        return URLSession(configuration: config)
    }()
}
