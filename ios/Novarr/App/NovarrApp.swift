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
    }
}
