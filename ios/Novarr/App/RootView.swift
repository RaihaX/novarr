import SwiftUI

struct RootView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.horizontalSizeClass) private var sizeClass

    // The app is read-only for every account: series are added on the Novarr
    // website. A tab remembered from an older build that no longer exists
    // fails to decode and falls back to Home. Highlights is a section of its
    // own only in the iPad sidebar; on a phone it opens from the Library.
    enum Tab: String, CaseIterable, Identifiable {
        case home, library, highlights, settings
        var id: String { rawValue }

        var title: String { rawValue.capitalized }

        var systemImage: String {
            switch self {
            case .home: "book"
            case .library: "books.vertical"
            case .highlights: "highlighter"
            case .settings: "gearshape"
            }
        }
    }
    @SceneStorage("selectedTab") private var selection: Tab = .home
    @State private var libraryPath = NavigationPath()
    @State private var columns = NavigationSplitViewVisibility.all

    var body: some View {
        if app.serverURL == nil {
            ConnectView(isInitialSetup: true)
        } else if app.needsSignIn {
            SignInView()
        } else {
            Group {
                if sizeClass == .regular {
                    sidebarLayout
                } else {
                    tabLayout
                }
            }
            // Recreate the tabs (and their models) when the server or account changes.
            .id(app.sessionID)
            #if DEBUG
            .task { openDebugRoute() }
            #endif
        }
    }

    /// iPhone (and compact iPad windows): the tab bar.
    private var tabLayout: some View {
        // Highlights (sidebar only) shows as the Library tab here.
        let tab = Binding<Tab>(get: { selection == .highlights ? .library : selection }, set: { selection = $0 })
        return TabView(selection: tab) {
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
    }

    /// iPad at regular width: a sidebar of sections, each with its own stack.
    private var sidebarLayout: some View {
        let sidebar = Binding<Tab?>(get: { selection }, set: { if let value = $0 { selection = value } })
        return NavigationSplitView(columnVisibility: $columns) {
            List(Tab.allCases, selection: sidebar) { tab in
                NavigationLink(value: tab) {
                    Label(tab.title, systemImage: tab.systemImage)
                }
            }
            .navigationTitle("Novarr")
        } detail: {
            Group {
                switch selection {
                case .home:
                    NavigationStack { HomeView().novarrDestinations() }
                case .library:
                    NavigationStack(path: $libraryPath) { LibraryView().novarrDestinations() }
                case .highlights:
                    NavigationStack { HighlightsView(route: HighlightsRoute(novelId: nil, novelName: nil)).novarrDestinations() }
                case .settings:
                    NavigationStack { SettingsView().novarrDestinations() }
                }
            }
            .id(selection)
        }
        // Side by side in portrait too, as the iPad Settings app; collapsible.
        .navigationSplitViewStyle(.balanced)
    }

    #if DEBUG
    /// Debug builds only: `-debugRoute settings|library|highlights|highlights:<novelId>|novel:<id>|reader:<novelId>:<chapterId>|latest:<novelId>:<chapterId>|manga:<novelId>:<chapterId>|downloads`
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
        case "downloads":
            selection = .library
            libraryPath.append(DebugDownloadsRoute())
        case "manga" where parts.count == 2: // the manga reader, with the latest-position check
            selection = .library
            libraryPath.append(NovelRoute(id: parts[0], summary: nil))
            libraryPath.append(ReaderRoute(novelId: parts[0], chapterId: parts[1], offerLatest: true, manga: true))
        case "latest" where parts.count == 2: // reader with the latest-position check
            selection = .library
            libraryPath.append(NovelRoute(id: parts[0], summary: nil))
            libraryPath.append(ReaderRoute(novelId: parts[0], chapterId: parts[1], offerLatest: true))
        default: break
        }
    }
    #endif
}

#if DEBUG
/// Debug builds only: the Downloads screen as a push destination, for `-debugRoute downloads`.
struct DebugDownloadsRoute: Hashable {}
#endif
