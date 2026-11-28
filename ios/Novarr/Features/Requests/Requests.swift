import Foundation

/// The reader-requests rules the screen and the notifications follow, kept
/// free of UI so they are unit-tested: what a search card offers, the
/// status labels, the review shortlist, Retry-After, and the feed.
/// Mirrors android domain/Requests.kt.
enum Requests {
    /// The search part's state, kept app-wide per session so leaving the
    /// Requests screen and coming back shows the same search, and a running
    /// one is polled again. Times are `ProcessInfo.systemUptime` seconds.
    struct SearchMemory: Equatable, Sendable {
        var query: String = ""
        var medium: RequestMedium = .defaultMedium
        var search: RequestSearch? = nil
        var startedAt: TimeInterval = 0
        var blockedUntil: TimeInterval? = nil
        var blockedMessage: String? = nil
    }

    /// How often a running search is asked again (seconds).
    static let pollInterval: TimeInterval = 1.5
    /// After this long a running search shows "some sources are slow".
    static let slowAfter: TimeInterval = 10
    /// Longest a note or decline reason may be (the server's limit).
    static let noteMax = 500
    /// Longest search query (the server's limit).
    static let queryMax = 100
    /// The last Discover shelf page a reader may open (RequestBrowse::MAX_PAGE).
    static let browseMaxPage = 10
    /// Longest pasted book link a book search takes (the server's limit).
    static let linkMax = 2048

    /// A request's medium as a label ("Book", "Web Novel", "Manga"); a medium
    /// this app doesn't know yet is shown by its own name, never as another.
    static func mediumLabel(_ raw: String?) -> String {
        if let known = RequestMedium.fromOrNil(raw) { return known.singular }
        let trimmed = raw?.trimmingCharacters(in: .whitespaces) ?? ""
        guard let first = trimmed.first else { return "" }
        return first.uppercased() + trimmed.dropFirst()
    }

    // MARK: The search field

    static func placeholder(_ medium: RequestMedium) -> String {
        switch medium {
        case .book, .comic: "Title, author, ISBN — or paste a Goodreads / Amazon link"
        case .novel: "A web novel to request"
        case .manga: "A manga to request"
        }
    }

    /// Hosts a book link may be pasted from (the server's BookLinkReader::HOSTS, loosely).
    private static let bookHosts = [
        "^(www\\.|m\\.)?goodreads\\.com$",
        "^(www\\.|smile\\.)?amazon\\.[a-z.]{2,7}$",
        "^(www\\.)?audible\\.[a-z.]{2,7}$",
        "^(www\\.)?hardcover\\.app$",
        "^books\\.google\\.[a-z.]{2,7}$",
        "^(www\\.)?openlibrary\\.org$",
    ]

    private static func matches(_ text: String, _ pattern: String, caseInsensitive: Bool = false) -> Bool {
        var options: String.CompareOptions = [.regularExpression]
        if caseInsensitive { options.insert(.caseInsensitive) }
        return text.range(of: pattern, options: options) != nil
    }

    /// Whether `text` is a link rather than a title, as the server decides
    /// (BookLinkReader::looksLikeUrl): no spaces, and a scheme, or a "www." /
    /// book-site host. "Dr.Stone/Reboot" stays a title.
    static func looksLikeURL(_ text: String) -> Bool {
        let t = text.trimmingCharacters(in: .whitespacesAndNewlines)
        if t.isEmpty || t.contains(where: \.isWhitespace) { return false }
        if matches(t, "^[a-z][a-z0-9+.-]*://", caseInsensitive: true) { return true }
        let bare = "^([a-z0-9-]+(\\.[a-z0-9-]+)*\\.[a-z]{2,})(/\\S*)?$"
        guard matches(t, bare, caseInsensitive: true) else { return false }
        let host = String(t.split(separator: "/", maxSplits: 1, omittingEmptySubsequences: false).first ?? "").lowercased()
        return host.hasPrefix("www.") || bookHosts.contains { matches(host, $0) }
    }

    /// Whether `text` is an ISBN-10 or ISBN-13 (hyphens and spaces allowed, an "ISBN" prefix too; no checksum).
    static func looksLikeISBN(_ text: String) -> Bool {
        var raw = Substring(text.trimmingCharacters(in: .whitespacesAndNewlines))
        if raw.lowercased().hasPrefix("isbn") {
            raw = raw.dropFirst(4)
            while let first = raw.first, first == ":" || first == " " { raw = raw.dropFirst() }
        }
        let allowed = Set("0123456789- Xx")
        if raw.isEmpty || raw.contains(where: { !allowed.contains($0) }) { return false }
        let digits = Array(raw.filter { $0 != "-" && $0 != " " })
        func isDigit(_ c: Character) -> Bool { c.isASCII && c.isNumber }
        switch digits.count {
        case 10:
            return digits.prefix(9).allSatisfy(isDigit) && (isDigit(digits[9]) || "Xx".contains(digits[9]))
        case 13:
            let text = String(digits)
            return digits.allSatisfy(isDigit) && (text.hasPrefix("978") || text.hasPrefix("979"))
        default:
            return false
        }
    }

    /// How long the field may get for `text`: a pasted link up to `linkMax`, anything else `queryMax`.
    static func queryLimit(_ text: String) -> Int { looksLikeURL(text) ? linkMax : queryMax }

    /// The text a search sends: trimmed, spaces collapsed, cut to its limit.
    static func searchText(_ text: String) -> String {
        let collapsed = text.trimmingCharacters(in: .whitespacesAndNewlines)
            .replacingOccurrences(of: "\\s+", with: " ", options: .regularExpression)
        return String(collapsed.prefix(queryLimit(collapsed)))
    }

    /// The muted line under the search field for what is typed; nil when there is nothing to say.
    static func queryHint(_ medium: RequestMedium, _ text: String) -> String? {
        let sites = "(Goodreads, Amazon, Hardcover, Google Books or Open Library)."
        if looksLikeURL(text) {
            switch medium {
            case .book: return "Novarr reads the book from this page \(sites)"
            case .comic: return "Novarr reads the comic from this page \(sites)"
            default: return "Links work for books and comics only. Switch to Books or Comics, or type a title."
            }
        }
        if looksLikeISBN(text), medium.takesLinks { return "Searching by ISBN." }
        return nil
    }

    /// A wanted book request's line: no ePub yet, the owner is looking for it.
    static let wantedLine = "Waiting for the owner to find it"

    /// Whether a request shows `wantedLine`: a wanted book still open (pending or approved).
    static func showsWanted(_ request: ReaderRequest) -> Bool {
        let s = status(request)
        return request.wanted && (s == .pending || s == .approved)
    }

    // MARK: Search cards

    /// The one action a search result's card offers.
    enum CardAction: Equatable {
        /// Nobody has asked for it: "Request".
        case request
        /// Already in the library: "In library" opens it (when the server said which series).
        case inLibrary(novelId: Int?)
        /// Someone else's open request: "Me too" joins it.
        case meToo
        /// An open request exists: "Requested by you" or "Requested", disabled.
        case requested(byMe: Bool)
    }

    private static let joinable: Set<String> = ["pending", "approved"]

    /// In the library wins over requested. Someone else's pending or approved
    /// request offers "Me too", unless `canJoin` is false (an older server
    /// turned a "Me too" down as `already_requested`).
    static func cardAction(_ result: SearchResult, canJoin: Bool = true) -> CardAction {
        if result.inLibrary || result.novelId != nil { return .inLibrary(novelId: result.novelId) }
        guard let requested = result.requested else { return .request }
        if !requested.byMe, canJoin, joinable.contains(requested.status.lowercased()) { return .meToo }
        return .requested(byMe: requested.byMe)
    }

    static func cardActionLabel(_ action: CardAction) -> String {
        switch action {
        case .request: "Request"
        case .meToo: "Me too"
        case .inLibrary: "In library"
        case .requested(let byMe): byMe ? "Requested by you" : "Requested"
        }
    }

    /// The notice after "Me too" joined a request.
    static let joinedNotice = "You'll be told when it's ready."
    static let requestedNotice = "Requested. You'll hear when the owner decides."

    /// "On MangaDex, MangaSee +2": the first `shown` distinct source names; empty with none.
    static func sourcesLabel(_ sources: [ResultSource], shown: Int = 2) -> String {
        var names: [String] = []
        for source in sources {
            let name = source.sourceName.trimmingCharacters(in: .whitespaces).isEmpty ? source.source : source.sourceName
            if !name.trimmingCharacters(in: .whitespaces).isEmpty, !names.contains(name) { names.append(name) }
        }
        guard !names.isEmpty else { return "" }
        let more = names.count - shown
        return "On " + names.prefix(shown).joined(separator: ", ") + (more > 0 ? " +\(more)" : "")
    }

    /// "Couldn't reach: X, Y." for the sources a search skipped; nil when none were.
    static func skippedLabel(_ names: [String]) -> String? {
        var distinct: [String] = []
        for name in names where !name.trimmingCharacters(in: .whitespaces).isEmpty && !distinct.contains(name) {
            distinct.append(name)
        }
        return distinct.isEmpty ? nil : "Couldn't reach: \(distinct.joined(separator: ", "))."
    }

    /// Marks `key` as requested by this account after a successful request
    /// (or "Me too"); a joined request keeps its status.
    static func markRequested(_ results: [SearchResult], key: String) -> [SearchResult] {
        markTaken(results, key: key, byMe: true)
    }

    /// Marks `key` as requested (`byMe`: by this account), e.g. after 409 `already_requested`.
    static func markTaken(_ results: [SearchResult], key: String, byMe: Bool) -> [SearchResult] {
        results.map { result in
            guard result.key == key else { return result }
            var copy = result
            copy.requested = ResultRequested(status: result.requested?.status ?? "pending", byMe: byMe)
            return copy
        }
    }

    // MARK: Request status

    enum Status: Equatable {
        case pending, approved, available, declined, unknown

        var label: String {
            switch self {
            case .pending: "Pending"
            case .approved: "Approved — downloading"
            case .available: "Available"
            case .declined: "Declined"
            case .unknown: "Unknown"
            }
        }

        static func from(_ raw: String?) -> Status {
            switch raw?.lowercased() {
            case "pending": .pending
            case "approved": .approved
            case "available": .available
            case "declined": .declined
            default: .unknown
            }
        }
    }

    static func status(_ request: ReaderRequest) -> Status { Status.from(request.status) }

    /// "Declined: Not licensed in English." or just "Declined".
    static func statusLine(_ request: ReaderRequest) -> String {
        let s = status(request)
        let reason = request.declineReason?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        return s == .declined && !reason.isEmpty ? "\(s.label): \(reason)" : s.label
    }

    /// "2 of 5 pending": this account's own pending requests against the
    /// limit (joined ones don't count). Nil without a limit and while none is pending.
    static func pendingLabel(_ mine: [ReaderRequest], limit: Int?) -> String? {
        guard let limit, limit > 0 else { return nil }
        let pending = mine.filter { !$0.isFollower && status($0) == .pending }.count
        return pending == 0 ? nil : "\(pending) of \(limit) pending"
    }

    enum MineAction: Equatable { case none, cancel, leave }

    /// Cancel your own pending request (when the server allows). Leave one you
    /// joined whenever the server allows (`can_cancel`); a server that doesn't
    /// say: while pending only.
    static func mineAction(_ request: ReaderRequest) -> MineAction {
        let pending = status(request) == .pending
        if request.isFollower { return (request.canCancel ?? pending) ? .leave : .none }
        if pending, request.canCancel == true { return .cancel }
        return .none
    }

    /// "Also wanted by Ana, Ben", "Also wanted by Ana and 2 more readers",
    /// "Also wanted by 3 readers"; nil when nobody joined.
    static func alsoWantedLabel(_ request: ReaderRequest) -> String? {
        var names: [String] = []
        for follower in request.followers {
            if let name = follower.name?.trimmingCharacters(in: .whitespaces), !name.isEmpty, !names.contains(name) {
                names.append(name)
            }
        }
        let count = max(request.alsoWantedBy, request.followers.count)
        func readers(_ n: Int, _ prefix: String = "") -> String { n == 1 ? "1 \(prefix)reader" : "\(n) \(prefix)readers" }
        if names.isEmpty { return count > 0 ? "Also wanted by \(readers(count))" : nil }
        let more = count - names.count
        return more > 0
            ? "Also wanted by \(names.joined(separator: ", ")) and \(readers(more, "more "))"
            : "Also wanted by \(names.joined(separator: ", "))"
    }

    /// "Open" goes to the series once it is available (or approved, as soon as it exists).
    static func openableNovel(_ request: ReaderRequest) -> Int? {
        let s = status(request)
        return s == .available || s == .approved ? request.novelId : nil
    }

    // MARK: Review (the admin view)

    /// Everyone's pending requests, oldest first (the list comes newest first).
    static func reviewQueue(_ requests: [ReaderRequest]) -> [ReaderRequest] {
        var seen = Set<Int>()
        let pending = requests.filter { status($0) == .pending && seen.insert($0.id).inserted }
        return pending.sorted { a, b in
            let da = instant(a.createdAt) ?? .distantFuture
            let db = instant(b.createdAt) ?? .distantFuture
            return da == db ? a.id < b.id : da < db
        }
    }

    /// The shortlist as shown: the server's order with failed candidates last.
    /// Bare source names (the reader view) are not a shortlist.
    static func shortlist(_ candidates: [RequestCandidate]) -> [RequestCandidate] {
        let detailed = candidates.filter(\.isDetailed)
        return detailed.filter(selectable) + detailed.filter { !selectable($0) }
    }

    /// A candidate that failed to check can't be chosen.
    static func selectable(_ candidate: RequestCandidate) -> Bool {
        candidate.isDetailed && (candidate.error?.trimmingCharacters(in: .whitespaces).isEmpty ?? true)
    }

    /// The index approve sends: `chosen` while still selectable, else the best one; nil when none can be chosen.
    static func selectedCandidate(_ candidates: [RequestCandidate], chosen: Int?) -> Int? {
        let usable = shortlist(candidates).filter(selectable)
        return usable.first { $0.index == chosen }?.index ?? usable.first?.index
    }

    /// No candidate to choose: approve takes a series page URL instead.
    static func needsURL(_ request: ReaderRequest) -> Bool { !shortlist(request.candidates).contains(where: selectable) }

    /// A manga shortlist still being ranked in the background ("Ranking…").
    static func rankingPending(_ request: ReaderRequest) -> Bool {
        request.isManga && request.candidatesRankedAt == nil && request.candidates.contains(where: \.isDetailed)
    }

    /// "120 chapters · up to 121 · 2 gaps · EN, ES · updated 3 Nov 2026".
    static func candidateDetails(_ candidate: RequestCandidate, zone: TimeZone = .current) -> String {
        var parts: [String] = []
        if let n = candidate.chaptersAvailable ?? candidate.chapters { parts.append(n == 1 ? "1 chapter" : "\(n) chapters") }
        if let high = candidate.highestChapter.map(trimNumber), !high.isEmpty { parts.append("up to \(high)") }
        if let gaps = candidate.gaps, gaps > 0 { parts.append(gaps == 1 ? "1 gap" : "\(gaps) gaps") }
        if !candidate.languages.isEmpty { parts.append(candidate.languages.map { $0.uppercased() }.joined(separator: ", ")) }
        if let date = date(candidate.updatedAt, zone: zone) { parts.append("updated \(date)") }
        return parts.joined(separator: " · ")
    }

    /// The signed-in account is the admin but this address can't decide (the public address).
    static func decidesElsewhere(isAdmin: Bool, canDecide: Bool) -> Bool { isAdmin && !canDecide }

    // MARK: Errors

    /// The server's `message` verbatim when it sent one, else the app's usual wording.
    static func message(_ error: Error) -> String {
        let api = APIError.from(error)
        return api.serverMessage ?? api.errorDescription ?? "Something went wrong."
    }

    /// The `code` of a refused action (`duplicate`, `search_busy`, ...).
    static func code(_ error: Error) -> String? { (error as? APIError)?.code }

    // MARK: Dates

    static func instant(_ raw: String?) -> Date? {
        guard let raw, !raw.isEmpty else { return nil }
        let full = ISO8601DateFormatter()
        full.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        if let date = full.date(from: raw) { return date }
        let plain = ISO8601DateFormatter()
        plain.formatOptions = [.withInternetDateTime]
        return plain.date(from: raw)
    }

    /// "3 Nov 2026" in `zone`; nil for none or an unreadable value.
    static func date(_ raw: String?, zone: TimeZone = .current) -> String? {
        guard let instant = instant(raw) else { return nil }
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_GB")
        formatter.timeZone = zone
        formatter.dateFormat = "d MMM yyyy"
        return formatter.string(from: instant)
    }

    private static func trimNumber(_ raw: String) -> String {
        let t = raw.trimmingCharacters(in: .whitespaces)
        return t.hasSuffix(".0") ? String(t.dropLast(2)) : t
    }
}

/// The media a reader can request, in the picker's order (the web's): Books
/// first and the default. Wire values stay as the server has them ("novel" is
/// shown as "Web Novels"). Comics only on a server that knows them.
enum RequestMedium: String, CaseIterable, Identifiable, Sendable, Hashable {
    case book, novel, manga, comic

    var id: String { rawValue }
    var wire: String { rawValue }

    var title: String {
        switch self {
        case .book: "Books"
        case .novel: "Web Novels"
        case .manga: "Manga"
        case .comic: "Comics"
        }
    }

    var singular: String {
        switch self {
        case .book: "Book"
        case .novel: "Web Novel"
        case .manga: "Manga"
        case .comic: "Comic"
        }
    }

    /// A search takes a pasted Goodreads / Amazon (…) link or an ISBN: books and comics.
    var takesLinks: Bool { self == .book || self == .comic }

    /// Has a Discover shelf (`/requests/browse`): the server has no lists for comics.
    var hasDiscover: Bool { self != .comic }

    static let defaultMedium = RequestMedium.book

    /// The picker's options: Comics only when the server knows them (meta `comics`).
    static func offered(comics: Bool) -> [RequestMedium] { comics ? allCases : allCases.filter { $0 != .comic } }

    static func fromOrNil(_ raw: String?) -> RequestMedium? {
        guard let wire = raw?.trimmingCharacters(in: .whitespaces).lowercased() else { return nil }
        return RequestMedium(rawValue: wire)
    }

    static func from(_ raw: String?, fallback: RequestMedium = .defaultMedium) -> RequestMedium {
        fromOrNil(raw) ?? fallback
    }
}

extension RequestsRoute.Section {
    /// A section by name (notification payloads), any case; unknown → search.
    static func from(_ raw: String?) -> RequestsRoute.Section {
        allCases.first { $0.rawValue.lowercased() == raw?.lowercased() } ?? .search
    }
}

/// Retry-After from a 429: seconds or an HTTP date. The search button stays
/// disabled until then.
enum RetryAfter {
    static let defaultSeconds: Int = 5
    static let maxSeconds: Int = 3600

    /// Seconds to wait from the header text, at least 1, at most `maxSeconds`.
    static func seconds(_ header: String?, now: Date) -> Int {
        let raw = header?.trimmingCharacters(in: .whitespaces) ?? ""
        let parsed: Int
        if let value = Int(raw) {
            parsed = value
        } else if let at = httpDate(raw) {
            // Round up: a date one and a half seconds away waits two.
            parsed = Int((at.timeIntervalSince(now)).rounded(.up))
        } else {
            parsed = defaultSeconds
        }
        return min(max(parsed, 1), maxSeconds)
    }

    /// Seconds to wait from a parsed Retry-After (`APIError.retryAfter`).
    static func seconds(_ retryAfter: TimeInterval?) -> Int {
        guard let retryAfter, retryAfter.isFinite else { return defaultSeconds }
        return min(max(Int(retryAfter.rounded(.up)), 1), maxSeconds)
    }

    /// Whole seconds left until `until` at `now` (rounded up); 0 once passed.
    static func remaining(_ until: TimeInterval?, now: TimeInterval) -> Int {
        guard let until, now < until else { return 0 }
        return Int((until - now).rounded(.up))
    }

    private static func httpDate(_ raw: String) -> Date? {
        let formatter = DateFormatter()
        formatter.locale = Locale(identifier: "en_US_POSIX")
        formatter.timeZone = TimeZone(identifier: "GMT")
        formatter.dateFormat = "EEE, dd MMM yyyy HH:mm:ss zzz"
        return formatter.date(from: raw)
    }
}

/// The notification feed (GET /notifications?since=). The cursor is opaque:
/// it is stored, with the newest item id handled, only after the items were
/// shown (posted, or counted for the in-app badge).
enum RequestFeed {
    static let kindNew = "request_new"
    static let kindApproved = "request_approved"
    static let kindDeclined = "request_declined"
    static let kindAvailable = "request_available"

    /// At most this many pages per poll.
    static let maxPages = 5

    /// Items newer than `lastId` (nil: none handled yet), oldest first, each once.
    static func fresh(_ items: [FeedItem], lastId: Int64?) -> [FeedItem] {
        var seen = Set<Int64>()
        return items.filter { (lastId == nil || $0.id > lastId!) && seen.insert($0.id).inserted }.sorted { $0.id < $1.id }
    }

    /// The newest item id handled after `items`; never moves back.
    static func lastId(_ previous: Int64?, _ items: [FeedItem]) -> Int64? {
        ([previous].compactMap { $0 } + items.map(\.id)).max()
    }

    /// Where tapping goes: the review part for a new request; the series once available; else My requests.
    struct Target: Equatable, Sendable {
        var section: RequestsRoute.Section
        var novelId: Int? = nil
    }

    static func target(_ item: FeedItem) -> Target {
        switch item.kind {
        case kindNew: Target(section: .review)
        case kindAvailable: Target(section: .mine, novelId: item.novelId)
        default: Target(section: .mine)
        }
    }

    static func target(_ items: [FeedItem]) -> Target {
        items.contains { $0.kind == kindNew } ? Target(section: .review) : Target(section: .mine)
    }

    /// A kind this app knows how to show; anything newer is skipped (and still counted as handled).
    static func known(_ item: FeedItem) -> Bool {
        [kindNew, kindApproved, kindDeclined, kindAvailable].contains(item.kind)
    }

    /// The local notification's identifier for a feed item.
    static func notificationId(_ itemId: Int64) -> String { "request-\(itemId)" }

    /// "3 request updates".
    static func summaryTitle(_ count: Int) -> String { count == 1 ? "1 request update" : "\(count) request updates" }
}

/// Which background checks are due when iOS runs the app-refresh task. The
/// task is asked for at the shortest period of the enabled checks (hourly
/// with request notifications on, every 2 hours for new chapters); a check
/// with a longer period keeps its own spacing by its last successful run.
enum BackgroundSchedule {
    static let hour: TimeInterval = 3600

    struct Spec: Equatable, Sendable {
        var key: String
        var enabled: Bool
        var period: TimeInterval
        var lastRun: Date?
    }

    static func interval(_ specs: [Spec]) -> TimeInterval? { specs.filter(\.enabled).map(\.period).min() }

    /// Due at `now`: half the interval is allowed as slack. Never run, or the clock went back: due.
    static func due(_ spec: Spec, now: Date, interval: TimeInterval) -> Bool {
        guard spec.enabled else { return false }
        guard let last = spec.lastRun else { return true }
        if now < last { return true }
        return now.timeIntervalSince(last) >= spec.period - interval / 2
    }

    static func dueChecks(_ specs: [Spec], now: Date) -> [String] {
        guard let interval = interval(specs) else { return [] }
        return specs.filter { due($0, now: now, interval: interval) }.map(\.key)
    }
}

// MARK: - Plain initialisers for the feed rows (tests, previews)

extension FeedItem {
    init(id: Int64, kind: String, title: String = "", body: String = "", requestId: Int? = nil,
         novelId: Int? = nil, at: String? = nil) {
        self.id = id
        self.kind = kind
        self.title = title
        self.body = body
        self.requestId = requestId
        self.novelId = novelId
        self.at = at
    }
}

extension UpdatesFeed.Series {
    init(novelId: Int, name: String, medium: String = Medium.novel, newCount: Int, chapterId: Int? = nil,
         chapterLabel: String? = nil, latestAt: String? = nil) {
        self.novelId = novelId
        self.name = name
        self.medium = medium
        self.newCount = newCount
        self.chapterId = chapterId
        self.chapterLabel = chapterLabel
        self.latestAt = latestAt
    }
}

extension UpdatesFeed {
    init(cursor: String?, series: [Series]) {
        self.cursor = cursor
        self.series = series
    }
}
