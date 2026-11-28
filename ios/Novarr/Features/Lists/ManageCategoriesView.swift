import SwiftUI

/// Manage categories (Home's link, the Library's menu, Settings): make one,
/// rename and delete the reader's own, reorder (Edit, then drag; saved when
/// dropped), and hide any of them — the built-ins too — from Home's
/// sections. Read-only offline. Mirrors android ui/lists/ManageCategoriesScreen.kt.
struct ManageCategoriesView: View {
    @Environment(AppModel.self) private var app
    @State private var renaming: ReadingList?
    @State private var deleting: ReadingList?
    @State private var message: String?

    var body: some View {
        let lists = app.lists
        let reachable = app.isReachable
        List {
            Section {
                if !reachable { CategoriesOfflineNote() }
                if lists.loaded {
                    NewCategoryField(lists: lists.lists, enabled: reachable, label: "New category") { name in
                        if case .failure(let problem) = await lists.create(name: name) { return problem.message }
                        return nil
                    }
                }
            } footer: {
                Text("Home shows each category that has something in it, in this order. Tap Edit to drag them; the eye hides one from Home (it stays in the Library).")
            }

            Section {
                if !lists.loaded {
                    ProgressView().frame(maxWidth: .infinity)
                } else if lists.lists.isEmpty {
                    Text("No categories yet. Name one above, then select series in the Library to put them in it.")
                        .foregroundStyle(.secondary)
                }
                ForEach(lists.sorted) { list in
                    row(list, reachable: reachable)
                }
                .onMove { from, to in
                    var order = lists.sorted.map(\.id)
                    order.move(fromOffsets: from, toOffset: to)
                    guard order != lists.sorted.map(\.id) else { return }
                    Task { message = await lists.reorder(ids: order) }
                }
                .moveDisabled(!reachable)
            }
        }
        .navigationTitle("Categories")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .topBarTrailing) { EditButton().disabled(!reachable || lists.lists.count < 2) }
        }
        .sheet(item: $renaming) { list in CategoryNameSheet(mode: .rename(list)) }
        .deleteCategoryConfirmation($deleting, onFailure: { message = $0 })
        .toast($message)
        .refreshable { await lists.refresh() }
        .task { if !lists.loaded { await lists.refresh() } }
    }

    private func row(_ list: ReadingList, reachable: Bool) -> some View {
        HStack(spacing: 12) {
            VStack(alignment: .leading, spacing: 2) {
                Text(list.name)
                    .foregroundStyle(list.hiddenOnHome ? Color.secondary : Color.primary)
                    .lineLimit(1)
                Text([seriesCount(list.count), list.isBuiltIn ? "built in" : nil, list.hiddenOnHome ? "hidden on Home" : nil]
                    .compactMap { $0 }.joined(separator: " · "))
                    .font(.ui(.footnote))
                    .foregroundStyle(.secondary)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Button {
                Task { message = await app.lists.setHiddenOnHome(listId: list.id, hidden: !list.hiddenOnHome) }
            } label: {
                Image(systemName: list.hiddenOnHome ? "eye.slash" : "eye")
                    .frame(width: 32, height: 32)
            }
            .buttonStyle(.borderless)
            .disabled(!reachable)
            .accessibilityLabel(list.hiddenOnHome ? "Show \(list.name) on Home" : "Hide \(list.name) on Home")
            // The built-ins are never renamed or deleted.
            if !list.isBuiltIn {
                Menu {
                    Button { renaming = list } label: { Label("Rename", systemImage: "pencil") }
                    Button(role: .destructive) { deleting = list } label: { Label("Delete", systemImage: "trash") }
                } label: {
                    Image(systemName: "ellipsis.circle").frame(width: 32, height: 32)
                }
                .buttonStyle(.borderless)
                .disabled(!reachable)
                .accessibilityLabel("Options for \(list.name)")
            } else {
                Color.clear.frame(width: 32, height: 32)
            }
        }
        .swipeActions(edge: .trailing) {
            if !list.isBuiltIn && reachable {
                Button(role: .destructive) { deleting = list } label: { Label("Delete", systemImage: "trash") }
                Button { renaming = list } label: { Label("Rename", systemImage: "pencil") }
            }
        }
    }
}

/// Settings › Home: the tab the app starts on, and which of 1.16's shelves
/// come back (Home is the categories since 1.18). Mirrors the Home section
/// of android ui/settings/SettingsScreen.kt.
struct HomeSettingsView: View {
    @Environment(AppModel.self) private var app
    @AppStorage(StartOn.defaultsKey) private var startOn = StartOn.home.rawValue
    @AppStorage(HomeLayout.Key.showContinue) private var showContinue = false
    @AppStorage(HomeLayout.Key.continueReading) private var continueReading = true
    @AppStorage(HomeLayout.Key.continueListening) private var continueListening = true
    @AppStorage(HomeLayout.Key.showFavourites) private var showFavourites = false
    @AppStorage(HomeLayout.Key.showWantToRead) private var showWantToRead = false
    @AppStorage(HomeLayout.Key.showInProgress) private var showInProgress = false

    var body: some View {
        let audiobooks = app.serverMeta?.audiobooks == true
        let lists = app.serverMeta?.lists == true
        Form {
            Section {
                Picker("Start on", selection: $startOn) {
                    ForEach(StartOn.allCases.filter { audiobooks || $0 != .listen }) { Text($0.title).tag($0.rawValue) }
                }
                .pickerStyle(.segmented)
                .accessibilityLabel("Start on")
            } header: {
                Text("Start on")
            } footer: {
                Text("The tab Novarr opens on.")
            }

            Section {
                if lists {
                    NavigationLink(value: CategoriesRoute()) { Text("Manage categories") }
                }
                Toggle("Show Continue on Home", isOn: $showContinue)
                if showContinue {
                    Toggle("Continue reading", isOn: $continueReading)
                    if audiobooks { Toggle("Continue listening", isOn: $continueListening) }
                }
                if lists {
                    Toggle("Show Favourites on Home", isOn: $showFavourites)
                    Toggle("Show Want to read on Home", isOn: $showWantToRead)
                }
                Toggle(audiobooks ? "Show Reading & listening on Home" : "Show Reading on Home", isOn: $showInProgress)
            } header: {
                Text("Home")
            } footer: {
                Text("Home shows a row for each of your categories (hide or reorder them in Manage categories) and new chapters. Switch on the shelves you'd like back from before. Everything in progress also stays under Library and Listen.")
            }
        }
        .navigationTitle("Home")
        .navigationBarTitleDisplayMode(.inline)
    }
}
