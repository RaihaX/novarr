import SwiftUI

// OWNER: stats / readers area. Replace the placeholder views.

extension View {
    func statsDestinations() -> some View {
        navigationDestination(for: StatsRoute.self) { _ in
            Text("Your stats")
        }
    }
}

/// Settings › Your stats.
struct SettingsStatsSection: View {
    var body: some View { EmptyView() }
}
