import Foundation
@testable import Novarr

final class BundleToken {}

enum Fixture {
    static func data(_ name: String) throws -> Data {
        guard let url = Bundle(for: BundleToken.self).url(forResource: name, withExtension: "json") else {
            throw FixtureError.missing(name)
        }
        return try Data(contentsOf: url)
    }

    static func decode<T: Decodable>(_ type: T.Type, _ name: String) throws -> T {
        try JSONCoding.decoder().decode(type, from: data(name))
    }

    static func decode<T: Decodable>(_ type: T.Type, json: String) throws -> T {
        try JSONCoding.decoder().decode(type, from: Data(json.utf8))
    }

    enum FixtureError: Error { case missing(String) }
}

/// Routes requests to per-host handlers so tests can run in parallel: each
/// test gets its own made-up host.
final class StubURLProtocol: URLProtocol, @unchecked Sendable {
    typealias Handler = @Sendable (URLRequest, Data?) throws -> (status: Int, body: Data)

    private static let lock = NSLock()
    nonisolated(unsafe) private static var handlers: [String: Handler] = [:]

    /// Returns a client whose requests go to `handler`.
    static func client(device: DeviceIdentity? = nil, token: String? = nil,
                       onUnauthorized: (@Sendable (String?) -> Void)? = nil,
                       _ handler: @escaping Handler) -> APIClient {
        let (base, session) = register(handler)
        return APIClient(baseURL: base, session: session, device: device, token: token,
                         onUnauthorized: onUnauthorized)
    }

    /// A made-up server base URL and a session whose requests to it go to `handler`.
    static func register(_ handler: @escaping Handler) -> (base: URL, session: URLSession) {
        let host = "stub-\(UUID().uuidString.lowercased()).test"
        lock.withLock { handlers[host] = handler }
        let config = URLSessionConfiguration.ephemeral
        config.protocolClasses = [StubURLProtocol.self]
        config.urlCache = nil
        let base = URL(string: "http://\(host)") ?? URL(fileURLWithPath: "/")
        return (base, URLSession(configuration: config))
    }

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        let handler = request.url?.host.flatMap { host in Self.lock.withLock { Self.handlers[host] } }
        guard let handler, let url = request.url else {
            client?.urlProtocol(self, didFailWithError: URLError(.cannotFindHost))
            return
        }
        do {
            let (status, body) = try handler(request, Self.body(of: request))
            guard let response = HTTPURLResponse(
                url: url, statusCode: status, httpVersion: "HTTP/1.1",
                headerFields: ["Content-Type": "application/json"])
            else { throw URLError(.badServerResponse) }
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: body)
            client?.urlProtocolDidFinishLoading(self)
        } catch {
            client?.urlProtocol(self, didFailWithError: error)
        }
    }

    override func stopLoading() {}

    /// URLSession hands protocols the body as a stream, not `httpBody`.
    private static func body(of request: URLRequest) -> Data? {
        if let body = request.httpBody { return body }
        guard let stream = request.httpBodyStream else { return nil }
        stream.open()
        defer { stream.close() }
        var data = Data()
        var buffer = [UInt8](repeating: 0, count: 4096)
        while stream.hasBytesAvailable {
            let read = stream.read(&buffer, maxLength: buffer.count)
            if read <= 0 { break }
            data.append(buffer, count: read)
        }
        return data
    }
}

/// A controllable clock for the write queue.
final class TestClock: @unchecked Sendable {
    private let lock = NSLock()
    private var current: Date

    init(_ start: Date = Date(timeIntervalSince1970: 1_800_000_000)) { current = start }

    var now: Date { lock.withLock { current } }
    func advance(_ seconds: TimeInterval) { lock.withLock { current += seconds } }
}

func temporaryFile(_ name: String = "queue.json") -> URL {
    FileManager.default.temporaryDirectory
        .appending(path: "novarr-tests-\(UUID().uuidString)", directoryHint: .isDirectory)
        .appending(path: name)
}

func makeNovel(
    id: Int, name: String, author: String? = nil, read: Int = 0, total: Int = 100, new: Int = 0,
    finished: Bool = false, lastReadAt: Date? = nil, updatedAt: Date? = nil
) -> NovelSummary {
    NovelSummary(
        id: id, name: name, author: author, coverUrl: nil, coverLargeUrl: nil, state: .active,
        completed: false, paused: false, origin: nil, tags: [], chaptersTotal: total,
        chaptersDownloaded: total, chaptersRead: read, chaptersNew: new,
        readPercent: total == 0 ? 0 : read * 100 / total, downloadPercent: 100, finished: finished,
        lastReadAt: lastReadAt, updatedAt: updatedAt, resumeChapterId: nil)
}
