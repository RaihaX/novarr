import Foundation

// Reader requests (RequestPresenter.php, RequestController, RequestSearchController,
// RequestBrowseController, NotificationController) and "Send to my Kindle".
// As elsewhere, only ids are required and one bad row never empties a list.
// Mirrors android api/RequestModels.kt.

/// `search.results[].sources[]`: one source that has the series.
struct ResultSource: Codable, Hashable, Sendable {
    var source: String
    var sourceName: String
    var chapters: Int?
    var status: String?

    enum CodingKeys: String, CodingKey { case source, sourceName, chapters, status }

    init(source: String = "", sourceName: String = "", chapters: Int? = nil, status: String? = nil) {
        self.source = source
        self.sourceName = sourceName
        self.chapters = chapters
        self.status = status
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        source = c.lenientString(.source) ?? ""
        sourceName = c.lenientString(.sourceName) ?? ""
        chapters = c.lenientInt(.chapters)
        status = c.lenientString(.status)
    }
}

/// `search.results[].requested`: someone (maybe this account) has an open request for it.
struct ResultRequested: Codable, Hashable, Sendable {
    var status: String
    var byMe: Bool

    enum CodingKeys: String, CodingKey { case status, byMe }

    init(status: String = "pending", byMe: Bool = false) {
        self.status = status
        self.byMe = byMe
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        status = c.lenientString(.status) ?? "pending"
        byMe = c.lenientBool(.byMe) ?? false
    }
}

/// One series a search (or a Discover shelf) found, grouped across sources.
/// `key` is what a request from it sends.
struct SearchResult: Codable, Hashable, Sendable, Identifiable {
    var key: String
    var title: String
    var author: String?
    var coverUrl: URL?
    var description: String?
    var medium: String
    var sources: [ResultSource]
    var inLibrary: Bool
    var novelId: Int?
    var requested: ResultRequested?
    var series: String?
    var seriesIndex: String?
    /// The reader's categories holding the series, when it is in the library.
    var inCategories: [Int]
    /// The categories the reader chose on their open request for it.
    var requestedCategories: [Int]

    var id: String { key }

    enum CodingKeys: String, CodingKey {
        case key, title, author, coverUrl, description, medium, sources, inLibrary, novelId, requested
        case series, seriesIndex, inCategories, requestedCategories
    }

    init(key: String, title: String = "", author: String? = nil, coverUrl: URL? = nil, description: String? = nil,
         medium: String = Medium.novel, sources: [ResultSource] = [], inLibrary: Bool = false, novelId: Int? = nil,
         requested: ResultRequested? = nil, series: String? = nil, seriesIndex: String? = nil,
         inCategories: [Int] = [], requestedCategories: [Int] = []) {
        self.key = key
        self.title = title
        self.author = author
        self.coverUrl = coverUrl
        self.description = description
        self.medium = medium
        self.sources = sources
        self.inLibrary = inLibrary
        self.novelId = novelId
        self.requested = requested
        self.series = series
        self.seriesIndex = seriesIndex
        self.inCategories = inCategories
        self.requestedCategories = requestedCategories
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let key = c.lenientString(.key), !key.isEmpty else {
            throw DecodingError.keyNotFound(CodingKeys.key, .init(codingPath: c.codingPath, debugDescription: "result.key"))
        }
        self.key = key
        title = c.lenientString(.title) ?? ""
        author = c.lenientString(.author)
        coverUrl = c.lenientURL(.coverUrl)
        description = c.lenientString(.description)
        medium = c.lenientString(.medium).flatMap { $0.isEmpty ? nil : $0 } ?? Medium.novel
        sources = c.lossyArray(ResultSource.self, .sources)
        inLibrary = c.lenientBool(.inLibrary) ?? false
        novelId = c.lenientInt(.novelId)
        requested = try? c.decodeIfPresent(ResultRequested.self, forKey: .requested)
        series = c.lenientString(.series)
        seriesIndex = c.lenientString(.seriesIndex)
        inCategories = c.lossyArray(Int.self, .inCategories)
        requestedCategories = c.lossyArray(Int.self, .requestedCategories)
    }

    var seriesLabel: String? { SeriesLabel.make(series: series, index: seriesIndex) }
}

/// One list of the Discover shelf: `id` is what browse and a browse request send.
struct BrowseList: Codable, Hashable, Sendable, Identifiable {
    var id: String
    var name: String

    enum CodingKeys: String, CodingKey { case id, name }

    init(id: String, name: String = "") {
        self.id = id
        self.name = name
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientString(.id), !id.isEmpty else {
            throw DecodingError.keyNotFound(CodingKeys.id, .init(codingPath: c.codingPath, debugDescription: "list.id"))
        }
        self.id = id
        name = c.lenientString(.name) ?? ""
    }
}

/// GET /requests/browse: a page of one list of the medium's shelf.
struct BrowsePage: Codable, Hashable, Sendable {
    var medium: String
    var lists: [BrowseList]
    var list: String?
    var sourceName: String?
    var page: Int
    var hasMore: Bool
    var results: [SearchResult]

    enum CodingKeys: String, CodingKey { case medium, lists, list, sourceName, page, hasMore, results }

    init(medium: String = Medium.manga, lists: [BrowseList] = [], list: String? = nil, sourceName: String? = nil,
         page: Int = 1, hasMore: Bool = false, results: [SearchResult] = []) {
        self.medium = medium
        self.lists = lists
        self.list = list
        self.sourceName = sourceName
        self.page = page
        self.hasMore = hasMore
        self.results = results
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        medium = c.lenientString(.medium) ?? Medium.manga
        lists = c.lossyArray(BrowseList.self, .lists)
        list = c.lenientString(.list)
        sourceName = c.lenientString(.sourceName)
        page = c.lenientInt(.page) ?? 1
        hasMore = c.lenientBool(.hasMore) ?? false
        results = c.lossyArray(SearchResult.self, .results)
    }
}

/// A source the search couldn't ask ("Couldn't reach: X, Y.").
struct SkippedSource: Codable, Hashable, Sendable {
    var source: String
    var sourceName: String
    var reason: String?

    enum CodingKeys: String, CodingKey { case source, sourceName, reason }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        source = c.lenientString(.source) ?? ""
        sourceName = c.lenientString(.sourceName) ?? ""
        reason = c.lenientString(.reason)
    }
}

/// GET /requests/searches/{id}: running → done (results) or failed (message).
struct RequestSearch: Codable, Hashable, Sendable, Identifiable {
    var id: String
    var status: String
    var message: String?
    var query: String
    var medium: String
    var results: [SearchResult]
    var skippedSources: [SkippedSource]

    enum CodingKeys: String, CodingKey { case id, status, message, query, medium, results, skippedSources }

    init(id: String, status: String = "running", message: String? = nil, query: String = "",
         medium: String = Medium.novel, results: [SearchResult] = [], skippedSources: [SkippedSource] = []) {
        self.id = id
        self.status = status
        self.message = message
        self.query = query
        self.medium = medium
        self.results = results
        self.skippedSources = skippedSources
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientString(.id), !id.isEmpty else {
            throw DecodingError.keyNotFound(CodingKeys.id, .init(codingPath: c.codingPath, debugDescription: "search.id"))
        }
        self.id = id
        status = c.lenientString(.status) ?? "running"
        message = c.lenientString(.message)
        query = c.lenientString(.query) ?? ""
        medium = c.lenientString(.medium) ?? Medium.novel
        results = c.lossyArray(SearchResult.self, .results)
        skippedSources = c.lossyArray(SkippedSource.self, .skippedSources)
    }

    var isRunning: Bool { status == "running" }
    var isDone: Bool { status == "done" }
    var isFailed: Bool { status == "failed" }
}

struct SearchEnvelope: Decodable, Sendable {
    var search: RequestSearch
}

struct Requester: Codable, Hashable, Sendable {
    var id: Int
    var name: String?

    enum CodingKeys: String, CodingKey { case id, name }

    init(id: Int = 0, name: String? = nil) {
        self.id = id
        self.name = name
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.lenientInt(.id) ?? 0
        name = c.lenientString(.name)
    }
}

/// One entry of a request's `candidates`. The reader view sends only source
/// names (strings): those decode with just `sourceName` and a nil `index`.
/// The admin view sends the ranked shortlist with `index` (what approve takes).
struct RequestCandidate: Codable, Hashable, Sendable {
    var index: Int?
    var source: String
    var sourceName: String
    var url: String
    var title: String
    var chapters: Int?
    var status: String?
    var languages: [String]
    var chaptersAvailable: Int?
    var highestChapter: String?
    var gaps: Int?
    var updatedAt: String?
    var error: String?
    var reason: String?

    enum CodingKeys: String, CodingKey {
        case index, source, sourceName, url, title, chapters, status, languages, chaptersAvailable
        case highestChapter, gaps, updatedAt, error, reason
    }

    init(index: Int? = nil, source: String = "", sourceName: String = "", url: String = "", title: String = "",
         chapters: Int? = nil, status: String? = nil, languages: [String] = [], chaptersAvailable: Int? = nil,
         highestChapter: String? = nil, gaps: Int? = nil, updatedAt: String? = nil, error: String? = nil,
         reason: String? = nil) {
        self.index = index
        self.source = source
        self.sourceName = sourceName
        self.url = url
        self.title = title
        self.chapters = chapters
        self.status = status
        self.languages = languages
        self.chaptersAvailable = chaptersAvailable
        self.highestChapter = highestChapter
        self.gaps = gaps
        self.updatedAt = updatedAt
        self.error = error
        self.reason = reason
    }

    init(from decoder: Decoder) throws {
        // A bare string: the reader view's source name.
        if let single = try? decoder.singleValueContainer(), let name = try? single.decode(String.self) {
            guard !name.trimmingCharacters(in: .whitespaces).isEmpty else {
                throw DecodingError.dataCorrupted(.init(codingPath: decoder.codingPath, debugDescription: "blank candidate"))
            }
            self.init(sourceName: name)
            return
        }
        let c = try decoder.container(keyedBy: CodingKeys.self)
        self.init(index: c.lenientInt(.index),
                  source: c.lenientString(.source) ?? "",
                  sourceName: c.lenientString(.sourceName) ?? "",
                  url: c.lenientString(.url) ?? "",
                  title: c.lenientString(.title) ?? "",
                  chapters: c.lenientInt(.chapters),
                  status: c.lenientString(.status),
                  languages: c.lossyArray(String.self, .languages),
                  chaptersAvailable: c.lenientInt(.chaptersAvailable),
                  highestChapter: c.lenientString(.highestChapter),
                  gaps: c.lenientInt(.gaps),
                  updatedAt: c.lenientString(.updatedAt),
                  error: c.lenientString(.error),
                  reason: c.lenientString(.reason))
    }

    /// From the admin view's shortlist (approvable), not a bare source name.
    var isDetailed: Bool { index != nil }
}

struct ReaderRequest: Codable, Hashable, Sendable, Identifiable {
    var id: Int
    var title: String
    var author: String?
    var coverUrl: URL?
    var description: String?
    var medium: String
    var note: String?
    var link: String?
    /// pending | approved | declined | available
    var status: String
    var declineReason: String?
    var requestedBy: Requester?
    var novelId: Int?
    var createdAt: String?
    var decidedAt: String?
    var availableAt: String?
    var candidates: [RequestCandidate]
    var candidatesRankedAt: String?
    var canDecide: Bool
    /// The server lets this account withdraw it; nil when the server didn't say.
    var canCancel: Bool?
    /// How many other readers joined it ("Me too").
    var alsoWantedBy: Int
    /// This account's part in it: requester | follower; nil when neither.
    var mine: String?
    var followers: [Requester]
    /// A book asked for by title: no ePub yet, the owner finds one.
    var wanted: Bool
    var requestedCategories: [Int]

    enum CodingKeys: String, CodingKey {
        case id, title, author, coverUrl, description, medium, note, link, status, declineReason, requestedBy
        case novelId, createdAt, decidedAt, availableAt, candidates, candidatesRankedAt, canDecide, canCancel
        case alsoWantedBy, mine, followers, wanted, requestedCategories
    }

    init(id: Int, title: String = "", author: String? = nil, coverUrl: URL? = nil, description: String? = nil,
         medium: String = Medium.novel, note: String? = nil, link: String? = nil, status: String = "pending",
         declineReason: String? = nil, requestedBy: Requester? = nil, novelId: Int? = nil, createdAt: String? = nil,
         decidedAt: String? = nil, availableAt: String? = nil, candidates: [RequestCandidate] = [],
         candidatesRankedAt: String? = nil, canDecide: Bool = false, canCancel: Bool? = nil, alsoWantedBy: Int = 0,
         mine: String? = nil, followers: [Requester] = [], wanted: Bool = false, requestedCategories: [Int] = []) {
        self.id = id
        self.title = title
        self.author = author
        self.coverUrl = coverUrl
        self.description = description
        self.medium = medium
        self.note = note
        self.link = link
        self.status = status
        self.declineReason = declineReason
        self.requestedBy = requestedBy
        self.novelId = novelId
        self.createdAt = createdAt
        self.decidedAt = decidedAt
        self.availableAt = availableAt
        self.candidates = candidates
        self.candidatesRankedAt = candidatesRankedAt
        self.canDecide = canDecide
        self.canCancel = canCancel
        self.alsoWantedBy = alsoWantedBy
        self.mine = mine
        self.followers = followers
        self.wanted = wanted
        self.requestedCategories = requestedCategories
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(CodingKeys.id, .init(codingPath: c.codingPath, debugDescription: "request.id"))
        }
        self.id = id
        title = c.lenientString(.title) ?? ""
        author = c.lenientString(.author)
        coverUrl = c.lenientURL(.coverUrl)
        description = c.lenientString(.description)
        medium = c.lenientString(.medium).flatMap { $0.isEmpty ? nil : $0 } ?? Medium.novel
        note = c.lenientString(.note)
        link = c.lenientString(.link)
        status = c.lenientString(.status) ?? "pending"
        declineReason = c.lenientString(.declineReason)
        requestedBy = try? c.decodeIfPresent(Requester.self, forKey: .requestedBy)
        novelId = c.lenientInt(.novelId)
        createdAt = c.lenientString(.createdAt)
        decidedAt = c.lenientString(.decidedAt)
        availableAt = c.lenientString(.availableAt)
        candidates = c.lossyArray(RequestCandidate.self, .candidates)
        candidatesRankedAt = c.lenientString(.candidatesRankedAt)
        canDecide = c.lenientBool(.canDecide) ?? false
        canCancel = c.lenientBool(.canCancel)
        alsoWantedBy = max(c.lenientInt(.alsoWantedBy) ?? 0, 0)
        mine = c.lenientString(.mine)
        followers = c.lossyArray(Requester.self, .followers)
        wanted = c.lenientBool(.wanted) ?? false
        requestedCategories = c.lossyArray(Int.self, .requestedCategories)
    }

    var isManga: Bool { medium == Medium.manga }
    /// This account joined someone else's request ("Me too") rather than making it.
    var isFollower: Bool { mine == "follower" }
}

struct RequestEnvelope: Decodable, Sendable {
    var request: ReaderRequest
}

/// POST /requests from a search result: 201 {request, joined: false} for a
/// new request, 200 {request, joined: true} when it joined someone else's.
struct CreatedRequest: Decodable, Sendable, Equatable {
    var request: ReaderRequest?
    var joined: Bool

    enum CodingKeys: String, CodingKey { case request, joined }

    init(request: ReaderRequest? = nil, joined: Bool = false) {
        self.request = request
        self.joined = joined
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        request = try? c.decodeIfPresent(ReaderRequest.self, forKey: .request)
        joined = c.lenientBool(.joined) ?? false
    }
}

/// GET /requests. `pendingLimit` is nil in the admin view (no limit).
struct RequestsPage: Codable, Hashable, Sendable {
    var requests: [ReaderRequest]
    var page: Int
    var hasMore: Bool
    var canDecide: Bool
    var pendingLimit: Int?

    enum CodingKeys: String, CodingKey { case requests, page, hasMore, canDecide, pendingLimit }

    init(requests: [ReaderRequest] = [], page: Int = 1, hasMore: Bool = false, canDecide: Bool = false,
         pendingLimit: Int? = nil) {
        self.requests = requests
        self.page = page
        self.hasMore = hasMore
        self.canDecide = canDecide
        self.pendingLimit = pendingLimit
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        requests = c.lossyArray(ReaderRequest.self, .requests)
        page = c.lenientInt(.page) ?? 1
        hasMore = c.lenientBool(.hasMore) ?? false
        canDecide = c.lenientBool(.canDecide) ?? false
        pendingLimit = c.lenientInt(.pendingLimit)
    }
}

struct ApproveResult: Decodable, Sendable, Equatable {
    var request: ReaderRequest?
    var novelId: Int?
    var created: Bool

    enum CodingKeys: String, CodingKey { case request, novelId, created }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        request = try? c.decodeIfPresent(ReaderRequest.self, forKey: .request)
        novelId = c.lenientInt(.novelId)
        created = c.lenientBool(.created) ?? false
    }
}

/// One item of the notification feed. kind: request_new | request_approved | request_declined | request_available.
struct FeedItem: Codable, Hashable, Sendable, Identifiable {
    var id: Int64
    var kind: String
    var title: String
    var body: String
    var requestId: Int?
    var novelId: Int?
    var at: String?

    enum CodingKeys: String, CodingKey { case id, kind, title, body, requestId, novelId, at }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt64(.id) else {
            throw DecodingError.keyNotFound(CodingKeys.id, .init(codingPath: c.codingPath, debugDescription: "item.id"))
        }
        self.id = id
        kind = c.lenientString(.kind) ?? ""
        title = c.lenientString(.title) ?? ""
        body = c.lenientString(.body) ?? ""
        requestId = c.lenientInt(.requestId)
        novelId = c.lenientInt(.novelId)
        at = c.lenientString(.at)
    }
}

/// GET /notifications?since=: always store `cursor` for the next call.
struct NotificationFeed: Decodable, Sendable, Equatable {
    var cursor: String?
    var hasMore: Bool
    var items: [FeedItem]

    enum CodingKeys: String, CodingKey { case cursor, hasMore, items }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        cursor = c.lenientString(.cursor)
        hasMore = c.lenientBool(.hasMore) ?? false
        items = c.lossyArray(FeedItem.self, .items)
    }
}

/// "Send to my Kindle / email" (GET / POST /novels/{id}/send):
/// status nil (none yet) | queued | sending | sent | failed.
struct SendState: Codable, Hashable, Sendable {
    var status: String?
    var message: String?
    var to: String?

    enum CodingKeys: String, CodingKey { case status, message, to }

    init(status: String? = nil, message: String? = nil, to: String? = nil) {
        self.status = status
        self.message = message
        self.to = to
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        status = c.lenientString(.status)
        message = c.lenientString(.message)
        to = c.lenientString(.to)
    }
}

struct SendEnvelope: Decodable, Sendable {
    var send: SendState?
    enum CodingKeys: String, CodingKey { case send }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        send = try? c.decodeIfPresent(SendState.self, forKey: .send)
    }
}

// MARK: - Books sent from the app ("Share to Novarr")

/// `meta.book_uploads`: absent on servers that predate uploads from the app.
struct BookUploadsMeta: Codable, Hashable, Sendable {
    var maxMb: Int?
    var maxFiles: Int?
    /// Where ePubs can also be emailed to; nil when the server has no mail-in address.
    var email: String?

    enum CodingKeys: String, CodingKey { case maxMb, maxFiles, email }

    init(maxMb: Int? = nil, maxFiles: Int? = nil, email: String? = nil) {
        self.maxMb = maxMb
        self.maxFiles = maxFiles
        self.email = email
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        maxMb = c.lenientInt(.maxMb)
        maxFiles = c.lenientInt(.maxFiles)
        email = c.lenientString(.email).flatMap { $0.isEmpty ? nil : $0 }
    }
}

/// One ePub that became a request waiting for the owner.
struct SentBook: Codable, Hashable, Sendable {
    var requestId: Int?
    var title: String

    enum CodingKeys: String, CodingKey { case requestId, title }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        requestId = c.lenientInt(.requestId)
        title = c.lenientString(.title) ?? ""
    }
}

/// One ePub the server would not take, with its sentence and a machine code
/// (in_library, already_requested, pending_limit, unreadable, failed).
struct RefusedBook: Codable, Hashable, Sendable {
    var file: String
    var message: String
    var code: String?

    enum CodingKeys: String, CodingKey { case file, message, code }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        file = c.lenientString(.file) ?? ""
        message = c.lenientString(.message) ?? ""
        code = c.lenientString(.code)
    }
}

/// The answer to an upload: 201 when at least one was sent, 422 (with `sent`) when none were.
struct BookUploadResponse: Decodable, Sendable, Equatable {
    var message: String?
    var sent: [SentBook]
    var refused: [RefusedBook]

    enum CodingKeys: String, CodingKey { case message, sent, refused }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        message = c.lenientString(.message)
        sent = c.lossyArray(SentBook.self, .sent)
        refused = c.lossyArray(RefusedBook.self, .refused)
    }
}

// MARK: - New chapters feed

/// GET /updates?since=<cursor>: series that had nothing left to read and now
/// do; without `since` it answers no series, only a cursor.
struct UpdatesFeed: Decodable, Sendable, Equatable {
    struct Series: Decodable, Sendable, Equatable, Hashable {
        var novelId: Int
        var name: String
        var medium: String
        var newCount: Int
        var chapterId: Int?
        var chapterLabel: String?
        var latestAt: String?

        enum CodingKeys: String, CodingKey { case novelId, name, medium, newCount, chapterId, chapterLabel, latestAt }

        init(from decoder: Decoder) throws {
            let c = try decoder.container(keyedBy: CodingKeys.self)
            guard let id = c.lenientInt(.novelId) else {
                throw DecodingError.keyNotFound(CodingKeys.novelId, .init(codingPath: c.codingPath, debugDescription: "novel_id"))
            }
            novelId = id
            name = c.lenientString(.name) ?? ""
            medium = c.lenientString(.medium) ?? Medium.novel
            newCount = c.lenientInt(.newCount) ?? 0
            chapterId = c.lenientInt(.chapterId)
            chapterLabel = c.lenientString(.chapterLabel)
            latestAt = c.lenientString(.latestAt)
        }
    }

    /// Opaque: sent back as `since` next time.
    var cursor: String?
    var series: [Series]

    enum CodingKeys: String, CodingKey { case cursor, series }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        cursor = c.lenientString(.cursor)
        series = c.lossyArray(Series.self, .series)
    }
}
