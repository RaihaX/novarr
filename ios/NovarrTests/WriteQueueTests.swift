import Foundation
import Testing
@testable import Novarr

/// Collects what a flush sends, and can be told to fail.
private final class Recorder: @unchecked Sendable {
    private let lock = NSLock()
    private var _sent: [(PendingWrite.Kind, Int)] = []
    var failWith: Error?
    var failOnlyFor: PendingWrite.Kind?

    var sent: [(kind: PendingWrite.Kind, age: Int)] { lock.withLock { _sent.map { ($0.0, $0.1) } } }

    func send(_ kind: PendingWrite.Kind, _ age: Int) throws {
        try lock.withLock {
            if let failWith, failOnlyFor == nil || failOnlyFor == kind { throw failWith }
            _sent.append((kind, age))
        }
    }
}

@Suite("Offline write queue")
struct WriteQueueTests {
    let a = PendingWrite.Kind.setRead(chapterId: 1, read: true)
    let b = PendingWrite.Kind.progress(chapterId: 2, progress: 50, read: false)
    let c = PendingWrite.Kind.bulkRead(.upTo(9, novelId: 4, read: true))

    @Test func sendsInRecordedOrder() async {
        let queue = WriteQueue(fileURL: temporaryFile())
        await queue.enqueue(a)
        await queue.enqueue(b)
        await queue.enqueue(c)
        let recorder = Recorder()
        let outcome = await queue.flush { try recorder.send($0, $1) }
        #expect(outcome == .drained(sent: 3, dropped: 0))
        #expect(recorder.sent.map(\.kind) == [a, b, c])
        #expect(await queue.count == 0)
    }

    @Test func ageIsMillisecondsSinceRecorded() async {
        let clock = TestClock()
        let queue = WriteQueue(fileURL: temporaryFile(), now: { clock.now })
        await queue.enqueue(a)
        clock.advance(2.5)
        await queue.enqueue(b)
        clock.advance(1)
        let recorder = Recorder()
        _ = await queue.flush { try recorder.send($0, $1) }
        #expect(recorder.sent.map(\.age) == [3500, 1000])
    }

    @Test func ageNeverNegative() {
        let write = PendingWrite(id: UUID(), kind: a, recordedAt: Date(timeIntervalSince1970: 100))
        #expect(write.age(at: Date(timeIntervalSince1970: 50)) == 0)
        #expect(write.age(at: Date(timeIntervalSince1970: 100.25)) == 250)
    }

    @Test func persistsAcrossReinit() async {
        let file = temporaryFile()
        let clock = TestClock()
        let first = WriteQueue(fileURL: file, now: { clock.now })
        await first.enqueue(a)
        await first.enqueue(c)
        let before = await first.items

        let second = WriteQueue(fileURL: file, now: { clock.now })
        let after = await second.items
        #expect(after == before)

        // Age keeps counting from the original recording time after a relaunch.
        clock.advance(60)
        let recorder = Recorder()
        _ = await second.flush { try recorder.send($0, $1) }
        #expect(recorder.sent.map(\.age) == [60_000, 60_000])
    }

    @Test func successRemovesFromDisk() async {
        let file = temporaryFile()
        let queue = WriteQueue(fileURL: file)
        await queue.enqueue(a)
        _ = await queue.flush { _, _ in }
        #expect(await WriteQueue(fileURL: file).count == 0)
    }

    @Test func retryableFailureKeepsEverythingInOrder() async {
        let file = temporaryFile()
        let queue = WriteQueue(fileURL: file)
        await queue.enqueue(a)
        await queue.enqueue(b)
        await queue.enqueue(c)

        let recorder = Recorder()
        recorder.failWith = URLError(.notConnectedToInternet)
        recorder.failOnlyFor = b
        let outcome = await queue.flush { try recorder.send($0, $1) }

        #expect(outcome == .stalled(sent: 1, remaining: 2))
        #expect(recorder.sent.map(\.kind) == [a])
        #expect(await queue.items.map(\.kind) == [b, c])
        #expect(await WriteQueue(fileURL: file).items.map(\.kind) == [b, c])

        // Back online: the rest goes out, still in order.
        recorder.failWith = nil
        let retry = await queue.flush { try recorder.send($0, $1) }
        #expect(retry == .drained(sent: 2, dropped: 0))
        #expect(recorder.sent.map(\.kind) == [a, b, c])
    }

    @Test func serverErrorsAreRetried() async {
        let queue = WriteQueue(fileURL: temporaryFile())
        await queue.enqueue(a)
        let outcome = await queue.flush { _, _ in throw APIError.http(status: 503, message: nil) }
        #expect(outcome == .stalled(sent: 0, remaining: 1))
    }

    @Test func permanentRejectionsAreDropped() async {
        let queue = WriteQueue(fileURL: temporaryFile())
        await queue.enqueue(a)
        await queue.enqueue(b)
        let recorder = Recorder()
        recorder.failWith = APIError.http(status: 422, message: "The read field is required.")
        recorder.failOnlyFor = a
        let outcome = await queue.flush { try recorder.send($0, $1) }
        #expect(outcome == .drained(sent: 1, dropped: 1))
        #expect(recorder.sent.map(\.kind) == [b])
    }

    @Test func newerScrollPositionReplacesOlderOne() async {
        let queue = WriteQueue(fileURL: temporaryFile())
        await queue.enqueue(.progress(chapterId: 5, progress: 10, read: false))
        await queue.enqueue(a)
        await queue.enqueue(.progress(chapterId: 5, progress: 60, read: false))
        await queue.enqueue(.progress(chapterId: 5, progress: 100, read: true))
        #expect(await queue.items.map(\.kind) == [
            a,
            .progress(chapterId: 5, progress: 60, read: false),
            .progress(chapterId: 5, progress: 100, read: true),
        ])
    }

    @Test func flushThroughAPIClientPostsContractBodies() async throws {
        let bodies = BodyLog()
        let client = StubURLProtocol.client { request, body in
            #expect(request.httpMethod == "POST")
            #expect(request.value(forHTTPHeaderField: "Accept") == "application/json")
            bodies.append(request.url?.path ?? "", body ?? Data())
            return (200, Data(#"{"success":true,"read":true}"#.utf8))
        }
        let clock = TestClock()
        let queue = WriteQueue(fileURL: temporaryFile(), now: { clock.now })
        await queue.enqueue(.progress(chapterId: 7, progress: 42, read: false))
        await queue.enqueue(.setRead(chapterId: 8, read: false))
        await queue.enqueue(.bulkRead(.upTo(9, novelId: 4, read: true)))
        clock.advance(1.5)

        let outcome = await queue.flush(using: client)
        #expect(outcome == .drained(sent: 3, dropped: 0))

        let log = bodies.entries
        #expect(log.map(\.path) == ["/api/v1/chapters/7/progress", "/api/v1/chapters/8/read", "/api/v1/chapters/bulk-read"])
        let progress = try JSONSerialization.jsonObject(with: log[0].body) as? [String: Any]
        #expect(progress?["progress"] as? Int == 42)
        #expect(progress?["read"] == nil) // only sent when marking read
        #expect(progress?["age"] as? Int == 1500)
        let read = try JSONSerialization.jsonObject(with: log[1].body) as? [String: Any]
        #expect(read?["read"] as? Bool == false)
        let bulk = try JSONSerialization.jsonObject(with: log[2].body) as? [String: Any]
        #expect(bulk?["scope"] as? String == "up_to")
        #expect(bulk?["anchor_id"] as? Int == 9)
        #expect(bulk?["read"] as? Bool == true)
        #expect(bulk?["age"] as? Int == 1500)
    }

    @Test func flushThroughAPIClientStopsWhenServerIsDown() async {
        let client = StubURLProtocol.client { _, _ in (500, Data(#"{"message":"Server Error"}"#.utf8)) }
        let queue = WriteQueue(fileURL: temporaryFile())
        await queue.enqueue(a)
        await queue.enqueue(b)
        let outcome = await queue.flush(using: client)
        #expect(outcome == .stalled(sent: 0, remaining: 2))
    }
}

private final class BodyLog: @unchecked Sendable {
    private let lock = NSLock()
    private var _entries: [(path: String, body: Data)] = []
    var entries: [(path: String, body: Data)] { lock.withLock { _entries } }
    func append(_ path: String, _ body: Data) { lock.withLock { _entries.append((path, body)) } }
}
