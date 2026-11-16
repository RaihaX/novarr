import Foundation
import Testing
@testable import Novarr

@Suite("Discover, add a novel, checks and highlights: decoding")
struct Round5DecodingTests {
    @Test func sources() throws {
        let sources = try Fixture.decode(DiscoverSourcesEnvelope.self, "discover").sources
        #expect(sources.map(\.id) == ["novelarrow", "empirenovel", "novelfull"])
        let first = try #require(sources.first)
        #expect(first.isDefault)
        #expect(first.name == "NovelPing (novelarrow)")
        #expect(first.host == "novelping.com")
        #expect(first.lists.map(\.id) == ["popular", "new", "random", "completed", "search"])
        #expect(first.browseLists.map(\.name) == ["Popular", "New", "Random", "Completed"])
        #expect(first.offersSearch)
        #expect(sources[1].isDefault == false)
        #expect(sources[1].browseLists.isEmpty)
        #expect(sources[1].offersSearch)
    }

    @Test func browsePage() throws {
        let page = try Fixture.decode(DiscoverPage.self, "discover-browse")
        #expect(page.page == 1)
        #expect(page.hasMore)
        #expect(page.items.count == 3)

        let slave = page.items[0]
        #expect(slave.title == "Shadow Slave")
        #expect(slave.author == "Guiltythree")
        #expect(slave.coverUrl?.host == "images.novelping.com")
        #expect(slave.synopsis?.hasSuffix("...") == true)
        #expect(slave.status == "Ongoing")
        #expect(slave.chapters == 3207)
        #expect(slave.url.absoluteString == "https://novelping.com/novel/shadow-slave")
        #expect(slave.id == slave.url.absoluteString)
        #expect(slave.source == "novelarrow")
        #expect(slave.inLibrary)
        #expect(slave.novelId == 42)
        #expect(slave.facts == "Ongoing · 3,207 chapters")

        let bare = page.items[1]
        #expect(bare.author == nil)
        #expect(bare.coverUrl == nil)
        #expect(bare.synopsis == nil)
        #expect(bare.status == nil)
        #expect(bare.chapters == nil)
        #expect(bare.facts == nil)
        #expect(bare.inLibrary == false)
        #expect(bare.novelId == nil)
    }

    @Test func emptyListAndItemWithoutURL() throws {
        let empty = try Fixture.decode(DiscoverPage.self, json: #"{"items": [], "page": 1, "has_more": false}"#)
        #expect(empty.items.isEmpty)
        #expect(empty.hasMore == false)
        let lossy = try Fixture.decode(DiscoverPage.self, json: """
        {"items": [{"title": "No url"}, {"title": "Ok", "url": "https://x.example/novel/ok"}], "page": 2, "has_more": false}
        """)
        #expect(lossy.items.map(\.title) == ["Ok"])
        #expect(lossy.page == 2)
        #expect(throws: (any Error).self) {
            try Fixture.decode(DiscoverPage.self, json: #"{"message": "Couldn't load this list from the source. Try again later."}"#)
        }
    }

    @Test func addedNovel() throws {
        let added = try Fixture.decode(AddNovelResponse.self, "novel-added")
        #expect(added.alreadyInLibrary == false)
        #expect(added.novel.id == 143)
        #expect(added.novel.name == "Lord of the Mysteries")
        #expect(added.novel.chaptersTotal == 0)
        #expect(added.novel.resumeChapterId == nil)

        let already = try Fixture.decode(AddNovelResponse.self, json: """
        {"novel": {"id": 42, "name": "Shadow Slave", "state": "active"}, "already_in_library": true}
        """)
        #expect(already.alreadyInLibrary)
        #expect(already.novel.id == 42)
    }

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

@Suite("Discover paging and queries")
struct DiscoverPagingTests {
    private func items(_ names: [String]) -> [DiscoverItem] {
        names.map { name in
            DiscoverItem(title: name, author: nil, coverUrl: nil, synopsis: nil, status: nil, chapters: nil,
                         url: URL(string: "https://source.example/novel/\(name)")!, source: "novelarrow",
                         inLibrary: false, novelId: nil)
        }
    }

    @Test func pagesAppendInOrder() {
        var list = PagedList<DiscoverItem>()
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
        var list = PagedList<DiscoverItem>()
        list.apply(page: 1, items: items(["a"]), hasMore: true)
        let repeated = list.apply(page: 1, items: items(["x"]), hasMore: true)
        let skipped = list.apply(page: 3, items: items(["y"]), hasMore: true)
        #expect(repeated == false)
        #expect(skipped == false)
        #expect(list.items.map(\.title) == ["a"])
        #expect(list.nextPage == 2)
    }

    @Test func duplicatesAcrossPagesAreSkipped() {
        var list = PagedList<DiscoverItem>()
        list.apply(page: 1, items: items(["a", "b"]), hasMore: true)
        list.apply(page: 2, items: items(["b", "c"]), hasMore: true)
        #expect(list.items.map(\.title) == ["a", "b", "c"])
    }

    @Test func anEmptyPageEndsTheList() {
        var list = PagedList<DiscoverItem>()
        list.apply(page: 1, items: items(["a"]), hasMore: true)
        list.apply(page: 2, items: [], hasMore: true) // a page past the end
        #expect(list.canLoadMore == false)
    }

    @Test func resetMarkAndRestore() throws {
        var list = PagedList<DiscoverItem>()
        list.apply(page: 1, items: items(["a", "b", "c"]), hasMore: false)
        let id = list.items[1].id
        list.update(id: id) {
            $0.inLibrary = true
            $0.novelId = 901
        }
        #expect(list.items[1].inLibrary)
        #expect(list.items[1].novelId == 901)

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

    @Test func queries() throws {
        let sources = try Fixture.decode(DiscoverSourcesEnvelope.self, "discover").sources
        let ping = sources[0], empire = sources[1]

        #expect(DiscoverQuery.make(source: ping, listId: "new", searchText: "")
                == DiscoverQuery(sourceId: "novelarrow", type: "new", text: nil))
        // Unknown list: the source's first list.
        #expect(DiscoverQuery.make(source: ping, listId: "nope", searchText: "  ")?.type == "popular")
        // Search text wins over the list, trimmed and capped at 100 characters.
        let search = try #require(DiscoverQuery.make(source: ping, listId: "new", searchText: "  sword "))
        #expect(search == DiscoverQuery(sourceId: "novelarrow", type: "search", text: "sword"))
        #expect(search.isSearch)
        let long = DiscoverQuery.make(source: ping, listId: nil, searchText: String(repeating: "x", count: 150))
        #expect(long?.text?.count == 100)
        // A search-only source has nothing to show until there is text.
        #expect(DiscoverQuery.make(source: empire, listId: nil, searchText: "") == nil)
        #expect(DiscoverQuery.make(source: empire, listId: nil, searchText: "lord")?.sourceId == "empirenovel")
        // A source without search can't be searched.
        let browseOnly = DiscoverSource(id: "b", name: "B", host: nil, isDefault: false,
                                        lists: [DiscoverList(id: "popular", name: "Popular")])
        #expect(DiscoverQuery.make(source: browseOnly, listId: nil, searchText: "x") == nil)
    }

    @MainActor
    @Test func errorMessages() {
        #expect(DiscoverModel.message(for: .http(status: 502, message: "Couldn't load this list from the source. Try again later."))
                == "That source didn't answer. Try again or pick another source.")
        #expect(DiscoverModel.addMessage(for: .http(status: 422, message: "The url field must be a valid URL."))
                == "The url field must be a valid URL.")
        #expect(DiscoverModel.addMessage(for: .http(status: 503, message: nil))
                == "Another novel is being added right now. Try again in a moment.")
        #expect(HighlightsModel.message(for: .http(status: 404, message: nil)).contains("doesn't offer highlights"))
    }
}

@Suite("Round 5 requests")
struct Round5ClientTests {
    @Test func browseSendsTheQuery() async throws {
        let client = StubURLProtocol.client { request, _ in
            let components = URLComponents(url: request.url!, resolvingAgainstBaseURL: false)
            #expect(components?.path == "/api/v1/discover/browse")
            let query = Dictionary(uniqueKeysWithValues: (components?.queryItems ?? []).map { ($0.name, $0.value ?? "") })
            #expect(query == ["type": "search", "source": "empirenovel", "q": "lord of", "page": "2", "per_page": "20"])
            #expect(request.timeoutInterval == APIClient.browseTimeout)
            return (200, try Fixture.data("discover-browse"))
        }
        let page = try await client.discoverBrowse(type: "search", source: "empirenovel", query: "lord of", page: 2)
        #expect(page.items.count == 3)
    }

    @Test func browse502() async {
        let client = StubURLProtocol.client { _, _ in
            (502, Data(#"{"message":"Couldn't load this list from the source. Try again later."}"#.utf8))
        }
        await #expect(throws: APIError.http(status: 502, message: "Couldn't load this list from the source. Try again later.")) {
            _ = try await client.discoverBrowse(type: "popular", source: nil, query: nil, page: 1)
        }
    }

    @Test func addNovel() async throws {
        let client = StubURLProtocol.client { request, body in
            #expect(request.httpMethod == "POST")
            #expect(request.url?.path == "/api/v1/novels")
            let json = try JSONSerialization.jsonObject(with: body ?? Data()) as? [String: String]
            #expect(json == ["title": "Lord of the Mysteries", "url": "https://novelping.com/novel/lord-of-the-mysteries"])
            return (201, try Fixture.data("novel-added"))
        }
        let added = try await client.addNovel(
            title: "Lord of the Mysteries", url: URL(string: "https://novelping.com/novel/lord-of-the-mysteries")!)
        #expect(added.novel.id == 143)
        #expect(added.alreadyInLibrary == false)
    }

    @Test func addNovelValidationError() async {
        let client = StubURLProtocol.client { _, _ in
            (422, Data(#"{"message":"The url field must be a valid URL.","errors":{"url":["The url field must be a valid URL."]}}"#.utf8))
        }
        await #expect(throws: APIError.http(status: 422, message: "The url field must be a valid URL.")) {
            _ = try await client.addNovel(title: "x", url: URL(string: "https://x.example")!)
        }
    }

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

    @Test func cancellingABrowseThrowsCancellation() async {
        let client = StubURLProtocol.client { _, _ in
            Thread.sleep(forTimeInterval: 0.5)
            return (200, Data(#"{"items":[],"page":1,"has_more":false}"#.utf8))
        }
        let task = Task { try await client.discoverBrowse(type: "popular", source: nil, query: nil, page: 1) }
        try? await Task.sleep(for: .milliseconds(50))
        task.cancel()
        let result = await task.result
        switch result {
        case .failure(let error): #expect(error is CancellationError)
        case .success: Issue.record("A cancelled browse should not succeed")
        }
    }
}
