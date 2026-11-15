import Foundation

/// The single client for the Novarr API v1. Stateless and Sendable: a new one
/// is made whenever the server URL changes.
struct APIClient: Sendable {
    static let minimumAPIVersion = 1

    let baseURL: URL
    let session: URLSession

    init(baseURL: URL, session: URLSession = .novarr) {
        self.baseURL = baseURL
        self.session = session
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

    // MARK: Writes

    /// Sends one queued write. `age` is how many milliseconds ago it was recorded.
    func send(_ write: PendingWrite.Kind, age: Int) async throws {
        switch write {
        case .progress(let chapterId, let progress, let read):
            try await post("chapters/\(chapterId)/progress",
                           body: ProgressBody(progress: progress, read: read ? true : nil, age: age))
        case .setRead(let chapterId, let read):
            try await post("chapters/\(chapterId)/read", body: ReadBody(read: read, age: age))
        case .bulkRead(let request):
            try await post("chapters/bulk-read", body: BulkBody(request: request, age: age))
        }
    }

    // MARK: Plumbing

    func url(for path: String) -> URL {
        baseURL.appending(path: "api/v1/" + path)
    }

    private func get<T: Decodable>(_ path: String, as type: T.Type, timeout: TimeInterval = 20) async throws -> T {
        var request = URLRequest(url: url(for: path), timeoutInterval: timeout)
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        let data = try await perform(request)
        do {
            return try JSONCoding.decoder().decode(T.self, from: data)
        } catch {
            throw APIError.decoding(String(describing: error))
        }
    }

    private func post<Body: Encodable>(_ path: String, body: Body) async throws {
        var request = URLRequest(url: url(for: path), timeoutInterval: 20)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONCoding.encoder().encode(body)
        _ = try await perform(request)
    }

    private func perform(_ request: URLRequest) async throws -> Data {
        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.data(for: request)
        } catch {
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
