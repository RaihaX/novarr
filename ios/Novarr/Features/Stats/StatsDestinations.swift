import SwiftUI

extension View {
    func statsDestinations() -> some View {
        navigationDestination(for: StatsRoute.self) { _ in
            StatsView()
        }
    }
}

/// Settings › Your stats: streaks, totals and graphs of the reader's own
/// reading and listening (android SettingsScreen's first row).
struct SettingsStatsSection: View {
    var body: some View {
        Section {
            NavigationLink(value: StatsRoute()) {
                LabeledContent {
                    Text("Streaks, totals, graphs")
                } label: {
                    Label("Your stats", systemImage: "chart.bar.xaxis")
                }
            }
        }
    }
}
