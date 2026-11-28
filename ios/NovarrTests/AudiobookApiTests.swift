import Foundation
import Testing
@testable import Novarr

/// The audiobooks API contract: decoding, every call's method, path, body and headers (android AudiobookApiTest).
@Suite("Audiobook API")
struct AudiobookApiTests {
    let device = DeviceIdentity(id: "i_TESTDEVICE123", name: "iPhone")

    static let detailJSON = """
        {"audiobook": {"id": 12, "novel_id": 40, "title": "The Book", "author": "A. Writer", "narrator": "N. Reader",
         "cover_url": "https://h/c.jpg", "duration_ms": 41234000,
         "progress": {"position_ms": 120000, "finished": false, "updated_at": "2026-12-10T10:00:00Z"},
         "description": "About it", "year": 2019, "size_bytes": 512000000,
         "parts": [
           {"index": 1, "url": "https://h/api/v1/audiobooks/12/parts/1?signature=x", "mime": "audio/mpeg", "duration_ms": 21234000, "start_ms": 20000000, "size_bytes": 2},
           {"index": 0, "url": "https://h/api/v1/audiobooks/12/parts/0?signature=y", "mime": "audio/mpeg", "duration_ms": 20000000, "start_ms": 0, "size_bytes": 1},
           {"broken": true}
         ],
         "chapters": [{"index": 0, "title": "One", "start_ms": 0, "end_ms": 600000}, {"index": 1, "title": "", "start_ms": 600000, "end_ms": 41234000}],
         "bookmarks": [{"id": 3, "position_ms": 5000, "note": null, "created_at": "2026-12-10T09:00:00Z"}],
         "future_field": {"x": 1}}}
        """

    final class Seen: @unchecked Sendable {
        private let lock = NSLock()
        private var stored: [(URLRequest, Data?)] = []
        func add(_ request: URLRequest, _ body: Data?) { lock.withLock { stored.append((request, body)) } }
        var all: [(URLRequest, Data?)] { lock.withLock { stored } }
        func body(_ i: Int) -> [String: Any] {
            (all[i].1.flatMap { try? JSONSerialization.jsonObject(with: $0) } as? [String: Any]) ?? [:]
        }
    }

    @Test func detailDecodesFlatWithItsPartsInOrder() throws {
        let detail = try Fixture.decode(AudiobookEnvelope.self, json: Self.detailJSON).audiobook
        #expect(detail.id == 12)
        #expect(detail.summary.novelId == 40)
        #expect(detail.summary.narratorOrNil == "N. Reader")
        #expect(detail.summary.progress?.positionMs == 120_000)
        #expect(detail.year == "2019")
        // A malformed part is skipped; parts come back in index order.
        #expect(detail.parts.map(\.index) == [0, 1])
        #expect(detail.chapters[1].label == "Chapter 2")
        #expect(detail.bookmarks.first?.noteOrNil == nil)
        // Cached and read back exactly the same way.
        let again = try JSONCoding.decoder().decode(AudiobookDetail.self, from: JSONCoding.encoder().encode(detail))
        #expect(again == detail)
        // The timeline uses the server's starts.
        #expect(AudiobookTimeline.of(detail).totalMs == 41_234_000)
    }

    @Test func summariesAreLenient() throws {
        let bare = try Fixture.decode(AudiobookSummary.self, json: #"{"id": 5}"#)
        #expect(bare.title == "Untitled")
        #expect(bare.progress == nil)
        #expect(!bare.started)
        let bad = try Fixture.decode(AudiobookSummary.self, json: #"{"id": 5, "duration_ms": 1000, "progress": "weird"}"#)
        #expect(bad.progress == nil)
        let finished = try Fixture.decode(AudiobookSummary.self,
                                          json: #"{"id": 5, "duration_ms": 1000, "progress": {"position_ms": 10, "finished": true}}"#)
        #expect(finished.fraction == 1)
        #expect(AudiobookTile.caption(finished) == "FINISHED")
        let started = try Fixture.decode(AudiobookSummary.self,
                                         json: #"{"id": 5, "duration_ms": 3600000, "progress": {"position_ms": 600000}}"#)
        #expect(AudiobookTile.caption(started) == "50 min left")
        #expect(AudiobookTile.caption(bare) == "AUDIOBOOK")
    }

    @Test func listsAndContinue() async throws {
        let seen = Seen()
        let client = StubURLProtocol.client(device: device, token: "tok") { request, body in
            seen.add(request, body)
            if request.url?.path.hasSuffix("continue") == true {
                return (200, Data(#"{"audiobooks":[{"id":1,"title":"A"}]}"#.utf8))
            }
            return (200, Data(#"{"audiobooks":[{"id":1},{"id":2}],"page":2,"has_more":true}"#.utf8))
        }
        let page = try await client.audiobooks(page: 2, search: "  dune ")
        #expect(page.audiobooks.map(\.id) == [1, 2])
        #expect(page.hasMore)
        let first = try #require(seen.all.first?.0)
        #expect(first.url?.path == "/api/v1/audiobooks")
        #expect(first.url?.query == "page=2&search=dune")
        #expect(first.value(forHTTPHeaderField: "Authorization") == "Bearer tok")
        #expect(try await client.continueListening().first?.title == "A")
        #expect(seen.all[1].0.url?.path == "/api/v1/audiobooks/continue")
    }

    @Test func oneAudiobookIsNeverServedFromTheCache() async throws {
        let seen = Seen()
        let client = StubURLProtocol.client(device: device, token: "tok") { request, body in
            seen.add(request, body)
            return (200, Data(Self.detailJSON.utf8))
        }
        let detail = try await client.audiobook(id: 12)
        #expect(detail.parts.count == 2)
        let request = try #require(seen.all.first?.0)
        #expect(request.url?.path == "/api/v1/audiobooks/12")
        // The signed URLs inside expire: never an old copy.
        #expect(request.cachePolicy == .reloadIgnoringLocalCacheData || request.value(forHTTPHeaderField: "Cache-Control") != nil)
    }

    @Test func progressIsAPutWithRecordedAtAndDeviceHeaders() async throws {
        let seen = Seen()
        let client = StubURLProtocol.client(device: device, token: "tok") { request, body in
            seen.add(request, body)
            return (200, Data(#"{"progress":{"position_ms":9000,"finished":false,"updated_at":"2026-12-10T10:00:00Z"},"stale":true}"#.utf8))
        }
        let result = try await client.saveAudiobookProgress(id: 12, positionMs: 8_000, speed: 1.2345, finished: false,
                                                            recordedAt: ListeningSync.isoOf(ListeningSync.parseInstant("2026-12-10T09:59:00Z")!))
        #expect(result.stale)
        #expect(result.progress?.positionMs == 9_000)
        let request = try #require(seen.all.first?.0)
        #expect(request.httpMethod == "PUT")
        #expect(request.url?.path == "/api/v1/audiobooks/12/progress")
        #expect(request.value(forHTTPHeaderField: DeviceIdentity.idHeader) == "i_TESTDEVICE123")
        #expect(request.value(forHTTPHeaderField: DeviceIdentity.nameHeader) == "iPhone")
        let body = seen.body(0)
        #expect((body["position_ms"] as? NSNumber)?.int64Value == 8_000)
        #expect(abs(((body["speed"] as? NSNumber)?.doubleValue ?? 0) - 1.23) < 0.0001)
        #expect((body["finished"] as? Bool) == false)
        #expect(body["recorded_at"] as? String == "2026-12-10T09:59:00Z")
    }

    @Test func listeningPositionsAreSentThroughTheStore() async throws {
        let seen = Seen()
        let client = StubURLProtocol.client(device: device, token: "tok") { request, body in
            seen.add(request, body)
            return (200, Data(#"{"progress":{"position_ms":1},"stale":false}"#.utf8))
        }
        let store = ListeningStore(fileURL: temporaryFile(ListeningStore.fileName))
        await store.record(ListeningRecord(audiobookId: 3, positionMs: 4_000, speed: 1.5, finished: true, recordedAt: 1_800_000_000_123))
        let outcome = await store.flush { record in
            _ = try await client.saveAudiobookProgress(id: record.audiobookId, positionMs: record.positionMs, speed: record.speed,
                                                       finished: record.finished, recordedAt: ListeningSync.isoOf(record.recordedAt))
        }
        #expect(outcome == .drained(sent: 1, dropped: 0))
        let body = seen.body(0)
        #expect(body["finished"] as? Bool == true)
        #expect(body["recorded_at"] as? String == "2027-01-15T08:00:00.123Z")
    }

    @Test func bookmarks() async throws {
        let seen = Seen()
        let client = StubURLProtocol.client(device: device, token: "tok") { request, body in
            seen.add(request, body)
            if request.httpMethod == "POST" { return (201, Data(#"{"bookmark":{"id":9,"position_ms":5000,"note":"here"}}"#.utf8)) }
            return (404, Data(#"{"message":"gone"}"#.utf8))
        }
        let bookmark = try await client.createAudiobookBookmark(id: 12, positionMs: 5_000, note: "  here  ")
        #expect(bookmark.id == 9)
        #expect(seen.all[0].0.url?.path == "/api/v1/audiobooks/12/bookmarks")
        #expect(seen.body(0)["note"] as? String == "here")
        // No note: no key at all.
        _ = try await client.createAudiobookBookmark(id: 12, positionMs: 1, note: "   ")
        #expect(seen.body(1)["note"] == nil)
        // Already deleted (404) counts as deleted.
        try await client.deleteAudiobookBookmark(id: 12, bookmarkId: 9)
        #expect(seen.all[2].0.httpMethod == "DELETE")
        #expect(seen.all[2].0.url?.path == "/api/v1/audiobooks/12/bookmarks/9")
    }

    @Test func adminCalls() async throws {
        let seen = Seen()
        let client = StubURLProtocol.client(device: device, token: "tok") { request, body in
            seen.add(request, body)
            if request.url?.path.hasSuffix("inbox") == true {
                return (200, Data(#"{"pending":[{"name":"Dune (Unabridged)","size_bytes":12,"status":"stabilising"}],"failed":[{"name":"x.mp3","reason":"ffprobe failed"}]}"#.utf8))
            }
            return (202, Data("{}".utf8))
        }
        let inbox = try await client.audiobookInbox()
        #expect(inbox.pending.first?.status == "stabilising")
        #expect(inbox.failed.first?.reason == "ffprobe failed")
        try await client.matchAudiobook(id: 12, novelId: 40)
        try await client.matchAudiobook(id: 12, novelId: nil)
        try await client.scanAudiobooks()
        #expect(seen.all[1].0.url?.path == "/api/v1/audiobooks/12/match")
        #expect((seen.body(1)["novel_id"] as? NSNumber)?.intValue == 40)
        #expect(seen.body(2)["novel_id"] is NSNull)
        #expect(seen.all[3].0.httpMethod == "POST")
        #expect(seen.all[3].0.url?.path == "/api/v1/audiobooks/scan")
    }

    // Download headers and file names

    @Test func theTokenOnlyGoesToTheReadersOwnServer() throws {
        let server = try #require(URL(string: "https://novarr.example:8443"))
        let headers = AudiobookDownloads.headersFor(try #require(URL(string: "https://novarr.example:8443/api/v1/audiobooks/1/parts/0?signature=s")),
                                                    server: server, token: "tok", device: device.headers)
        #expect(headers["Authorization"] == "Bearer tok")
        #expect(headers[DeviceIdentity.idHeader] == "i_TESTDEVICE123")
        // Another host, port or scheme: nothing.
        #expect(AudiobookDownloads.headersFor(URL(string: "https://cdn.example/a.mp3")!, server: URL(string: "https://novarr.example")!,
                                              token: "tok", device: device.headers).isEmpty)
        #expect(AudiobookDownloads.headersFor(URL(string: "https://novarr.example:9999/a")!, server: server,
                                              token: "tok", device: device.headers).isEmpty)
        #expect(AudiobookDownloads.headersFor(URL(string: "http://novarr.example/a")!, server: URL(string: "https://novarr.example")!,
                                              token: "tok", device: device.headers).isEmpty)
        // A local file: nothing.
        #expect(AudiobookDownloads.headersFor(URL(fileURLWithPath: "/data/x.m4b"), server: server, token: "tok",
                                              device: device.headers).isEmpty)
        // Sign-in off: device headers, no Authorization.
        #expect(AudiobookDownloads.headersFor(URL(string: "https://novarr.example/a")!, server: URL(string: "https://novarr.example")!,
                                              token: nil, device: device.headers)["Authorization"] == nil)
    }

    @Test func downloadFileNames() {
        #expect(AudiobookDownloads.fileName(AudiobookPart(index: 0, mime: "audio/mpeg")) == "part-000.mp3")
        #expect(AudiobookDownloads.fileName(AudiobookPart(index: 12, mime: "audio/x-m4b")) == "part-012.m4b")
        #expect(AudiobookDownloads.fileName(AudiobookPart(index: 1, mime: "audio/mp4; codecs=mp4a")) == "part-001.m4a")
        #expect(AudiobookDownloads.fileName(AudiobookPart(index: 2, mime: nil)) == "part-002.audio")
    }

    @Test func audioStorageIsASiblingOfTheOfflineStore() {
        let base = temporaryFile("base").deletingLastPathComponent()
        let root = OfflineStore.directory(for: URL(string: "http://host:8080")!, userId: 3, base: base)
        let storage = AudioStorage(storeRoot: root)
        #expect(storage.root.path.hasPrefix(base.appending(path: "Novarr/audio").path))
        #expect(storage.root.lastPathComponent == root.lastPathComponent)
        #expect(storage.downloadDirectory(7).lastPathComponent == "7")
    }
}
