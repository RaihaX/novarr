import Foundation

/// A JSON request body built in code (the feature endpoints' small bodies).
/// Keys are sent exactly as written (snake_case), never converted.
indirect enum JSONValue: Encodable, Sendable, Equatable {
    case string(String)
    case int(Int)
    case int64(Int64)
    case double(Double)
    case bool(Bool)
    case null
    case array([JSONValue])
    case object([String: JSONValue])

    static func ids(_ ids: [Int]) -> JSONValue { .array(ids.map { .int($0) }) }

    func encode(to encoder: Encoder) throws {
        switch self {
        case .string(let value): var c = encoder.singleValueContainer(); try c.encode(value)
        case .int(let value): var c = encoder.singleValueContainer(); try c.encode(value)
        case .int64(let value): var c = encoder.singleValueContainer(); try c.encode(value)
        case .double(let value): var c = encoder.singleValueContainer(); try c.encode(value)
        case .bool(let value): var c = encoder.singleValueContainer(); try c.encode(value)
        case .null: var c = encoder.singleValueContainer(); try c.encodeNil()
        case .array(let values):
            var c = encoder.unkeyedContainer()
            for value in values { try c.encode(value) }
        case .object(let fields):
            var c = encoder.container(keyedBy: RawKey.self)
            for (key, value) in fields.sorted(by: { $0.key < $1.key }) { try c.encode(value, forKey: RawKey(key)) }
        }
    }

    private struct RawKey: CodingKey {
        var stringValue: String
        var intValue: Int? { nil }
        init(_ string: String) { stringValue = string }
        init?(stringValue: String) { self.stringValue = stringValue }
        init?(intValue: Int) { nil }
    }

    func encoded() throws -> Data {
        // A plain encoder: the keys are already the wire's.
        try JSONEncoder().encode(self)
    }
}

/// The feature endpoints (categories, requests, audiobooks, uploads, stats,
/// send, feeds), mirroring android api/ApiClient.kt. Non-2xx answers throw
/// `APIError.rejected` with the body's `code` and `Retry-After`; no answer
/// throws the usual connectivity errors. None of these are queued: they need
/// an answer now, so offline they fail and the screen says so (except
/// audiobook progress, which `ListeningSync` keeps and retries).
extension APIClient {
    // MARK: Plumbing

    /// Sends a request and returns the body of a 2xx; anything else throws `.rejected`.
    func call(_ method: String, _ path: String, query: [URLQueryItem] = [], json: JSONValue? = nil,
              timeout: TimeInterval = 20, fresh: Bool = false, headers: [String: String] = [:],
              reportUnauthorized: Bool = true) async throws -> Data {
        var request = makeRequest(method, path, query: query, timeout: timeout, fresh: fresh, headers: headers)
        if let json {
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try json.encoded()
        } else if method != "GET" {
            request.httpBody = Data()
        }
        return try await perform(detailed: request, reportUnauthorized: reportUnauthorized)
    }

    func call<T: Decodable>(_ method: String, _ path: String, query: [URLQueryItem] = [], json: JSONValue? = nil,
                            timeout: TimeInterval = 20, fresh: Bool = false, headers: [String: String] = [:],
                            reportUnauthorized: Bool = true, as type: T.Type) async throws -> T {
        let data = try await call(method, path, query: query, json: json, timeout: timeout, fresh: fresh,
                                  headers: headers, reportUnauthorized: reportUnauthorized)
        return try decode(T.self, from: data)
    }

    /// Runs a prepared request (any body); 2xx returns the body, else throws `.rejected`.
    func perform(detailed request: URLRequest, reportUnauthorized: Bool = true,
                 session override: URLSession? = nil) async throws -> Data {
        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await (override ?? session).data(for: request)
        } catch {
            if Task.isCancelled { throw CancellationError() }
            throw APIError.from(error)
        }
        guard let http = response as? HTTPURLResponse else { throw APIError.unreachable("No HTTP response.") }
        guard (200..<300).contains(http.statusCode) else {
            if http.statusCode == 401, reportUnauthorized { onUnauthorized?(token) }
            throw APIError.rejected(Self.failure(status: http.statusCode, data: data, response: http))
        }
        return data
    }

    static func failure(status: Int, data: Data, response: HTTPURLResponse?) -> HTTPFailure {
        struct Body: Decodable { var message: String?; var code: String? }
        let body = try? JSONDecoder().decode(Body.self, from: data)
        let retry = response?.value(forHTTPHeaderField: "Retry-After")
            .flatMap { TimeInterval($0.trimmingCharacters(in: .whitespaces)) }
        return HTTPFailure(status: status, message: body?.message, code: body?.code, retryAfter: retry, body: data)
    }

    /// RFC 3986 unreserved characters only, never starting with a dot, so a
    /// server value can be put into a path as is.
    static func isSafePathSegment(_ value: String) -> Bool {
        value.range(of: "^[A-Za-z0-9_~-][A-Za-z0-9._~-]{0,511}$", options: .regularExpression) != nil
    }

    // MARK: Categories (reading lists). Never queued: offline the lists are read-only.

    func lists() async throws -> [ReadingList] {
        try await call("GET", "lists", fresh: true, as: ListsEnvelope.self).lists
    }

    /// 201 {list}; 422 with a message for a blank, duplicate or too long name, or too many lists.
    func createList(name: String) async throws -> ReadingList {
        try await call("POST", "lists", json: .object(["name": .string(name)]), as: ListEnvelope.self).list
    }

    func renameList(id: Int, name: String) async throws -> ReadingList {
        try await call("PATCH", "lists/\(id)", json: .object(["name": .string(name)]), as: ListEnvelope.self).list
    }

    /// 204; a list already gone (404) counts as deleted.
    func deleteList(id: Int) async throws {
        do {
            _ = try await call("DELETE", "lists/\(id)")
        } catch let error as APIError where error.status == 404 {
            return
        }
    }

    /// Adds or removes a series; idempotent either way, answers the whole list.
    func setListMembership(listId: Int, novelId: Int, add: Bool) async throws -> ReadingList {
        try await call(add ? "PUT" : "DELETE", "lists/\(listId)/novels/\(novelId)",
                       json: add ? .object([:]) : nil, as: ListEnvelope.self).list
    }

    /// Hides a category from Home's sections, or shows it again (built-ins too).
    func setListHiddenOnHome(id: Int, hidden: Bool) async throws -> ReadingList {
        try await call("PATCH", "lists/\(id)", json: .object(["hidden_on_home": .bool(hidden)]),
                       as: ListEnvelope.self).list
    }

    /// The reader's order: `ids` first, in this order. Answers the whole index, sorted.
    func reorderLists(ids: [Int]) async throws -> [ReadingList] {
        try await call("PATCH", "lists/order", json: .object(["ids": .ids(ids)]), as: ListsEnvelope.self).lists
    }

    /// Puts every series in each of `add` and takes it out of each of `remove`
    /// in one round trip (multi-select "Set categories"). Answers the whole index.
    func setMemberships(novelIds: [Int], add: [Int], remove: [Int]) async throws -> [ReadingList] {
        try await call("PUT", "lists/memberships",
                       json: .object(["novel_ids": .ids(novelIds), "add": .ids(add), "remove": .ids(remove)]),
                       as: ListsEnvelope.self).lists
    }

    /// Marks whole series read or unread: every chapter of each. Not queued here.
    func bulkReadNovels(ids: [Int], read: Bool) async throws -> BulkReadAnswer {
        let headers = device?.headers ?? [:]
        return try await call("POST", "novels/bulk-read", json: .object(["ids": .ids(ids), "read": .bool(read)]),
                              headers: headers, as: BulkReadAnswer.self)
    }

    /// The whole "Want to read", every medium, newest first.
    func wantToRead() async throws -> WantToReadEnvelope {
        try await call("GET", "want-to-read", query: [URLQueryItem(name: "medium", value: "all")], fresh: true,
                       as: WantToReadEnvelope.self)
    }

    // MARK: Requests

    /// Starts a search: 202 {search}. 429 (busy, running, rate limited) carries Retry-After.
    func startRequestSearch(query: String, medium: String) async throws -> RequestSearch {
        try await call("POST", "requests/searches", json: .object(["q": .string(query), "medium": .string(medium)]),
                       as: SearchEnvelope.self).search
    }

    /// One poll of a running search; 404 `search_expired` once it is gone.
    func requestSearch(id: String) async throws -> RequestSearch {
        guard Self.isSafePathSegment(id) else { throw APIError.decoding("unusable search id") }
        return try await call("GET", "requests/searches/\(id)", fresh: true, as: SearchEnvelope.self).search
    }

    /// `scope` mine | all (the admin view); `status` pending | approved | declined | available.
    func requests(scope: String = "mine", status: String? = nil, page: Int = 1) async throws -> RequestsPage {
        var query = [URLQueryItem(name: "scope", value: scope)]
        if let status { query.append(URLQueryItem(name: "status", value: status)) }
        query.append(URLQueryItem(name: "page", value: String(page)))
        return try await call("GET", "requests", query: query, fresh: true, as: RequestsPage.self)
    }

    /// Requests a search result: 201 {request, joined: false}, or 200 joined ("Me too").
    func createRequest(searchId: String, key: String, note: String?, categories: [Int]? = nil) async throws -> CreatedRequest {
        var body: [String: JSONValue] = ["search_id": .string(searchId), "key": .string(key)]
        if let note { body["note"] = .string(note) }
        if let categories { body["categories"] = .ids(categories) }
        return try await call("POST", "requests", json: .object(body), as: CreatedRequest.self)
    }

    /// A request typed in by hand ("Request it anyway"): 201 {request}.
    func createFreeTextRequest(title: String, medium: String, link: String?, note: String?) async throws -> ReaderRequest {
        var body: [String: JSONValue] = ["title": .string(title), "medium": .string(medium)]
        if let link { body["link"] = .string(link) }
        if let note { body["note"] = .string(note) }
        return try await call("POST", "requests", json: .object(body), as: RequestEnvelope.self).request
    }

    /// A page of the Discover shelf (`list` nil: the medium's first list). 502
    /// `browse_unavailable`, 503 `browse_busy` (+ Retry-After), 422 an unknown list.
    func requestBrowse(medium: String, list: String?, page: Int = 1) async throws -> BrowsePage {
        var query = [URLQueryItem(name: "medium", value: medium)]
        if let list { query.append(URLQueryItem(name: "list", value: list)) }
        query.append(URLQueryItem(name: "page", value: String(page)))
        return try await call("GET", "requests/browse", query: query, timeout: 45, fresh: true, as: BrowsePage.self)
    }

    /// Requests a shelf result: the same answers as `createRequest`.
    func createRequestFromBrowse(medium: String, list: String, page: Int, key: String, note: String?,
                                 categories: [Int]? = nil) async throws -> CreatedRequest {
        var body: [String: JSONValue] = ["list": .string(list), "page": .int(page), "key": .string(key),
                                         "medium": .string(medium)]
        if let note { body["note"] = .string(note) }
        if let categories { body["categories"] = .ids(categories) }
        return try await call("POST", "requests", json: .object(body), as: CreatedRequest.self)
    }

    /// Cancels a pending request (204); for a request this account joined, leaves it.
    func cancelRequest(id: Int) async throws {
        _ = try await call("DELETE", "requests/\(id)")
    }

    /// Approves with a shortlist `candidate` (its index), or a series page `url`.
    /// `force` adds it even though the library seems to have it (after 409 `duplicate`).
    func approveRequest(id: Int, candidate: Int? = nil, url: String? = nil, title: String? = nil,
                        force: Bool = false) async throws -> ApproveResult {
        var body: [String: JSONValue] = [:]
        if let candidate { body["candidate"] = .int(candidate) }
        if let url { body["url"] = .string(url) }
        if let title { body["title"] = .string(title) }
        if force { body["force"] = .bool(true) }
        return try await call("POST", "requests/\(id)/approve", json: .object(body), timeout: 60, as: ApproveResult.self)
    }

    func declineRequest(id: Int, reason: String?) async throws -> ReaderRequest {
        var body: [String: JSONValue] = [:]
        if let reason { body["reason"] = .string(reason) }
        return try await call("POST", "requests/\(id)/decline", json: .object(body), as: RequestEnvelope.self).request
    }

    /// Rebuilds a manga request's shortlist in the background (202).
    func rerankRequest(id: Int) async throws -> ReaderRequest {
        try await call("POST", "requests/\(id)/rerank", json: .object([:]), as: RequestEnvelope.self).request
    }

    /// Replaces the categories this account chose on a request ([] clears them).
    func setRequestCategories(id: Int, categories: [Int]) async throws -> ReaderRequest {
        try await call("PUT", "requests/\(id)/categories", json: .object(["categories": .ids(categories)]),
                       as: RequestEnvelope.self).request
    }

    /// The notification feed after `since` (opaque; nil: the last 24 hours).
    func notifications(since: String?, reportUnauthorized: Bool = true) async throws -> NotificationFeed {
        let query = since.map { [URLQueryItem(name: "since", value: $0)] } ?? []
        return try await call("GET", "notifications", query: query, fresh: true,
                              reportUnauthorized: reportUnauthorized, as: NotificationFeed.self)
    }

    /// Series with something new to read since `since` (nil: just a cursor to start from).
    func updates(since: String?, reportUnauthorized: Bool = true) async throws -> UpdatesFeed {
        let query = since.map { [URLQueryItem(name: "since", value: $0)] } ?? []
        return try await call("GET", "updates", query: query, fresh: true,
                              reportUnauthorized: reportUnauthorized, as: UpdatesFeed.self)
    }

    // MARK: Ask for the audiobook, Send to my Kindle

    /// Puts the book's audiobook on the owner's wanted list: the server's message. 422 when it cannot be asked for.
    func askAudiobook(novelId: Int) async throws -> String {
        let answer = try await call("POST", "novels/\(novelId)/audiobook/ask", json: .object([:]),
                                    as: AudiobookAskAnswer.self)
        return answer.message.flatMap { $0.isEmpty ? nil : $0 }
            ?? "Asked for. The audiobook shows up on this book once it is here."
    }

    /// The last send of this series ({status: null} when none).
    func sendState(novelId: Int) async throws -> SendState {
        try await call("GET", "novels/\(novelId)/send", fresh: true, as: SendEnvelope.self).send ?? SendState()
    }

    /// Starts a send: 202 queued. 422 `no_address` (with `account_url`) / `manga`, 429 `too_many`.
    func startSend(novelId: Int) async throws -> SendState {
        try await call("POST", "novels/\(novelId)/send", json: .object([:]), as: SendEnvelope.self).send
            ?? SendState(status: "queued")
    }

    // MARK: Stats

    /// The reader's own stats over the last `range` days (30, 90 or 365). An older server answers 404.
    func stats(range: Int) async throws -> ReadingStats {
        try await call("GET", "stats", query: [URLQueryItem(name: "range", value: String(range))], fresh: true,
                       as: ReadingStats.self)
    }

    // MARK: Audiobooks

    /// Every audiobook the reader can see, a page at a time.
    func audiobooks(page: Int = 1, search: String? = nil, status: String? = nil) async throws -> AudiobooksPage {
        var query = [URLQueryItem(name: "page", value: String(page))]
        if let search = search?.trimmingCharacters(in: .whitespaces), !search.isEmpty {
            query.append(URLQueryItem(name: "search", value: search))
        }
        if let status { query.append(URLQueryItem(name: "status", value: status)) }
        return try await call("GET", "audiobooks", query: query, fresh: true, as: AudiobooksPage.self)
    }

    /// One audiobook with its (pre-signed) parts, chapters, bookmarks and progress. Fresh: the part URLs expire.
    func audiobook(id: Int) async throws -> AudiobookDetail {
        try await call("GET", "audiobooks/\(id)", fresh: true, as: AudiobookEnvelope.self).audiobook
    }

    /// Started and not finished, newest progress first (up to 12).
    func continueListening() async throws -> [AudiobookSummary] {
        try await call("GET", "audiobooks/continue", fresh: true, as: AudiobooksEnvelope.self).audiobooks
    }

    /// The progress body: speed kept within 0.5–3.0 and rounded to two decimals.
    static func audiobookProgressBody(positionMs: Int64, speed: Double, finished: Bool,
                                      recordedAt: String) -> JSONValue {
        let clamped = min(max(speed, 0.5), 3.0)
        return .object([
            "position_ms": .int64(max(positionMs, 0)),
            "speed": .double((clamped * 100).rounded() / 100),
            "finished": .bool(finished),
            "recorded_at": .string(recordedAt),
        ])
    }

    /// Saves a whole-book position. `recordedAt` (ISO 8601) is when it was
    /// recorded on this device: the server keeps the newest and answers `stale` for an older one.
    func saveAudiobookProgress(id: Int, positionMs: Int64, speed: Double, finished: Bool,
                               recordedAt: String) async throws -> AudiobookProgressResult {
        try await call("PUT", "audiobooks/\(id)/progress",
                       json: Self.audiobookProgressBody(positionMs: positionMs, speed: speed, finished: finished,
                                                        recordedAt: recordedAt),
                       headers: device?.headers ?? [:], as: AudiobookProgressResult.self)
    }

    /// 201 {bookmark}.
    func createAudiobookBookmark(id: Int, positionMs: Int64, note: String?) async throws -> AudiobookBookmark {
        var body: [String: JSONValue] = ["position_ms": .int64(max(positionMs, 0))]
        if let note = note?.trimmingCharacters(in: .whitespacesAndNewlines), !note.isEmpty {
            body["note"] = .string(String(note.prefix(500)))
        }
        return try await call("POST", "audiobooks/\(id)/bookmarks", json: .object(body),
                              as: AudiobookBookmarkEnvelope.self).bookmark
    }

    /// 204; already gone (404) counts as deleted.
    func deleteAudiobookBookmark(id: Int, bookmarkId: Int) async throws {
        do {
            _ = try await call("DELETE", "audiobooks/\(id)/bookmarks/\(bookmarkId)")
        } catch let error as APIError where error.status == 404 {
            return
        }
    }

    /// Admin: the drop-in folder.
    func audiobookInbox() async throws -> AudiobookInbox {
        try await call("GET", "audiobooks/inbox", fresh: true, as: AudiobookInbox.self)
    }

    /// Admin: links an audiobook to a book, or leaves it standalone (nil).
    func matchAudiobook(id: Int, novelId: Int?) async throws {
        _ = try await call("POST", "audiobooks/\(id)/match",
                           json: .object(["novel_id": novelId.map { .int($0) } ?? .null]))
    }

    /// Admin: queues a scan of the drop-in folder now.
    func scanAudiobooks() async throws {
        _ = try await call("POST", "audiobooks/scan", json: .object([:]))
    }

    // MARK: Audiobook uploads (chunked, resumable)

    /// `POST audiobook-uploads {files:[{name,size}], novel_id?, note?}` → 201 {upload}.
    /// 422 (limits, types, space, too many pending) and 404 (older server / off) throw `.rejected`.
    func createAudiobookUpload(files: [(name: String, size: Int64)], novelId: Int?,
                               note: String?) async throws -> AudiobookUploadResource {
        var body: [String: JSONValue] = [
            "files": .array(files.map { .object(["name": .string($0.name), "size": .int64($0.size)]) }),
        ]
        if let novelId { body["novel_id"] = .int(novelId) }
        if let note = note?.trimmingCharacters(in: .whitespacesAndNewlines), !note.isEmpty {
            body["note"] = .string(String(note.prefix(500)))
        }
        let envelope = try await call("POST", "audiobook-uploads", json: .object(body), timeout: 60,
                                      headers: device?.headers ?? [:], as: AudiobookUploadEnvelope.self)
        guard let upload = envelope.upload else { throw APIError.decoding("no upload") }
        return upload
    }

    /// Which chunks the server has, and the upload's status.
    func audiobookUpload(id: Int) async throws -> AudiobookUploadResource {
        let envelope = try await call("GET", "audiobook-uploads/\(id)", fresh: true, as: AudiobookUploadEnvelope.self)
        guard let upload = envelope.upload else { throw APIError.decoding("no upload") }
        return upload
    }

    /// One chunk: `PUT audiobook-uploads/{id}/files/{file}/chunks/{n}?offset=` with
    /// the raw bytes and `Content-Range: bytes start-end/size` → 204. Idempotent.
    func putAudiobookChunk(id: Int, file: Int, chunk: Int, offset: Int64, fileSize: Int64,
                           data: Data) async throws {
        let end = offset + Int64(data.count) - 1
        var request = makeRequest("PUT", "audiobook-uploads/\(id)/files/\(file)/chunks/\(chunk)",
                                  query: [URLQueryItem(name: "offset", value: String(offset))], timeout: 300,
                                  headers: ["Content-Range": "bytes \(offset)-\(end)/\(fileSize)",
                                            "Content-Type": "application/octet-stream"])
        request.httpBody = data
        _ = try await perform(detailed: request)
    }

    /// Every chunk is there: the server assembles, verifies and probes →
    /// 200 (pending) / 202 (still working) / 422 why not (with `upload`).
    func completeAudiobookUpload(id: Int) async throws -> AudiobookUploadResource? {
        try await call("POST", "audiobook-uploads/\(id)/complete", json: .object([:]), timeout: 600,
                       as: AudiobookUploadEnvelope.self).upload
    }

    /// The reader cancels (the server deletes the files): 204; already gone (404) counts as cancelled.
    func cancelAudiobookUpload(id: Int) async throws {
        do {
            _ = try await call("DELETE", "audiobook-uploads/\(id)", timeout: 30)
        } catch let error as APIError where error.status == 404 {
            return
        }
    }

    // MARK: Books sent from the app ("Share to Novarr")

    /// One ePub to send, read from disk (never held whole in memory).
    struct UploadFile: Sendable {
        var name: String
        var fileURL: URL
    }

    /// Sends ePubs to the owner's approval queue: one multipart part `epub[]`
    /// per file and an optional `note`. 201, or 422 with `sent`/`refused` when
    /// none were taken, both answer a `BookUploadResponse`; anything else (a
    /// validation 422 without `sent`, 404 `requests_disabled`, 401, 413) throws `.rejected`.
    /// The multipart body is written to a temporary file and streamed from it.
    func uploadBooks(_ files: [UploadFile], note: String?,
                     delegate: (any URLSessionTaskDelegate)? = nil) async throws -> BookUploadResponse {
        let boundary = "novarr-\(UUID().uuidString)"
        let bodyURL = FileManager.default.temporaryDirectory.appending(path: "upload-\(UUID().uuidString).multipart")
        defer { try? FileManager.default.removeItem(at: bodyURL) }
        try Self.writeMultipart(files, note: note, boundary: boundary, to: bodyURL)

        var request = makeRequest("POST", "books/uploads", timeout: 600)
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        let data: Data
        let response: URLResponse
        do {
            (data, response) = try await session.upload(for: request, fromFile: bodyURL, delegate: delegate)
        } catch {
            if Task.isCancelled { throw CancellationError() }
            throw APIError.from(error)
        }
        guard let http = response as? HTTPURLResponse else { throw APIError.unreachable("No HTTP response.") }
        if (200..<300).contains(http.statusCode) { return try decode(BookUploadResponse.self, from: data) }
        if http.statusCode == 401 { onUnauthorized?(token) }
        // None were taken: still the per-file answer. A validation 422 has no `sent`.
        if http.statusCode == 422,
           let object = try? JSONSerialization.jsonObject(with: data) as? [String: Any], object["sent"] != nil {
            return try decode(BookUploadResponse.self, from: data)
        }
        throw APIError.rejected(Self.failure(status: http.statusCode, data: data, response: http))
    }

    static func writeMultipart(_ files: [UploadFile], note: String?, boundary: String, to url: URL) throws {
        FileManager.default.createFile(atPath: url.path, contents: nil)
        let out = try FileHandle(forWritingTo: url)
        defer { try? out.close() }
        func write(_ string: String) throws { try out.write(contentsOf: Data(string.utf8)) }
        for file in files {
            let safeName = file.name.replacingOccurrences(of: "\"", with: "'").replacingOccurrences(of: "\r", with: "")
                .replacingOccurrences(of: "\n", with: "")
            try write("--\(boundary)\r\n")
            try write("Content-Disposition: form-data; name=\"epub[]\"; filename=\"\(safeName)\"\r\n")
            try write("Content-Type: application/epub+zip\r\n\r\n")
            let input = try FileHandle(forReadingFrom: file.fileURL)
            defer { try? input.close() }
            while let chunk = try input.read(upToCount: 1 << 20), !chunk.isEmpty {
                try out.write(contentsOf: chunk)
            }
            try write("\r\n")
        }
        if let note = note?.trimmingCharacters(in: .whitespacesAndNewlines), !note.isEmpty {
            try write("--\(boundary)\r\n")
            try write("Content-Disposition: form-data; name=\"note\"\r\n\r\n")
            try write(note)
            try write("\r\n")
        }
        try write("--\(boundary)--\r\n")
    }
}
