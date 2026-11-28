import Foundation
import Testing
@testable import Novarr

/// 1.19: comics (medium "comic"), a page medium like manga (android
/// ComicsTest.kt; its shelf and request cases belong to the Library and
/// Requests suites).
@Suite("Comics")
struct ComicsTests {
    private func summary(_ id: Int, medium: String) throws -> NovelSummary {
        try Fixture.decode(NovelSummary.self, json: #"{"id": \#(id), "name": "N\#(id)", "medium": "\#(medium)"}"#)
    }

    // MARK: The medium

    @Test func comicIsAPageMediumLikeManga() throws {
        let comic = try summary(1, medium: "comic")
        #expect(comic.isComic)
        #expect(comic.isPaged)
        #expect(!comic.isManga)
        #expect(!comic.isBook)
        #expect(try summary(2, medium: "manga").isPaged)
        #expect(try summary(3, medium: "Comic").isPaged)
        #expect(try !summary(4, medium: "novel").isPaged)
        #expect(try !summary(5, medium: "book").isPaged)
        #expect(try !summary(6, medium: "").isPaged)
        #expect(Medium.isPaged("comic"))
        #expect(!Medium.isPaged(nil))
    }

    @Test func mediumLabels() {
        #expect(Medium.label("comic") == "Comic")
        #expect(Medium.label("manga") == "Manga")
        #expect(Medium.label("book") == "Book")
        #expect(Medium.label("novel") == "Web novel")
        #expect(Medium.label(nil) == "Web novel")
    }

    @Test func comicsParseFromTheLibraryListing() throws {
        let novels = try Fixture.decode(NovelsEnvelope.self, json: """
        {"novels":[{"id":7,"name":"Saga","medium":"comic","origin_country":"EN","chapters_total":54},{"id":8,"name":"Berserk","medium":"manga"}]}
        """).novels
        #expect(novels[0].medium == "comic")
        #expect(novels[0].isComic)
        #expect(novels[0].chaptersTotal == 54)
        #expect(novels[1].isManga)
    }

    @Test func metaSaysWhetherTheServerKnowsComics() throws {
        #expect(try Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1,"comics":true}"#).comics)
        #expect(try !Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1}"#).comics)
    }

    // MARK: Where a chapter opens and how it downloads

    @MainActor
    @Test func pageMediumsOpenInThePageReaderAndBooksInTheTextReader() throws {
        for (medium, paged) in [("comic", true), ("manga", true), ("book", false), ("novel", false)] {
            let model = NovelModel(route: NovelRoute(id: 1, summary: try summary(1, medium: medium)))
            #expect(model.readerRoute(chapterId: 5).manga == paged, "\(medium)")
            #expect(model.isPaged == paged, "\(medium)")
            #expect(model.isBook == (medium == "book"), "\(medium)")
        }
    }

    // MARK: The reader's direction

    private func pages(_ mode: String?) throws -> MangaPages {
        let extra = mode.map { #","reading_mode":"\#($0)""# } ?? ""
        return try Fixture.decode(MangaPages.self, json: """
        {"chapter":{"id":1,"novel_id":7,"label":"Issue 1","number":1,"page_count":1,"read":false,"progress":null},
         "pages":[{"page":1,"url":"https://example.test/u1","width":800,"height":1200}],"prev":null,"next":null\(extra)}
        """)
    }

    @Test func comicsReadLeftToRightUnlessTheServerSaysOtherwise() throws {
        // No reading_mode (or "ltr"): left to right.
        #expect(MangaReadingMode(raw: try pages(nil).readingMode) == .leftToRight)
        #expect(MangaReadingMode(raw: try pages("ltr").readingMode) == .leftToRight)
        // A comic file that says it is manga, or the reader's choice on the web: right to left.
        #expect(MangaReadingMode(raw: try pages("rtl").readingMode) == .rightToLeft)
        // This device's Pages / Scroll choice applies to comics as to manga.
        #expect(MangaModeOverride.scroll.resolve(.leftToRight) == .vertical)
        #expect(MangaModeOverride.pages.resolve(.rightToLeft) == .rightToLeft)
    }

    @Test func theTextEndpointsRefusalOpensThePageReader() async {
        func client(_ body: String, status: Int = 422) -> APIClient {
            StubURLProtocol.client { _, _ in (status, Data(body.utf8)) }
        }
        await #expect(throws: APIError.mangaChapter) {
            _ = try await client(#"{"message":"This chapter is page images.","medium":"comic","pages":true}"#).chapter(id: 1)
        }
        await #expect(throws: APIError.mangaChapter) {
            _ = try await client(#"{"message":"This chapter is page images.","medium":"manga","pages":true}"#).chapter(id: 1)
        }
        // An older server's manga refusal had no `pages`.
        await #expect(throws: APIError.mangaChapter) {
            _ = try await client(#"{"medium":"manga"}"#).chapter(id: 1)
        }
        await #expect(throws: APIError.http(status: 422, message: "nope")) {
            _ = try await client(#"{"message":"nope"}"#).chapter(id: 1)
        }
        await #expect(throws: APIError.http(status: 404, message: nil)) {
            _ = try await client(#"{"medium":"comic","pages":true}"#, status: 404).chapter(id: 1)
        }
    }
}

/// The parts of the shared resume rule iOS lacked (android ResumeRuleTest).
@Suite("Resume rule: started")
struct ResumeRuleStartedTests {
    private func list(_ n: Int, read: Int = 0, progress: [Int: Int] = [:]) -> [ChapterListItem] {
        (1...n).map { i in
            ChapterListItem(id: 100 + i, chapter: Double(i), book: 0, label: "Chapter \(i)", downloaded: true,
                            read: i <= read, readProgress: progress[i] ?? (i <= read ? 100 : nil), note: false,
                            state: nil, reason: nil, downloadedAt: nil, wordCount: nil)
        }
    }

    private func write(_ kind: PendingWrite.Kind, at seconds: TimeInterval) -> PendingWrite {
        PendingWrite(id: UUID(), kind: kind, recordedAt: Date(timeIntervalSince1970: 1_800_000_000 + seconds), novelId: 1)
    }

    @Test func highlightsAreNotReading() throws {
        let draft = try #require(HighlightDraft(chapterId: 109, excerpt: "a passage", note: nil))
        let pending = [write(.highlight(draft), at: 9)]
        #expect(ResumeRule.resolve(chapters: list(10, read: 4), serverId: 105, pending: pending) == 105)
    }

    @Test func startedCountsPartReadAndUnsentReading() {
        #expect(!ResumeRule.hasStarted(chapters: list(10), serverReadCount: 0, pending: []))
        #expect(ResumeRule.hasStarted(chapters: list(10), serverReadCount: 2, pending: []))
        // Part-read on the server (unread, 40 %): the server counts no chapter read.
        #expect(ResumeRule.hasStarted(chapters: list(10, progress: [1: 40]), serverReadCount: 0, pending: []))
        // Read in this app, not sent yet.
        #expect(ResumeRule.hasStarted(chapters: list(10), serverReadCount: 0,
                                      pending: [write(.progress(chapterId: 101, progress: 30, read: false), at: 1)]))
        #expect(!ResumeRule.hasStarted(chapters: list(10), serverReadCount: 0,
                                       pending: [write(.progress(chapterId: 101, progress: 0, read: false), at: 1)]))
    }
}
