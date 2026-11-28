import Foundation
import Testing
@testable import Novarr

/// Port of android RequestsBooksTest.kt: books in Requests, the Discover shelf, Send to my Kindle.
@Suite("Requests for books, the Discover shelf, Send to my Kindle")
struct RequestsBooksTests {
    // MARK: Medium

    @Test func booksComeFirstAndAreTheDefault() {
        #expect(RequestMedium.allCases == [.book, .novel, .manga, .comic])
        #expect(RequestMedium.defaultMedium == .book)
        #expect(Requests.SearchMemory().medium == .book)
        #expect(RequestMedium.allCases.map(\.title) == ["Books", "Web Novels", "Manga", "Comics"])
        #expect(RequestMedium.offered(comics: false) == [.book, .novel, .manga])
        #expect(RequestMedium.offered(comics: true).last == .comic)
        #expect(!RequestMedium.comic.hasDiscover)
        #expect(RequestMedium.book.takesLinks && RequestMedium.comic.takesLinks && !RequestMedium.manga.takesLinks)
    }

    @Test func mediumMapping() {
        #expect(RequestMedium.from("book") == .book)
        #expect(RequestMedium.from("novel") == .novel)
        #expect(RequestMedium.from(" Manga ") == .manga)
        #expect(RequestMedium.from("audiobook") == .book)
        #expect(RequestMedium.from(nil) == .book)
        #expect(RequestMedium.from("webtoon", fallback: .manga) == .manga)
        #expect(RequestMedium.fromOrNil("webtoon") == nil)
        #expect(RequestMedium.fromOrNil("comic") == .comic)
    }

    @Test func bookRequestsAreNoLongerLabelledNovel() {
        #expect(Requests.mediumLabel("book") == "Book")
        #expect(Requests.mediumLabel("novel") == "Web Novel")
        #expect(Requests.mediumLabel("manga") == "Manga")
        #expect(Requests.mediumLabel("comic") == "Comic")
        #expect(Requests.mediumLabel("webtoon") == "Webtoon")
        #expect(Requests.mediumLabel(nil) == "")
    }

    // MARK: The search field

    @Test func urlDetectionFollowsTheServer() {
        #expect(Requests.looksLikeURL("https://www.goodreads.com/book/show/1-scion"))
        #expect(Requests.looksLikeURL("goodreads.com/book/show/1"))
        #expect(Requests.looksLikeURL("amazon.co.uk/dp/B000"))
        #expect(Requests.looksLikeURL("www.example.com/x"))
        #expect(Requests.looksLikeURL("hardcover.app/books/scion"))
        #expect(Requests.looksLikeURL("books.google.com/books?id=1"))
        #expect(!Requests.looksLikeURL("Dr.Stone/Reboot"))
        #expect(!Requests.looksLikeURL("The Way of Kings"))
        #expect(!Requests.looksLikeURL("https://x.com/a b"))
        #expect(!Requests.looksLikeURL(""))
        #expect(!Requests.looksLikeURL("example.com/book"))
    }

    @Test func isbnDetection() {
        #expect(Requests.looksLikeISBN("9780765326355"))
        #expect(Requests.looksLikeISBN("978-0-7653-2635-5"))
        #expect(Requests.looksLikeISBN("ISBN: 0765326353"))
        #expect(Requests.looksLikeISBN("080442957X"))
        #expect(!Requests.looksLikeISBN("1234567890123"))
        #expect(!Requests.looksLikeISBN("12345"))
        #expect(!Requests.looksLikeISBN("Mistborn 1"))
    }

    @Test func queryLimitsAndHints() {
        let link = "https://www.goodreads.com/book/show/" + String(repeating: "1", count: 300)
        #expect(Requests.queryLimit(link) == Requests.linkMax)
        #expect(Requests.queryLimit("a title") == Requests.queryMax)
        #expect(Requests.searchText("  \(link) ") == link)
        #expect(Requests.searchText(String(repeating: "x", count: 150)).count == Requests.queryMax)
        #expect(Requests.searchText(" a   b ") == "a b")
        #expect(Requests.queryHint(.book, link)?.hasPrefix("Novarr reads the book") == true)
        #expect(Requests.queryHint(.comic, link)?.hasPrefix("Novarr reads the comic") == true)
        #expect(Requests.queryHint(.manga, link)?.hasPrefix("Links work for books and comics only") == true)
        #expect(Requests.queryHint(.book, "9780765326355") == "Searching by ISBN.")
        #expect(Requests.queryHint(.novel, "9780765326355") == nil)
        #expect(Requests.queryHint(.book, "Mistborn") == nil)
        #expect(Requests.placeholder(.book) == "Title, author, ISBN — or paste a Goodreads / Amazon link")
    }

    @Test func seriesLabels() {
        #expect(SeriesLabel.make(series: "Scion", index: "1") == "Scion #1")
        #expect(SeriesLabel.make(series: "Scion", index: "1.0") == "Scion #1")
        #expect(SeriesLabel.make(series: "Scion", index: "1.5") == "Scion #1.5")
        #expect(SeriesLabel.make(series: "Scion", index: nil) == "Scion")
        #expect(SeriesLabel.make(series: " Scion ", index: "II") == "Scion #II")
        #expect(SeriesLabel.make(series: " ", index: "1") == nil)
        #expect(SeriesLabel.make(series: nil, index: "1") == nil)
    }

    // MARK: Payloads

    @Test func linkResultsCarryTheirSeriesLeniently() throws {
        let page = try Fixture.decode(BrowsePage.self, json: """
        {"medium":"book","lists":[{"id":"trending","name":"Trending today"},{"bad":1},{"id":"classics"}],
         "list":"trending","source_name":"Open Library","page":1,"has_more":true,"surprise":{"x":1},
         "results":[{"key":"k1","title":"Scion","series":"Scion","series_index":1,"in_library":false},
                    {"key":"k2","title":"Other","series_index":"2.5"},{"title":"no key"}]}
        """)
        #expect(page.lists.map(\.id) == ["trending", "classics"])
        #expect(page.sourceName == "Open Library")
        #expect(page.results.count == 2)
        #expect(page.results[0].seriesLabel == "Scion #1")
        #expect(page.results[1].seriesLabel == nil)
    }

    @Test func emptyShelfDecodes() throws {
        let page = try Fixture.decode(BrowsePage.self, json: #"{"medium":"novel","lists":[],"list":null,"source_name":null,"page":1,"has_more":false,"results":[]}"#)
        #expect(page.lists.isEmpty)
        #expect(page.list == nil)
    }

    @Test func wantedBookRequests() throws {
        func request(_ json: String) throws -> ReaderRequest { try Fixture.decode(ReaderRequest.self, json: json) }
        #expect(Requests.showsWanted(try request(#"{"id":1,"medium":"book","status":"pending","wanted":true}"#)))
        #expect(Requests.showsWanted(try request(#"{"id":1,"medium":"book","status":"approved","wanted":"1"}"#)))
        #expect(!Requests.showsWanted(try request(#"{"id":1,"medium":"book","status":"available","wanted":true}"#)))
        #expect(!Requests.showsWanted(try request(#"{"id":1,"medium":"book","status":"pending","wanted":false}"#)))
        #expect(!Requests.showsWanted(try request(#"{"id":1,"medium":"book","status":"pending"}"#)))
        #expect(!Requests.showsWanted(try request(#"{"id":1,"status":"pending","wanted":{"x":1}}"#)))
    }

    @Test func browseCacheKeysArePerMediumAndList() {
        #expect(Requests.browseCachePath("book", nil) == "requests/browse/book/_first.json")
        #expect(Requests.browseCachePath("book", "hardcover_trending") == "requests/browse/book/hardcover_trending.json")
        #expect(Requests.browseCachePath("manga", "../etc/passwd") == "requests/browse/manga/etcpasswd.json")
    }

    // MARK: Send to my Kindle / email

    @Test func sendPhases() {
        #expect(SendToKindle.phase(SendState()) == .none)
        #expect(SendToKindle.phase(nil) == .none)
        #expect(SendToKindle.phase(SendState(status: "queued")) == .running)
        #expect(SendToKindle.phase(SendState(status: "sending")) == .running)
        #expect(SendToKindle.phase(SendState(status: "retrying")) == .running)
        #expect(SendToKindle.phase(SendState(status: "SENT")) == .sent)
        #expect(SendToKindle.phase(SendState(status: "failed")) == .failed)
    }

    @Test func sendPolling() {
        #expect(SendToKindle.next(SendState(status: "queued"), elapsed: 0) == nil)
        #expect(SendToKindle.next(SendState(status: "sending"), elapsed: SendToKindle.giveUpAfter - 1) == nil)
        #expect(SendToKindle.next(SendState(status: "sending"), elapsed: SendToKindle.giveUpAfter) == .stillSending(SendToKindle.stillSendingMessage))
        #expect(SendToKindle.next(SendState(status: "sent", message: "Sent to me@kindle.com. Approve the sender."), elapsed: 5)
                == .sent("Sent to me@kindle.com. Approve the sender."))
        #expect(SendToKindle.next(SendState(status: "sent"), elapsed: 5) == .sent(SendToKindle.sentFallback))
        #expect(SendToKindle.next(SendState(status: "failed", message: "SMTP said no"), elapsed: 5) == .failed("SMTP said no"))
        #expect(SendToKindle.next(SendState(status: "failed", message: " "), elapsed: 5) == .failed(SendToKindle.failedFallback))
        #expect(SendToKindle.next(SendState(), elapsed: 5) == .stillSending(SendToKindle.stillSendingMessage))
    }

    private func rejected(_ status: Int, _ json: String) -> APIError {
        .rejected(APIClient.failure(status: status, data: Data(json.utf8), response: nil))
    }

    @Test func sendRefusals() {
        let noAddress = rejected(422, #"{"message":"Add your Kindle or email address on your Account page first.","code":"no_address","account_url":"https://novarr.example/account#send-to"}"#)
        #expect(SendToKindle.refusal(noAddress, baseURL: "https://host")
                == .noAddress(message: SendToKindle.noAddressMessage, accountURL: "https://novarr.example/account#send-to"))
        let relative = rejected(422, #"{"code":"no_address","account_url":"/account#send-to"}"#)
        #expect(SendToKindle.refusal(relative, baseURL: "https://host/")
                == .noAddress(message: SendToKindle.noAddressMessage, accountURL: "https://host/account#send-to"))
        let missing = rejected(422, #"{"code":"no_address"}"#)
        #expect(SendToKindle.refusal(missing, baseURL: "https://host") == .noAddress(message: SendToKindle.noAddressMessage, accountURL: nil))
        let tooMany = rejected(429, #"{"message":"That is a lot of sending: try again in a while.","code":"too_many"}"#)
        #expect(SendToKindle.refusal(tooMany, baseURL: nil) == .failed("That is a lot of sending: try again in a while."))
        let manga = rejected(422, #"{"message":"A manga has no ePub to send.","code":"manga"}"#)
        #expect(SendToKindle.refusal(manga, baseURL: nil) == .failed("A manga has no ePub to send."))
    }

    @Test func resolveOnlyHTTPLinks() {
        #expect(SendToKindle.resolve(baseURL: "https://host", url: "javascript:alert(1)") == nil)
        #expect(SendToKindle.resolve(baseURL: nil, url: "/account") == nil)
        #expect(SendToKindle.resolve(baseURL: "https://host", url: nil) == nil)
        #expect(SendToKindle.resolve(baseURL: "https://host", url: "HTTPS://other/x") == "HTTPS://other/x")
    }

    @Test func sendEnvelopeDecodes() throws {
        let queued = try Fixture.decode(SendEnvelope.self, json: #"{"send":{"status":"queued","message":null,"to":"me@kindle.com","extra":1}}"#)
        #expect(queued.send == SendState(status: "queued", message: nil, to: "me@kindle.com"))
        let none = try Fixture.decode(SendEnvelope.self, json: #"{"send":{"status":null,"message":null,"to":null}}"#)
        #expect(SendToKindle.phase(none.send) == .none)
        #expect(try Fixture.decode(SendEnvelope.self, json: #"{"send":"weird"}"#).send == nil)
    }

    @Test func sendStartsAndPolls() async throws {
        let client = StubURLProtocol.client(token: "t") { request, _ in
            #expect(request.url?.path == "/api/v1/novels/8/send")
            return request.httpMethod == "POST"
                ? (202, Data(#"{"send":{"status":"queued","to":"me@kindle.com"}}"#.utf8))
                : (200, Data(#"{"send":{"status":"sent","message":"Sent."}}"#.utf8))
        }
        let started = try await client.startSend(novelId: 8)
        #expect(started.to == "me@kindle.com")
        #expect(SendToKindle.phase(started) == .running)
        let later = try await client.sendState(novelId: 8)
        #expect(SendToKindle.next(later, elapsed: 3) == .sent("Sent."))
    }
}
