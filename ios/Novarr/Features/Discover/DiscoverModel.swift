import Foundation
import Observation

@MainActor
@Observable
final class DiscoverModel {
    enum Phase: Equatable {
        case idle, loading, loaded, cancelled
        case failed(String)
    }

    enum AddState: Equatable {
        case adding
        case added(novelId: Int, alreadyInLibrary: Bool)
        case failed(String)
    }

    static let sourceDidNotAnswer = "That source didn't answer. Try again or pick another source."

    private(set) var sources: [DiscoverSource] = []
    private(set) var sourcesPhase: LoadPhase = .idle
    private(set) var selectedSourceId: String?
    private(set) var selectedListId: String?
    var searchText = ""

    /// What `results` belong to (nil: nothing to show yet).
    private(set) var query: DiscoverQuery?
    private(set) var results = PagedList<DiscoverItem>()
    private(set) var phase: Phase = .idle
    private(set) var isLoadingMore = false
    private(set) var moreError: String?
    private(set) var addStates: [DiscoverItem.ID: AddState] = [:]

    @ObservationIgnored private var task: Task<Void, Never>?

    var selectedSource: DiscoverSource? { sources.first { $0.id == selectedSourceId } }
    var selectedList: DiscoverList? { selectedSource?.browseLists.first { $0.id == selectedListId } }

    func item(id: DiscoverItem.ID) -> DiscoverItem? { results.items.first { $0.id == id } }

    // MARK: Sources

    func loadSources(app: AppModel) async {
        guard let client = app.client else { return }
        sourcesPhase = .loading
        do {
            let loaded = try await client.discoverSources()
            app.noteSuccess()
            sources = loaded
            sourcesPhase = .loaded
            if selectedSource == nil {
                selectedSourceId = (loaded.first(where: \.isDefault) ?? loaded.first)?.id
                selectedListId = selectedSource?.browseLists.first?.id
            }
            if query == nil { show(app: app) }
        } catch is CancellationError {
            sourcesPhase = sources.isEmpty ? .idle : .loaded
        } catch {
            let apiError = APIError.from(error)
            app.noteFailure(apiError)
            sourcesPhase = .failed(apiError.localizedDescription)
        }
    }

    func select(sourceId: String, app: AppModel) {
        guard sourceId != selectedSourceId else { return }
        selectedSourceId = sourceId
        let lists = selectedSource?.browseLists ?? []
        if !lists.contains(where: { $0.id == selectedListId }) { selectedListId = lists.first?.id }
        // Keeps a search going on the new source; otherwise shows its list.
        show(app: app)
    }

    func select(listId: String, app: AppModel) {
        selectedListId = listId
        searchText = ""
        show(app: app)
    }

    func submitSearch(app: AppModel) { show(app: app) }

    /// Called when the search field empties: back to the chosen list.
    func searchCleared(app: AppModel) {
        guard query?.isSearch == true || phase == .cancelled else { return }
        show(app: app)
    }

    // MARK: Results

    /// Loads page 1 of whatever the source/list/search text now describe.
    func show(app: AppModel) {
        let next = DiscoverQuery.make(source: selectedSource, listId: selectedListId, searchText: searchText)
        guard let next else {
            cancelTask()
            query = nil
            results.reset()
            phase = .idle
            return
        }
        fetch(next, page: 1, app: app)
    }

    /// Pull to refresh / try again: page 1 of the same query. `random` draws a new list.
    func reload(app: AppModel) async {
        if let query {
            fetch(query, page: 1, app: app)
        } else {
            show(app: app)
        }
        await task?.value
    }

    func loadMoreIfNeeded(after item: DiscoverItem, app: AppModel) {
        guard item.id == results.items.last?.id, moreError == nil else { return }
        loadMore(app: app)
    }

    func loadMore(app: AppModel) {
        guard let query, results.canLoadMore, !isLoadingMore, phase == .loaded else { return }
        fetch(query, page: results.nextPage, app: app)
    }

    /// Abandons the request in flight (the server may still finish it).
    func cancel() {
        guard task != nil else { return }
        cancelTask()
        if phase == .loading { phase = .cancelled }
    }

    private func cancelTask() {
        task?.cancel()
        task = nil
        isLoadingMore = false
    }

    private func fetch(_ query: DiscoverQuery, page: Int, app: AppModel) {
        guard let client = app.client else { return }
        cancelTask()
        moreError = nil
        if page == 1 {
            self.query = query
            results.reset()
            phase = .loading
        } else {
            isLoadingMore = true
        }
        task = Task { [weak self] in
            do {
                let answer = try await client.discoverBrowse(
                    type: query.type, source: query.sourceId, query: query.text, page: page)
                guard let self, !Task.isCancelled, self.query == query else { return }
                app.noteSuccess()
                self.results.apply(page: page, items: answer.items, hasMore: answer.hasMore)
                self.phase = .loaded
                self.isLoadingMore = false
                self.task = nil
            } catch {
                guard let self, !Task.isCancelled, !(error is CancellationError), self.query == query else { return }
                let apiError = APIError.from(error)
                app.noteFailure(apiError)
                let message = Self.message(for: apiError)
                if page == 1 {
                    self.phase = .failed(message)
                } else {
                    self.moreError = message
                }
                self.isLoadingMore = false
                self.task = nil
            }
        }
    }

    static func message(for error: APIError) -> String {
        switch error {
        case .http(502, _), .http(504, _): sourceDidNotAnswer
        case .timedOut: "The source took too long to answer. Try again or pick another source."
        default: error.localizedDescription
        }
    }

    // MARK: Adding

    func add(_ item: DiscoverItem, app: AppModel) async {
        guard let client = app.client, app.permissions.canManageLibrary, addStates[item.id] != .adding else { return }
        addStates[item.id] = .adding
        do {
            let response = try await client.addNovel(title: item.title, url: item.url)
            app.noteSuccess()
            results.update(id: item.id) {
                $0.inLibrary = true
                $0.novelId = response.novel.id
            }
            addStates[item.id] = .added(novelId: response.novel.id, alreadyInLibrary: response.alreadyInLibrary)
            app.libraryChanged()
            // A new novel has no chapter list yet: a check fetches it.
            if !response.alreadyInLibrary { app.startCheck(novelId: response.novel.id) }
        } catch {
            let apiError = APIError.from(error)
            app.noteFailure(apiError)
            addStates[item.id] = .failed(Self.addMessage(for: apiError))
        }
    }

    static func addMessage(for error: APIError) -> String {
        switch error {
        case .http(422, let message?), .http(503, let message?):
            message
        case .http(503, nil):
            "Another novel is being added right now. Try again in a moment."        default:
            error.localizedDescription
        }
    }
}
