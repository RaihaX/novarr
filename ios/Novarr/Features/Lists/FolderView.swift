import SwiftUI

/// The library's series by id, from the saved copy then the server (Home,
/// a category's screen). Mirrors android ui/lists/ListSheets.kt LibraryNovels.
@MainActor
@Observable
final class LibraryNovels {
    private(set) var novels: [Int: NovelSummary] = [:]
    private(set) var loaded = false
    private(set) var fromCache = false
    private(set) var failure: String?

    /// The saved copy at once; the server's when `fetch` (or when nothing is saved).
    func load(app: AppModel, fetch: Bool) async {
        if !loaded, let cached = await app.cached([NovelSummary].self, .novels) {
            // The saved copy straight away; "offline" only once a fetch has failed.
            novels = Self.byId(cached)
            loaded = true
        }
        if !fetch && loaded { return }
        let result = await app.load(.novels) { try await $0.novels() }
        if let value = result.value {
            novels = Self.byId(value)
            loaded = true
        }
        fromCache = result.fromCache || (result.error != nil && loaded)
        failure = loaded ? nil : (result.error?.localizedDescription ?? "Couldn't load your library.")
    }

    /// Shows `ids` marked read or unread at once (a multi-select's mark); answers how to put them back.
    func mark(_ ids: Set<Int>, read: Bool) -> @MainActor () -> Void {
        let before = novels.filter { ids.contains($0.key) }
        for id in ids { if let novel = novels[id] { novels[id] = Categories.marked(novel, read: read) } }
        return { [weak self] in
            guard let self else { return }
            for (id, novel) in before where self.novels[id] != nil { self.novels[id] = novel }
        }
    }

    private static func byId(_ list: [NovelSummary]) -> [Int: NovelSummary] {
        Dictionary(list.map { ($0.id, $0) }, uniquingKeysWith: { first, _ in first })
    }
}

/// One category (a reading list; Home's "See all"): its series as covers,
/// newest first for the built-ins, with the same multi-select as the
/// Library. The reader's own categories can be renamed and deleted here.
/// Mirrors android ui/lists/FolderScreen.kt.
struct FolderView: View {
    let listId: Int

    @Environment(AppModel.self) private var app
    @Environment(\.horizontalSizeClass) private var sizeClass
    @Environment(\.dismiss) private var dismiss
    @State private var library = LibraryNovels()
    @State private var selection = SeriesSelection()
    @State private var renaming: ReadingList?
    @State private var deleting: ReadingList?
    @State private var message: String?

    private var columns: [GridItem] {
        [GridItem(.adaptive(minimum: sizeClass == .regular ? 150 : 104, maximum: 200),
                  spacing: sizeClass == .regular ? 22 : 14, alignment: .top)]
    }

    var body: some View {
        let list = app.lists.lists.first { $0.id == listId }
        let shown = HomeShelves.shelf(list, novels: library.novels)
        ScrollView {
            VStack(spacing: 14) {
                if library.fromCache { OfflinePill() }
                content(list: list, shown: shown, selection: selection)
            }
            .padding(.horizontal, sizeClass == .regular ? 24 : 16)
            .padding(.vertical, 12)
            .frame(maxWidth: 1400)
            .frame(maxWidth: .infinity)
        }
        .brandBackground()
        .navigationTitle(list?.name ?? "Category")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            // The built-ins are never renamed or deleted.
            if !selection.active, let list, !list.isBuiltIn {
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Button { renaming = list } label: { Label("Rename", systemImage: "pencil") }
                        Button(role: .destructive) { deleting = list } label: { Label("Delete", systemImage: "trash") }
                    } label: {
                        Label("Category options", systemImage: "ellipsis.circle")
                    }
                    .disabled(!app.isReachable)
                }
            }
        }
        .seriesSelection(selection, shown: shown, categorise: true) { read in
            selection.markRead(read) { ids, value in library.mark(ids, read: value) }
        }
        .sheet(item: $renaming) { list in CategoryNameSheet(mode: .rename(list)) }
        .deleteCategoryConfirmation($deleting, onDeleted: { dismiss() }, onFailure: { message = $0 })
        .toast($message)
        .refreshable { await library.load(app: app, fetch: true) }
        .task {
            selection.attach(app)
            await library.load(app: app, fetch: false)
            if !app.lists.loaded { await app.lists.refresh() }
        }
        // A series the library copy doesn't know yet (added elsewhere): the library is fetched again.
        .task(id: list?.novelIds) {
            if library.loaded, let list, list.novelIds.contains(where: { library.novels[$0] == nil }) {
                await library.load(app: app, fetch: true)
            }
        }
        // Read state changed elsewhere (a mark sent, reading): the covers follow.
        .onChange(of: app.readStateRevision) {
            Task { if library.loaded { await library.load(app: app, fetch: true) } }
        }
    }

    @ViewBuilder
    private func content(list: ReadingList?, shown: [NovelSummary], selection: SeriesSelection) -> some View {
        if list == nil && app.lists.loaded {
            ContentUnavailableView("This category is gone", systemImage: "folder",
                                   description: Text("It may have been deleted on another device."))
                .padding(.top, 40)
        } else if list == nil || (!library.loaded && library.failure == nil) {
            ProgressView().frame(maxWidth: .infinity).padding(.top, 80)
        } else if !library.loaded {
            LoadFailedView(title: "Category unavailable", message: library.failure ?? "") {
                await library.load(app: app, fetch: true)
            }
            .padding(.top, 40)
        } else if let list, shown.isEmpty {
            ContentUnavailableView(
                "“\(list.name)” is empty",
                systemImage: list.isFavourites ? "heart" : "folder",
                description: Text(list.isFavourites
                    ? "Open a book, web novel or manga and tap the heart to keep it here."
                    : list.isWantToRead
                    ? "Open a series and tap Want to read to add it here."
                    : "Select series in the Library (or tap Categories on a series page) to put them here."))
                .padding(.top, 40)
        } else {
            LazyVGrid(columns: columns, alignment: .leading, spacing: 20) {
                ForEach(shown) { novel in
                    SelectableSeriesCell(novel: novel, selection: selection) { ids, read in
                        selection.markRead(read, series: ids) { ids, value in library.mark(ids, read: value) }
                    }
                }
            }
        }
    }
}

/// The whole "Want to read" (GET /want-to-read): every medium, newest
/// first; saved for offline. Mirrors android ui/lists/WantToReadScreen.kt.
struct WantToReadView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var items: [WantToReadItem] = []
    @State private var loaded = false
    @State private var failure: String?
    @State private var fromCache = false

    private var columns: [GridItem] {
        [GridItem(.adaptive(minimum: sizeClass == .regular ? 150 : 104, maximum: 200),
                  spacing: sizeClass == .regular ? 22 : 14, alignment: .top)]
    }

    var body: some View {
        let list = app.lists.wantToRead
        // Taken off since (here or elsewhere): gone at once.
        let shown = ReadingLists.shown(items, list: list)
        ScrollView {
            VStack(spacing: 14) {
                if fromCache { OfflinePill() }
                if !loaded, let failure {
                    LoadFailedView(title: "Want to read unavailable", message: failure) { await load() }
                        .padding(.top, 40)
                } else if !loaded {
                    ProgressView().frame(maxWidth: .infinity).padding(.top, 80)
                } else if shown.isEmpty {
                    ContentUnavailableView(
                        "Nothing to read next yet", systemImage: "bookmark",
                        description: Text("Open a book, web novel or manga and tap Want to read: it waits here, and on Home, until you get to it."))
                        .padding(.top, 40)
                } else {
                    LazyVGrid(columns: columns, alignment: .leading, spacing: 20) {
                        ForEach(shown) { item in
                            NavigationLink(value: NovelRoute(id: item.novel.id, summary: item.novel)) {
                                VStack(alignment: .leading, spacing: 2) {
                                    LibraryCell(novel: item.novel)
                                    if ReadingLists.started(item) {
                                        Text("STARTED")
                                            .font(.ui(.caption2, .semibold))
                                            .foregroundStyle(Color.brandAccent)
                                    }
                                }
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
            }
            .padding(.horizontal, sizeClass == .regular ? 24 : 16)
            .padding(.vertical, 12)
            .frame(maxWidth: 1400)
            .frame(maxWidth: .infinity)
        }
        .brandBackground()
        .navigationTitle(ReadingLists.wantToReadName)
        .navigationBarTitleDisplayMode(.inline)
        .refreshable { await load() }
        .task {
            await load()
            if !app.lists.loaded { await app.lists.refresh() }
        }
        // A series added elsewhere since (the series screen's button): fetch again to show it.
        .task(id: list?.novelIds) {
            if loaded, ReadingLists.needsRefetch(items, list: list) { await load() }
        }
    }

    private func load() async {
        if !loaded, let cached = await app.cached(WantToReadEnvelope.self, .wantToRead) {
            items = cached.items
            loaded = true
            fromCache = true
        }
        let result = await app.load(.wantToRead) { try await $0.wantToRead() }
        if let value = result.value {
            items = value.items
            loaded = true
        }
        failure = loaded ? nil : (result.error?.localizedDescription ?? "Couldn't load Want to read.")
        fromCache = result.fromCache || (result.error != nil && loaded)
    }
}
