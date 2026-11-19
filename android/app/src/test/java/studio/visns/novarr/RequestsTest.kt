package studio.visns.novarr

import java.time.ZoneOffset
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Protocol
import okhttp3.Request
import okhttp3.Response
import okhttp3.ResponseBody.Companion.toResponseBody
import okio.Buffer
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.ApproveResult
import studio.visns.novarr.api.FeedItem
import studio.visns.novarr.api.NotificationFeed
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.ReaderRequest
import studio.visns.novarr.api.RequestCandidate
import studio.visns.novarr.api.RequestEnvelope
import studio.visns.novarr.api.RequestsPage
import studio.visns.novarr.api.ResultRequested
import studio.visns.novarr.api.ResultSource
import studio.visns.novarr.api.SearchEnvelope
import studio.visns.novarr.api.SearchResult
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.domain.BackgroundSchedule
import studio.visns.novarr.domain.BackgroundSchedule.Spec
import studio.visns.novarr.domain.RequestFeed
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.domain.Requests.CardAction
import studio.visns.novarr.domain.Requests.Section
import studio.visns.novarr.domain.Requests.Status
import studio.visns.novarr.domain.RetryAfter

/** Fixtures follow RequestPresenter / RequestFeed and tests/Feature/ReaderRequestsTest.php. */
class RequestsTest {
    // Decoding

    @Test
    fun metaSaysWhetherRequestsAreOffered() {
        val on = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"auth":{"required":true},"requests":true}""")
        assertTrue(on.requests)
        val off = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"requests":false}""")
        assertFalse(off.requests)
        // An older server has no key: no requests.
        val old = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"server_version":"28da68d"}""")
        assertFalse(old.requests)
        val junk = NovarrJson.decodeFromString(ServerMeta.serializer(), """{"app":"novarr","api_version":1,"requests":null}""")
        assertFalse(junk.requests)
    }

    @Test
    fun runningSearchHasNoResults() {
        val search = NovarrJson.decodeFromString(
            SearchEnvelope.serializer(),
            """{"search":{"id":"abc123","status":"running","message":null,"query":"solo","medium":"manga","results":null,"skipped_sources":[]}}""",
        ).search
        assertTrue(search.isRunning)
        assertEquals("abc123", search.id)
        assertEquals(emptyList<SearchResult>(), search.results)
    }

    @Test
    fun doneSearchWithResultsAndSkippedSources() {
        val search = NovarrJson.decodeFromString(SearchEnvelope.serializer(), DONE_SEARCH).search
        assertTrue(search.isDone)
        assertEquals(3, search.results.size)
        val first = search.results[0]
        assertEquals("g1", first.key)
        assertEquals("Solo Leveling", first.title)
        assertEquals("https://host/requests/cover?u=x&signature=y", first.coverUrl)
        assertEquals(listOf("MangaDex", "MangaSee"), first.sources.map { it.sourceName })
        assertEquals(179, first.sources[0].chapters)
        assertNull(first.requested)
        assertEquals(ResultRequested("pending", byMe = true), search.results[1].requested)
        assertTrue(search.results[2].inLibrary)
        assertEquals(42, search.results[2].novelId)
        assertEquals(listOf("Comick"), search.skippedSources.map { it.sourceName })
        assertEquals("timeout", search.skippedSources[0].reason)
    }

    @Test
    fun failedSearchCarriesItsMessage() {
        val search = NovarrJson.decodeFromString(
            SearchEnvelope.serializer(),
            """{"search":{"id":"z","status":"failed","message":"The search took too long.","query":"q","medium":"novel","results":null,"skipped_sources":[]}}""",
        ).search
        assertTrue(search.isFailed)
        assertEquals("The search took too long.", search.message)
    }

    @Test
    fun readerViewCandidatesAreSourceNames() {
        val page = NovarrJson.decodeFromString(RequestsPage.serializer(), READER_PAGE)
        assertEquals(5, page.pendingLimit)
        assertFalse(page.canDecide)
        val request = page.requests.single()
        assertEquals(listOf("Fake B (manga)", "Fake A (manga)"), request.candidates.map { it.sourceName })
        assertTrue(request.candidates.none { it.isDetailed })
        assertTrue(request.canCancel)
        assertEquals("Ana", request.requestedBy?.name)
        assertEquals("Translated by X", request.note)
    }

    @Test
    fun adminViewCandidatesAreTheRankedShortlist() {
        val page = NovarrJson.decodeFromString(RequestsPage.serializer(), ADMIN_PAGE)
        assertTrue(page.canDecide)
        assertNull(page.pendingLimit)
        val request = page.requests.single()
        assertEquals(listOf(1, 0, 2), request.candidates.map { it.index })
        val best = request.candidates[0]
        assertEquals("Fake B (manga)", best.sourceName)
        assertEquals(120, best.chaptersAvailable)
        // A number or a string: both read as text.
        assertEquals("121.5", best.highestChapter)
        assertEquals(listOf("en", "es"), best.languages)
        assertEquals("Most chapters, updated recently", best.reason)
        assertEquals("Source timed out", request.candidates[2].error)
        assertEquals("2026-11-18T10:00:00+00:00", request.candidatesRankedAt)
    }

    @Test
    fun candidatesInAnUnexpectedShapeAreSkipped() {
        val request = NovarrJson.decodeFromString(
            ReaderRequest.serializer(),
            """{"id":1,"candidates":["A",null,7,{"index":0,"source_name":"B"},[1],{"index":"x"}]}""",
        )
        // Strings and objects are kept; null, arrays and an object that doesn't decode are skipped.
        assertEquals(listOf("A", "7", "B"), request.candidates.map { it.sourceName })
        assertEquals(listOf(null, null, 0), request.candidates.map { it.index })
        val notAList = NovarrJson.decodeFromString(ReaderRequest.serializer(), """{"id":1,"candidates":"nope"}""")
        assertEquals(emptyList<RequestCandidate>(), notAList.candidates)
    }

    @Test
    fun freeTextRequestAndApproveResult() {
        val created = NovarrJson.decodeFromString(
            RequestEnvelope.serializer(),
            """{"request":{"id":9,"title":"The Obscure One","medium":"novel","link":"https://somewhere.example/obscure","note":"Translated by X","status":"pending","candidates":[],"cover_url":null,"can_decide":false,"can_cancel":true,"requested_by":{"id":3,"name":"Ana"},"created_at":"2026-11-19T08:00:00+00:00"}}""",
        ).request
        assertEquals("https://somewhere.example/obscure", created.link)
        assertTrue(created.candidates.isEmpty())
        val approved = NovarrJson.decodeFromString(
            ApproveResult.serializer(),
            """{"request":{"id":9,"status":"approved","novel_id":77},"novel_id":77,"created":true}""",
        )
        assertEquals(77, approved.novelId)
        assertTrue(approved.created)
        assertEquals("approved", approved.request?.status)
    }

    @Test
    fun notificationFeed() {
        val feed = NovarrJson.decodeFromString(NotificationFeed.serializer(), FEED)
        assertEquals("cm4xOjEy", feed.cursor)
        assertFalse(feed.hasMore)
        assertEquals(listOf(11L, 12L), feed.items.map { it.id })
        assertEquals(RequestFeed.KIND_NEW, feed.items[0].kind)
        assertEquals(5, feed.items[0].requestId)
        assertEquals(42, feed.items[1].novelId)
        // A broken item is skipped, the rest kept.
        val broken = NovarrJson.decodeFromString(NotificationFeed.serializer(), """{"cursor":"c","items":[{"kind":"x"},{"id":3,"kind":"request_declined"}]}""")
        assertEquals(listOf(3L), broken.items.map { it.id })
    }

    // Errors and the client

    @Test
    fun refusalsCarryCodeRetryAfterAndExtraFields() = runTest {
        val server = HeaderServer { 429 to Triple("""{"message":"Search is busy right now. Try again in a moment.","code":"search_busy"}""", "Retry-After", "30") }
        val client = ApiClient("http://host", server.client, token = "tok")
        try {
            client.startRequestSearch("solo", "manga")
            fail("expected 429")
        } catch (e: ApiError.Http) {
            assertEquals(429, e.status)
            assertEquals("search_busy", e.code)
            assertEquals("30", e.retryAfter)
            assertEquals("Search is busy right now. Try again in a moment.", Requests.message(e))
        }
        val body = NovarrJson.parseToJsonElement(server.bodies.single()).jsonObject
        assertEquals("solo", body["q"]?.jsonPrimitive?.content)
        assertEquals("manga", body["medium"]?.jsonPrimitive?.content)
        assertEquals("Bearer tok", server.requests.single().header("Authorization"))

        server.respond = { 409 to Triple("""{"message":"This series is already in the library.","code":"in_library","novel_id":42}""", "X", "") }
        try {
            client.createRequest("s1", "g1", "please")
            fail("expected 409")
        } catch (e: ApiError.Http) {
            assertEquals("in_library", Requests.code(e))
            assertEquals("42", e.body?.get("novel_id")?.jsonPrimitive?.content)
            assertEquals("This series is already in the library.", Requests.message(e))
        }
    }

    @Test
    fun requestBodiesAndPaths() = runTest {
        val server = HeaderServer { 200 to Triple("""{"request":{"id":1},"novel_id":5,"created":false,"requests":[],"cursor":"c","items":[]}""", "X", "") }
        val client = ApiClient("http://host", server.client, token = "tok")
        client.approveRequest(3, candidate = 1, force = true)
        client.approveRequest(4, url = "https://manga-a.test/obscure", title = "Obscure")
        client.declineRequest(5, null)
        client.requests("all", status = "pending", page = 2)
        client.notifications("cm4xOjEy")
        client.notifications(null)
        assertEquals("""{"candidate":1,"force":true}""", server.bodies[0])
        assertEquals("""{"url":"https://manga-a.test/obscure","title":"Obscure"}""", server.bodies[1])
        assertEquals("{}", server.bodies[2])
        assertEquals("/api/v1/requests/3/approve", server.requests[0].url.encodedPath)
        assertEquals("scope=all&status=pending&page=2", server.requests[3].url.encodedQuery)
        assertEquals("since=cm4xOjEy", server.requests[4].url.encodedQuery)
        assertNull(server.requests[5].url.encodedQuery)
    }

    @Test
    fun backgroundFeedPollDoesNotEndTheSessionOn401() = runTest {
        var rejected = 0
        val server = HeaderServer { 401 to Triple("""{"message":"Unauthenticated."}""", "X", "") }
        val client = ApiClient("http://host", server.client, token = "tok") { rejected++ }
        runCatching { client.notifications("c", reportUnauthorized = false) }
        assertEquals(0, rejected)
        runCatching { client.notifications("c") }
        assertEquals(1, rejected)
    }

    // Search cards

    @Test
    fun cardActionFromAResult() {
        val plain = result()
        assertEquals(CardAction.Request, Requests.cardAction(plain))
        assertEquals(CardAction.InLibrary(42), Requests.cardAction(plain.copy(inLibrary = true, novelId = 42)))
        assertEquals(CardAction.Requested(byMe = true), Requests.cardAction(plain.copy(requested = ResultRequested("pending", true))))
        assertEquals(CardAction.Requested(byMe = false), Requests.cardAction(plain.copy(requested = ResultRequested("approved", false))))
        // In the library wins over an open request.
        assertEquals(CardAction.InLibrary(7), Requests.cardAction(plain.copy(inLibrary = true, novelId = 7, requested = ResultRequested("approved", true))))
        assertEquals("Requested by you", Requests.cardActionLabel(CardAction.Requested(true)))
        assertEquals("Requested", Requests.cardActionLabel(CardAction.Requested(false)))
        assertEquals("In library", Requests.cardActionLabel(CardAction.InLibrary(null)))
        assertEquals("Request", Requests.cardActionLabel(CardAction.Request))
    }

    @Test
    fun sourcesAndSkippedLabels() {
        val sources = listOf("MangaDex", "MangaSee", "Comick", "MangaDex", "Bato").map { ResultSource(it.lowercase(), it) }
        assertEquals("On MangaDex, MangaSee +2", Requests.sourcesLabel(sources))
        assertEquals("On MangaDex", Requests.sourcesLabel(sources.take(1)))
        assertEquals("On fallback", Requests.sourcesLabel(listOf(ResultSource("fallback", ""))))
        assertEquals("", Requests.sourcesLabel(emptyList()))
        assertEquals("Couldn't reach: Comick, Bato.", Requests.skippedLabel(listOf("Comick", "Bato", "Comick")))
        assertNull(Requests.skippedLabel(emptyList()))
    }

    @Test
    fun markRequestedUpdatesOnlyThatCard() {
        val results = listOf(result("a"), result("b"))
        val marked = Requests.markRequested(results, "b")
        assertNull(marked[0].requested)
        assertEquals(CardAction.Requested(byMe = true), Requests.cardAction(marked[1]))
    }

    // Status

    @Test
    fun statusLabels() {
        assertEquals("Pending", Requests.statusLine(request(status = "pending")))
        assertEquals("Approved — downloading", Requests.statusLine(request(status = "approved")))
        assertEquals("Available", Requests.statusLine(request(status = "available")))
        assertEquals("Declined", Requests.statusLine(request(status = "declined")))
        assertEquals("Declined: Not licensed in English.", Requests.statusLine(request(status = "declined").copy(declineReason = "Not licensed in English.")))
        assertEquals(Status.UNKNOWN, Requests.status(request(status = "weird")))
    }

    @Test
    fun pendingCountAndOpen() {
        val mine = listOf(request(1, "pending"), request(2, "pending"), request(3, "declined"))
        assertEquals("2 of 5 pending", Requests.pendingLabel(mine, 5))
        assertNull(Requests.pendingLabel(mine, null))
        assertEquals(9, Requests.openableNovel(request(status = "available").copy(novelId = 9)))
        assertNull(Requests.openableNovel(request(status = "available")))
        assertNull(Requests.openableNovel(request(status = "declined").copy(novelId = 9)))
    }

    // Review

    @Test
    fun reviewQueueIsPendingOldestFirst() {
        val list = listOf(
            request(3, "pending", "2026-11-19T10:00:00+00:00"),
            request(2, "approved", "2026-11-18T10:00:00+00:00"),
            request(1, "pending", "2026-11-17T10:00:00+00:00"),
            request(4, "pending", "2026-11-19T10:00:00+00:00"),
            request(5, "pending", null),
        )
        assertEquals(listOf(1, 3, 4, 5), Requests.reviewQueue(list).map { it.id })
    }

    @Test
    fun shortlistPutsFailedCandidatesLastAndPreselectsTheBest() {
        val candidates = listOf(cand(1, error = "timed out"), cand(0), cand(2))
        assertEquals(listOf(0, 2, 1), Requests.shortlist(candidates).map { it.index })
        assertEquals(0, Requests.selectedCandidate(candidates, null))
        assertEquals(2, Requests.selectedCandidate(candidates, 2))
        // A choice that can't be made (failed, or gone) falls back to the best one.
        assertEquals(0, Requests.selectedCandidate(candidates, 1))
        assertEquals(0, Requests.selectedCandidate(candidates, 9))
        assertNull(Requests.selectedCandidate(listOf(cand(0, error = "x")), null))
        // Bare names (the reader view) are no shortlist.
        assertTrue(Requests.shortlist(listOf(RequestCandidate(sourceName = "A"))).isEmpty())
    }

    @Test
    fun urlFieldAndRanking() {
        val freeText = request(status = "pending")
        assertTrue(Requests.needsUrl(freeText))
        assertTrue(Requests.needsUrl(freeText.copy(candidates = listOf(cand(0, error = "down")))))
        assertFalse(Requests.needsUrl(freeText.copy(candidates = listOf(cand(0)))))
        val manga = freeText.copy(medium = "manga", candidates = listOf(cand(0)))
        assertTrue(Requests.rankingPending(manga))
        assertFalse(Requests.rankingPending(manga.copy(candidatesRankedAt = "2026-11-19T10:00:00+00:00")))
        assertFalse(Requests.rankingPending(manga.copy(medium = "novel")))
        assertFalse(Requests.rankingPending(manga.copy(candidates = emptyList())))
        assertTrue(Requests.decidesElsewhere(isAdmin = true, canDecide = false))
        assertFalse(Requests.decidesElsewhere(isAdmin = true, canDecide = true))
        assertFalse(Requests.decidesElsewhere(isAdmin = false, canDecide = false))
    }

    @Test
    fun candidateDetailsLine() {
        val c = cand(0).copy(chaptersAvailable = 120, highestChapter = "121.0", gaps = 2, languages = listOf("en", "es"), updatedAt = "2026-11-03T23:30:00+00:00")
        assertEquals("120 chapters · up to 121 · 2 gaps · EN, ES · updated 3 Nov 2026", Requests.candidateDetails(c, ZoneOffset.UTC))
        assertEquals("1 chapter", Requests.candidateDetails(cand(0).copy(chapters = 1, gaps = 0), ZoneOffset.UTC))
        assertEquals("", Requests.candidateDetails(cand(0), ZoneOffset.UTC))
    }

    // Retry-After

    @Test
    fun retryAfterSecondsOrDate() {
        val now = 1_763_550_000_000L // 2025-11-19T11:00:00Z
        assertEquals(30, RetryAfter.seconds("30", now))
        assertEquals(RetryAfter.DEFAULT_SECONDS, RetryAfter.seconds(null, now))
        assertEquals(RetryAfter.DEFAULT_SECONDS, RetryAfter.seconds("soon", now))
        assertEquals(1, RetryAfter.seconds("0", now))
        assertEquals(RetryAfter.MAX_SECONDS, RetryAfter.seconds("999999", now))
        assertEquals(90, RetryAfter.seconds("Wed, 19 Nov 2025 11:01:30 GMT", now))
        // A date already past still waits a second.
        assertEquals(1, RetryAfter.seconds("Wed, 19 Nov 2025 10:00:00 GMT", now))
        assertEquals(0, RetryAfter.remaining(null, now))
        assertEquals(0, RetryAfter.remaining(now, now))
        assertEquals(2, RetryAfter.remaining(now + 1_500, now))
    }

    // The feed

    @Test
    fun feedSkipsItemsAlreadyHandled() {
        val items = listOf(item(12), item(11), item(12), item(9))
        assertEquals(listOf(9L, 11L, 12L), RequestFeed.fresh(items, null).map { it.id })
        assertEquals(listOf(11L, 12L), RequestFeed.fresh(items, 10).map { it.id })
        assertTrue(RequestFeed.fresh(items, 12).isEmpty())
        assertEquals(12L, RequestFeed.lastId(10, items))
        // Never moves back.
        assertEquals(20L, RequestFeed.lastId(20, items))
        assertNull(RequestFeed.lastId(null, emptyList()))
    }

    @Test
    fun feedTargets() {
        assertEquals(RequestFeed.Target(Section.REVIEW), RequestFeed.target(item(1, RequestFeed.KIND_NEW)))
        assertEquals(RequestFeed.Target(Section.MINE, 42), RequestFeed.target(item(2, RequestFeed.KIND_AVAILABLE, novelId = 42)))
        assertEquals(RequestFeed.Target(Section.MINE), RequestFeed.target(item(3, RequestFeed.KIND_AVAILABLE)))
        assertEquals(RequestFeed.Target(Section.MINE), RequestFeed.target(item(4, RequestFeed.KIND_DECLINED, novelId = 42)))
        assertEquals(RequestFeed.Target(Section.MINE), RequestFeed.target(item(5, RequestFeed.KIND_APPROVED)))
        assertEquals(RequestFeed.Target(Section.REVIEW), RequestFeed.target(listOf(item(1, RequestFeed.KIND_APPROVED), item(2, RequestFeed.KIND_NEW))))
        assertEquals(RequestFeed.Target(Section.MINE), RequestFeed.target(listOf(item(1, RequestFeed.KIND_APPROVED))))
        assertFalse(RequestFeed.known(item(6, "request_something_new")))
        assertTrue(RequestFeed.known(item(6, RequestFeed.KIND_DECLINED)))
        assertEquals(Section.REVIEW, Section.from("REVIEW"))
        assertEquals(Section.SEARCH, Section.from(null))
    }

    @Test
    fun notificationIdsAreDistinctAndStable() {
        assertEquals(RequestFeed.NOTIFICATION_BASE + 11, RequestFeed.notificationId(11))
        assertTrue(RequestFeed.notificationId(11) != RequestFeed.notificationId(12))
        assertTrue(RequestFeed.notificationId(5_000_000_123L) >= RequestFeed.NOTIFICATION_BASE)
        assertEquals("3 request updates", RequestFeed.summaryTitle(3))
    }

    // The worker's schedule

    @Test
    fun workerIntervalIsTheShortestEnabledPeriod() {
        val update = Spec("update", true, 12 * HOUR, null)
        val requests = Spec("requests", true, HOUR, null)
        assertEquals(HOUR, BackgroundSchedule.intervalMs(listOf(update, requests)))
        assertEquals(12 * HOUR, BackgroundSchedule.intervalMs(listOf(update, requests.copy(enabled = false))))
        assertNull(BackgroundSchedule.intervalMs(listOf(update.copy(enabled = false), requests.copy(enabled = false))))
    }

    @Test
    fun updateCheckKeepsItsTwelveHourSpacingInAnHourlyWorker() {
        val now = 100 * HOUR
        val specs = { updateLast: Long?, requestsLast: Long? ->
            listOf(Spec("update", true, 12 * HOUR, updateLast), Spec("requests", true, HOUR, requestsLast))
        }
        // Never run: both.
        assertEquals(listOf("update", "requests"), BackgroundSchedule.dueChecks(specs(null, null), now))
        // Update ran 2 hours ago: only requests.
        assertEquals(listOf("requests"), BackgroundSchedule.dueChecks(specs(now - 2 * HOUR, now - HOUR), now))
        // Periodic work wanders: 40 minutes after the last requests run still counts as the next hour.
        assertEquals(listOf("requests"), BackgroundSchedule.dueChecks(specs(now - 2 * HOUR, now - 40 * MINUTE), now))
        assertEquals(emptyList<String>(), BackgroundSchedule.dueChecks(specs(now - 2 * HOUR, now - 10 * MINUTE), now))
        // 11.5 hours on: the update check is due again.
        assertEquals(listOf("update", "requests"), BackgroundSchedule.dueChecks(specs(now - 11 * HOUR - 30 * MINUTE, now - HOUR), now))
        assertEquals(listOf("requests"), BackgroundSchedule.dueChecks(specs(now - 11 * HOUR, now - HOUR), now))
        // The clock went back: due.
        assertEquals(listOf("update", "requests"), BackgroundSchedule.dueChecks(specs(now + HOUR, now + HOUR), now))
        // Requests off: a 12-hour worker runs the update check every time.
        val updateOnly = listOf(Spec("update", true, 12 * HOUR, now - 7 * HOUR), Spec("requests", false, HOUR, null))
        assertEquals(listOf("update"), BackgroundSchedule.dueChecks(updateOnly, now))
    }

    // Fixtures

    private fun result(key: String = "g1") = SearchResult(key = key, title = "Solo Leveling")

    private fun request(id: Int = 1, status: String = "pending", createdAt: String? = null) =
        ReaderRequest(id = id, title = "Series $id", status = status, createdAt = createdAt)

    private fun cand(index: Int, error: String? = null) = RequestCandidate(index = index, sourceName = "Source $index", error = error)

    private fun item(id: Long, kind: String = RequestFeed.KIND_APPROVED, novelId: Int? = null) = FeedItem(id = id, kind = kind, novelId = novelId)

    private class HeaderServer(var respond: (Request) -> Pair<Int, Triple<String, String, String>>) : Interceptor {
        val requests = mutableListOf<Request>()
        val bodies = mutableListOf<String>()

        override fun intercept(chain: Interceptor.Chain): Response {
            val request = chain.request()
            requests += request
            bodies += request.body?.let { body -> Buffer().also { body.writeTo(it) }.readUtf8() }.orEmpty()
            val (code, answer) = respond(request)
            return Response.Builder()
                .request(request)
                .protocol(Protocol.HTTP_1_1)
                .code(code)
                .message("test")
                .header(answer.second, answer.third)
                .body(answer.first.toResponseBody("application/json".toMediaType()))
                .build()
        }

        val client: OkHttpClient get() = OkHttpClient.Builder().addInterceptor(this).build()
    }

    private companion object {
        const val MINUTE = 60_000L
        const val HOUR = 60 * MINUTE

        val DONE_SEARCH = """
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
        """.trimIndent()

        val READER_PAGE = """
            {"requests":[{"id":1,"title":"Solo Leveling","author":"Chugong","cover_url":null,"description":"d","medium":"manga",
              "note":"Translated by X","link":null,"status":"pending","decline_reason":null,"requested_by":{"id":3,"name":"Ana"},
              "novel_id":null,"created_at":"2026-11-19T08:00:00+00:00","decided_at":null,"available_at":null,
              "candidates":["Fake B (manga)","Fake A (manga)"],"candidates_ranked_at":null,"can_decide":false,"can_cancel":true}],
             "page":1,"has_more":false,"can_decide":false,"pending_limit":5}
        """.trimIndent()

        val ADMIN_PAGE = """
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
        """.trimIndent()

        val FEED = """
            {"cursor":"cm4xOjEy","has_more":false,"items":[
              {"id":11,"kind":"request_new","title":"New request","body":"Ana requested Solo Leveling (manga).","request_id":5,"novel_id":null,"at":"2026-11-19T08:00:00+00:00"},
              {"id":12,"kind":"request_available","title":"Request available","body":"Solo Leveling is in the library.","request_id":5,"novel_id":42,"at":"2026-11-19T09:00:00+00:00"}]}
        """.trimIndent()
    }
}
