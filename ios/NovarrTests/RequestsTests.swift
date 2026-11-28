import Foundation
import Testing
@testable import Novarr

/// Port of android RequestsTest.kt: fixtures follow RequestPresenter / RequestFeed.
@Suite("Requests: decoding, cards, status, review, Retry-After, the feed, the schedule")
struct RequestsTests {
    // MARK: Decoding

    @Test func metaSaysWhetherRequestsAreOffered() throws {
        #expect(try Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1,"auth":{"required":true},"requests":true}"#).requests)
        #expect(!(try Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1,"requests":false}"#).requests))
        #expect(!(try Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1,"server_version":"28da68d"}"#).requests))
        #expect(!(try Fixture.decode(ServerMeta.self, json: #"{"app":"novarr","api_version":1,"requests":null}"#).requests))
    }

    @Test func runningSearchHasNoResults() throws {
        let search = try Fixture.decode(SearchEnvelope.self, json: #"{"search":{"id":"abc123","status":"running","message":null,"query":"solo","medium":"manga","results":null,"skipped_sources":[]}}"#).search
        #expect(search.isRunning)
        #expect(search.id == "abc123")
        #expect(search.results.isEmpty)
    }

    @Test func doneSearchWithResultsAndSkippedSources() throws {
        let search = try Fixture.decode(SearchEnvelope.self, json: Self.doneSearch).search
        #expect(search.isDone)
        #expect(search.results.count == 3)
        let first = search.results[0]
        #expect(first.key == "g1")
        #expect(first.title == "Solo Leveling")
        #expect(first.coverUrl?.absoluteString == "https://host/requests/cover?u=x&signature=y")
        #expect(first.sources.map(\.sourceName) == ["MangaDex", "MangaSee"])
        #expect(first.sources[0].chapters == 179)
        #expect(first.requested == nil)
        #expect(search.results[1].requested == ResultRequested(status: "pending", byMe: true))
        #expect(search.results[2].inLibrary)
        #expect(search.results[2].novelId == 42)
        #expect(search.skippedSources.map(\.sourceName) == ["Comick"])
        #expect(search.skippedSources[0].reason == "timeout")
    }

    @Test func failedSearchCarriesItsMessage() throws {
        let search = try Fixture.decode(SearchEnvelope.self, json: #"{"search":{"id":"z","status":"failed","message":"The search took too long.","query":"q","medium":"novel","results":null,"skipped_sources":[]}}"#).search
        #expect(search.isFailed)
        #expect(search.message == "The search took too long.")
    }

    @Test func readerViewCandidatesAreSourceNames() throws {
        let page = try Fixture.decode(RequestsPage.self, json: Self.readerPage)
        #expect(page.pendingLimit == 5)
        #expect(!page.canDecide)
        let request = try #require(page.requests.first)
        #expect(request.candidates.map(\.sourceName) == ["Fake B (manga)", "Fake A (manga)"])
        #expect(request.candidates.allSatisfy { !$0.isDetailed })
        #expect(request.canCancel == true)
        #expect(request.requestedBy?.name == "Ana")
        #expect(request.note == "Translated by X")
    }

    @Test func adminViewCandidatesAreTheRankedShortlist() throws {
        let page = try Fixture.decode(RequestsPage.self, json: Self.adminPage)
        #expect(page.canDecide)
        #expect(page.pendingLimit == nil)
        let request = try #require(page.requests.first)
        #expect(request.candidates.map(\.index) == [1, 0, 2])
        let best = request.candidates[0]
        #expect(best.sourceName == "Fake B (manga)")
        #expect(best.chaptersAvailable == 120)
        #expect(best.highestChapter == "121.5")
        #expect(best.languages == ["en", "es"])
        #expect(best.reason == "Most chapters, updated recently")
        #expect(request.candidates[2].error == "Source timed out")
        #expect(request.candidatesRankedAt == "2026-11-18T10:00:00+00:00")
    }

    @Test func freeTextRequestAndApproveResult() throws {
        let created = try Fixture.decode(RequestEnvelope.self, json: #"{"request":{"id":9,"title":"The Obscure One","medium":"novel","link":"https://somewhere.example/obscure","note":"Translated by X","status":"pending","candidates":[],"cover_url":null,"can_decide":false,"can_cancel":true,"requested_by":{"id":3,"name":"Ana"},"created_at":"2026-11-19T08:00:00+00:00"}}"#).request
        #expect(created.link == "https://somewhere.example/obscure")
        #expect(created.candidates.isEmpty)
        let approved = try Fixture.decode(ApproveResult.self, json: #"{"request":{"id":9,"status":"approved","novel_id":77},"novel_id":77,"created":true}"#)
        #expect(approved.novelId == 77)
        #expect(approved.created)
        #expect(approved.request?.status == "approved")
    }

    @Test func notificationFeed() throws {
        let feed = try Fixture.decode(NotificationFeed.self, json: Self.feed)
        #expect(feed.cursor == "cm4xOjEy")
        #expect(!feed.hasMore)
        #expect(feed.items.map(\.id) == [11, 12])
        #expect(feed.items[0].kind == RequestFeed.kindNew)
        #expect(feed.items[0].requestId == 5)
        #expect(feed.items[1].novelId == 42)
        let broken = try Fixture.decode(NotificationFeed.self, json: #"{"cursor":"c","items":[{"kind":"x"},{"id":3,"kind":"request_declined"}]}"#)
        #expect(broken.items.map(\.id) == [3])
    }

    @Test func requestFollowerFieldsDecodeOldAndNewShapes() throws {
        let old = try Fixture.decode(RequestEnvelope.self, json: #"{"request":{"id":1,"title":"A","status":"pending","can_cancel":true}}"#).request
        #expect(old.alsoWantedBy == 0)
        #expect(old.mine == nil)
        #expect(old.followers.isEmpty)
        #expect(!old.isFollower)
        let mine = try Fixture.decode(RequestEnvelope.self, json: #"{"request":{"id":2,"title":"B","status":"pending","mine":"follower","also_wanted_by":2,"can_cancel":false}}"#).request
        #expect(mine.isFollower)
        #expect(mine.alsoWantedBy == 2)
    }

    // MARK: The client

    @Test func refusalsCarryCodeAndExtraFields() async throws {
        let client = StubURLProtocol.client(token: "tok") { request, body in
            let json = try JSONSerialization.jsonObject(with: body ?? Data()) as? [String: Any]
            #expect(json?["q"] as? String == "solo")
            #expect(json?["medium"] as? String == "manga")
            #expect(request.value(forHTTPHeaderField: "Authorization") == "Bearer tok")
            return (429, Data(#"{"message":"Search is busy right now. Try again in a moment.","code":"search_busy"}"#.utf8))
        }
        do {
            _ = try await client.startRequestSearch(query: "solo", medium: "manga")
            Issue.record("expected 429")
        } catch {
            #expect(APIError.from(error).status == 429)
            #expect(Requests.code(error) == "search_busy")
            #expect(Requests.message(error) == "Search is busy right now. Try again in a moment.")
        }
        let library = StubURLProtocol.client(token: "tok") { _, _ in
            (409, Data(#"{"message":"This series is already in the library.","code":"in_library","novel_id":42}"#.utf8))
        }
        do {
            _ = try await library.createRequest(searchId: "s1", key: "g1", note: "please")
            Issue.record("expected 409")
        } catch {
            #expect(Requests.code(error) == "in_library")
            #expect(Requests.message(error) == "This series is already in the library.")
            struct Body: Decodable { var novelId: Int? }
            if case .rejected(let failure) = APIError.from(error) { #expect(failure.decode(Body.self)?.novelId == 42) }
        }
    }

    @Test func createRequestAnswersOldAndNew() async throws {
        let joined = try await StubURLProtocol.client(token: "t") { _, body in
            #expect(String(data: body ?? Data(), encoding: .utf8)?.contains(#""search_id":"s1""#) == true)
            return (200, Data(#"{"request":{"id":7,"title":"Solo","status":"pending","mine":"follower","also_wanted_by":1},"joined":true}"#.utf8))
        }.createRequest(searchId: "s1", key: "g1", note: nil)
        #expect(joined.joined)
        #expect(joined.request?.id == 7)
        #expect(joined.request?.isFollower == true)
        let old = try await StubURLProtocol.client(token: "t") { _, _ in (201, Data(#"{"request":{"id":9}}"#.utf8)) }
            .createRequest(searchId: "s1", key: "g3", note: nil)
        #expect(!old.joined)
        #expect(old.request?.id == 9)
    }

    @Test func backgroundFeedPollDoesNotEndTheSessionOn401() async {
        let counter = RequestsCallCounter()
        let client = StubURLProtocol.client(token: "tok", onUnauthorized: { _ in counter.bump() }) { _, _ in
            (401, Data(#"{"message":"Unauthenticated."}"#.utf8))
        }
        _ = try? await client.notifications(since: "c", reportUnauthorized: false)
        #expect(counter.value == 0)
        _ = try? await client.notifications(since: "c")
        #expect(counter.value == 1)
    }

    // MARK: Search cards

    @Test func cardActionFromAResult() {
        let plain = result()
        #expect(Requests.cardAction(plain) == .request)
        #expect(Requests.cardAction(modified(plain) { $0.inLibrary = true; $0.novelId = 42 }) == .inLibrary(novelId: 42))
        #expect(Requests.cardAction(modified(plain) { $0.requested = ResultRequested(status: "pending", byMe: true) }) == .requested(byMe: true))
        #expect(Requests.cardAction(modified(plain) { $0.requested = ResultRequested(status: "approved", byMe: false) }) == .meToo)
        #expect(Requests.cardAction(modified(plain) {
            $0.inLibrary = true; $0.novelId = 7; $0.requested = ResultRequested(status: "approved", byMe: true)
        }) == .inLibrary(novelId: 7))
        #expect(Requests.cardActionLabel(.requested(byMe: true)) == "Requested by you")
        #expect(Requests.cardActionLabel(.requested(byMe: false)) == "Requested")
        #expect(Requests.cardActionLabel(.inLibrary(novelId: nil)) == "In library")
        #expect(Requests.cardActionLabel(.request) == "Request")
    }

    @Test func meTooOnSomeoneElsesOpenRequest() {
        let plain = result()
        func action(_ status: String, byMe: Bool, canJoin: Bool = true) -> Requests.CardAction {
            Requests.cardAction(modified(plain) { $0.requested = ResultRequested(status: status, byMe: byMe) }, canJoin: canJoin)
        }
        #expect(action("pending", byMe: false) == .meToo)
        #expect(action("approved", byMe: false) == .meToo)
        #expect(action("PENDING", byMe: false) == .meToo)
        #expect(action("pending", byMe: true) == .requested(byMe: true))
        #expect(action("declined", byMe: false) == .requested(byMe: false))
        #expect(action("available", byMe: false) == .requested(byMe: false))
        #expect(action("pending", byMe: false, canJoin: false) == .requested(byMe: false))
        #expect(Requests.cardAction(modified(plain) { $0.novelId = 3; $0.requested = ResultRequested(status: "pending", byMe: false) }) == .inLibrary(novelId: 3))
        #expect(Requests.cardAction(plain, canJoin: false) == .request)
        #expect(Requests.cardActionLabel(.meToo) == "Me too")
        #expect(Requests.joinedNotice == "You'll be told when it's ready.")
    }

    @Test func joiningKeepsTheRequestStatusAndRefusalMarksTheCard() {
        let results = [modified(result("a")) { $0.requested = ResultRequested(status: "approved", byMe: false) }, result("b")]
        let joined = Requests.markRequested(results, key: "a")
        #expect(joined[0].requested == ResultRequested(status: "approved", byMe: true))
        #expect(Requests.cardActionLabel(Requests.cardAction(joined[0])) == "Requested by you")
        let taken = Requests.markTaken(results, key: "b", byMe: false)
        #expect(taken[1].requested == ResultRequested(status: "pending", byMe: false))
        #expect(Requests.cardAction(taken[1], canJoin: false) == .requested(byMe: false))
    }

    @Test func followersLeaveInsteadOfCancel() {
        let own = modified(request(status: "pending")) { $0.canCancel = true; $0.mine = "requester" }
        #expect(Requests.mineAction(own) == .cancel)
        #expect(Requests.mineAction(modified(own) { $0.canCancel = false }) == .none)
        let joined = modified(request(status: "pending")) { $0.mine = "follower" }
        #expect(Requests.mineAction(joined) == .leave)
        #expect(Requests.mineAction(modified(joined) { $0.canCancel = true }) == .leave)
        #expect(Requests.mineAction(modified(joined) { $0.status = "approved"; $0.canCancel = true }) == .leave)
        #expect(Requests.mineAction(modified(joined) { $0.status = "approved"; $0.canCancel = false }) == .none)
        #expect(Requests.mineAction(modified(joined) { $0.status = "available"; $0.canCancel = false }) == .none)
        #expect(Requests.mineAction(modified(joined) { $0.canCancel = false }) == .none)
        #expect(Requests.mineAction(modified(joined) { $0.status = "approved" }) == .none)
        #expect(Requests.mineAction(modified(own) { $0.status = "approved" }) == .none)
        #expect(Requests.mineAction(modified(own) { $0.status = "declined" }) == .none)
        #expect(Requests.mineAction(modified(request()) { $0.canCancel = true }) == .cancel)
    }

    @Test func alsoWantedByNamesOrCount() {
        let r = request()
        #expect(Requests.alsoWantedLabel(r) == nil)
        #expect(Requests.alsoWantedLabel(modified(r) { $0.alsoWantedBy = 2; $0.followers = [Requester(id: 1, name: "Ana"), Requester(id: 2, name: "Ben")] }) == "Also wanted by Ana, Ben")
        #expect(Requests.alsoWantedLabel(modified(r) { $0.followers = [Requester(id: 1, name: "Ana")] }) == "Also wanted by Ana")
        #expect(Requests.alsoWantedLabel(modified(r) { $0.alsoWantedBy = 3 }) == "Also wanted by 3 readers")
        #expect(Requests.alsoWantedLabel(modified(r) { $0.alsoWantedBy = 1 }) == "Also wanted by 1 reader")
        #expect(Requests.alsoWantedLabel(modified(r) { $0.alsoWantedBy = 3; $0.followers = [Requester(id: 1, name: "Ana")] }) == "Also wanted by Ana and 2 more readers")
        #expect(Requests.alsoWantedLabel(modified(r) { $0.followers = [Requester(id: 1, name: nil), Requester(id: 2, name: " ")] }) == "Also wanted by 2 readers")
    }

    @Test func sourcesAndSkippedLabels() {
        let sources = ["MangaDex", "MangaSee", "Comick", "MangaDex", "Bato"].map { ResultSource(source: $0.lowercased(), sourceName: $0) }
        #expect(Requests.sourcesLabel(sources) == "On MangaDex, MangaSee +2")
        #expect(Requests.sourcesLabel(Array(sources.prefix(1))) == "On MangaDex")
        #expect(Requests.sourcesLabel([ResultSource(source: "fallback", sourceName: "")]) == "On fallback")
        #expect(Requests.sourcesLabel([]) == "")
        #expect(Requests.skippedLabel(["Comick", "Bato", "Comick"]) == "Couldn't reach: Comick, Bato.")
        #expect(Requests.skippedLabel([]) == nil)
    }

    @Test func markRequestedUpdatesOnlyThatCard() {
        let marked = Requests.markRequested([result("a"), result("b")], key: "b")
        #expect(marked[0].requested == nil)
        #expect(Requests.cardAction(marked[1]) == .requested(byMe: true))
    }

    // MARK: Status

    @Test func statusLabels() {
        #expect(Requests.statusLine(request(status: "pending")) == "Pending")
        #expect(Requests.statusLine(request(status: "approved")) == "Approved — downloading")
        #expect(Requests.statusLine(request(status: "available")) == "Available")
        #expect(Requests.statusLine(request(status: "declined")) == "Declined")
        #expect(Requests.statusLine(modified(request(status: "declined")) { $0.declineReason = "Not licensed in English." }) == "Declined: Not licensed in English.")
        #expect(Requests.status(request(status: "weird")) == .unknown)
    }

    @Test func pendingCountAndOpen() {
        let mine = [request(1, "pending"), request(2, "pending"), request(3, "declined")]
        #expect(Requests.pendingLabel(mine, limit: 5) == "2 of 5 pending")
        #expect(Requests.pendingLabel(mine, limit: nil) == nil)
        #expect(Requests.pendingLabel([request(3, "declined"), request(4, "available")], limit: 5) == nil)
        #expect(Requests.pendingLabel([], limit: 5) == nil)
        #expect(Requests.pendingLabel([modified(request(5, "pending")) { $0.mine = "follower" }], limit: 5) == nil)
        #expect(Requests.pendingLabel([request(6, "pending"), modified(request(7, "pending")) { $0.mine = "follower" }], limit: 5) == "1 of 5 pending")
        #expect(Requests.openableNovel(modified(request(status: "available")) { $0.novelId = 9 }) == 9)
        #expect(Requests.openableNovel(request(status: "available")) == nil)
        #expect(Requests.openableNovel(modified(request(status: "declined")) { $0.novelId = 9 }) == nil)
    }

    // MARK: Review

    @Test func reviewQueueIsPendingOldestFirst() {
        let list = [
            request(3, "pending", "2026-11-19T10:00:00+00:00"),
            request(2, "approved", "2026-11-18T10:00:00+00:00"),
            request(1, "pending", "2026-11-17T10:00:00+00:00"),
            request(4, "pending", "2026-11-19T10:00:00+00:00"),
            request(5, "pending", nil),
        ]
        #expect(Requests.reviewQueue(list).map(\.id) == [1, 3, 4, 5])
    }

    @Test func shortlistPutsFailedCandidatesLastAndPreselectsTheBest() {
        let candidates = [cand(1, error: "timed out"), cand(0), cand(2)]
        #expect(Requests.shortlist(candidates).map(\.index) == [0, 2, 1])
        #expect(Requests.selectedCandidate(candidates, chosen: nil) == 0)
        #expect(Requests.selectedCandidate(candidates, chosen: 2) == 2)
        #expect(Requests.selectedCandidate(candidates, chosen: 1) == 0)
        #expect(Requests.selectedCandidate(candidates, chosen: 9) == 0)
        #expect(Requests.selectedCandidate([cand(0, error: "x")], chosen: nil) == nil)
        #expect(Requests.shortlist([RequestCandidate(sourceName: "A")]).isEmpty)
    }

    @Test func urlFieldAndRanking() {
        let freeText = request(status: "pending")
        #expect(Requests.needsURL(freeText))
        #expect(Requests.needsURL(modified(freeText) { $0.candidates = [cand(0, error: "down")] }))
        #expect(!Requests.needsURL(modified(freeText) { $0.candidates = [cand(0)] }))
        let manga = modified(freeText) { $0.medium = "manga"; $0.candidates = [cand(0)] }
        #expect(Requests.rankingPending(manga))
        #expect(!Requests.rankingPending(modified(manga) { $0.candidatesRankedAt = "2026-11-19T10:00:00+00:00" }))
        #expect(!Requests.rankingPending(modified(manga) { $0.medium = "novel" }))
        #expect(!Requests.rankingPending(modified(manga) { $0.candidates = [] }))
        #expect(Requests.decidesElsewhere(isAdmin: true, canDecide: false))
        #expect(!Requests.decidesElsewhere(isAdmin: true, canDecide: true))
        #expect(!Requests.decidesElsewhere(isAdmin: false, canDecide: false))
    }

    @Test func candidateDetailsLine() {
        let utc = TimeZone(identifier: "UTC")!
        let c = modified(cand(0)) {
            $0.chaptersAvailable = 120; $0.highestChapter = "121.0"; $0.gaps = 2; $0.languages = ["en", "es"]
            $0.updatedAt = "2026-11-03T23:30:00+00:00"
        }
        #expect(Requests.candidateDetails(c, zone: utc) == "120 chapters · up to 121 · 2 gaps · EN, ES · updated 3 Nov 2026")
        #expect(Requests.candidateDetails(modified(cand(0)) { $0.chapters = 1; $0.gaps = 0 }, zone: utc) == "1 chapter")
        #expect(Requests.candidateDetails(cand(0), zone: utc) == "")
    }

    // MARK: Retry-After

    @Test func retryAfterSecondsOrDate() {
        let now = Date(timeIntervalSince1970: 1_763_550_000) // 2025-11-19T11:00:00Z
        #expect(RetryAfter.seconds("30", now: now) == 30)
        #expect(RetryAfter.seconds(nil, now: now) == RetryAfter.defaultSeconds)
        #expect(RetryAfter.seconds("soon", now: now) == RetryAfter.defaultSeconds)
        #expect(RetryAfter.seconds("0", now: now) == 1)
        #expect(RetryAfter.seconds("999999", now: now) == RetryAfter.maxSeconds)
        #expect(RetryAfter.seconds("Wed, 19 Nov 2025 11:01:30 GMT", now: now) == 90)
        #expect(RetryAfter.seconds("Wed, 19 Nov 2025 10:00:00 GMT", now: now) == 1)
        #expect(RetryAfter.seconds(TimeInterval?.none) == RetryAfter.defaultSeconds)
        #expect(RetryAfter.seconds(TimeInterval(30)) == 30)
        #expect(RetryAfter.remaining(nil, now: 100) == 0)
        #expect(RetryAfter.remaining(100, now: 100) == 0)
        #expect(RetryAfter.remaining(101.5, now: 100) == 2)
    }

    // MARK: The feed

    @Test func feedSkipsItemsAlreadyHandled() {
        let items = [item(12), item(11), item(12), item(9)]
        #expect(RequestFeed.fresh(items, lastId: nil).map(\.id) == [9, 11, 12])
        #expect(RequestFeed.fresh(items, lastId: 10).map(\.id) == [11, 12])
        #expect(RequestFeed.fresh(items, lastId: 12).isEmpty)
        #expect(RequestFeed.lastId(10, items) == 12)
        #expect(RequestFeed.lastId(20, items) == 20)
        #expect(RequestFeed.lastId(nil, []) == nil)
    }

    @Test func feedTargets() {
        #expect(RequestFeed.target(item(1, RequestFeed.kindNew)) == .init(section: .review))
        #expect(RequestFeed.target(item(2, RequestFeed.kindAvailable, novelId: 42)) == .init(section: .mine, novelId: 42))
        #expect(RequestFeed.target(item(3, RequestFeed.kindAvailable)) == .init(section: .mine))
        #expect(RequestFeed.target(item(4, RequestFeed.kindDeclined, novelId: 42)) == .init(section: .mine))
        #expect(RequestFeed.target(item(5, RequestFeed.kindApproved)) == .init(section: .mine))
        #expect(RequestFeed.target([item(1, RequestFeed.kindApproved), item(2, RequestFeed.kindNew)]) == .init(section: .review))
        #expect(RequestFeed.target([item(1, RequestFeed.kindApproved)]) == .init(section: .mine))
        #expect(!RequestFeed.known(item(6, "request_something_new")))
        #expect(RequestFeed.known(item(6, RequestFeed.kindDeclined)))
        #expect(RequestsRoute.Section.from("REVIEW") == .review)
        #expect(RequestsRoute.Section.from(nil) == .search)
    }

    @Test func notificationIdsAreDistinctAndStable() {
        #expect(RequestFeed.notificationId(11) == "request-11")
        #expect(RequestFeed.notificationId(11) != RequestFeed.notificationId(12))
        #expect(RequestFeed.summaryTitle(3) == "3 request updates")
        #expect(RequestFeed.summaryTitle(1) == "1 request update")
    }

    // MARK: The background schedule

    @Test func intervalIsTheShortestEnabledPeriod() {
        let hour = BackgroundSchedule.hour
        let chapters = BackgroundSchedule.Spec(key: "newChapters", enabled: true, period: 2 * hour, lastRun: nil)
        let requests = BackgroundSchedule.Spec(key: "requests", enabled: true, period: hour, lastRun: nil)
        #expect(BackgroundSchedule.interval([chapters, requests]) == hour)
        #expect(BackgroundSchedule.interval([chapters, modified(requests) { $0.enabled = false }]) == 2 * hour)
        #expect(BackgroundSchedule.interval([modified(chapters) { $0.enabled = false }, modified(requests) { $0.enabled = false }]) == nil)
    }

    @Test func aLongerCheckKeepsItsSpacing() {
        let hour = BackgroundSchedule.hour
        let now = Date(timeIntervalSince1970: 100 * hour)
        func specs(_ slowLast: Date?, _ fastLast: Date?) -> [BackgroundSchedule.Spec] {
            [.init(key: "update", enabled: true, period: 12 * hour, lastRun: slowLast),
             .init(key: "requests", enabled: true, period: hour, lastRun: fastLast)]
        }
        #expect(BackgroundSchedule.dueChecks(specs(nil, nil), now: now) == ["update", "requests"])
        #expect(BackgroundSchedule.dueChecks(specs(now - 2 * hour, now - hour), now: now) == ["requests"])
        #expect(BackgroundSchedule.dueChecks(specs(now - 2 * hour, now - 40 * 60), now: now) == ["requests"])
        #expect(BackgroundSchedule.dueChecks(specs(now - 2 * hour, now - 10 * 60), now: now) == [])
        #expect(BackgroundSchedule.dueChecks(specs(now - 11.5 * hour, now - hour), now: now) == ["update", "requests"])
        #expect(BackgroundSchedule.dueChecks(specs(now - 11 * hour, now - hour), now: now) == ["requests"])
        #expect(BackgroundSchedule.dueChecks(specs(now + hour, now + hour), now: now) == ["update", "requests"])
        let slowOnly: [BackgroundSchedule.Spec] = [.init(key: "update", enabled: true, period: 12 * hour, lastRun: now - 7 * hour),
                                                   .init(key: "requests", enabled: false, period: hour, lastRun: nil)]
        #expect(BackgroundSchedule.dueChecks(slowOnly, now: now) == ["update"])
    }

    // MARK: Fixtures

    private func result(_ key: String = "g1") -> SearchResult { SearchResult(key: key, title: "Solo Leveling") }

    private func request(_ id: Int = 1, _ status: String = "pending", _ createdAt: String? = nil) -> ReaderRequest {
        ReaderRequest(id: id, title: "Series \(id)", status: status, createdAt: createdAt)
    }

    private func request(status: String) -> ReaderRequest { request(1, status) }

    private func cand(_ index: Int, error: String? = nil) -> RequestCandidate {
        RequestCandidate(index: index, sourceName: "Source \(index)", error: error)
    }

    private func item(_ id: Int64, _ kind: String = RequestFeed.kindApproved, novelId: Int? = nil) -> FeedItem {
        FeedItem(id: id, kind: kind, novelId: novelId)
    }

    static let doneSearch = """
    {"search":{"id":"abc","status":"done","message":null,"query":"solo","medium":"manga",
     "results":[
       {"key":"g1","title":"Solo Leveling","author":"Chugong","cover_url":"https://host/requests/cover?u=x&signature=y",
        "description":"E-rank hunter.","medium":"manga",
        "sources":[{"source":"mangadex","source_name":"MangaDex","chapters":179,"status":"completed"},
                   {"source":"mangasee","source_name":"MangaSee","chapters":null,"status":null}],
        "in_library":false,"novel_id":null,"requested":null},
       {"key":"g2","title":"Solo Max-Level Newbie","author":null,"cover_url":null,"description":null,"medium":"manga",
        "sources":[],"in_library":false,"novel_id":null,"requested":{"status":"pending","by_me":true}},
       {"key":"g3","title":"Solo Farming","medium":"manga","sources":[{"source":"comick"}],"in_library":true,"novel_id":42,"requested":null},
       {"title":"no key: skipped"}
     ],
     "skipped_sources":[{"source":"comick","source_name":"Comick","reason":"timeout"}]}}
    """

    static let readerPage = """
    {"requests":[{"id":1,"title":"Solo Leveling","author":"Chugong","cover_url":null,"description":"d","medium":"manga",
      "note":"Translated by X","link":null,"status":"pending","decline_reason":null,"requested_by":{"id":3,"name":"Ana"},
      "novel_id":null,"created_at":"2026-11-19T08:00:00+00:00","decided_at":null,"available_at":null,
      "candidates":["Fake B (manga)","Fake A (manga)"],"candidates_ranked_at":null,"can_decide":false,"can_cancel":true}],
     "page":1,"has_more":false,"can_decide":false,"pending_limit":5}
    """

    static let adminPage = """
    {"requests":[{"id":1,"title":"Solo Leveling","medium":"manga","status":"pending","requested_by":{"id":3,"name":"Ana"},
      "candidates":[
        {"index":1,"source":"fake_b","source_name":"Fake B (manga)","url":"https://b.test/solo","title":"Solo Leveling","chapters":120,
         "status":"ongoing","languages":["en","es"],"chapters_available":120,"highest_chapter":121.5,"gaps":0,
         "updated_at":"2026-11-18T09:00:00+00:00","error":null,"reason":"Most chapters, updated recently"},
        {"index":0,"source":"fake_a","source_name":"Fake A (manga)","url":"https://a.test/solo","languages":[],"highest_chapter":"98",
         "error":null,"reason":"Fewer chapters"},
        {"index":2,"source":"fake_c","source_name":"Fake C (manga)","url":"https://c.test/solo","error":"Source timed out","reason":null}],
      "candidates_ranked_at":"2026-11-18T10:00:00+00:00","can_decide":true,"can_cancel":true}],
     "page":1,"has_more":false,"can_decide":true,"pending_limit":null}
    """

    static let feed = """
    {"cursor":"cm4xOjEy","has_more":false,"items":[
      {"id":11,"kind":"request_new","title":"New request","body":"Ana requested Solo Leveling (manga).","request_id":5,"novel_id":null,"at":"2026-11-19T08:00:00+00:00"},
      {"id":12,"kind":"request_available","title":"Request available","body":"Solo Leveling is in the library.","request_id":5,"novel_id":42,"at":"2026-11-19T09:00:00+00:00"}]}
    """
}

/// A copy of `value` changed by `change` (Kotlin's `copy`).
func modified<T>(_ value: T, _ change: (inout T) -> Void) -> T {
    var copy = value
    change(&copy)
    return copy
}

final class RequestsCallCounter: @unchecked Sendable {
    private let lock = NSLock()
    private var count = 0
    var value: Int { lock.withLock { count } }
    func bump() { lock.withLock { count += 1 } }
}
