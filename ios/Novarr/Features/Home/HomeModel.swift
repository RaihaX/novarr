import Foundation
import Observation

@MainActor
@Observable
final class HomeModel {
    private(set) var feed: HomeFeed?
    private(set) var phase: LoadPhase = .idle
    private(set) var isShowingCache = false
    /// Why the last load failed, for the failure state's title.
    private(set) var failure: APIError?
    private var loadedRevision = -1
    private var loadedAt: Date?

    func refreshIfStale(app: AppModel) async {
        let stale = loadedAt.map { Date().timeIntervalSince($0) > 60 } ?? true
        if stale || loadedRevision != app.readStateRevision {
            await load(app: app)
        }
    }

    func load(app: AppModel) async {
        if feed == nil {
            phase = .loading
            if let cached = await app.cached(HomeFeed.self, .home) {
                feed = cached
                isShowingCache = true
            }
        }
        loadedRevision = app.readStateRevision
        let result = await app.load(.home) { try await $0.home() }
        failure = result.error
        if let value = result.value {
            feed = value
            phase = .loaded
            loadedAt = result.fromCache ? nil : Date()
        } else if feed == nil {
            phase = .failed(result.error?.localizedDescription ?? "Couldn't load Home.")
        } else {
            phase = .loaded
        }
        isShowingCache = result.fromCache || (result.error != nil && feed != nil)
    }
}
