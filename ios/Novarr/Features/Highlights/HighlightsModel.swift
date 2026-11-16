import Foundation
import Observation

@MainActor
@Observable
final class HighlightsModel {
    static let perPage = 50

    let novelId: Int?
    private(set) var list = PagedList<Highlight>()
    private(set) var phase: LoadPhase = .idle
    private(set) var isLoadingMore = false
    private(set) var moreError: String?
    /// A delete that didn't go through; shown as an alert.
    var actionError: String?

    @ObservationIgnored private var generation = 0

    init(novelId: Int?) {
        self.novelId = novelId
    }

    var highlights: [Highlight] { list.items }

    func load(app: AppModel) async {
        guard let client = app.client else { return }
        generation += 1
        let current = generation
        if !list.hasLoaded { phase = .loading }
        moreError = nil
        isLoadingMore = false
        do {
            let page = try await client.highlights(novelId: novelId, page: 1, perPage: Self.perPage)
            guard current == generation else { return }
            app.noteSuccess()
            list.reset()
            list.apply(page: 1, items: page.highlights, hasMore: page.hasMore)
            phase = .loaded
        } catch {
            guard current == generation, !(error is CancellationError) else { return }
            let apiError = APIError.from(error)
            app.noteFailure(apiError)
            let message = Self.message(for: apiError)
            if list.hasLoaded {
                actionError = message
            } else {
                phase = .failed(message)
            }
        }
    }

    func loadMoreIfNeeded(after highlight: Highlight, app: AppModel) async {
        guard highlight.id == list.items.last?.id, moreError == nil else { return }
        await loadMore(app: app)
    }

    func loadMore(app: AppModel) async {
        guard let client = app.client, list.canLoadMore, !isLoadingMore else { return }
        let current = generation
        let page = list.nextPage
        isLoadingMore = true
        moreError = nil
        defer { if current == generation { isLoadingMore = false } }
        do {
            let answer = try await client.highlights(novelId: novelId, page: page, perPage: Self.perPage)
            guard current == generation else { return }
            list.apply(page: page, items: answer.highlights, hasMore: answer.hasMore)
        } catch {
            guard current == generation, !(error is CancellationError) else { return }
            moreError = Self.message(for: APIError.from(error))
        }
    }

    /// Removes it straight away and puts it back if the server refuses.
    func delete(_ highlight: Highlight, app: AppModel) async {
        guard let client = app.client, let removed = list.remove(id: highlight.id) else { return }
        do {
            try await client.deleteHighlight(id: highlight.id)
            app.noteSuccess()
        } catch {
            let apiError = APIError.from(error)
            app.noteFailure(apiError)
            list.restore(removed.item, at: removed.index)
            actionError = apiError.isConnectivity
                ? "Couldn't delete the highlight: the server can't be reached. Try again when you're back online."
                : "Couldn't delete the highlight. \(apiError.localizedDescription)"
        }
    }

    static func message(for error: APIError) -> String {
        if case .http(404, _) = error {
            return "This Novarr server doesn't offer highlights yet. Update the server to see them here."
        }
        return error.localizedDescription
    }
}
