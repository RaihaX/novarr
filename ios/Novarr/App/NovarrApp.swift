import SwiftUI

@main
struct NovarrApp: App {
    @State private var app = AppModel()
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(app)
                .tint(.accentColor)
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active { Task { await app.flushWrites() } }
            // Checks poll only in the foreground and look again on return.
            switch phase {
            case .active: app.checks.setForeground(true)
            case .background: app.checks.setForeground(false)
            default: break
            }
        }
    }
}

/// Navigation values shared by every tab's stack.
struct NovelRoute: Hashable {
    var id: Int
    /// Shown immediately while the full detail loads.
    var summary: NovelSummary?
}

struct ReaderRoute: Hashable {
    var novelId: Int
    var chapterId: Int
    /// Opened by choosing a chapter (continue, resume, chapter list): check
    /// whether another device read this series more recently and offer to go
    /// there. Off for highlights and debug routes.
    var offerLatest = false
}

/// All highlights, or one novel's when `novelId` is set.
struct HighlightsRoute: Hashable {
    var novelId: Int?
    var novelName: String?
}

extension View {
    /// Registers the app's push destinations on a NavigationStack.
    func novarrDestinations() -> some View {
        navigationDestination(for: NovelRoute.self) { route in
            NovelView(route: route)
        }
        .navigationDestination(for: ReaderRoute.self) { route in
            ReaderView(route: route)
        }
        .navigationDestination(for: HighlightsRoute.self) { route in
            HighlightsView(route: route)
        }
    }
}
