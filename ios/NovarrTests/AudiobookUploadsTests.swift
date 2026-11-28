import Foundation
import Testing
@testable import Novarr

/// Chunk planning, resume, retries, picked files, the upload API and the saved
/// state (android AudiobookUploadsTest and AudiobookUploadResendTest).
@Suite("Audiobook uploads")
struct AudiobookUploadsTests {
    typealias Chunk = AudiobookUploads.Chunk
    typealias Picked = AudiobookUploads.Picked
    let mb: Int64 = 1024 * 1024
    var chunk: Int64 { 8 * mb }

    // MARK: Chunk planning

    @Test func filesAreCutIntoEightMegabyteChunksWithASmallerLastOne() {
        let plan = AudiobookUploads.plan([20 * mb, 8 * mb, 1], chunkSize: chunk)
        #expect(plan.count == 5)
        #expect(plan.filter { $0.file == 0 }.map(\.index) == [0, 1, 2])
        #expect(plan[2] == Chunk(file: 0, index: 2, offset: 16 * mb, length: 4 * mb, fileSize: 20 * mb))
        #expect(plan[3] == Chunk(file: 1, index: 0, offset: 0, length: 8 * mb, fileSize: 8 * mb))
        #expect(plan[4] == Chunk(file: 2, index: 0, offset: 0, length: 1, fileSize: 1))
        #expect(AudiobookUploads.bytesOf(plan) == 20 * mb + 8 * mb + 1)
        // Every byte once, no gaps.
        for file in 0..<3 {
            let chunks = plan.filter { $0.file == file }
            for (a, b) in zip(chunks, chunks.dropFirst()) { #expect(a.end == b.offset) }
        }
    }

    @Test func chunkCountsAndRanges() {
        #expect(AudiobookUploads.chunkCount(0, chunk) == 0)
        #expect(AudiobookUploads.chunkCount(1, chunk) == 1)
        #expect(AudiobookUploads.chunkCount(chunk, chunk) == 1)
        #expect(AudiobookUploads.chunkCount(chunk + 1, chunk) == 2)
        // A 3 GB file: 384 chunks.
        #expect(AudiobookUploads.chunkCount(3 * 1024 * mb, chunk) == 384)
        #expect(Chunk(file: 0, index: 0, offset: 0, length: chunk, fileSize: 20 * mb).contentRange == "bytes 0-8388607/20971520")
        #expect(Chunk(file: 0, index: 2, offset: 16 * mb, length: 4 * mb, fileSize: 20 * mb).contentRange
                == "bytes 16777216-20971519/20971520")
        #expect(Chunk(file: 0, index: 0, offset: 0, length: 1, fileSize: 1).contentRange == "bytes 0-0/1")
    }

    @Test func serverChunkSizeIsBytesOrMegabytesWithTheDefaultForNonsense() {
        #expect(AudiobookUploads.chunkSize(nil) == chunk)
        #expect(AudiobookUploads.chunkSize(0) == chunk)
        #expect(AudiobookUploads.chunkSize(-5) == chunk)
        #expect(AudiobookUploads.chunkSize(4) == 4 * mb)
        #expect(AudiobookUploads.chunkSize(1000) == chunk)
        #expect(AudiobookUploads.chunkSize(5 * mb) == 5 * mb)
    }

    // MARK: Resume

    @Test func resumeSendsOnlyWhatTheServerLacks() {
        let plan = AudiobookUploads.plan([20 * mb, 16 * mb, 3 * mb], chunkSize: chunk)
        let missing = AudiobookUploads.missing(plan, present: [0: [0, 2], 1: [0, 1]], doneFiles: [2])
        #expect(missing.map { [$0.file, $0.index] } == [[0, 1]])
        #expect(AudiobookUploads.bytesOf(missing) == chunk)
        // Nothing known: everything; everything present: nothing.
        #expect(AudiobookUploads.missing(plan, present: [:]) == plan)
        #expect(AudiobookUploads.missing(plan, present: [:], doneFiles: [0, 1, 2]).isEmpty)
        // Chunk numbers the plan doesn't have are ignored.
        #expect(AudiobookUploads.missing(plan, present: [0: [99], 7: [0]]).count == plan.count)
    }

    @Test func whatTheServerHasIsReadFromItsReceivedChunks() throws {
        // The server's payload: `chunks` is a COUNT, `received` the chunk numbers it has.
        let upload = try #require(try Fixture.decode(AudiobookUploadEnvelope.self, json: """
            {"upload":{"id":12,"status":"uploading","chunk_size":8388608,"files":[
              {"index":0,"name":"a.m4b","size":20971520,"chunks":3,"received":[0,2],"done":false},
              {"index":1,"name":"b.m4b","size":1,"chunks":1,"received":[0],"done":true}]}}
            """).upload)
        #expect(AudiobookUploads.present(upload) == [0: [0, 2], 1: [0]])
        #expect(AudiobookUploads.doneFiles(upload) == [1])
        let plan = AudiobookUploads.plan(upload.files.map(\.size), chunkSize: upload.chunkSize)
        let missing = AudiobookUploads.missing(plan, present: AudiobookUploads.present(upload),
                                               doneFiles: AudiobookUploads.doneFiles(upload))
        #expect(missing.map { [$0.file, $0.index] } == [[0, 1]])
    }

    @Test func statusesAndReasons() throws {
        typealias Status = AudiobookUploads.Status
        #expect(Status.fromServer("pending") == .pending)
        #expect(Status.fromServer("APPROVED") == .approved)
        #expect(Status.fromServer("processing") == .completing)
        #expect(Status.fromServer("weird") == nil)
        let declined = try #require(try Fixture.decode(AudiobookUploadEnvelope.self,
            json: #"{"upload":{"id":1,"status":"declined","decline_reason":"Wrong book."}}"#).upload)
        #expect(AudiobookUploads.serverMessage(declined) == "Wrong book.")
        #expect(Status.pending.finished && Status.failed.finished && !Status.uploading.finished && !Status.completing.finished)
        #expect(Status.pending.label == "Waiting for the owner")
        #expect(Status.approved.label == "In the library")
    }

    // MARK: Complete asking for chunks again

    func body(_ json: String) -> Data { Data(json.utf8) }

    @Test func checksumNamesChunksToResend() {
        let answer = body(#"{"message":"A chunk was damaged.","code":"checksum","missing":{"0":[2,3],"1":[0]}}"#)
        #expect(AudiobookUploads.resendAfterComplete(status: 422, code: "checksum", body: answer) == [0: [2, 3], 1: [0]])
    }

    @Test func incompleteAlsoCarriesMissing() {
        #expect(AudiobookUploads.resendAfterComplete(status: 422, code: "incomplete",
                                                     body: body(#"{"code":"incomplete","missing":{"1":[4]}}"#)) == [1: [4]])
        // An array of arrays (by file position) is read too; empty files drop out.
        #expect(AudiobookUploads.resendAfterComplete(status: 422, code: "incomplete",
                                                     body: body(#"{"code":"incomplete","missing":[[],[1,2]]}"#)) == [1: [1, 2]])
    }

    @Test func otherRefusalsStayRefusals() {
        #expect(AudiobookUploads.resendAfterComplete(status: 422, code: "not_audio",
                                                     body: body(#"{"code":"not_audio","message":"Not an audiobook."}"#)) == nil)
        // A checksum answer with nothing readable listed: a real failure, not an endless loop.
        #expect(AudiobookUploads.resendAfterComplete(status: 422, code: "checksum", body: body(#"{"code":"checksum"}"#)) == nil)
        #expect(AudiobookUploads.resendAfterComplete(status: 422, code: "checksum",
                                                     body: body(#"{"code":"checksum","missing":{"0":[]}}"#)) == nil)
        #expect(AudiobookUploads.resendAfterComplete(status: 409, code: "checksum", body: body(#"{"missing":{"0":[1]}}"#)) == nil)
    }

    @Test func namedChunksAreResentEvenWhenListedPresent() {
        let plan = AudiobookUploads.plan([25, 10], chunkSize: 10)
        let present: [Int: Set<Int>] = [0: [0, 1, 2], 1: [0]]
        #expect(AudiobookUploads.missing(plan, present: present).isEmpty)
        let again = AudiobookUploads.named(plan, [0: [2], 1: [0]])
        #expect(again.map { [$0.file, $0.index] } == [[0, 2], [1, 0]])
        #expect(AudiobookUploads.bytesOf(again) == 15)
    }

    // MARK: Retries

    @Test func backoffDoublesUpToAMinuteWithJitterAndRetryAfterWins() {
        #expect((0...7).map { AudiobookUploads.backoffMillis($0) } == [1_000, 2_000, 4_000, 8_000, 16_000, 32_000, 60_000, 60_000])
        #expect(AudiobookUploads.backoffMillis(0, jitter: 1) == 750)
        #expect(AudiobookUploads.backoffMillis(10, jitter: 1) == 45_000)
        #expect(AudiobookUploads.backoffMillis(100) == 60_000)
        #expect(AudiobookUploads.backoffMillis(0, retryAfter: "7") == 7_000)
        #expect(AudiobookUploads.backoffMillis(0, retryAfter: "9999") == 300_000)
        #expect(AudiobookUploads.backoffMillis(0, retryAfter: "Wed, 21 Oct 2015 07:28:00 GMT") == 1_000)
    }

    @Test func onlyTransientFailuresAreRetried() {
        #expect(AudiobookUploads.isRetryable(.timedOut))
        #expect(AudiobookUploads.isRetryable(.unreachable("reset")))
        #expect(AudiobookUploads.isRetryable(.rejected(HTTPFailure(status: 503))))
        #expect(AudiobookUploads.isRetryable(.rejected(HTTPFailure(status: 429))))
        #expect(!AudiobookUploads.isRetryable(.rejected(HTTPFailure(status: 422))))
        #expect(!AudiobookUploads.isRetryable(.rejected(HTTPFailure(status: 401))))
    }

    // MARK: Picked files

    @Test func audioFilesByExtensionOrTypeWithLimits() {
        #expect(AudiobookUploads.problem(Picked(name: "Dune.m4b", mime: "application/octet-stream", size: 100)) == nil)
        #expect(AudiobookUploads.problem(Picked(name: "01 Part.MP3", mime: nil, size: 100)) == nil)
        #expect(AudiobookUploads.problem(Picked(name: "track", mime: "audio/mpeg", size: 100)) == nil)
        #expect(AudiobookUploads.problem(Picked(name: "cover.jpg", mime: "audio/mpeg", size: 100)) == .notAudio)
        #expect(AudiobookUploads.problem(Picked(name: "book.wav", mime: "audio/wav", size: 100)) == .notAudio)
        #expect(AudiobookUploads.problem(Picked(name: "a.mp3", mime: nil, size: nil)) == .unknownSize)
        #expect(AudiobookUploads.problem(Picked(name: "a.mp3", mime: nil, size: 0)) == .empty)
        #expect(AudiobookUploads.problem(Picked(name: "a.mp3", mime: nil, size: 1, readable: false)) == .unreadable)
        #expect(AudiobookUploads.problem(Picked(name: "a.flac", mime: nil, size: AudiobookUploads.maxFileBytes)) == nil)
        #expect(AudiobookUploads.problem(Picked(name: "a.flac", mime: nil, size: AudiobookUploads.maxFileBytes + 1)) == .tooLarge)
        #expect(AudiobookUploads.tooMuch(count: 200, totalBytes: AudiobookUploads.maxTotalBytes) == nil)
        #expect(AudiobookUploads.tooMuch(count: 201, totalBytes: 1)?.contains("200") == true)
        #expect(AudiobookUploads.tooMuch(count: 2, totalBytes: AudiobookUploads.maxTotalBytes + 1)?.contains("6 GB") == true)
    }

    @Test func uploadNamesKeepTheExtensionAndPartsSortNaturally() {
        #expect(AudiobookUploads.uploadName("primary:Audio/Dune.m4b", index: 0, mime: nil) == "Dune.m4b")
        #expect(AudiobookUploads.uploadName("track", index: 0, mime: "audio/mpeg") == "track.mp3")
        #expect(AudiobookUploads.uploadName(nil, index: 2, mime: "audio/mp4") == "part-003.m4a")
        #expect(AudiobookUploads.uploadName("a\"b.opus", index: 0, mime: nil) == "ab.opus")
        let names = ["Part 10.mp3", "Part 2.mp3", "part 1.mp3", "Part 02b.mp3", "Intro.mp3"]
        #expect(names.sorted(by: AudiobookUploads.naturalOrder) == ["Intro.mp3", "part 1.mp3", "Part 2.mp3", "Part 02b.mp3", "Part 10.mp3"])
    }

    // MARK: The API (against a stub server)

    final class Seen: @unchecked Sendable {
        private let lock = NSLock()
        private var stored: [(URLRequest, Data?)] = []
        func add(_ request: URLRequest, _ body: Data?) { lock.withLock { stored.append((request, body)) } }
        var all: [(URLRequest, Data?)] { lock.withLock { stored } }
    }

    func json(_ data: Data?) -> [String: Any] {
        (data.flatMap { try? JSONSerialization.jsonObject(with: $0) } as? [String: Any]) ?? [:]
    }

    @Test func createSendsTheFilesBookAndNoteAndReadsTheId() async throws {
        let seen = Seen()
        let client = StubURLProtocol.client(token: "t") { request, body in
            seen.add(request, body)
            return (201, Data(#"{"upload":{"id":42,"chunk_size":8388608}}"#.utf8))
        }
        let created = try await client.createAudiobookUpload(files: [("01.mp3", 10), ("02.mp3", 20)], novelId: 7, note: " thanks ")
        #expect(created.id == 42)
        #expect(created.chunkSize == chunk)
        let (request, data) = try #require(seen.all.first)
        #expect(request.httpMethod == "POST")
        #expect(request.url?.path == "/api/v1/audiobook-uploads")
        #expect(request.value(forHTTPHeaderField: "Authorization") == "Bearer t")
        let sent = json(data)
        let files = try #require(sent["files"] as? [[String: Any]])
        #expect(files.compactMap { $0["name"] as? String } == ["01.mp3", "02.mp3"])
        #expect(files.compactMap { ($0["size"] as? NSNumber)?.int64Value } == [10, 20])
        #expect((sent["novel_id"] as? NSNumber)?.intValue == 7)
        #expect(sent["note"] as? String == "thanks")

        // No book, no note: neither is sent.
        let bareSeen = Seen()
        let bare = StubURLProtocol.client(token: "t") { request, body in
            bareSeen.add(request, body)
            return (201, Data(#"{"upload":{"id":3}}"#.utf8))
        }
        _ = try await bare.createAudiobookUpload(files: [("a.m4b", 1)], novelId: nil, note: "  ")
        let bareBody = json(bareSeen.all.first?.1)
        #expect(bareBody["novel_id"] == nil && bareBody["note"] == nil)
    }

    @Test func createWithoutAUsableIdIsAnError() async {
        for answer in [#"{"upload":{}}"#, #"{"upload":{"id":"../x"}}"#, ""] {
            let client = StubURLProtocol.client(token: "t") { _, _ in (201, Data(answer.utf8)) }
            await #expect(throws: (any Error).self) {
                _ = try await client.createAudiobookUpload(files: [("a.mp3", 1)], novelId: nil, note: nil)
            }
        }
    }

    @Test func chunksArePutWithTheirRangeAndRawBytes() async throws {
        let seen = Seen()
        let client = StubURLProtocol.client(token: "t") { request, body in
            seen.add(request, body)
            return (204, Data())
        }
        let piece = Chunk(file: 1, index: 2, offset: 16 * mb, length: 5, fileSize: 16 * mb + 5)
        try await client.putAudiobookChunk(id: 42, file: piece.file, chunk: piece.index, offset: piece.offset,
                                           fileSize: piece.fileSize, data: Data("HELLO".utf8))
        let (request, data) = try #require(seen.all.first)
        #expect(request.httpMethod == "PUT")
        #expect(request.url?.path == "/api/v1/audiobook-uploads/42/files/1/chunks/2")
        #expect(URLComponents(url: request.url!, resolvingAgainstBaseURL: false)?.queryItems?.first { $0.name == "offset" }?.value
                == String(16 * mb))
        #expect(request.value(forHTTPHeaderField: "Content-Range") == piece.contentRange)
        #expect(request.value(forHTTPHeaderField: "Content-Range") == "bytes 16777216-16777220/16777221")
        #expect(request.value(forHTTPHeaderField: "Authorization") == "Bearer t")
        #expect(data == Data("HELLO".utf8))
    }

    @Test func statusCompleteAndCancel() async throws {
        let seen = Seen()
        let client = StubURLProtocol.client(token: "t") { request, body in
            seen.add(request, body)
            switch (request.httpMethod, request.url?.path ?? "") {
            case ("GET", _): return (200, Data(#"{"upload":{"id":42,"status":"uploading","files":[{"index":0,"chunks":1,"received":[0]}]}}"#.utf8))
            case (_, let path) where path.hasSuffix("/complete"): return (200, Data(#"{"upload":{"id":42,"status":"pending"}}"#.utf8))
            case ("DELETE", _): return (404, Data(#"{"message":"Not found"}"#.utf8))
            default: return (500, Data())
            }
        }
        #expect(AudiobookUploads.present(try await client.audiobookUpload(id: 42)) == [0: [0]])
        let completed = try await client.completeAudiobookUpload(id: 42)
        #expect(AudiobookUploads.Status.fromServer(completed?.status) == .pending)
        try await client.cancelAudiobookUpload(id: 42) // 404: already gone, fine
        #expect(seen.all.map { $0.0.httpMethod } == ["GET", "POST", "DELETE"])
        #expect(seen.all[1].0.url?.path == "/api/v1/audiobook-uploads/42/complete")
    }

    @Test func aRefusedCompleteCarriesTheServersReason() async throws {
        let client = StubURLProtocol.client(token: "t") { _, _ in
            (422, Data(#"{"message":"02.mp3 isn't an audio file.","code":"not_audio","upload":{"id":42,"status":"failed"}}"#.utf8))
        }
        do {
            _ = try await client.completeAudiobookUpload(id: 42)
            Issue.record("expected 422")
        } catch let error as APIError {
            #expect(error.status == 422)
            #expect(AudiobookUploads.failureMessage(error) == "02.mp3 isn't an audio file.")
            #expect(AudiobookUploads.resendAfterComplete(status: 422, code: error.code, body: nil) == nil)
        }
        #expect(AudiobookUploads.failureMessage(.rejected(HTTPFailure(status: 404, message: "Not Found"))).contains("update Novarr"))
        #expect(AudiobookUploads.failureMessage(.rejected(HTTPFailure(status: 401))).contains("signed out"))
        let validation = Data(#"{"message":"Invalid.","errors":{"files":["Too many files."]}}"#.utf8)
        #expect(AudiobookUploads.failureMessage(.rejected(HTTPFailure(status: 422, message: "Invalid.", body: validation)))
                == "Too many files.")
    }

    // MARK: Saved state

    @Test func uploadsAreSavedChangedAndRemoved() async throws {
        let file = temporaryFile(AudiobookUploadStore.fileName)
        let store = AudiobookUploadStore(fileURL: file)
        let entry = AudiobookUploadEntry(localId: "l1", bookTitle: "Dune",
                                         files: [UploadFileRef(path: "/x/1", name: "01.mp3", size: 10),
                                                 UploadFileRef(path: "/x/2", name: "02.mp3", size: 20)])
        await store.put(entry)
        #expect(await store.get("l1")?.totalBytes == 30)
        #expect(await store.get("l1")?.state == .queued)
        await store.change("l1") { e in
            var e = e
            e.serverId = 42
            e.status = AudiobookUploads.Status.uploading.rawValue
            e.doneBytes = 10
            return e
        }
        // A fresh store on the same file (a restart) reads it back.
        let again = try #require(await AudiobookUploadStore(fileURL: file).get("l1"))
        #expect(again.serverId == 42)
        #expect(again.state == .uploading)
        #expect(again.title == "Dune")
        #expect(again.updatedAt > 0)
        #expect(await store.change("nope") { $0 } == nil)
        await store.remove("l1")
        #expect(await store.all().isEmpty)
        // An unknown status reads as failed, never crashes.
        var odd = entry
        odd.status = "???"
        #expect(odd.state == .failed)
        odd.bookTitle = nil
        #expect(odd.title == "01")
    }

    @Test func picksInTheAppsOwnStorageAreMovedIntoTheUpload() throws {
        let storage = AudioStorage(root: temporaryFile("audio").deletingLastPathComponent())
        let incoming = FileManager.default.temporaryDirectory.appending(path: "incoming-\(UUID().uuidString)/Book.m4b")
        try FileManager.default.createDirectory(at: incoming.deletingLastPathComponent(), withIntermediateDirectories: true)
        try Data(repeating: 7, count: 100).write(to: incoming)
        let picked = PickedUpload.describe(incoming, index: 0)
        #expect(picked.problem == nil)
        #expect(picked.picked.size == 100)
        let refs = try AudiobookUploadManager.keepReadable(localId: "l1", files: [picked], storage: storage)
        let path = try #require(refs.first?.path)
        #expect(FileManager.default.fileExists(atPath: path))
        #expect(!FileManager.default.fileExists(atPath: incoming.path))
        #expect(refs.first?.name == "Book.m4b")
    }

    @Test func chunksAreReadFromTheFile() async throws {
        let file = temporaryFile("data.mp3")
        try FileManager.default.createDirectory(at: file.deletingLastPathComponent(), withIntermediateDirectories: true)
        try Data((0..<25).map { UInt8($0) }).write(to: file)
        let ref = UploadFileRef(path: file.path, name: "data.mp3", size: 25)
        let plan = AudiobookUploads.plan([25], chunkSize: 10)
        let last = try await AudiobookUploadManager.readChunk(ref, plan[2])
        #expect(last == Data([20, 21, 22, 23, 24]))
        try await AudiobookUploadManager.checkFiles(AudiobookUploadEntry(localId: "x", files: [ref]))
        let changed = UploadFileRef(path: file.path, name: "data.mp3", size: 26)
        await #expect(throws: AudiobookUploadManager.SourceUnreadable.self) {
            try await AudiobookUploadManager.checkFiles(AudiobookUploadEntry(localId: "x", files: [changed]))
        }
    }
}
