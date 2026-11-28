import SwiftUI

// OWNER: categories / Home / Library area.

extension View {
    /// Want to read, a category's folder, Manage categories, Settings › Home.
    func listsDestinations() -> some View {
        navigationDestination(for: WantToReadRoute.self) { _ in
            WantToReadView()
        }
        .navigationDestination(for: FolderRoute.self) { route in
            FolderView(listId: route.id)
        }
        .navigationDestination(for: CategoriesRoute.self) { _ in
            ManageCategoriesView()
        }
        .navigationDestination(for: HomeSettingsRoute.self) { _ in
            HomeSettingsView()
        }
    }
}

/// The series screen's category controls: the heart (Favourites), Want to
/// read and "Categories…", each saved at once (undone, with a message, on
/// failure). Only on servers with categories; the heart only where the
/// server has the built-in Favourites. Mirrors the top bar of android
/// ui/novel/NovelScreen.kt.
struct NovelCategoryActions: View {
    let summary: NovelSummary

    @Environment(AppModel.self) private var app
    @State private var showCategories = false
    @State private var message: String?

    var body: some View {
        let lists = app.lists
        let novelId = summary.id
        if app.serverMeta?.lists == true {
            let favourites = app.serverMeta?.favourites == true ? lists.favourites : nil
            let want = lists.wantToRead
            let own = ReadingLists.own(lists.lists).filter { $0.contains(novelId) }
            HStack(spacing: 10) {
                if let favourites {
                    let on = lists.isFavourite(novelId)
                    actionButton("Favourite", systemImage: on ? "heart.fill" : "heart", on: on) {
                        Task { message = await lists.toggleFavourite(novelId: novelId, add: !on) }
                    }
                    .disabled(!app.isReachable || lists.state.busy(listId: favourites.id, novelId: novelId))
                    .accessibilityLabel(on ? "Remove from favourites" : "Favourite")
                }
                if let want {
                    let on = lists.isWanted(novelId)
                    actionButton("Want to read", systemImage: on ? "bookmark.fill" : "bookmark", on: on) {
                        Task { message = await lists.toggleWantToRead(novelId: novelId, add: !on) }
                    }
                    .disabled(!app.isReachable || lists.state.busy(listId: want.id, novelId: novelId))
                    .accessibilityAddTraits(on ? .isSelected : [])
                }
                actionButton(own.isEmpty ? "Categories" : "In \(own.count)",
                             systemImage: own.isEmpty ? "text.badge.plus" : "text.badge.checkmark", on: !own.isEmpty) {
                    showCategories = true
                }
                .accessibilityLabel(own.isEmpty ? "Categories" : "Categories (in \(own.count))")
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .sheet(isPresented: $showCategories) {
                SeriesCategoriesSheet(novelId: novelId, novelName: summary.name)
            }
            .alert("Categories", isPresented: Binding(get: { message != nil }, set: { if !$0 { message = nil } })) {
                Button("OK", role: .cancel) {}
            } message: {
                Text(message ?? "")
            }
            // Opened from Home before the Library ever loaded: the buttons show where it is.
            .task { if !lists.loaded { await lists.refresh() } }
        }
    }

    private func actionButton(_ title: String, systemImage: String, on: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            // Icon over a short label: three fit side by side on a phone.
            VStack(spacing: 4) {
                Image(systemName: systemImage).font(.system(size: 18))
                Text(title).font(.ui(.caption, .medium)).lineLimit(1).minimumScaleFactor(0.8)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 2)
        }
        .buttonStyle(.bordered)
        .tint(on ? Color.brandAccent : Color.primary)
    }
}

/// Settings rows for this area: Home (Settings › Home) and Manage categories.
struct SettingsListsSection: View {
    @Environment(AppModel.self) private var app

    var body: some View {
        Section("Home") {
            NavigationLink(value: HomeSettingsRoute()) {
                LabeledContent("Home & start screen") {
                    Text(startOnTitle)
                }
            }
            if app.serverMeta?.lists == true {
                NavigationLink(value: CategoriesRoute()) { Text("Manage categories") }
            }
        }
    }

    private var startOnTitle: String {
        (StartOn(rawValue: UserDefaults.standard.string(forKey: StartOn.defaultsKey) ?? "") ?? .home).title
    }
}
