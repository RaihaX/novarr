import SwiftUI

struct RootView: View {
    @Environment(AppModel.self) private var app

    // The app is read-only for every account: series are added on the Novarr
    // website. A tab remembered from an older build that no longer exists
    // fails to decode and falls back to Home.
    enum Tab: String { case home, library, settings }
    @SceneStorage("selectedTab") private var selection: Tab = .home
    @State private var libraryPath = NavigationPath()

    var body: some View {
        if app.serverURL == nil {
            ConnectView(isInitialSetup: true)
        } else if app.needsSignIn {
            SignInView()
        } else {
            TabView(selection: $selection) {
                NavigationStack { HomeView().novarrDestinations() }
                    .tabItem { Label("Home", systemImage: "book") }
                    .tag(Tab.home)
                NavigationStack(path: $libraryPath) { LibraryView().novarrDestinations() }
                    .tabItem { Label("Library", systemImage: "books.vertical") }
                    .tag(Tab.library)
                NavigationStack { SettingsView().novarrDestinations() }
                    .tabItem { Label("Settings", systemImage: "gearshape") }
                    .tag(Tab.settings)
            }
            // Recreate the tabs (and their models) when the server or account changes.
            .id(app.sessionID)
            #if DEBUG
            .task { openDebugRoute() }
            #endif
        }
    }

    #if DEBUG
    /// Debug builds only: `-debugRoute settings|library|highlights|highlights:<novelId>|novel:<id>|reader:<novelId>:<chapterId>|latest:<novelId>:<chapterId>`
    /// opens a screen at launch, for simulator screenshots without UI automation.
    private func openDebugRoute() {
        guard let route = UserDefaults.standard.string(forKey: "debugRoute") else { return }
        let parts = route.split(separator: ":").compactMap { Int($0) }
        switch route.split(separator: ":").first {
        case "settings": selection = .settings
        case "library": selection = .library
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
        case "latest" where parts.count == 2: // reader with the latest-position check
            selection = .library
            libraryPath.append(NovelRoute(id: parts[0], summary: nil))
            libraryPath.append(ReaderRoute(novelId: parts[0], chapterId: parts[1], offerLatest: true))
        default: break
        }
    }
    #endif
}
