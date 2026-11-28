import SwiftUI

@main
struct NovarrApp: App {
    @State private var app = AppModel()
    @Environment(\.scenePhase) private var scenePhase
    @AppStorage(AppAppearance.defaultsKey) private var appearance = AppAppearance.dark

    init() {
        Self.styleNavigationBars()
        // Before launch finishes: the app-refresh task and notification taps.
        BackgroundRefresh.register()
        NotificationDelegate.install()
    }

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(app)
                .tint(.brandAccent)
                // Geist wherever a view doesn't choose its own font.
                .font(.ui(.body))
                // On the window rather than preferredColorScheme, so a themed
                // reader can switch it while shown (ReaderView).
                .onAppear { AppAppearance.applyToWindows(appearance.interfaceStyle) }
                .onChange(of: appearance) { _, value in AppAppearance.applyToWindows(value.interfaceStyle) }
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active { Task { await app.flushWrites() } }
            // Checks poll only in the foreground and look again on return.
            switch phase {
            case .active:
                app.checks.setForeground(true)
                // Request news into the dot; the new-chapters notification is seen.
                Task { await NotificationHub.shared.appBecameActive(app) }
            case .background:
                app.checks.setForeground(false)
                BackgroundRefresh.schedule()
            default: break
            }
        }
    }
}

extension NovarrApp {
    /// Screen titles in Geist SemiBold (the hand-off's page title), scaling with Dynamic Type.
    private static func styleNavigationBars() {
        func geist(_ size: CGFloat, _ style: UIFont.TextStyle) -> UIFont? {
            UIFont(name: BrandFont.geistSemiBold, size: size).map { UIFontMetrics(forTextStyle: style).scaledFont(for: $0) }
        }
        let bar = UINavigationBar.appearance()
        if let large = geist(34, .largeTitle) { bar.largeTitleTextAttributes = [.font: large] }
        if let inline = geist(17, .headline) { bar.titleTextAttributes = [.font: inline] }
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
    /// Opened from a highlight: the reader goes to its passage instead of
    /// the saved progress, as a look-up (no position asked for or sent
    /// until the reader moves to another chapter).
    var highlight: Highlight? = nil
    /// A manga chapter: opens the manga reader (pages) instead of the text one.
    var manga = false
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
            ReaderDestination(route: route)
        }
        .navigationDestination(for: HighlightsRoute.self) { route in
            HighlightsView(route: route)
        }
        .listsDestinations()
        .requestsDestinations()
        .audiobooksDestinations()
        .statsDestinations()
        #if DEBUG
        .navigationDestination(for: DebugDownloadsRoute.self) { _ in DownloadsView() }
        #endif
    }
}

/// The reader a route opens: pages for manga, text otherwise.
struct ReaderDestination: View {
    let route: ReaderRoute

    var body: some View {
        if route.manga {
            MangaReaderView(route: route)
        } else {
            ReaderView(route: route)
        }
    }
}
