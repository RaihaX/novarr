import SwiftUI

struct RootView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.horizontalSizeClass) private var sizeClass

    // Series are added on the Novarr website or asked for in Requests. A tab
    // remembered from an older build that no longer exists fails to decode
    // and falls back to Home. Highlights and Requests are sections of their
    // own only in the iPad sidebar; on a phone Highlights opens from the
    // Library and Requests from Home's top bar. Listen only on servers with
    // audiobooks.
    enum Tab: String, CaseIterable, Identifiable {
        case home, library, listen, requests, highlights, settings
        var id: String { rawValue }

        var title: String { rawValue.capitalized }

        var systemImage: String {
            switch self {
            case .home: "book"
            case .library: "books.vertical"
            case .listen: "headphones"
            case .requests: "tray.and.arrow.down"
            case .highlights: "highlighter"
            case .settings: "gearshape"
            }
        }
    }
    @SceneStorage("selectedTab") private var selection: Tab = .home
    @State private var libraryPath = NavigationPath()
    @State private var columns = NavigationSplitViewVisibility.all
    /// Files opened from Files or the Share sheet, shown in an upload sheet.
    @State private var incoming: IncomingFiles?
    /// Settings › Home › "Start on", applied once per launch.
    @State private var startApplied = false

    /// "Listen" only on servers with audiobooks (meta `audiobooks`).
    private var listenOffered: Bool { app.serverMeta?.audiobooks == true }
    /// Requests in the iPad sidebar only when the server offers them.
    private var requestsOffered: Bool { app.serverMeta?.requests == true }

    private var sidebarTabs: [Tab] {
        Tab.allCases.filter { tab in
            switch tab {
            case .listen: listenOffered
            case .requests: requestsOffered
            default: true
            }
        }
    }

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
            .onOpenURL { url in
                if let files = IncomingFiles.receive(url) { incoming = files }
            }
            .sheet(item: $incoming) { files in
                NavigationStack {
                    Group {
                        switch files.kind {
                        case .books: BookUploadView(route: BookUploadRoute(files: files.files))
                        case .audio: AudiobookUploadView(route: AudiobookUploadRoute(files: files.files))
                        }
                    }
                    .novarrDestinations()
                }
            }
            .task(id: app.serverMeta?.audiobooks) { applyStartOn() }
            #if DEBUG
            .task { openDebugRoute() }
            #endif
        }
    }

    /// iPhone (and compact iPad windows): the tab bar.
    private var tabLayout: some View {
        // Highlights (sidebar only) shows as the Library tab here, Requests as
        // Home (its button is in Home's top bar), Listen as Home on a server
        // without audiobooks.
        let tab = Binding<Tab>(get: {
            switch selection {
            case .highlights: .library
            case .requests: .home
            case .listen where !listenOffered: .home
            default: selection
            }
        }, set: { selection = $0 })
        return TabView(selection: tab) {
            NavigationStack { HomeView().novarrDestinations() }
                .miniPlayerInset()
                .tabItem { Label("Home", systemImage: "book") }
                .tag(Tab.home)
            NavigationStack(path: $libraryPath) { LibraryView().novarrDestinations() }
                .miniPlayerInset()
                .tabItem { Label("Library", systemImage: "books.vertical") }
                .tag(Tab.library)
            if listenOffered {
                NavigationStack { AudiobooksView().novarrDestinations() }
                    .miniPlayerInset()
                    .tabItem { Label("Listen", systemImage: "headphones") }
                    .tag(Tab.listen)
            }
            NavigationStack { SettingsView().novarrDestinations() }
                .miniPlayerInset()
                .tabItem { Label("Settings", systemImage: "gearshape") }
                .tag(Tab.settings)
        }
    }

    /// iPad at regular width: a sidebar of sections, each with its own stack.
    private var sidebarLayout: some View {
        let sidebar = Binding<Tab?>(get: { selection }, set: { if let value = $0 { selection = value } })
        return NavigationSplitView(columnVisibility: $columns) {
            List(sidebarTabs, selection: sidebar) { tab in
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
                case .listen:
                    NavigationStack { AudiobooksView().novarrDestinations() }
                case .requests:
                    NavigationStack { RequestsRootView().novarrDestinations() }
                case .highlights:
                    NavigationStack { HighlightsView(route: HighlightsRoute(novelId: nil, novelName: nil)).novarrDestinations() }
                case .settings:
                    NavigationStack { SettingsView().novarrDestinations() }
                }
            }
            .id(selection)
            .miniPlayerInset()
        }
        // Side by side in portrait too, as the iPad Settings app; collapsible.
        .navigationSplitViewStyle(.balanced)
    }

    /// Settings › Home › "Start on": once per launch, the chosen tab instead
    /// of Home. Listen waits for meta (it may only become available then).
    private func applyStartOn() {
        guard !startApplied else { return }
        let start = StartOn(rawValue: UserDefaults.standard.string(forKey: StartOn.defaultsKey) ?? "") ?? .home
        if start == .listen, app.serverMeta == nil { return }
        startApplied = true
        guard selection == .home else { return }
        switch start {
        case .home: break
        case .library: selection = .library
        case .listen: if listenOffered { selection = .listen }
        }
    }

    #if DEBUG
    /// Debug builds only: `-debugRoute settings|library|listen|requests|stats|categories|wanttoread|highlights|highlights:<novelId>|novel:<id>|reader:<novelId>:<chapterId>|latest:<novelId>:<chapterId>|manga:<novelId>:<chapterId>|folder:<listId>|player:<audiobookId>|downloads`
    /// opens a screen at launch, for simulator screenshots without UI automation.
    private func openDebugRoute() {
        guard let route = UserDefaults.standard.string(forKey: "debugRoute") else { return }
        let parts = route.split(separator: ":").compactMap { Int($0) }
        switch route.split(separator: ":").first {
        case "settings": selection = .settings
        case "library": selection = .library
        case "listen": selection = .listen
        case "requests":
            selection = .library
            libraryPath.append(RequestsRoute())
        case "stats":
            selection = .library
            libraryPath.append(StatsRoute())
        case "categories":
            selection = .library
            libraryPath.append(CategoriesRoute())
        case "wanttoread":
            selection = .library
            libraryPath.append(WantToReadRoute())
        case "folder" where parts.count == 1:
            selection = .library
            libraryPath.append(FolderRoute(id: parts[0]))
        case "player" where parts.count == 1:
            selection = .library
            libraryPath.append(PlayerRoute(id: parts[0]))
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

extension View {
    /// Room for the mini-player at the bottom while an audiobook is loaded.
    func miniPlayerInset() -> some View {
        safeAreaInset(edge: .bottom, spacing: 0) { MiniPlayerBar() }
    }
}

#if DEBUG
/// Debug builds only: the Downloads screen as a push destination, for `-debugRoute downloads`.
struct DebugDownloadsRoute: Hashable {}
#endif
