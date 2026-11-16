import SwiftUI

struct RootView: View {
    @Environment(AppModel.self) private var app

    enum Tab: String { case home, library, discover, settings }
    @SceneStorage("selectedTab") private var selection: Tab = .home
    @State private var libraryPath = NavigationPath()

    var body: some View {
        if app.serverURL == nil {
            ConnectView(isInitialSetup: true)
        } else {
            TabView(selection: $selection) {
                NavigationStack { HomeView().novarrDestinations() }
                    .tabItem { Label("Home", systemImage: "book") }
                    .tag(Tab.home)
                NavigationStack(path: $libraryPath) { LibraryView().novarrDestinations() }
                    .tabItem { Label("Library", systemImage: "books.vertical") }
                    .tag(Tab.library)
                NavigationStack { DiscoverView().novarrDestinations() }
                    .tabItem { Label("Discover", systemImage: "safari") }
                    .tag(Tab.discover)
                NavigationStack { SettingsView().novarrDestinations() }
                    .tabItem { Label("Settings", systemImage: "gearshape") }
                    .tag(Tab.settings)
            }
            // Recreate the tabs (and their models) when the server changes.
            .id(app.serverURL)
            #if DEBUG
            .task { openDebugRoute() }
            #endif
        }
    }

    #if DEBUG
    /// Debug builds only: `-debugRoute settings|library|discover|highlights|highlights:<novelId>|novel:<id>|reader:<novelId>:<chapterId>`
    /// opens a screen at launch, for simulator screenshots without UI automation.
    private func openDebugRoute() {
        guard let route = UserDefaults.standard.string(forKey: "debugRoute") else { return }
        let parts = route.split(separator: ":").compactMap { Int($0) }
        switch route.split(separator: ":").first {
        case "settings": selection = .settings
        case "library": selection = .library
        case "discover": selection = .discover
        case "highlights":
            selection = .library
            libraryPath.append(HighlightsRoute(novelId: parts.first, novelName: nil))
        case "novel" where parts.count == 1:
            selection = .library
            libraryPath.append(NovelRoute(id: parts[0], summary: nil))
        case "reader" where parts.count == 2:
            selection = .library
            libraryPath.append(NovelRoute(id: parts[0], summary: nil))
            libraryPath.append(ReaderRoute(novelId: parts[0], chapterId: parts[1]))
        default: break
        }
    }
    #endif
}
