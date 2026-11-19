package studio.visns.novarr.domain

import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.util.Locale
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.FeedItem
import studio.visns.novarr.api.ReaderRequest
import studio.visns.novarr.api.RequestCandidate
import studio.visns.novarr.api.RequestSearch
import studio.visns.novarr.api.ResultSource
import studio.visns.novarr.api.SearchResult

/**
 * The reader-requests rules the screen and the notifications follow, kept
 * free of Android so they are unit-tested: what a search card offers, the
 * status labels, the review shortlist, Retry-After, and the feed.
 */
object Requests {
    /**
     * The search part's state, kept by the app (per session) so leaving the
     * Requests screen and coming back shows the same search, and a running
     * one is polled again. Times are SystemClock.elapsedRealtime.
     */
    data class SearchMemory(
        val query: String = "",
        val medium: Medium = Medium.NOVEL,
        val search: RequestSearch? = null,
        val startedAt: Long = 0L,
        val blockedUntil: Long? = null,
        val blockedMessage: String? = null,
    )

    /** How often a running search is asked again. */
    const val POLL_MS = 1_500L

    /** After this long a running search shows "some sources are slow". */
    const val SLOW_AFTER_MS = 10_000L

    /** Longest a note or decline reason may be (the server's limit). */
    const val NOTE_MAX = 500

    /** Longest search query (the server's limit). */
    const val QUERY_MAX = 100

    enum class Medium(val wire: String, val title: String, val singular: String) {
        NOVEL("novel", "Novels", "Novel"),
        MANGA("manga", "Manga", "Manga");

        companion object {
            fun from(raw: String?): Medium = entries.firstOrNull { it.wire == raw } ?: NOVEL
        }
    }

    /** The screen's three parts; a notification names the one to open. */
    enum class Section { SEARCH, MINE, REVIEW;

        companion object {
            fun from(raw: String?): Section = entries.firstOrNull { it.name == raw } ?: SEARCH
        }
    }

    // Search cards

    /** The one action a search result's card offers. */
    sealed interface CardAction {
        /** Nobody has asked for it: "Request". */
        data object Request : CardAction

        /** Already in the library: "In library" opens it (when the server said which series). */
        data class InLibrary(val novelId: Int?) : CardAction

        /** Someone else's open request: "Me too" joins it (told when it's ready). */
        data object MeToo : CardAction

        /** An open request exists: "Requested by you" or "Requested", disabled. */
        data class Requested(val byMe: Boolean) : CardAction
    }

    /** The statuses of someone else's request that "Me too" can join. */
    private val JOINABLE = setOf("pending", "approved")

    /**
     * In the library wins over requested (an approved request becomes a
     * series). Someone else's pending or approved request offers "Me too",
     * unless [canJoin] is false: the server turned a "Me too" down as
     * `already_requested` (an older server that can't join requests).
     */
    fun cardAction(result: SearchResult, canJoin: Boolean = true): CardAction {
        val requested = result.requested
        return when {
            result.inLibrary || result.novelId != null -> CardAction.InLibrary(result.novelId)
            requested == null -> CardAction.Request
            !requested.byMe && canJoin && requested.status.lowercase() in JOINABLE -> CardAction.MeToo
            else -> CardAction.Requested(requested.byMe)
        }
    }

    /** The card's button text. */
    fun cardActionLabel(action: CardAction): String = when (action) {
        CardAction.Request -> "Request"
        CardAction.MeToo -> "Me too"
        is CardAction.InLibrary -> "In library"
        is CardAction.Requested -> if (action.byMe) "Requested by you" else "Requested"
    }

    /** The snackbar after "Me too" joined a request. */
    const val JOINED_NOTICE = "You'll be told when it's ready."

    /** "On MangaDex, MangaSee +2": the first [shown] distinct source names; empty with none. */
    fun sourcesLabel(sources: List<ResultSource>, shown: Int = 2): String {
        val names = sources.map { it.sourceName.ifBlank { it.source } }.filter { it.isNotBlank() }.distinct()
        if (names.isEmpty()) return ""
        val more = names.size - shown
        return "On " + names.take(shown).joinToString(", ") + if (more > 0) " +$more" else ""
    }

    /** "Couldn't reach: X, Y." for the sources a search skipped; null when none were. */
    fun skippedLabel(names: List<String>): String? {
        val distinct = names.filter { it.isNotBlank() }.distinct()
        return if (distinct.isEmpty()) null else "Couldn't reach: ${distinct.joinToString(", ")}."
    }

    /**
     * Marks [key] as requested by this account after a successful request
     * (or "Me too"), so its card updates at once; a joined request keeps its
     * status.
     */
    fun markRequested(results: List<SearchResult>, key: String): List<SearchResult> = markTaken(results, key, byMe = true)

    /** Marks [key] as requested ([byMe]: by this account), e.g. after 409 `already_requested`. */
    fun markTaken(results: List<SearchResult>, key: String, byMe: Boolean): List<SearchResult> =
        results.map {
            if (it.key == key) it.copy(requested = studio.visns.novarr.api.ResultRequested(it.requested?.status ?: "pending", byMe)) else it
        }

    // Request status

    enum class Status(val label: String) {
        PENDING("Pending"),
        APPROVED("Approved — downloading"),
        AVAILABLE("Available"),
        DECLINED("Declined"),
        UNKNOWN("Unknown");

        companion object {
            fun from(raw: String?): Status = when (raw?.lowercase()) {
                "pending" -> PENDING
                "approved" -> APPROVED
                "available" -> AVAILABLE
                "declined" -> DECLINED
                else -> UNKNOWN
            }
        }
    }

    fun status(request: ReaderRequest): Status = Status.from(request.status)

    /** "Declined: Not licensed in English." or just "Declined". */
    fun statusLine(request: ReaderRequest): String {
        val status = status(request)
        val reason = request.declineReason?.trim().orEmpty()
        return if (status == Status.DECLINED && reason.isNotEmpty()) "${status.label}: $reason" else status.label
    }

    /**
     * "2 of 5 pending": this account's own pending requests against the
     * limit (joined ones don't count). Null without a limit (the admin) and
     * while none is pending.
     */
    fun pendingLabel(mine: List<ReaderRequest>, limit: Int?): String? {
        if (limit == null || limit <= 0) return null
        val pending = mine.count { !it.isFollower && status(it) == Status.PENDING }
        return if (pending == 0) null else "$pending of $limit pending"
    }

    /** The action on a row of My requests. */
    enum class MineAction { NONE, CANCEL, LEAVE }

    /**
     * Cancel your own pending request (when the server allows). Leave one you
     * joined with "Me too" whenever the server allows (`can_cancel`: pending
     * or approved, not yet available); a server that doesn't say: while
     * pending only. Nothing otherwise.
     */
    fun mineAction(request: ReaderRequest): MineAction {
        val pending = status(request) == Status.PENDING
        return when {
            request.isFollower -> if (request.canCancel ?: pending) MineAction.LEAVE else MineAction.NONE
            pending && request.canCancel == true -> MineAction.CANCEL
            else -> MineAction.NONE
        }
    }

    /**
     * The review card's "Also wanted by Ana, Ben" (the admin view's
     * followers), "Also wanted by Ana and 2 more readers" when the count is
     * higher than the names given, "Also wanted by 3 readers" with only the
     * count; null when nobody joined.
     */
    fun alsoWantedLabel(request: ReaderRequest): String? {
        val names = request.followers.mapNotNull { it.name?.trim()?.takeIf(String::isNotEmpty) }.distinct()
        val count = maxOf(request.alsoWantedBy, request.followers.size)
        if (names.isEmpty()) return if (count > 0) "Also wanted by ${readers(count)}" else null
        val more = count - names.size
        return if (more > 0) "Also wanted by ${names.joinToString(", ")} and ${readers(more, "more ")}"
        else "Also wanted by ${names.joinToString(", ")}"
    }

    private fun readers(n: Int, prefix: String = "") = if (n == 1) "1 ${prefix}reader" else "$n ${prefix}readers"

    /** "Open" goes to the series once it is available (or approved, as soon as it exists). */
    fun openableNovel(request: ReaderRequest): Int? =
        request.novelId?.takeIf { status(request) == Status.AVAILABLE || status(request) == Status.APPROVED }

    // Review (the admin view)

    /** Everyone's pending requests, oldest first (the list comes newest first). */
    fun reviewQueue(requests: List<ReaderRequest>): List<ReaderRequest> =
        requests.filter { status(it) == Status.PENDING }
            .distinctBy { it.id }
            .sortedWith(compareBy<ReaderRequest> { instant(it.createdAt) ?: Instant.MAX }.thenBy { it.id })

    /**
     * The shortlist as shown: the server's order (best first) with the
     * candidates that failed to check moved last. Bare source names (the
     * reader view) are not a shortlist.
     */
    fun shortlist(candidates: List<RequestCandidate>): List<RequestCandidate> {
        val detailed = candidates.filter { it.isDetailed }
        return detailed.filter { selectable(it) } + detailed.filterNot { selectable(it) }
    }

    /** A candidate that failed to check can't be chosen. */
    fun selectable(candidate: RequestCandidate): Boolean = candidate.isDetailed && candidate.error.isNullOrBlank()

    /**
     * The candidate index approve sends: [chosen] while it is still a
     * selectable candidate, else the best one; null when none can be chosen.
     */
    fun selectedCandidate(candidates: List<RequestCandidate>, chosen: Int?): Int? {
        val usable = shortlist(candidates).filter(::selectable)
        return usable.firstOrNull { it.index == chosen }?.index ?: usable.firstOrNull()?.index
    }

    /** No candidate to choose (a free-text request, or every source failed): approve takes a series page URL instead. */
    fun needsUrl(request: ReaderRequest): Boolean = shortlist(request.candidates).none(::selectable)

    /** A manga shortlist still being ranked in the background ("ranking…"). */
    fun rankingPending(request: ReaderRequest): Boolean =
        request.isManga && request.candidatesRankedAt == null && request.candidates.any { it.isDetailed }

    /** The muted line under a candidate: "120 chapters · up to 121 · 2 gaps · EN, ES · updated 3 Nov 2026". */
    fun candidateDetails(candidate: RequestCandidate, zone: ZoneId = ZoneId.systemDefault()): String = buildList {
        (candidate.chaptersAvailable ?: candidate.chapters)?.let { add(if (it == 1) "1 chapter" else "$it chapters") }
        candidate.highestChapter?.let(::trimNumber)?.takeIf { it.isNotBlank() }?.let { add("up to $it") }
        candidate.gaps?.takeIf { it > 0 }?.let { add(if (it == 1) "1 gap" else "$it gaps") }
        if (candidate.languages.isNotEmpty()) add(candidate.languages.joinToString(", ") { it.uppercase() })
        date(candidate.updatedAt, zone)?.let { add("updated $it") }
    }.joinToString(" · ")

    /** Whether the signed-in account is the admin but this address can't decide (the public address). */
    fun decidesElsewhere(isAdmin: Boolean, canDecide: Boolean): Boolean = isAdmin && !canDecide

    // Errors

    /** The server's `message` verbatim when it sent one, else the app's usual wording. */
    fun message(error: Throwable): String {
        val api = ApiError.from(error)
        return (api as? ApiError.Http)?.serverMessage ?: api.userMessage
    }

    /** The `code` of a refused action (`duplicate`, `search_busy`, ...). */
    fun code(error: Throwable): String? = (error as? ApiError.Http)?.code

    // Dates

    fun instant(raw: String?): Instant? = raw?.let { runCatching { OffsetDateTime.parse(it).toInstant() }.getOrNull() }

    /** "3 Nov 2026" in [zone]; null for none or an unreadable value. */
    fun date(raw: String?, zone: ZoneId = ZoneId.systemDefault()): String? =
        instant(raw)?.atZone(zone)?.format(DateTimeFormatter.ofPattern("d MMM yyyy", Locale.ENGLISH))

    private fun trimNumber(raw: String): String = raw.trim().removeSuffix(".0")
}

/**
 * Retry-After from a 429: seconds or an HTTP date. The search button stays
 * disabled until then.
 */
object RetryAfter {
    /** Used when a 429 carries no (or an unreadable) Retry-After. */
    const val DEFAULT_SECONDS = 5L

    /** Never wait longer than this, whatever the header says. */
    const val MAX_SECONDS = 3_600L

    /** Seconds to wait, at least 1, at most [MAX_SECONDS]. [nowMs] is the wall clock (for an HTTP date). */
    fun seconds(header: String?, nowMs: Long): Long {
        val raw = header?.trim().orEmpty()
        val parsed = raw.toLongOrNull()
            ?: runCatching {
                val at = ZonedDateTime.parse(raw, DateTimeFormatter.RFC_1123_DATE_TIME).toInstant().toEpochMilli()
                // Round up: a date one and a half seconds away waits two.
                (at - nowMs + 999) / 1000
            }.getOrNull()
            ?: DEFAULT_SECONDS
        return parsed.coerceIn(1, MAX_SECONDS)
    }

    /** Whole seconds left until [untilMs] at [nowMs] (rounded up); 0 once it has passed. */
    fun remaining(untilMs: Long?, nowMs: Long): Long =
        if (untilMs == null || nowMs >= untilMs) 0 else (untilMs - nowMs + 999) / 1000
}

/**
 * The notification feed (GET /notifications?since=). The cursor is opaque:
 * it is stored, with the newest item id handled, only after the items were
 * shown (posted, or counted for the in-app badge), so an item is never
 * handled twice and a failed run simply gets the same items again.
 */
object RequestFeed {
    const val KIND_NEW = "request_new"
    const val KIND_APPROVED = "request_approved"
    const val KIND_DECLINED = "request_declined"
    const val KIND_AVAILABLE = "request_available"

    /** At most this many pages per poll (100 items each). */
    const val MAX_PAGES = 5

    /** Notification ids for feed items: this base plus the item id, wrapped. */
    const val NOTIFICATION_BASE = 10_000
    private const val NOTIFICATION_SPAN = 1_000_000

    /** Items newer than [lastId] (null: none handled yet), oldest first, each once. */
    fun fresh(items: List<FeedItem>, lastId: Long?): List<FeedItem> =
        items.filter { lastId == null || it.id > lastId }.distinctBy { it.id }.sortedBy { it.id }

    /** The newest item id handled after [items]; never moves back. */
    fun lastId(previous: Long?, items: List<FeedItem>): Long? =
        listOfNotNull(previous, items.maxOfOrNull { it.id }).maxOrNull()

    /** Where tapping an item goes: the review part for a new request; the series once available; else My requests. */
    data class Target(val section: Requests.Section, val novelId: Int? = null)

    fun target(item: FeedItem): Target = when (item.kind) {
        KIND_NEW -> Target(Requests.Section.REVIEW)
        KIND_AVAILABLE -> Target(Requests.Section.MINE, item.novelId)
        else -> Target(Requests.Section.MINE)
    }

    /** Where a group of items goes: the review part if any is a new request, else My requests. */
    fun target(items: List<FeedItem>): Target =
        if (items.any { it.kind == KIND_NEW }) Target(Requests.Section.REVIEW) else Target(Requests.Section.MINE)

    /** A kind this app knows how to show; anything newer is skipped (and still counted as handled). */
    fun known(item: FeedItem): Boolean = item.kind in setOf(KIND_NEW, KIND_APPROVED, KIND_DECLINED, KIND_AVAILABLE)

    fun notificationId(itemId: Long): Int = NOTIFICATION_BASE + Math.floorMod(itemId, NOTIFICATION_SPAN.toLong()).toInt()

    /** The group summary's title: "3 request updates". */
    fun summaryTitle(count: Int): String = if (count == 1) "1 request update" else "$count request updates"
}

/**
 * When the one periodic background worker runs and which checks it runs.
 * The worker runs at the shortest period of the enabled checks (hourly with
 * request notifications on, else every 12 hours); a check with a longer
 * period keeps its own spacing by its last successful run.
 */
object BackgroundSchedule {
    const val HOUR_MS = 60 * 60 * 1000L

    data class Spec(val key: String, val enabled: Boolean, val periodMs: Long, val lastRunMs: Long?)

    /** The worker's interval: the shortest enabled period; null when nothing is enabled (no work). */
    fun intervalMs(specs: List<Spec>): Long? = specs.filter { it.enabled }.minOfOrNull { it.periodMs }

    /**
     * Whether a check is due at [now]. Periodic work wanders inside its
     * window, so half the worker's interval is allowed as slack: hourly, a
     * 12-hour check runs on the first run at least 11.5 hours after its last.
     * Never run, or the clock went back: due.
     */
    fun due(spec: Spec, now: Long, intervalMs: Long): Boolean {
        if (!spec.enabled) return false
        val last = spec.lastRunMs ?: return true
        if (now < last) return true
        return now - last >= spec.periodMs - intervalMs / 2
    }

    /** The keys of the checks to run now, in order. */
    fun dueChecks(specs: List<Spec>, now: Long): List<String> {
        val interval = intervalMs(specs) ?: return emptyList()
        return specs.filter { due(it, now, interval) }.map { it.key }
    }
}
