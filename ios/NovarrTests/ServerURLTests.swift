import Foundation
import Testing
@testable import Novarr

@Suite("Server URL normalisation")
struct ServerURLTests {
    @Test(arguments: [
        ("192.168.1.10", "http://192.168.1.10"),
        ("  192.168.1.10:8080/  ", "http://192.168.1.10:8080"),
        ("http://nas.local:8080///", "http://nas.local:8080"),
        ("HTTPS://novarr.example.ts.net", "https://novarr.example.ts.net"),
        ("https://example.com/novarr/", "https://example.com/novarr"),
        ("http://nas.local/api/v1/", "http://nas.local"),
        ("http://nas.local/api", "http://nas.local"),
        ("nas.local/?x=1#frag", "http://nas.local"),
    ])
    func normalises(input: String, expected: String) {
        #expect(ServerURL.normalize(input)?.absoluteString == expected)
    }

    @Test(arguments: ["", "   ", "ftp://nas.local", "http://", "not a url", "http:///path"])
    func rejects(input: String) {
        #expect(ServerURL.normalize(input) == nil)
    }

    @Test func apiPathsHangOffTheBase() throws {
        let base = try #require(ServerURL.normalize("https://example.com/novarr"))
        let client = APIClient(baseURL: base)
        #expect(client.url(for: "meta").absoluteString == "https://example.com/novarr/api/v1/meta")
    }

    @Test func storageKeysDifferPerServer() throws {
        let a = try #require(ServerURL.normalize("192.168.1.10:8080"))
        let b = try #require(ServerURL.normalize("192.168.1.11:8080"))
        #expect(ServerURL.storageKey(for: a) != ServerURL.storageKey(for: b))
        #expect(!ServerURL.storageKey(for: a).contains("/"))
    }
}

@Suite("Server verification")
struct MetaTests {
    @Test func acceptsNovarr() async throws {
        let client = StubURLProtocol.client { request, _ in
            #expect(request.url?.path == "/api/v1/meta")
            return (200, Data(#"{"app":"novarr","api_version":1,"server_version":"abc123"}"#.utf8))
        }
        let meta = try await client.meta()
        #expect(meta.serverVersion == "abc123")
    }

    @Test func rejectsOtherApps() async {
        let client = StubURLProtocol.client { _, _ in (200, Data(#"{"app":"sonarr","api_version":3}"#.utf8)) }
        await #expect(throws: APIError.notNovarr) { try await client.meta() }
    }

    @Test func rejectsHTMLPages() async {
        let client = StubURLProtocol.client { _, _ in (200, Data("<html>router login</html>".utf8)) }
        await #expect(throws: APIError.notNovarr) { try await client.meta() }
    }

    @Test func rejectsMissingRoute() async {
        let client = StubURLProtocol.client { _, _ in (404, Data(#"{"message":"Not Found"}"#.utf8)) }
        await #expect(throws: APIError.notNovarr) { try await client.meta() }
    }

    @Test func rejectsOldAPI() async {
        let client = StubURLProtocol.client { _, _ in (200, Data(#"{"app":"novarr","api_version":0}"#.utf8)) }
        await #expect(throws: APIError.apiTooOld(serverVersion: 0, required: 1)) { try await client.meta() }
    }

    @Test func reportsUnreachable() async {
        let client = StubURLProtocol.client { _, _ in throw URLError(.cannotConnectToHost) }
        do {
            _ = try await client.meta()
            Issue.record("Expected a failure")
        } catch let error as APIError {
            #expect(error.isConnectivity)
        } catch {
            Issue.record("Unexpected error \(error)")
        }
    }
}
