import Foundation
import Testing
@testable import Novarr

@Suite("Decoding the feature endpoints (categories, requests, audiobooks, stats)")
struct FeatureDecodingTests {
    @Test func metaFeatureFlags() throws {
        let meta = try Fixture.decode(ServerMeta.self, "meta-features")
        #expect(meta.requests)
        #expect(meta.lists)
        #expect(meta.audiobooks)
        #expect(meta.favourites)
        #expect(meta.comics)
        #expect(meta.bookUploads?.maxMb == 50)
        #expect(meta.bookUploads?.maxFiles == 20)
        #expect(meta.bookUploads?.email == nil)
        // An older meta has none of them.
        let old = try Fixture.decode(ServerMeta.self, "meta")
        #expect(!old.requests && !old.lists && !old.audiobooks && !old.comics)
        #expect(old.bookUploads == nil)
    }

    @Test func listsWithBuiltIns() throws {
        let lists = try Fixture.decode(ListsEnvelope.self, "lists").lists
        #expect(lists.count == 2)
        #expect(lists[0].isWantToRead)
        #expect(lists[1].isFavourites)
        #expect(lists.allSatisfy { $0.isBuiltIn })
        #expect(lists[1].position == 1)
        #expect(!lists[0].hiddenOnHome)
    }

    @Test func readingListLenient() throws {
        let json = #"{"list":{"id":"5","name":"Cosy","novel_ids":[1,"x",3],"hidden_on_home":1}}"#
        let list = try JSONCoding.decoder().decode(ListEnvelope.self, from: Data(json.utf8)).list
        #expect(list.id == 5)
        #expect(list.novelIds == [1, 3])
        #expect(list.contains(3))
        #expect(list.hiddenOnHome)
        #expect(!list.isBuiltIn)
        #expect(list.count == 0)
    }

    @Test func statsFromTheServer() throws {
        let stats = try Fixture.decode(ReadingStats.self, "stats")
        #expect(stats.range == 30)
        #expect(stats.daily.count == 30)
        #expect(stats.hours.count == 24)
        #expect(stats.weekdays.count == 7)
        #expect(stats.headline.chaptersRead == 20)
        #expect(stats.headline.bestStreak == 12)
        #expect(stats.year?.topSeries?.name == "Ascending the Nine Heavens")
        #expect(stats.year?.busiestMonth?.label == "August")
        #expect(stats.milestones.first?.earned == true)
        #expect(stats.byMedium.first?.label == "Web novels")
        #expect(!stats.isEmpty)
    }

    @Test func emptyStatsObject() throws {
        let stats = try JSONCoding.decoder().decode(ReadingStats.self, from: Data("{}".utf8))
        #expect(stats.isEmpty)
        #expect(stats.range == 30)
        #expect(stats.year == nil)
    }

    @Test func browseShelf() throws {
        let page = try Fixture.decode(BrowsePage.self, "browse-book")
        #expect(page.medium == "book")
        #expect(page.lists.map(\.id) == ["popular", "trending", "classics"])
        #expect(page.list == "popular")
        #expect(page.hasMore)
        let first = try #require(page.results.first)
        #expect(first.title == "Atomic Habits")
        #expect(first.coverUrl != nil)
        #expect(!first.inLibrary)
        #expect(first.sources.first?.sourceName == "Book catalogues")
    }

    @Test func requestsPageLenient() throws {
        let page = try Fixture.decode(RequestsPage.self, "requests-mine")
        // The row without an id is skipped.
        #expect(page.requests.count == 1)
        let request = try #require(page.requests.first)
        #expect(request.candidates.map(\.sourceName) == ["MangaDex", "Bato"])
        #expect(request.candidates.allSatisfy { !$0.isDetailed })
        #expect(request.alsoWantedBy == 2)
        #expect(request.isFollower)
        #expect(request.canCancel == true)
        #expect(!request.wanted)
        #expect(request.requestedCategories == [3])
        #expect(page.pendingLimit == 5)
    }

    @Test func audiobookDetail() throws {
        let book = try Fixture.decode(AudiobookEnvelope.self, "audiobook").audiobook
        #expect(book.id == 7)
        #expect(book.summary.novelId == 8)
        #expect(book.summary.narratorOrNil == "Dan Stevens")
        #expect(book.parts.map(\.index) == [0, 1])
        #expect(book.parts[0].url?.query == "signature=a")
        #expect(book.chapters.map(\.label) == ["Letter 1", "Chapter 2"])
        #expect(book.bookmarks.first?.noteOrNil == nil)
        #expect(book.year == "2017")
        #expect(book.summary.seriesLabel == "Classics #2")
        #expect(book.summary.started)
        #expect(abs(book.summary.fraction - 0.004) < 0.0001)
        // Round-trips through the offline cache's coders.
        let data = try JSONCoding.encoder().encode(book)
        let again = try JSONCoding.decoder().decode(AudiobookDetail.self, from: data)
        #expect(again == book)
    }

    @Test func audiobookUpload() throws {
        let upload = try #require(try Fixture.decode(AudiobookUploadEnvelope.self, "audiobook-upload").upload)
        #expect(upload.id == 12)
        #expect(upload.status == "uploading")
        #expect(upload.chunkSize == 8_388_608)
        #expect(upload.files.first?.received == [0])
        #expect(upload.files.first?.chunks == 3)
        #expect(upload.novelId == 8)
    }

    @Test func novelSummaryNewFields() throws {
        let json = #"""
        {"id":8,"name":"Frankenstein","medium":"book","origin_country":"EN","want_to_read":true,"favourite":1,
         "audiobook":{"id":7,"duration_ms":"30000000"}}
        """#
        let novel = try JSONCoding.decoder().decode(NovelSummary.self, from: Data(json.utf8))
        #expect(novel.isBook)
        #expect(!novel.isPaged)
        #expect(novel.wantToRead)
        #expect(novel.favourite)
        #expect(novel.audiobook == AudiobookRef(id: 7, durationMs: 30_000_000))
        #expect(novel.originCountry == "EN")
        let comic = try JSONCoding.decoder().decode(NovelSummary.self, from: Data(#"{"id":1,"medium":"comic","audiobook":"x"}"#.utf8))
        #expect(comic.isPaged && comic.isComic && !comic.isManga)
        #expect(comic.audiobook == nil)
    }

    @Test func mediumHelpers() {
        #expect(Medium.isPaged("MANGA"))
        #expect(Medium.isPaged("comic"))
        #expect(!Medium.isPaged("book"))
        #expect(!Medium.isPaged(nil))
        #expect(Medium.label("comic") == "Comic")
        #expect(Medium.label("") == "Web novel")
        #expect(Medium.label(" book ") == "Book")
    }

    @Test func seriesLabels() {
        #expect(SeriesLabel.make(series: "Dune", index: "1") == "Dune #1")
        #expect(SeriesLabel.make(series: "Dune", index: "1.5") == "Dune #1.5")
        #expect(SeriesLabel.make(series: "Dune", index: "2.0") == "Dune #2")
        #expect(SeriesLabel.make(series: "Dune", index: nil) == "Dune")
        #expect(SeriesLabel.make(series: " ", index: "1") == nil)
        #expect(SeriesLabel.make(series: "Dune", index: "Book One") == "Dune #Book One")
    }

    @Test func jsonBodiesKeepTheirKeys() throws {
        let body = APIClient.audiobookProgressBody(positionMs: -5, speed: 3.456, finished: false,
                                                   recordedAt: "2026-11-28T00:00:00Z")
        let object = try #require(try JSONSerialization.jsonObject(with: body.encoded()) as? [String: Any])
        #expect(object["position_ms"] as? Int == 0)
        #expect(object["speed"] as? Double == 3.0)
        #expect(object["recorded_at"] as? String == "2026-11-28T00:00:00Z")
        let slow = APIClient.audiobookProgressBody(positionMs: 1, speed: 1.256, finished: true, recordedAt: "x")
        let slowObject = try #require(try JSONSerialization.jsonObject(with: slow.encoded()) as? [String: Any])
        #expect(slowObject["speed"] as? Double == 1.26)
    }

    @Test func safePathSegments() {
        #expect(APIClient.isSafePathSegment("abc-123_~"))
        #expect(!APIClient.isSafePathSegment(".."))
        #expect(!APIClient.isSafePathSegment("a/b"))
        #expect(!APIClient.isSafePathSegment(""))
    }

    @Test func rejectedErrorCarriesCodeAndRetry() {
        let body = Data(#"{"message":"Busy","code":"browse_busy"}"#.utf8)
        let failure = APIClient.failure(status: 503, data: body, response: nil)
        let error = APIError.rejected(failure)
        #expect(error.status == 503)
        #expect(error.code == "browse_busy")
        #expect(error.serverMessage == "Busy")
        #expect(error.isRetryable)
        #expect(APIError.http(status: 404, message: nil).status == 404)
        #expect(APIError.rejected(HTTPFailure(status: 401)).isUnauthenticated)
    }
}
