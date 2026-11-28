import Foundation
import Observation

/// The Stats screen: the chosen range's answer, the saved copy first (so it
/// shows at once and offline), then a fresh one when the server is reachable.
/// Mirrors android ui/stats/StatsViewModel.kt.
@MainActor
@Observable
final class StatsModel {
    private(set) var range = StatsFormat.ranges[0]
    private(set) var stats: ReadingStats?
    private(set) var fromCache = false
    private(set) var failure: String?
    private var loadToken = 0

    func select(_ newRange: Int, app: AppModel) async {
        guard newRange != range else { return }
        range = newRange
        await load(app: app)
    }

    func load(app: AppModel) async {
        loadToken += 1
        let token = loadToken
        let wanted = range
        let cached = await app.cached(ReadingStats.self, .stats(wanted))
        guard token == loadToken else { return }
        // Another range's figures are not this one's: the saved copy, or a spinner.
        if cached != nil || stats?.range != wanted {
            stats = cached
            fromCache = cached != nil
        }
        let result = await app.load(.stats(wanted)) { try await $0.stats(range: wanted) }
        // A newer request (another range) owns the screen now.
        guard token == loadToken else { return }
        if let value = result.value { stats = value }
        fromCache = result.fromCache
        failure = result.value != nil ? nil : StatsFormat.failureMessage(result.error)
    }
}
