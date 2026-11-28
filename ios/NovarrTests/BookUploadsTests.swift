import Foundation
import Testing
@testable import Novarr

/// Port of android BookUploadsTest.kt: "Send ePubs to Novarr".
@Suite("Book uploads (Send ePubs)")
struct BookUploadsTests {
    private let limits = BookUploads.Limits(maxMb: 50, maxFiles: 20)

    @Test func epubsAreKnownByNameOrByTypeWhenTheNameHasNoExtension() {
        #expect(BookUploads.isEpub(name: "Dune.epub", mime: nil))
        #expect(BookUploads.isEpub(name: "DUNE.EPUB", mime: "application/octet-stream"))
        #expect(BookUploads.isEpub(name: "document", mime: "application/epub+zip"))
        #expect(BookUploads.isEpub(name: nil, mime: "application/epub+zip"))
        #expect(!BookUploads.isEpub(name: "notes.pdf", mime: "application/epub+zip"))
        #expect(!BookUploads.isEpub(name: "archive.zip", mime: "application/octet-stream"))
        #expect(!BookUploads.isEpub(name: "document", mime: "application/octet-stream"))
        #expect(!BookUploads.isEpub(name: nil, mime: nil))
    }

    @Test func problemsComeInOrderUnreadableTypeEmptySize() {
        typealias P = BookUploads.Picked
        #expect(BookUploads.problem(P(name: "a.epub", mime: nil, size: 1_000), limits: limits) == nil)
        #expect(BookUploads.problem(P(name: "a.epub", mime: nil, size: nil), limits: limits) == nil)
        #expect(BookUploads.problem(P(name: "a.pdf", mime: nil, size: 1, readable: false), limits: limits) == .unreadable)
        #expect(BookUploads.problem(P(name: "a.pdf", mime: nil, size: 1), limits: limits) == .notEpub)
        #expect(BookUploads.problem(P(name: "a.epub", mime: nil, size: 0), limits: limits) == .empty)
        #expect(BookUploads.problem(P(name: "a.epub", mime: nil, size: 50 * 1024 * 1024), limits: limits) == nil)
        #expect(BookUploads.problem(P(name: "a.epub", mime: nil, size: 50 * 1024 * 1024 + 1), limits: limits) == .tooLarge)
        #expect(BookUploads.message(.notEpub, limits: limits) == "Only ePub files (.epub) can be added.")
        #expect(BookUploads.message(.tooLarge, limits: limits) == "Too large: ePubs can be up to 50 MB.")
    }

    @Test func countIsCheckedAgainstTheLimit() {
        #expect(BookUploads.tooMany(20, limits: limits) == nil)
        #expect(BookUploads.tooMany(21, limits: limits)?.contains("up to 20") == true)
        #expect(BookUploads.tooMany(3, limits: .init(maxMb: 50, maxFiles: 3)) == nil)
        #expect(BookUploads.tooMany(4, limits: .init(maxMb: 50, maxFiles: 3)) != nil)
    }

    @Test func uploadNamesLoseFoldersAndAlwaysEndInEpub() {
        #expect(BookUploads.uploadName("Dune.epub", index: 0) == "Dune.epub")
        #expect(BookUploads.uploadName("primary:Download/Dune.epub", index: 0) == "Dune.epub")
        #expect(BookUploads.uploadName("C:\\Books\\Dune.epub", index: 0) == "Dune.epub")
        #expect(BookUploads.uploadName("document", index: 0) == "document.epub")
        #expect(BookUploads.uploadName(nil, index: 2) == "book-3.epub")
        #expect(BookUploads.uploadName("  ", index: 0) == "book-1.epub")
        #expect(BookUploads.uploadName("a\"b.epub", index: 0) == "ab.epub")
        #expect(BookUploads.uploadName("Ünïcødé.EPUB", index: 0) == "Ünïcødé.EPUB")
    }

    @Test func noteIsClampedTo500() {
        #expect(BookUploads.clampNote(String(repeating: "x", count: 600)).count == 500)
        #expect(BookUploads.clampNote("short") == "short")
    }

    @Test func limitsComeFromMetaWithDefaults() throws {
        let meta = try Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1,"book_uploads":{"max_mb":10,"max_files":5,"email":"books@example.test"}}"#)
        #expect(BookUploads.Limits.from(meta.bookUploads) == .init(maxMb: 10, maxFiles: 5))
        #expect(meta.bookUploads?.email == "books@example.test")
        let old = try Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1}"#)
        #expect(old.bookUploads == nil)
        #expect(BookUploads.Limits.from(old.bookUploads) == .init(maxMb: 50, maxFiles: 20))
        let noEmail = try Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1,"book_uploads":{"max_mb":0,"email":null}}"#)
        #expect(noEmail.bookUploads?.email == nil)
        #expect(BookUploads.Limits.from(noEmail.bookUploads) == .init(maxMb: 50, maxFiles: 20))
        let malformed = try Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1,"book_uploads":"yes"}"#)
        #expect(malformed.bookUploads == nil)
    }

    @Test func multipartIsOnePartPerFileWithTheNote() throws {
        let folder = FileManager.default.temporaryDirectory.appending(path: "novarr-upload-\(UUID().uuidString)", directoryHint: .isDirectory)
        try FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: folder) }
        let dune = folder.appending(path: "Dune.epub")
        let emma = folder.appending(path: "Emma.epub")
        try Data("DUNE-BYTES".utf8).write(to: dune)
        try Data("EMMA-BYTES".utf8).write(to: emma)
        let out = folder.appending(path: "body")
        try APIClient.writeMultipart([.init(name: "Dune.epub", fileURL: dune), .init(name: "Emma.epub", fileURL: emma)],
                                     note: "for the shelf", boundary: "B", to: out)
        let body = try String(contentsOf: out, encoding: .utf8)
        #expect(body.components(separatedBy: #"name="epub[]"; filename="#).count == 3)
        #expect(body.contains(#"filename="Dune.epub""#))
        #expect(body.contains("Content-Type: application/epub+zip"))
        #expect(body.contains("DUNE-BYTES") && body.contains("EMMA-BYTES"))
        #expect(body.contains(#"name="note""#) && body.contains("for the shelf"))
        #expect(body.hasSuffix("--B--\r\n"))

        try APIClient.writeMultipart([.init(name: "a.epub", fileURL: dune)], note: "  ", boundary: "B", to: out)
        #expect(!(try String(contentsOf: out, encoding: .utf8)).contains(#"name="note""#))
    }

    private func rejected(_ status: Int, _ body: String) -> APIError {
        .rejected(APIClient.failure(status: status, data: Data(body.utf8), response: nil))
    }

    @Test func validation422ShowsItsMessage() {
        #expect(BookUploads.failureMessage(rejected(422, #"{"message":"The epub.0 field must be a file of type: epub.","errors":{"epub.0":["The epub.0 field must be a file of type: epub."]}}"#))
                == "The epub.0 field must be a file of type: epub.")
        #expect(BookUploads.failureMessage(rejected(422, #"{"errors":{"epub":["Too many files."]}}"#)) == "Too many files.")
    }

    @Test func notFoundIsRequestsOffOrAnOldServer() {
        #expect(BookUploads.failureMessage(rejected(404, #"{"message":"Requests are switched off.","code":"requests_disabled"}"#)) == "Requests are switched off.")
        #expect(BookUploads.failureMessage(rejected(404, #"{"code":"requests_disabled"}"#)).hasPrefix("Requests are switched off"))
        #expect(BookUploads.failureMessage(rejected(404, #"{"message":"Not Found"}"#)) == "This server is too old to accept books from the app — update Novarr.")
        #expect(BookUploads.failureMessage(rejected(404, "<html>Not found</html>")) == "This server is too old to accept books from the app — update Novarr.")
    }

    @Test func signedOutAndTooLargeHaveTheirOwnSentences() {
        let signedOut = rejected(401, #"{"message":"Unauthenticated."}"#)
        #expect(signedOut.isUnauthenticated)
        #expect(BookUploads.failureMessage(signedOut).contains("Sign in"))
        #expect(BookUploads.failureMessage(rejected(413, "<html>413</html>")).contains("too large"))
        #expect(BookUploads.failureMessage(APIError.timedOut) == APIError.timedOut.errorDescription)
    }

    @Test func noneTaken422StillAnswersPerFile() throws {
        let answer = try Fixture.decode(BookUploadResponse.self, json: #"{"message":"No books were sent.","sent":[],"refused":[{"file":"x.epub","message":"This book is already in the library: Dune.","code":"in_library"},{"file":"y.epub","message":"Can't read it.","code":"unreadable"}]}"#)
        #expect(answer.sent.isEmpty)
        #expect(answer.refused.map(\.code) == ["in_library", "unreadable"])
        #expect(answer.refused.first?.message == "This book is already in the library: Dune.")
    }
}
