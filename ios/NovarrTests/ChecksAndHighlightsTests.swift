import Foundation
import Testing
@testable import Novarr

@Suite("Checks and highlights: decoding")
struct ChecksAndHighlightsDecodingTests {
    @Test func checks() throws {
        let running = try Fixture.decode(CheckEnvelope.self, "check-running").check
        #expect(running.status == .running)
        #expect(running.startedAt == JSONCoding.parseDate("2026-11-15T03:20:11Z"))
        #expect(running.finishedAt == nil)
        #expect(running.chaptersBefore == 120)
        #expect(running.chaptersNow == 121)
        #expect(running.newChapters == 1)
        #expect(running.message == nil)

        let done = try Fixture.decode(CheckEnvelope.self, "check-done").check
        #expect(done.status == .done)
        #expect(done.finishedAt == JSONCoding.parseDate("2026-11-15T03:24:02Z"))
        #expect(done.newChapters == 3)

        let failed = try Fixture.decode(CheckEnvelope.self, "check-failed").check
        #expect(failed.status == .failed)
        #expect(failed.message?.contains("already running") == true)

        let idle = try Fixture.decode(CheckEnvelope.self, json: """
        {"check": {"status": "idle", "started_at": null, "finished_at": null, "chapters_before": null,
                   "chapters_now": 123, "new_chapters": 0, "message": null}}
        """).check
        #expect(idle.status == .idle)
        #expect(idle.chaptersBefore == nil)
        #expect(idle.chaptersNow == 123)
    }

    @Test func checkRoundTrip() throws {
        let done = try Fixture.decode(CheckEnvelope.self, "check-done").check
        let data = try JSONCoding.encoder().encode(done)
        #expect(try JSONCoding.decoder().decode(NovelCheck.self, from: data) == done)
    }

    @Test func highlights() throws {
        let page = try Fixture.decode(HighlightsPage.self, "highlights")
        #expect(page.page == 1)
        #expect(page.hasMore == false)
        #expect(page.highlights.map(\.id) == [7, 3])
        let first = page.highlights[0]
        #expect(first.novelId == 42)
        #expect(first.novelName == "Shadow Slave")
        #expect(first.chapterId == 187423)
        #expect(first.chapterLabel == "Chapter 12: The Dark Sea")
        #expect(first.excerpt.hasPrefix("The sea was black"))
        #expect(first.note == "Great line.")
        #expect(first.createdAt == JSONCoding.parseDate("2026-11-15T03:20:11Z"))
        #expect(page.highlights[1].note == nil)
    }
}

/// A minimal pageable item, so the paging rules are tested on their own.
private struct PageItem: Identifiable, Equatable {
    var title: String
    var note: String?
    var id: String { title }
}

@Suite("Paging")
struct PagingTests {
    private func items(_ names: [String]) -> [PageItem] {
        names.map { PageItem(title: $0) }
    }

    @Test func pagesAppendInOrder() {
        var list = PagedList<PageItem>()
        #expect(list.nextPage == 1)
        #expect(list.canLoadMore == false) // nothing loaded yet
        let first = list.apply(page: 1, items: items(["a", "b"]), hasMore: true)
        #expect(first)
        #expect(list.canLoadMore)
        #expect(list.nextPage == 2)
        let second = list.apply(page: 2, items: items(["c"]), hasMore: false)
        #expect(second)
        #expect(list.items.map(\.title) == ["a", "b", "c"])
        #expect(list.canLoadMore == false)
    }

    @Test func staleOrRepeatedPagesAreIgnored() {
        var list = PagedList<PageItem>()
        list.apply(page: 1, items: items(["a"]), hasMore: true)
        let repeated = list.apply(page: 1, items: items(["x"]), hasMore: true)
        let skipped = list.apply(page: 3, items: items(["y"]), hasMore: true)
        #expect(repeated == false)
        #expect(skipped == false)
        #expect(list.items.map(\.title) == ["a"])
        #expect(list.nextPage == 2)
    }

    @Test func duplicatesAcrossPagesAreSkipped() {
        var list = PagedList<PageItem>()
        list.apply(page: 1, items: items(["a", "b"]), hasMore: true)
        list.apply(page: 2, items: items(["b", "c"]), hasMore: true)
        #expect(list.items.map(\.title) == ["a", "b", "c"])
    }

    @Test func anEmptyPageEndsTheList() {
        var list = PagedList<PageItem>()
        list.apply(page: 1, items: items(["a"]), hasMore: true)
        list.apply(page: 2, items: [], hasMore: true) // a page past the end
        #expect(list.canLoadMore == false)
    }

    @Test func resetMarkAndRestore() throws {
        var list = PagedList<PageItem>()
        list.apply(page: 1, items: items(["a", "b", "c"]), hasMore: false)
        let id = list.items[1].id
        list.update(id: id) { $0.note = "marked" }
        #expect(list.items[1].note == "marked")

        let maybeRemoved = list.remove(id: id)
        let removed = try #require(maybeRemoved)
        #expect(removed.index == 1)
        #expect(list.items.map(\.title) == ["a", "c"])
        list.restore(removed.item, at: removed.index)
        list.restore(removed.item, at: 0) // no duplicate
        #expect(list.items.map(\.title) == ["a", "b", "c"])

        list.reset()
        #expect(list.items.isEmpty)
        #expect(list.hasLoaded == false)
        #expect(list.nextPage == 1)
    }

    @MainActor
    @Test func highlightErrorMessage() {
        #expect(HighlightsModel.message(for: .http(status: 404, message: nil)).contains("doesn't offer highlights"))
    }
}

@Suite("Check and highlight requests")
struct ChecksAndHighlightsClientTests {
    @Test func checkStartAndPoll() async throws {
        let client = StubURLProtocol.client { request, body in
            #expect(request.url?.path == "/api/v1/novels/42/check")
            if request.httpMethod == "POST" {
                #expect(body == nil || body?.isEmpty == true) // no body
                return (202, try Fixture.data("check-running"))
            }
            return (200, try Fixture.data("check-done"))
        }
        #expect(try await client.startCheck(novelId: 42).status == .running)
        #expect(try await client.check(novelId: 42).newChapters == 3)
    }

    @Test func highlightsAndDelete() async throws {
        let client = StubURLProtocol.client { request, _ in
            if request.httpMethod == "DELETE" {
                // 204 for a live one, 404 for one that's already gone: both fine.
                return request.url?.path == "/api/v1/highlights/7" ? (204, Data()) : (404, Data(#"{"message":"Not Found"}"#.utf8))
            }
            let components = URLComponents(url: request.url!, resolvingAgainstBaseURL: false)
            #expect(components?.path == "/api/v1/highlights")
            #expect(components?.queryItems?.contains(URLQueryItem(name: "novel_id", value: "42")) == true)
            #expect(components?.queryItems?.contains(URLQueryItem(name: "per_page", value: "3")) == true)
            return (200, try Fixture.data("highlights"))
        }
        let page = try await client.highlights(novelId: 42, perPage: 3)
        #expect(page.highlights.count == 2)
        try await client.deleteHighlight(id: 7)
        try await client.deleteHighlight(id: 8)
    }
}
