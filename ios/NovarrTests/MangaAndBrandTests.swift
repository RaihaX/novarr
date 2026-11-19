import Foundation
import Testing
@testable import Novarr

@Suite("Manga: decoding and the medium")
struct MangaDecodingTests {
    @Test func mediumDecodesAndDefaultsToNovel() throws {
        let novels = try Fixture.decode(NovelsEnvelope.self, json: """
        {"novels": [
          {"id": 1, "name": "Text", "medium": "novel"},
          {"id": 2, "name": "Pages", "medium": "manga"},
          {"id": 3, "name": "Old server"},
          {"id": 4, "name": "Shouting", "medium": "MANGA"},
          {"id": 5, "name": "Empty", "medium": ""}
        ]}
        """).novels
        #expect(novels.map(\.isManga) == [false, true, false, true, false])
        #expect(novels[2].medium == "novel")
        #expect(novels[4].medium == "novel")
    }

    @Test func mediumSurvivesTheOfflineCache() throws {
        let novel = try Fixture.decode(NovelSummary.self, json: #"{"id": 9, "name": "M", "medium": "manga"}"#)
        let data = try JSONCoding.encoder().encode(novel)
        let back = try JSONCoding.decoder().decode(NovelSummary.self, from: data)
        #expect(back.isManga)
        #expect(back == novel)
    }

    @Test func mangaPagesDecode() throws {
        let pages = try Fixture.decode(MangaPages.self, json: """
        {"chapter": {"id": 501, "novel_id": 7, "label": "", "number": 12.5, "page_count": 3,
                     "read": false, "progress": 40},
         "pages": [
           {"page": 1, "url": "http://h/chapters/501/pages/1?signature=a", "width": 800, "height": 1200},
           {"page": 2, "url": "http://h/chapters/501/pages/2", "width": null, "height": null},
           {"page": 3},
           {"url": "http://h/no-number"}
         ],
         "prev": {"id": 500, "label": "Chapter 12"},
         "next": {"id": 502, "label": "Chapter 13", "downloaded": false},
         "reading_mode": "rtl"}
        """)
        #expect(pages.chapter.id == 501)
        #expect(pages.chapter.novelId == 7)
        #expect(pages.chapter.label == "Chapter 12.5") // empty label falls back to the number
        #expect(pages.chapter.pageCount == 3)
        #expect(pages.chapter.progress == 40)
        // A page without a number is skipped; one without a URL can't be shown.
        #expect(pages.pages.map(\.page) == [1, 2])
        #expect(pages.pages[0].url?.query == "signature=a")
        #expect(pages.pages[0].aspect == 1.5)
        #expect(pages.pages[1].aspect == nil)
        #expect(pages.prev?.id == 500)
        #expect(pages.next?.downloaded == false)
        #expect(pages.readingMode == "rtl")
    }

    @Test func mangaPagesDefaults() throws {
        let pages = try Fixture.decode(MangaPages.self, json: #"{"chapter": {"id": 1}}"#)
        #expect(pages.pages.isEmpty)
        #expect(pages.readingMode == "ltr")
        #expect(pages.chapter.read == false)
        #expect(pages.chapter.label == "Chapter")
    }

    @Test func listsAskForAllMedia() async throws {
        let seen = Locked<[String]>([])
        let client = StubURLProtocol.client { request, _ in
            seen.mutate { $0.append(request.url?.absoluteString ?? "") }
            let body = request.url?.path.hasSuffix("home") == true ? #"{"continue": [], "new_chapters": []}"# : #"{"novels": []}"#
            return (200, Data(body.utf8))
        }
        _ = try await client.novels()
        _ = try await client.home()
        #expect(seen.value.count == 2)
        #expect(seen.value.allSatisfy { $0.hasSuffix("?medium=all") })
    }

    @Test func textEndpointRefusingAMangaChapterIsTyped() async {
        let client = StubURLProtocol.client { _, _ in
            (422, Data(#"{"message": "This chapter belongs to a manga: …", "medium": "manga"}"#.utf8))
        }
        await #expect(throws: APIError.mangaChapter) { _ = try await client.chapter(id: 1) }
        #expect(APIError.mangaChapter.localizedDescription == "This is a manga chapter.")
        #expect(!APIError.mangaChapter.isRetryable)
    }

    @Test func otherValidationErrorsStayHTTP() async {
        let client = StubURLProtocol.client { _, _ in (422, Data(#"{"message": "Nope"}"#.utf8)) }
        await #expect(throws: APIError.http(status: 422, message: "Nope")) { _ = try await client.chapter(id: 1) }
    }

    @Test func pagesEndpoint() async throws {
        let client = StubURLProtocol.client { request, _ in
            #expect(request.url?.path == "/api/v1/chapters/42/pages")
            return (200, Data(#"{"chapter": {"id": 42}, "pages": [{"page": 1, "url": "http://h/p/1"}], "reading_mode": "vertical"}"#.utf8))
        }
        let pages = try await client.mangaPages(id: 42)
        #expect(pages.pages.count == 1)
        #expect(MangaReadingMode(raw: pages.readingMode) == .vertical)
    }
}

@Suite("Manga: reading direction, tap zones and progress")
struct MangaReadingTests {
    @Test func seriesModeFromServer() {
        #expect(MangaReadingMode(raw: "rtl") == .rightToLeft)
        #expect(MangaReadingMode(raw: " RTL ") == .rightToLeft)
        #expect(MangaReadingMode(raw: "vertical") == .vertical)
        #expect(MangaReadingMode(raw: "ltr") == .leftToRight)
        #expect(MangaReadingMode(raw: "webtoon") == .leftToRight) // unknown: the server's fallback
        #expect(MangaReadingMode(raw: nil) == .leftToRight)
        #expect(MangaReadingMode.vertical.isPaged == false)
        #expect(MangaReadingMode.rightToLeft.isRightToLeft)
    }

    @Test func overrideResolves() {
        #expect(MangaModeOverride.follow.resolve(.rightToLeft) == .rightToLeft)
        #expect(MangaModeOverride.follow.resolve(.vertical) == .vertical)
        #expect(MangaModeOverride.scroll.resolve(.rightToLeft) == .vertical)
        // Pages keeps the series' direction; a vertical series pages left to right.
        #expect(MangaModeOverride.pages.resolve(.rightToLeft) == .rightToLeft)
        #expect(MangaModeOverride.pages.resolve(.vertical) == .leftToRight)
    }

    @Test func overrideIsPerServerAndSeries() throws {
        let defaults = try #require(UserDefaults(suiteName: "manga-\(UUID().uuidString)"))
        let a = URL(string: "http://a.test:8080")
        let b = URL(string: "http://b.test")
        #expect(MangaModeOverride.stored(defaults: defaults, server: a, novelId: 1) == .follow)
        MangaModeOverride.scroll.store(defaults: defaults, server: a, novelId: 1)
        #expect(MangaModeOverride.stored(defaults: defaults, server: a, novelId: 1) == .scroll)
        #expect(MangaModeOverride.stored(defaults: defaults, server: a, novelId: 2) == .follow)
        #expect(MangaModeOverride.stored(defaults: defaults, server: b, novelId: 1) == .follow)
        // "Follow series" forgets the choice.
        MangaModeOverride.follow.store(defaults: defaults, server: a, novelId: 1)
        #expect((defaults.dictionary(forKey: MangaModeOverride.defaultsKey) ?? [:]).isEmpty)
    }

    @Test func tapZonesFollowReadingDirection() {
        // Left to right: the right third goes forward (the text reader's Pages mode).
        #expect(TapZones.action(x: 10, width: 300) == .previous)
        #expect(TapZones.action(x: 150, width: 300) == .toggleChrome)
        #expect(TapZones.action(x: 290, width: 300) == .next)
        // Right to left mirrors the sides; the middle still toggles the chrome.
        #expect(TapZones.action(x: 10, width: 300, rightToLeft: true) == .next)
        #expect(TapZones.action(x: 150, width: 300, rightToLeft: true) == .toggleChrome)
        #expect(TapZones.action(x: 290, width: 300, rightToLeft: true) == .previous)
        #expect(TapZones.action(x: 10, width: 0, rightToLeft: true) == .toggleChrome)
        // Boundaries: exactly a third in is the middle; the last third starts at width - third.
        #expect(TapZones.action(x: 100, width: 300, rightToLeft: true) == .toggleChrome)
        #expect(TapZones.action(x: 200, width: 300, rightToLeft: true) == .previous)
    }

    @Test func swipesFollowReadingDirection() {
        #expect(TapZones.swipe(dx: -60, threshold: 48) == .next)
        #expect(TapZones.swipe(dx: 60, threshold: 48) == .previous)
        #expect(TapZones.swipe(dx: 60, threshold: 48, rightToLeft: true) == .next)
        #expect(TapZones.swipe(dx: -60, threshold: 48, rightToLeft: true) == .previous)
        #expect(TapZones.swipe(dx: 20, threshold: 48, rightToLeft: true) == nil)
    }

    @Test func progressByPage() {
        #expect(MangaProgress.progress(page: 0, count: 10) == 0)
        #expect(MangaProgress.progress(page: 3, count: 10) == 33)
        // The last page is the read mark (100), never a 0–99 position.
        #expect(MangaProgress.progress(page: 9, count: 10) == 99)
        #expect(MangaProgress.progress(page: 0, count: 1) == 0)
        #expect(MangaProgress.page(progress: 33, count: 10) == 3)
        #expect(MangaProgress.page(progress: 100, count: 10) == 9)
        #expect(MangaProgress.page(progress: 0, count: 10) == nil)
        // Round trip for every page.
        for index in 0..<9 {
            #expect(MangaProgress.page(progress: MangaProgress.progress(page: index, count: 12), count: 12) ?? 0 == index)
        }
    }

    @Test func shelves() {
        var manga = makeNovel(id: 2, name: "Pages")
        manga.medium = Medium.manga
        let novels = [makeNovel(id: 1, name: "Text"), manga]
        #expect(LibraryFilter.shelf(novels, .novels).map(\.id) == [1])
        #expect(LibraryFilter.shelf(novels, .manga).map(\.id) == [2])
    }
}

@Suite("Manga offline and look-up writes")
struct MangaOfflineTests {
    private func store() -> OfflineStore {
        OfflineStore(root: temporaryFile("store").deletingLastPathComponent())
    }

    private func pages(_ chapterId: Int, count: Int = 2) throws -> MangaPages {
        try Fixture.decode(MangaPages.self, json: """
        {"chapter": {"id": \(chapterId), "novel_id": 7, "label": "Chapter \(chapterId)"},
         "pages": [\((1...count).map { #"{"page": \#($0), "url": "http://h/pages/\#($0)?signature=s"}"# }.joined(separator: ","))],
         "reading_mode": "rtl"}
        """)
    }

    @Test func mangaChaptersCountOnceComplete() async throws {
        let store = store()
        // Images saved but no index yet: not a downloaded chapter.
        let partial = store.pageFile(novelId: 7, chapterId: 11, page: 1)
        try FileManager.default.createDirectory(at: partial.deletingLastPathComponent(), withIntermediateDirectories: true)
        try Data([1, 2, 3]).write(to: partial)
        #expect(await store.cachedChapterIDs(novelId: 7).isEmpty)
        #expect(await !store.hasChapter(id: 11, novelId: 7))

        await store.saveMangaIndex(try pages(11), novelId: 7)
        #expect(await store.cachedChapterIDs(novelId: 7) == [11])
        #expect(await store.hasChapter(id: 11, novelId: 7))
        let downloads = await store.downloads()
        #expect(downloads.map(\.chapterCount) == [1])
        #expect(downloads.first?.name == "Series 7")
    }

    @Test func readStateUpdatesTheMangaIndex() async throws {
        let store = store()
        await store.saveMangaIndex(try pages(12), novelId: 7)
        await store.updateChapterState(id: 12, novelId: 7, progress: 100, read: true)
        let saved = try #require(await store.mangaChapter(id: 12, novelId: 7))
        #expect(saved.chapter.read)
        #expect(saved.chapter.progress == 100)
        #expect(saved.readingMode == "rtl")
    }

    @Test func saveMangaFetchesEveryPageWithoutHeaders() async throws {
        let store = store()
        let requests = Locked<[URLRequest]>([])
        let (base, session) = StubURLProtocol.register { request, _ in
            requests.mutate { $0.append(request) }
            return (200, Data("image \(request.url?.lastPathComponent ?? "")".utf8))
        }
        // Pre-signed URLs on the stub host.
        var stubbed = try pages(13, count: 3)
        stubbed.pages = stubbed.pages.map { page in
            var page = page
            page.url = base.appending(path: "pages/\(page.page)").appending(queryItems: [URLQueryItem(name: "signature", value: "s")])
            return page
        }
        let ok = await DownloadManager.saveManga(stubbed, novelId: 7, store: store, session: session)
        #expect(ok)
        #expect(requests.value.count == 3)
        #expect(requests.value.allSatisfy { $0.value(forHTTPHeaderField: "Authorization") == nil })
        let first = try Data(contentsOf: store.pageFile(novelId: 7, chapterId: 13, page: 1))
        #expect(String(decoding: first, as: UTF8.self) == "image 1")
        #expect(await store.cachedChapterIDs(novelId: 7) == [13])
    }

    @Test func saveMangaWithoutPagesFails() async throws {
        let store = store()
        let empty = try Fixture.decode(MangaPages.self, json: #"{"chapter": {"id": 14}}"#)
        #expect(await !DownloadManager.saveManga(empty, novelId: 7, store: store, session: .shared))
        #expect(await store.cachedChapterIDs(novelId: 7).isEmpty)
    }

    @Test func lookupReadMarkIsFlaggedAndDoesNotMovePosition() async throws {
        let kind = PendingWrite.Kind.lookupRead(chapterId: 9)
        #expect(!kind.movesPosition)
        let write = PendingWrite(id: UUID(), kind: kind, recordedAt: Date())
        #expect(PendingWrite.containsChapterWrite([write], chapterId: 9))
        // Persisted queues round-trip it.
        let data = try JSONEncoder().encode([write])
        #expect(try JSONDecoder().decode([PendingWrite].self, from: data).first?.kind == kind)

        let body = Locked<[String: Any]>([:])
        let client = StubURLProtocol.client { request, data in
            #expect(request.url?.path == "/api/v1/chapters/9/progress")
            body.mutate { $0 = (try? JSONSerialization.jsonObject(with: data ?? Data())) as? [String: Any] ?? [:] }
            return (200, Data("{}".utf8))
        }
        try await client.send(kind, age: 5)
        #expect(body.value["lookup"] as? Bool == true)
        #expect(body.value["read"] as? Bool == true)
        #expect(body.value["progress"] as? Int == 100)
        #expect(body.value["age"] as? Int == 5)
    }

    @Test func ordinaryReadMarkHasNoLookupKey() async throws {
        let body = Locked<[String: Any]>([:])
        let client = StubURLProtocol.client { _, data in
            body.mutate { $0 = (try? JSONSerialization.jsonObject(with: data ?? Data())) as? [String: Any] ?? [:] }
            return (200, Data("{}".utf8))
        }
        try await client.send(.progress(chapterId: 9, progress: 100, read: true), age: 0)
        #expect(body.value["lookup"] == nil)
        #expect(body.value["read"] as? Bool == true)
    }
}

@Suite("Failure titles")
struct FailureTitleTests {
    @Test func titleFollowsTheErrorKind() {
        #expect(APIError.unreachable("x").failureTitle == "Can't reach your server")
        #expect(APIError.timedOut.failureTitle == "Can't reach your server")
        #expect(APIError.http(status: 401, message: nil).failureTitle == "You're signed out")
        #expect(APIError.http(status: 500, message: nil).failureTitle == "Server error")
        #expect(APIError.http(status: 404, message: nil).failureTitle == "Server error")
        #expect(APIError.decoding("x").failureTitle == "Unexpected response")
        #expect(APIError.notNovarr.failureTitle == "Unexpected response")
    }
}

/// A value shared with a `@Sendable` stub handler.
final class Locked<Value>: @unchecked Sendable {
    private let lock = NSLock()
    private var _value: Value
    init(_ value: Value) { _value = value }
    var value: Value { lock.withLock { _value } }
    func mutate(_ change: (inout Value) -> Void) { lock.withLock { change(&_value) } }
}
