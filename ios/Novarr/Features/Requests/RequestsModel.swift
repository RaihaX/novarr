import Foundation
import Observation

/// "Request it anyway": a request typed in by hand.
struct FreeTextDraft: Equatable {
    var title = ""
    var medium: RequestMedium = .defaultMedium
    var link = ""
    var note = ""
}

/// A 409 `duplicate` on approve: the server's sentence, and "Add anyway" resends with force.
struct DuplicatePrompt: Identifiable, Equatable {
    var request: ReaderRequest
    var message: String
    var id: Int { request.id }
}

/// The Requests screen (android ui/requests/RequestsViewModel.kt): search
/// (kept app-wide per session, so it survives leaving the screen), the
/// Discover shelf, my requests, and the owner's review queue. Writes go
/// straight to the server, never through the offline write queue.
///
/// A running search is polled only while the screen is visible
/// (`setVisible`); returning picks the polling up again.
@MainActor
@Observable
final class RequestsModel {
    @ObservationIgnored private weak var app: AppModel?
    @ObservationIgnored private let sessionID: String
    @ObservationIgnored private let hub: NotificationHub

    var section: RequestsRoute.Section

    // MARK: Search

    var query = ""
    private(set) var medium: RequestMedium = .defaultMedium
    private(set) var search: RequestSearch?
    private(set) var starting = false
    private(set) var searchError: String?
    private(set) var slow = false
    /// Results with "Me too" running.
    private(set) var joining: Set<String> = []
    /// False once the server answered a "Me too" with 409 `already_requested` (an older server).
    private(set) var canJoin = true
    /// The search button waits until then (system uptime) after a 429.
    private(set) var blockedUntil: TimeInterval?
    @ObservationIgnored private var startedAt: TimeInterval = 0
    @ObservationIgnored private var pollTask: Task<Void, Never>?
    @ObservationIgnored private var visible = false

    var searching: Bool { starting || search?.isRunning == true }

    // MARK: Lists

    private(set) var mine: [ReaderRequest] = []
    private(set) var pendingLimit: Int?
    private(set) var canDecide = false
    private(set) var review: [ReaderRequest] = []
    private(set) var listsLoaded = false
    private(set) var listsFailure: String?
    @ObservationIgnored private var rankTask: Task<Void, Never>?

    /// Requests with an action running.
    private(set) var busy: Set<Int> = []
    /// The chosen shortlist candidate per request; unset means the best one.
    var choices: [Int: Int] = [:]
    /// The series page URL typed for a request without a shortlist.
    var urls: [Int: String] = [:]

    // MARK: Sheets and dialogs

    var requestSheet: SearchResult?
    private(set) var requestFromShelf = false
    var requestNote = ""
    var freeText: FreeTextDraft?
    var declineSheet: ReaderRequest?
    var declineReason = ""
    var confirmCancel: ReaderRequest?
    var confirmLeave: ReaderRequest?
    var duplicate: DuplicatePrompt?
    private(set) var sending = false
    var sheetError: String?
    var errorDialog: String?
    /// A one-off line for the toast; the screen clears it once shown.
    var notice: String?

    // MARK: Discover shelf (follows `medium`)

    private(set) var browseMedium: RequestMedium?
    private(set) var browseLists: [BrowseList] = []
    private(set) var browseList: String?
    private(set) var browseResults: [SearchResult] = []
    private(set) var browseSourceName: String?
    private(set) var browseHasMore = false
    private(set) var browseLoading = false
    private(set) var browseLoadingMore = false
    private(set) var browseError: String?
    private(set) var browseFromCache = false
    private(set) var browseLoaded = false
    @ObservationIgnored private var browsePage = 1
    @ObservationIgnored private var browsePageOf: [String: Int] = [:]
    @ObservationIgnored private var browseTask: Task<Void, Never>?
    @ObservationIgnored private var browseGeneration = 0

    // MARK: Add to category (long-press a card)

    private(set) var categorySheet: SearchResult?
    @ObservationIgnored private var categoryFromShelf = false
    private(set) var categorySending = false

    static var now: TimeInterval { ProcessInfo.processInfo.systemUptime }

    init(app: AppModel, section: RequestsRoute.Section, hub: NotificationHub = .shared) {
        self.app = app
        self.hub = hub
        sessionID = app.sessionID
        self.section = section
        if let saved = hub.searchMemory, saved.session == sessionID {
            let memory = saved.memory
            query = memory.query
            medium = memory.medium
            search = memory.search
            startedAt = memory.startedAt
            if let until = memory.blockedUntil, until > Self.now {
                blockedUntil = until
                searchError = memory.blockedMessage
            }
        } else {
            let remembered = RequestMedium.from(hub.prefs.requestMedium)
            // A saved Comics on a server without comics is Books.
            medium = mediums.contains(remembered) ? remembered : .defaultMedium
        }
        loadBrowse()
        #if DEBUG
        // Debug builds: `-debugRequestsSection mine|review` and `-debugRequestsQuery <text>`
        // (searches at once), for simulator screenshots without UI automation.
        let defaults = UserDefaults.standard
        if let raw = defaults.string(forKey: "debugRequestsSection") { self.section = .from(raw) }
        if let medium = defaults.string(forKey: "debugRequestsMedium") { selectMedium(RequestMedium.from(medium)) }
        if let text = defaults.string(forKey: "debugRequestsQuery"), search == nil {
            query = text
            Task { [weak self] in self?.startSearch() }
        }
        #endif
    }

    private var client: APIClient? {
        guard let app, app.sessionID == sessionID else { return nil }
        return app.client
    }

    /// The picker's options: Comics only when the server knows them (meta `comics`).
    var mediums: [RequestMedium] { RequestMedium.offered(comics: app?.serverMeta?.comics == true) }

    /// The signed-in account is the owner (always, with sign-in off).
    var isAdmin: Bool { app.map { $0.account == nil || $0.account?.user.role == .admin } ?? false }

    var decidesElsewhere: Bool { listsLoaded && Requests.decidesElsewhere(isAdmin: isAdmin, canDecide: canDecide) }

    /// Search, My requests, and To review for the owner.
    var sections: [RequestsRoute.Section] {
        canDecide || decidesElsewhere ? [.search, .mine, .review] : [.search, .mine]
    }

    /// The section shown: a review asked for before the lists say whether this account may decide shows My requests.
    var currentSection: RequestsRoute.Section {
        if sections.contains(section) { return section }
        return section == .review && !listsLoaded ? .mine : .search
    }

    // MARK: Medium and the shelf

    func selectMedium(_ next: RequestMedium) {
        if next == medium, browseMedium == next { return }
        medium = next
        remember()
        hub.prefs.requestMedium = next.wire
        loadBrowse()
    }

    func selectBrowseList(_ id: String) {
        if id == browseList, browseMedium == medium, !browseResults.isEmpty { return }
        loadBrowse(list: id)
    }

    /// Page 1 of `list` (nil: the medium's first) for the current medium,
    /// cached per medium and list; offline, the saved copy shows.
    func loadBrowse(list: String? = nil) {
        let target = medium
        let sameMedium = browseMedium == target
        browseTask?.cancel()
        browseGeneration += 1
        let generation = browseGeneration
        guard target.hasDiscover else {
            // No Discover lists for this medium (Comics): no request, no shelf.
            browseMedium = target
            browseList = nil
            browseLists = []
            browseResults = []
            browseHasMore = false
            browseSourceName = nil
            browseError = nil
            browseFromCache = false
            browseLoading = false
            browseLoaded = true
            return
        }
        if !sameMedium {
            browseLists = []
            browseSourceName = nil
            browseLoaded = false
        }
        if !sameMedium || (list != nil && list != browseList) {
            browseResults = []
            browseHasMore = false
        }
        browseMedium = target
        browseList = list ?? (sameMedium ? browseList : nil)
        browseError = nil
        browseLoading = true
        let wanted = browseList
        guard let app else { return }
        browseTask = Task { [weak self] in
            let loaded: Loaded<BrowsePage> = await app.load(.custom(Requests.browseCachePath(target.wire, wanted))) { client in
                try await client.requestBrowse(medium: target.wire, list: wanted)
            }
            guard let self, generation == self.browseGeneration else { return }
            defer {
                if generation == self.browseGeneration {
                    self.browseLoading = false
                    self.browseLoaded = true
                }
            }
            if let page = loaded.value {
                // Saved under its own list too, so choosing that chip later shows it offline.
                if wanted == nil, !loaded.fromCache, let id = page.list, let store = app.store {
                    await store.save(page, as: .custom(Requests.browseCachePath(target.wire, id)))
                }
                self.showBrowse(page, append: false)
                self.browseFromCache = loaded.fromCache
                self.browseError = loaded.fromCache ? loaded.error.map(Requests.message) : nil
            } else if loaded.error?.status == 404 {
                // A server from before the shelf (or with it off): no shelf, no error.
                self.browseLists = []
                self.browseResults = []
                self.browseError = nil
            } else {
                self.browseError = loaded.error.map(Requests.message) ?? "Couldn't load this list right now."
            }
        }
    }

    /// The next page of the chosen list (not cached).
    func loadMoreBrowse() {
        guard let client, let list = browseList, let target = browseMedium else { return }
        if browseLoadingMore || browseLoading || !browseHasMore || browsePage >= Requests.browseMaxPage { return }
        browseLoadingMore = true
        let nextPage = browsePage + 1
        Task {
            defer { browseLoadingMore = false }
            do {
                let next = try await client.requestBrowse(medium: target.wire, list: list, page: nextPage)
                app?.noteSuccess()
                if browseMedium == target, browseList == list { showBrowse(next, append: true) }
            } catch {
                let api = APIError.from(error)
                app?.noteFailure(api)
                notice = Requests.message(api)
            }
        }
    }

    private func showBrowse(_ page: BrowsePage, append: Bool) {
        browseLists = page.lists
        browseList = page.list ?? browseList ?? page.lists.first?.id
        browseSourceName = page.sourceName.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 }
        browseHasMore = page.hasMore
        browsePage = max(page.page, 1)
        if !append { browsePageOf = [:] }
        for result in page.results where browsePageOf[result.key] == nil { browsePageOf[result.key] = browsePage }
        var seen = Set<String>()
        browseResults = ((append ? browseResults : []) + page.results).filter { seen.insert($0.key).inserted }
    }

    private func remember() {
        hub.searchMemory = (sessionID, Requests.SearchMemory(
            query: query, medium: medium, search: search, startedAt: startedAt, blockedUntil: blockedUntil,
            blockedMessage: blockedUntil != nil ? searchError : nil))
    }

    /// The screen appeared (true) or went away: polling runs only in between.
    func setVisible(_ on: Bool) {
        visible = on
        if on {
            pollSearch()
            scheduleRankingRefresh()
        } else {
            pollTask?.cancel()
            rankTask?.cancel()
            remember()
        }
    }

    // MARK: Search

    func startSearch() {
        let text = Requests.searchText(query)
        guard let client, !text.isEmpty, !searching, !isBlocked() else { return }
        pollTask?.cancel()
        searchError = nil
        slow = false
        starting = true
        let medium = medium
        Task {
            defer { starting = false }
            do {
                let started = try await client.startRequestSearch(query: text, medium: medium.wire)
                app?.noteSuccess()
                search = started
                startedAt = Self.now
                remember()
                pollSearch()
            } catch {
                let api = APIError.from(error)
                app?.noteFailure(api)
                if api.status == 429 { blockedUntil = Self.now + TimeInterval(RetryAfter.seconds(api.retryAfter)) }
                searchError = Requests.message(api)
                remember()
            }
        }
    }

    func isBlocked(now: TimeInterval = RequestsModel.now) -> Bool { RetryAfter.remaining(blockedUntil, now: now) > 0 }

    /// Called by the countdown once Retry-After has passed.
    func unblock() {
        guard !isBlocked(), blockedUntil != nil else { return }
        blockedUntil = nil
        searchError = nil
        remember()
    }

    private func pollSearch() {
        pollTask?.cancel()
        guard let current = search, visible, current.isRunning else { return }
        pollTask = Task { [weak self] in
            var id = current.id
            while !Task.isCancelled {
                guard let self else { return }
                self.slow = Self.now - self.startedAt >= Requests.slowAfter
                try? await Task.sleep(for: .seconds(Requests.pollInterval))
                if Task.isCancelled { break }
                guard let client = self.client else { break }
                do {
                    let next = try await client.requestSearch(id: id)
                    self.app?.noteSuccess()
                    self.search = next
                    id = next.id
                    if !next.isRunning { break }
                } catch {
                    if Task.isCancelled { break }
                    let api = APIError.from(error)
                    self.app?.noteFailure(api)
                    // Offline for a moment: keep asking; the search goes on without us.
                    if api.isConnectivity { continue }
                    // 404 search_expired (or anything else): say so; the user searches again.
                    self.search = nil
                    self.searchError = Requests.message(api)
                    break
                }
            }
            self?.slow = false
            self?.remember()
        }
    }

    func openRequestSheet(_ result: SearchResult, fromShelf: Bool = false) {
        requestSheet = result
        requestFromShelf = fromShelf
        requestNote = ""
        sheetError = nil
    }

    func openFreeText() {
        // A pasted link is not a title: the form starts empty then (the link goes in its own field).
        let typed = search?.query ?? query.trimmingCharacters(in: .whitespacesAndNewlines)
        let isLink = Requests.looksLikeURL(typed)
        freeText = FreeTextDraft(title: isLink ? "" : String(typed.prefix(255)),
                                 medium: RequestMedium.from(search?.medium, fallback: medium),
                                 link: isLink ? String(typed.prefix(Requests.linkMax)) : "")
        sheetError = nil
    }

    private func createFromShelf(_ client: APIClient, _ result: SearchResult, note: String?,
                                 categories: [Int]? = nil) async throws -> CreatedRequest {
        guard let list = browseList else { throw APIError.decoding("no list") }
        return try await client.createRequestFromBrowse(medium: (browseMedium ?? medium).wire, list: list,
                                                        page: browsePageOf[result.key] ?? 1, key: result.key,
                                                        note: note, categories: categories)
    }

    private func cleanNote(_ text: String) -> String? {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        return trimmed.isEmpty ? nil : String(trimmed.prefix(Requests.noteMax))
    }

    func sendRequest() {
        guard let result = requestSheet, let client else { return }
        let shelf = requestFromShelf
        let searchId = search?.id
        if !shelf, searchId == nil { return }
        sending = true
        sheetError = nil
        let note = cleanNote(requestNote)
        Task {
            defer { sending = false }
            do {
                if shelf {
                    _ = try await createFromShelf(client, result, note: note)
                } else {
                    _ = try await client.createRequest(searchId: searchId!, key: result.key, note: note)
                }
                app?.noteSuccess()
                updateResults { Requests.markRequested($0, key: result.key) }
                requestSheet = nil
                notice = Requests.requestedNotice
                loadLists()
            } catch {
                let api = APIError.from(error)
                app?.noteFailure(api)
                if correctCard(api, result) { requestSheet = nil } else { sheetError = Requests.message(api) }
            }
        }
    }

    /// "Me too" on someone else's request: the same POST as Request, without a note.
    func meToo(_ result: SearchResult, fromShelf: Bool = false) {
        let searchId = search?.id
        guard let client, fromShelf || searchId != nil, !joining.contains(result.key) else { return }
        joining.insert(result.key)
        Task {
            defer { joining.remove(result.key) }
            do {
                let created = fromShelf
                    ? try await createFromShelf(client, result, note: nil)
                    : try await client.createRequest(searchId: searchId!, key: result.key, note: nil)
                app?.noteSuccess()
                updateResults { Requests.markRequested($0, key: result.key) }
                notice = created.joined ? Requests.joinedNotice : Requests.requestedNotice
                loadLists()
            } catch {
                let api = APIError.from(error)
                app?.noteFailure(api)
                if api.code == "already_requested" { canJoin = false }
                if !correctCard(api, result) { errorDialog = Requests.message(api) }
            }
        }
    }

    /// Fields some refusals carry (409 in_library / already_requested).
    private struct RefusalBody: Decodable {
        var novelId: Int?
        var byMe: Bool?
        var addedToCategories: [Int]?
        var requestedCategories: [Int]?
    }

    private func refusalBody(_ error: APIError) -> RefusalBody? {
        if case .rejected(let failure) = error { return failure.decode(RefusalBody.self) }
        return nil
    }

    /// A refusal that means the card was out of date: corrects it and says why. False for anything else.
    private func correctCard(_ error: APIError, _ result: SearchResult) -> Bool {
        let body = refusalBody(error)
        switch error.code {
        case "in_library":
            updateResults { list in
                list.map { item in
                    guard item.key == result.key else { return item }
                    var copy = item
                    copy.inLibrary = true
                    copy.novelId = body?.novelId ?? item.novelId
                    return copy
                }
            }
            errorDialog = Requests.message(error)
            return true
        case "already_requested":
            updateResults { Requests.markTaken($0, key: result.key, byMe: body?.byMe ?? false) }
            errorDialog = Requests.message(error)
            return true
        default:
            return false
        }
    }

    func sendFreeText() {
        guard let draft = freeText, let client else { return }
        let title = draft.title.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !title.isEmpty else { return }
        sending = true
        sheetError = nil
        let link = draft.link.trimmingCharacters(in: .whitespacesAndNewlines)
        let note = cleanNote(draft.note)
        Task {
            defer { sending = false }
            do {
                _ = try await client.createFreeTextRequest(title: title, medium: draft.medium.wire,
                                                           link: link.isEmpty ? nil : link, note: note)
                app?.noteSuccess()
                freeText = nil
                notice = Requests.requestedNotice
                loadLists()
            } catch {
                app?.noteFailure(APIError.from(error))
                sheetError = Requests.message(error)
            }
        }
    }

    // MARK: Add to category

    func openCategorySheet(_ result: SearchResult, fromShelf: Bool) {
        categorySheet = result
        categoryFromShelf = fromShelf
    }

    func closeCategorySheet() {
        if !categorySending { categorySheet = nil }
    }

    /// In the library: one memberships call; not yet: a request (new, "Me
    /// too", or the reader's own updated) carrying the categories.
    func confirmCategories(_ chosen: Set<Int>) {
        guard let result = categorySheet, let app, let client else { return }
        let action = Categories.browseAction(result, chosen: chosen)
        let fromShelf = categoryFromShelf
        let searchId = search?.id
        switch action {
        case .nothing:
            categorySheet = nil
        case .memberships(let novelId, let add, let remove):
            categorySending = true
            Task {
                let failure = await app.lists.setMemberships(novelIds: [novelId], add: add, remove: remove)
                categorySending = false
                if let failure {
                    notice = failure
                } else {
                    updateResults { list in list.map { $0.key == result.key ? Categories.afterBrowse($0, action: action) : $0 } }
                    categorySheet = nil
                    notice = "Saved."
                }
            }
        case .request(let categories):
            if !fromShelf, searchId == nil { return }
            categorySending = true
            Task {
                defer { categorySending = false }
                do {
                    let created = fromShelf
                        ? try await createFromShelf(client, result, note: nil, categories: categories)
                        : try await client.createRequest(searchId: searchId!, key: result.key, note: nil, categories: categories)
                    app.noteSuccess()
                    updateResults { list in list.map { $0.key == result.key ? Categories.afterBrowse($0, action: action) : $0 } }
                    categorySheet = nil
                    notice = created.joined
                        ? "Me too: you'll be told when it's ready, and it goes into your categories."
                        : "Requested. Once it's in the library it goes into your categories."
                    loadLists()
                } catch {
                    let api = APIError.from(error)
                    app.noteFailure(api)
                    let body = refusalBody(api)
                    switch api.code {
                    case "in_library":
                        // In the library already: the server put it in the categories straight away.
                        let added = body?.addedToCategories ?? categories
                        updateResults { list in
                            list.map { item in
                                guard item.key == result.key else { return item }
                                var copy = item
                                copy.inLibrary = true
                                copy.novelId = body?.novelId ?? item.novelId
                                copy.inCategories = Array(Set(item.inCategories + added)).sorted()
                                return copy
                            }
                        }
                        categorySheet = nil
                        notice = "It's in the library already, so it was added to your categories."
                        await app.lists.refresh()
                        app.libraryChanged()
                    case "already_requested":
                        let remembered = body?.requestedCategories
                        updateResults { list in
                            Requests.markTaken(list, key: result.key, byMe: body?.byMe ?? false).map { item in
                                guard item.key == result.key, let remembered else { return item }
                                var copy = item
                                copy.requestedCategories = remembered
                                return copy
                            }
                        }
                        categorySheet = nil
                        notice = remembered != nil
                            ? "Saved: it goes into your categories once it's in the library."
                            : Requests.message(api)
                    default:
                        notice = Requests.message(api)
                    }
                }
            }
        }
    }

    /// Applies to the search's results and the shelf's alike (a key names the same series in both).
    private func updateResults(_ transform: ([SearchResult]) -> [SearchResult]) {
        if var current = search {
            current.results = transform(current.results)
            search = current
        }
        browseResults = transform(browseResults)
        remember()
    }

    // MARK: Lists

    private static let maxPages = 10
    private static let rankRefresh: TimeInterval = 5

    func loadLists() {
        Task { await reloadLists() }
    }

    func reloadLists() async {
        guard let client else { return }
        do {
            let first = try await client.requests(scope: "mine")
            var all = first.requests
            var page = first
            while page.hasMore, page.page < Self.maxPages {
                page = try await client.requests(scope: "mine", page: page.page + 1)
                all += page.requests
            }
            var seen = Set<Int>()
            mine = all.filter { seen.insert($0.id).inserted }
            pendingLimit = first.pendingLimit
            canDecide = first.canDecide
            review = first.canDecide ? try await loadReview(client) : []
            listsFailure = nil
            app?.noteSuccess()
        } catch {
            let api = APIError.from(error)
            app?.noteFailure(api)
            listsFailure = Requests.message(api)
        }
        listsLoaded = true
        scheduleRankingRefresh()
    }

    /// Everyone's pending requests, every page, oldest first.
    private func loadReview(_ client: APIClient) async throws -> [ReaderRequest] {
        var all: [ReaderRequest] = []
        var page = 0
        var next: RequestsPage
        repeat {
            page += 1
            next = try await client.requests(scope: "all", status: "pending", page: page)
            all += next.requests
        } while next.hasMore && page < Self.maxPages
        return Requests.reviewQueue(all)
    }

    /// While a manga shortlist is being ranked, look again every few seconds (screen visible only).
    private func scheduleRankingRefresh() {
        rankTask?.cancel()
        guard visible, review.contains(where: Requests.rankingPending) else { return }
        rankTask = Task { [weak self] in
            try? await Task.sleep(for: .seconds(Self.rankRefresh))
            guard !Task.isCancelled else { return }
            await self?.reloadLists()
        }
    }

    // MARK: Actions

    private func act(_ request: ReaderRequest, _ block: @escaping (APIClient) async throws -> Void) {
        guard let client, !busy.contains(request.id) else { return }
        busy.insert(request.id)
        Task {
            defer { busy.remove(request.id) }
            do {
                try await block(client)
                app?.noteSuccess()
            } catch {
                let api = APIError.from(error)
                app?.noteFailure(api)
                if api.code == "duplicate" {
                    duplicate = DuplicatePrompt(request: request, message: Requests.message(api))
                } else {
                    errorDialog = Requests.message(api)
                }
            }
        }
    }

    func cancel(_ request: ReaderRequest) {
        confirmCancel = nil
        act(request) { [weak self] client in
            try await client.cancelRequest(id: request.id)
            self?.mine.removeAll { $0.id == request.id }
            self?.notice = "Request cancelled."
            self?.loadLists()
        }
    }

    /// Leaves a request this account joined with "Me too" (DELETE, 204).
    func leave(_ request: ReaderRequest) {
        confirmLeave = nil
        act(request) { [weak self] client in
            try await client.cancelRequest(id: request.id)
            self?.mine.removeAll { $0.id == request.id }
            self?.notice = "You left the request for \(request.title)."
            self?.loadLists()
        }
    }

    /// `force`: "Add anyway" after a 409 duplicate.
    func approve(_ request: ReaderRequest, force: Bool = false) {
        duplicate = nil
        let url = (urls[request.id] ?? "").trimmingCharacters(in: .whitespacesAndNewlines)
        let needsURL = Requests.needsURL(request)
        if needsURL, url.isEmpty { return }
        let candidate = Requests.selectedCandidate(request.candidates, chosen: choices[request.id])
        act(request) { [weak self] client in
            let result = needsURL
                ? try await client.approveRequest(id: request.id, url: url, title: request.title, force: force)
                : try await client.approveRequest(id: request.id, candidate: candidate, force: force)
            self?.review.removeAll { $0.id == request.id }
            self?.notice = result.created
                ? "Approved. \(request.title) is being added to the library."
                : "Approved. \(request.title) is already in the library."
            self?.app?.libraryChanged()
            self?.loadLists()
        }
    }

    func openDecline(_ request: ReaderRequest) {
        declineSheet = request
        declineReason = ""
        sheetError = nil
    }

    func decline() {
        guard let request = declineSheet, let client else { return }
        sending = true
        sheetError = nil
        let reason = cleanNote(declineReason)
        Task {
            defer { sending = false }
            do {
                _ = try await client.declineRequest(id: request.id, reason: reason)
                app?.noteSuccess()
                declineSheet = nil
                review.removeAll { $0.id == request.id }
                notice = "Declined."
                loadLists()
            } catch {
                app?.noteFailure(APIError.from(error))
                sheetError = Requests.message(error)
            }
        }
    }

    func rerank(_ request: ReaderRequest) {
        act(request) { [weak self] client in
            let updated = try await client.rerankRequest(id: request.id)
            self?.review = self?.review.map { $0.id == updated.id ? updated : $0 } ?? []
            self?.scheduleRankingRefresh()
        }
    }
}

extension Requests {
    /// Where a Discover page is cached: per medium and list, the list id kept to safe characters.
    static func browseCachePath(_ medium: String, _ list: String?) -> String {
        func safe(_ raw: String) -> String {
            String(raw.unicodeScalars.filter { CharacterSet.alphanumerics.contains($0) && $0.isASCII || $0 == "_" || $0 == "-" })
        }
        let name = list.map(safe).flatMap { $0.isEmpty ? nil : $0 } ?? "_first"
        let folder = safe(medium).isEmpty ? "other" : safe(medium)
        return "requests/browse/\(folder)/\(name).json"
    }
}
