import Foundation
import Testing
@testable import Novarr

@Suite("Decoding the API fixtures")
struct DecodingTests {
    @Test func meta() throws {
        let meta = try Fixture.decode(ServerMeta.self, "meta")
        #expect(meta.app == "novarr")
        #expect(meta.apiVersion == 1)
        #expect(meta.serverVersion?.isEmpty == false)
    }

    @Test func novels() throws {
        let novels = try Fixture.decode(NovelsEnvelope.self, "novels").novels
        #expect(novels.count == 5)
        let first = try #require(novels.first)
        #expect(first.id == 2)
        #expect(first.name == "Ascending the Nine Heavens")
        #expect(first.state == .failed)
        #expect(first.tags.map(\.name) == ["Cultivation", "Xianxia"])
        #expect(first.chaptersTotal == 323)
        #expect(first.resumeChapterId == 21)
        #expect(first.coverUrl?.absoluteString.hasSuffix("ascending.jpg") == true)
        #expect(first.lastReadAt == JSONCoding.parseDate("2026-11-14T06:19:59Z"))
        // A novel without a cover decodes to nil URLs.
        let noCover = try #require(novels.first { $0.id == 6 })
        #expect(noCover.coverUrl == nil)
        #expect(noCover.state == .completed)
    }

    @Test func home() throws {
        let home = try Fixture.decode(HomeFeed.self, "home")
        #expect(home.continueReading.count == 1)
        #expect(home.continueReading.first?.chapter.id == 16)
        #expect(home.newChapters.count == 1)
        #expect(home.stats?.novels == 5)
    }

    @Test func novelDetail() throws {
        let detail = try Fixture.decode(NovelEnvelope.self, "novel").novel
        #expect(detail.summary.id == 2)
        #expect(detail.description?.contains("Nine Heavens") == true)
        #expect(detail.sourceUrl?.host == "novelping.com")
        #expect(detail.altNames.isEmpty) // key omitted in the fixture
        #expect(detail.sourceUnavailable?.count == 1)
    }

    @Test func chapterList() throws {
        let chapters = try Fixture.decode(ChaptersEnvelope.self, "chapters").chapters
        #expect(chapters.count == 22)
        #expect(chapters.first?.readProgress == 20)
        #expect(chapters.first?.wordCount == 930)
        #expect(chapters.first?.downloadedAt != nil)
        let last = try #require(chapters.last)
        #expect(last.downloaded == false)
        #expect(last.reason?.contains("404") == true)
        #expect(last.wordCount == nil)
    }

    @Test func chapterContent() throws {
        let chapter = try Fixture.decode(ChapterEnvelope.self, "chapter").chapter
        #expect(chapter.id == 18)
        #expect(chapter.novelId == 2)
        #expect(chapter.readProgress == 40)
        #expect(chapter.paragraphs.count == 30)
        #expect(chapter.prev?.id == 17)
        #expect(chapter.next?.downloaded == true)
    }

    @Test func unknownStateAndMissingOptionalKeys() throws {
        let json = """
        {"novels":[{"id":9,"name":"X","state":"archived","some_future_key":{"a":1},
                    "chapters_total":"12","finished":1}]}
        """
        let novel = try #require(try Fixture.decode(NovelsEnvelope.self, json: json).novels.first)
        #expect(novel.state == .other("archived"))
        #expect(novel.state.label == "Archived")
        #expect(novel.chaptersTotal == 12)
        #expect(novel.finished)
        #expect(novel.tags.isEmpty)
        #expect(novel.author == nil)
        #expect(novel.lastReadAt == nil)
    }

    @Test func malformedRowsAreSkippedNotFatal() throws {
        let json = """
        {"chapters":[{"id":1,"label":"One","downloaded":true,"read":false},
                     {"label":"no id"},
                     {"id":3,"chapter":3,"downloaded":false,"read":false,"downloaded_at":"not a date"}]}
        """
        let chapters = try Fixture.decode(ChaptersEnvelope.self, json: json).chapters
        #expect(chapters.map(\.id) == [1, 3])
        #expect(chapters[1].label == "Chapter 3") // fallback label
        #expect(chapters[1].downloadedAt == nil)
    }

    @Test func missingTopLevelKeyIsAnError() {
        #expect(throws: (any Error).self) {
            try Fixture.decode(NovelsEnvelope.self, json: #"{"message":"Server Error"}"#)
        }
    }

    @Test func fractionalSecondsAreTolerated() {
        #expect(JSONCoding.parseDate("2026-11-15T03:20:11Z") != nil)
        #expect(JSONCoding.parseDate("2026-11-15T03:20:11.123Z") != nil)
        #expect(JSONCoding.parseDate("yesterday") == nil)
    }

    @Test func cacheRoundTrip() throws {
        let novels = try Fixture.decode(NovelsEnvelope.self, "novels").novels
        let detail = try Fixture.decode(NovelEnvelope.self, "novel").novel
        let home = try Fixture.decode(HomeFeed.self, "home")
        let chapter = try Fixture.decode(ChapterEnvelope.self, "chapter").chapter
        let chapters = try Fixture.decode(ChaptersEnvelope.self, "chapters").chapters

        func roundTrip<T: Codable & Equatable>(_ value: T) throws -> Bool {
            let data = try JSONCoding.encoder().encode(value)
            return try JSONCoding.decoder().decode(T.self, from: data) == value
        }
        #expect(try roundTrip(novels))
        #expect(try roundTrip(detail))
        #expect(try roundTrip(home))
        #expect(try roundTrip(chapter))
        #expect(try roundTrip(chapters))
    }
}
