import Foundation

/// The single client for the Novarr API v1. Stateless and Sendable: a new one
/// is made whenever the server URL changes.
struct APIClient: Sendable {
    static let minimumAPIVersion = 1

    let baseURL: URL
    let session: URLSession
    /// Identifies this install on read-state writes; nil sends no device headers.
    let device: DeviceIdentity?

    init(baseURL: URL, session: URLSession = .novarr, device: DeviceIdentity? = nil) {
        self.baseURL = baseURL
        self.session = session
        self.device = device
    }

    // MARK: Reads

    /// Verifies the server: it must say it is Novarr and speak a supported API version.
    func meta() async throws -> ServerMeta {
        let meta: ServerMeta
        do {
            meta = try await get("meta", as: ServerMeta.self, timeout: 10)
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

    func novels() async throws -> [NovelSummary] {
        try await get("novels", as: NovelsEnvelope.self).novels
    }

    func home() async throws -> HomeFeed {
        try await get("home", as: HomeFeed.self)
    }

    func novel(id: Int) async throws -> NovelDetail {
        try await get("novels/\(id)", as: NovelEnvelope.self).novel
    }

    func chapters(novelId: Int) async throws -> [ChapterListItem] {
        try await get("novels/\(novelId)/chapters", as: ChaptersEnvelope.self, timeout: 60).chapters
    }

    func chapter(id: Int) async throws -> ChapterContent {
        try await get("chapters/\(id)", as: ChapterEnvelope.self, timeout: 30).chapter
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
        case .setRead(let chapterId, let read):
            try await post("chapters/\(chapterId)/read", body: ReadBody(read: read, age: age), headers: headers)
        case .bulkRead(let request):
            try await post("chapters/bulk-read", body: BulkBody(request: request, age: age), headers: headers)
        }
    }

    // MARK: Discover and adding novels

    /// Searches through FlareSolverr-backed sources can take minutes on the server.
    static let browseTimeout: TimeInterval = 150

    func discoverSources() async throws -> [DiscoverSource] {
        try await get("discover", as: DiscoverSourcesEnvelope.self).sources
    }

    /// One page of a source's list, or of a search when `type` is `search`.
    /// Blocking on the server; cancel the calling task to abandon it.
    func discoverBrowse(type: String, source: String?, query: String?,
                        page: Int, perPage: Int = 20) async throws -> DiscoverPage {
        var items = [URLQueryItem(name: "type", value: type)]
        if let source { items.append(URLQueryItem(name: "source", value: source)) }
        if let query { items.append(URLQueryItem(name: "q", value: query)) }
        items.append(URLQueryItem(name: "page", value: String(page)))
        items.append(URLQueryItem(name: "per_page", value: String(perPage)))
        return try await get("discover/browse", query: items, as: DiscoverPage.self, timeout: Self.browseTimeout)
    }

    /// 201 (added) and 200 (already in the library) both succeed; 422/503 throw `.http`.
    func addNovel(title: String, url: URL) async throws -> AddNovelResponse {
        let data = try await send("POST", "novels", body: AddNovelBody(title: title, url: url.absoluteString))
        return try decode(AddNovelResponse.self, from: data)
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

    // MARK: Plumbing

    func url(for path: String) -> URL {
        baseURL.appending(path: "api/v1/" + path)
    }

    func url(for path: String, query: [URLQueryItem]) -> URL {
        let base = url(for: path)
        return query.isEmpty ? base : base.appending(queryItems: query)
    }

    private func get<T: Decodable>(_ path: String, query: [URLQueryItem] = [], as type: T.Type,
                                   timeout: TimeInterval = 20, fresh: Bool = false) async throws -> T {
        var request = URLRequest(url: url(for: path, query: query),
                                 cachePolicy: fresh ? .reloadIgnoringLocalCacheData : .useProtocolCachePolicy,
                                 timeoutInterval: timeout)
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        let data = try await perform(request)
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
                                       headers: [String: String] = [:]) async throws -> Data {
        var request = URLRequest(url: url(for: path), timeoutInterval: timeout)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        for (field, value) in headers { request.setValue(value, forHTTPHeaderField: field) }
        if let body {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try JSONCoding.encoder().encode(body)
        }
        return try await perform(request)
    }

    private func perform(_ request: URLRequest) async throws -> Data {
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
        guard (200..<300).contains(http.statusCode) else {
            let message = (try? JSONDecoder().decode(ErrorBody.self, from: data))?.message
            throw APIError.http(status: http.statusCode, message: message)
        }
        return data
    }

    private struct ErrorBody: Decodable { var message: String? }

    private struct EmptyBody: Encodable {}

    private struct AddNovelBody: Encodable {
        var title: String
        var url: String
    }

    private struct ProgressBody: Encodable {
        var progress: Int?
        var read: Bool?
        var age: Int
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
