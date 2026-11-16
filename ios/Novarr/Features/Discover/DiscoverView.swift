import SwiftUI

struct DiscoverView: View {
    @Environment(AppModel.self) private var app
    @State private var model = DiscoverModel()
    @State private var selectedItemId: DiscoverItem.ID?
    @State private var openRoute: NovelRoute?
    @State private var expanded: Set<DiscoverItem.ID> = []

    var body: some View {
        @Bindable var model = model
        List {
            if model.sourcesPhase == .loaded, let source = model.selectedSource {
                listPicker(source)
            }
            content
        }
        .listStyle(.plain)
        .navigationTitle("Discover")
        .searchable(text: $model.searchText, placement: .navigationBarDrawer(displayMode: .always),
                    prompt: searchPrompt)
        .onSubmit(of: .search) { model.submitSearch(app: app) }
        .onChange(of: model.searchText) { _, text in
            if text.isEmpty { model.searchCleared(app: app) }
        }
        .toolbar { sourceMenu }
        .refreshable { await model.reload(app: app) }
        .task {
            if model.sources.isEmpty { await model.loadSources(app: app) }
            #if DEBUG
            await runDebugActions()
            #endif
        }
        .sheet(item: Binding(
            get: { selectedItemId.map(SheetID.init) },
            set: { selectedItemId = $0?.id }
        )) { sheet in
            if let item = model.item(id: sheet.id) {
                DiscoverDetailSheet(item: item, model: model, sourceName: model.selectedSource?.name) { novelId in
                    selectedItemId = nil
                    openRoute = NovelRoute(id: novelId, summary: nil)
                }
            }
        }
        .navigationDestination(item: $openRoute) { route in
            NovelView(route: route)
        }
    }

    private struct SheetID: Identifiable { let id: DiscoverItem.ID }

    private var searchPrompt: String {
        guard let source = model.selectedSource else { return "Search" }
        return "Search \(source.host ?? source.name)"
    }

    // MARK: Pickers

    @ViewBuilder
    private func listPicker(_ source: DiscoverSource) -> some View {
        let lists = source.browseLists
        if !lists.isEmpty && model.query?.isSearch != true {
            Picker("List", selection: Binding(
                get: { model.selectedListId ?? lists[0].id },
                set: { model.select(listId: $0, app: app) }
            )) {
                ForEach(lists) { Text($0.name).tag($0.id) }
            }
            .pickerStyle(.segmented)
            .listRowSeparator(.hidden)
        }
    }

    @ToolbarContentBuilder
    private var sourceMenu: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            if model.sources.count > 1 {
                Menu {
                    Picker("Source", selection: Binding(
                        get: { model.selectedSourceId ?? "" },
                        set: { model.select(sourceId: $0, app: app) }
                    )) {
                        ForEach(model.sources) { source in
                            Text(source.name).tag(source.id)
                        }
                    }
                } label: {
                    Label(model.selectedSource?.host ?? "Source", systemImage: "globe")
                        .labelStyle(.titleAndIcon)
                        .font(.subheadline)
                }
                .accessibilityLabel("Source: \(model.selectedSource?.name ?? "none")")
            }
        }
    }

    // MARK: Content

    @ViewBuilder
    private var content: some View {
        switch model.sourcesPhase {
        case .failed(let message):
            LoadFailedView(title: "Discover unavailable", message: message) { await model.loadSources(app: app) }
                .listRowSeparator(.hidden)
        case .idle, .loading:
            ProgressView().frame(maxWidth: .infinity).padding(.top, 80)
                .listRowSeparator(.hidden)
        case .loaded:
            if model.sources.isEmpty {
                ContentUnavailableView("No sources", systemImage: "globe",
                                       description: Text("This Novarr server doesn't offer any sources to browse."))
                    .listRowSeparator(.hidden)
            } else {
                results
            }
        }
    }

    @ViewBuilder
    private var results: some View {
        let source = model.selectedSource
        switch model.phase {
        case .idle:
            if !model.searchText.isEmpty, source?.offersSearch == false {
                ContentUnavailableView("Search isn't available", systemImage: "magnifyingglass",
                                       description: Text("\(source?.name ?? "This source") can't be searched."))
                    .listRowSeparator(.hidden)
            } else {
                ContentUnavailableView("Search \(source?.name ?? "this source")", systemImage: "magnifyingglass",
                                       description: Text("Type a title or author above."))
                    .listRowSeparator(.hidden)
            }
        case .loading:
            loading.listRowSeparator(.hidden)
        case .cancelled:
            ContentUnavailableView {
                Label("Search cancelled", systemImage: "xmark.circle")
            } actions: {
                Button("Search again") { Task { await model.reload(app: app) } }
                    .buttonStyle(.borderedProminent)
            }
            .listRowSeparator(.hidden)
        case .failed(let message):
            ContentUnavailableView {
                Label("Couldn't load this list", systemImage: "exclamationmark.icloud")
            } description: {
                Text(message)
            } actions: {
                Button("Try again") { Task { await model.reload(app: app) } }
                    .buttonStyle(.borderedProminent)
            }
            .listRowSeparator(.hidden)
        case .loaded:
            if model.results.items.isEmpty {
                emptyResults.listRowSeparator(.hidden)
            } else {
                ForEach(model.results.items) { item in
                    DiscoverRow(item: item, isExpanded: expanded.contains(item.id)) {
                        withAnimation(.easeInOut(duration: 0.2)) {
                            if expanded.contains(item.id) { expanded.remove(item.id) } else { expanded.insert(item.id) }
                        }
                    }
                    .contentShape(Rectangle())
                    .onTapGesture { selectedItemId = item.id }
                    .accessibilityAddTraits(.isButton)
                    .accessibilityAction { selectedItemId = item.id }
                    .onAppear { model.loadMoreIfNeeded(after: item, app: app) }
                }
                pagingFooter
            }
        }
    }

    private var loading: some View {
        VStack(spacing: 14) {
            ProgressView()
            if let query = model.query, query.isSearch {
                Text("Searching \(model.selectedSource?.host ?? "the source") for “\(query.text ?? "")”…")
                    .font(.callout)
                    .multilineTextAlignment(.center)
                Text("Some sources take a minute to answer.")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                Button("Cancel", role: .cancel) { model.cancel() }
                    .buttonStyle(.bordered)
            } else {
                Text("Loading \(model.selectedList?.name.lowercased() ?? "list")…")
                    .font(.callout)
                    .foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 60)
    }

    @ViewBuilder
    private var emptyResults: some View {
        if let query = model.query, query.isSearch {
            ContentUnavailableView("No results", systemImage: "magnifyingglass",
                                   description: Text("\(model.selectedSource?.name ?? "The source") has nothing for “\(query.text ?? "")”. Try another spelling or source."))
        } else {
            ContentUnavailableView("Nothing here", systemImage: "books.vertical",
                                   description: Text("This list is empty on \(model.selectedSource?.name ?? "this source") right now."))
        }
    }

    @ViewBuilder
    private var pagingFooter: some View {
        if model.isLoadingMore {
            ProgressView().frame(maxWidth: .infinity).padding(.vertical, 12)
                .listRowSeparator(.hidden)
        } else if let message = model.moreError {
            VStack(spacing: 8) {
                Text(message).font(.footnote).foregroundStyle(.secondary).multilineTextAlignment(.center)
                Button("Load more") { model.loadMore(app: app) }
                    .font(.footnote.weight(.semibold))
                    .buttonStyle(.borderless)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 12)
            .listRowSeparator(.hidden)
        }
    }

    #if DEBUG
    /// Debug builds only: `-debugDiscoverSearch <text>` runs a search and
    /// `-debugDiscoverDetail <index>` opens that result's sheet (and
    /// `-debugDiscoverAdd YES` adds it), for simulator
    /// screenshots without UI automation.
    private func runDebugActions() async {
        let defaults = UserDefaults.standard
        if let list = defaults.string(forKey: "debugDiscoverList") {
            model.select(listId: list, app: app)
        }
        if let text = defaults.string(forKey: "debugDiscoverSearch") {
            model.searchText = text
            model.submitSearch(app: app)
        }
        guard defaults.object(forKey: "debugDiscoverDetail") != nil else { return }
        let index = defaults.integer(forKey: "debugDiscoverDetail")
        for _ in 0..<100 where model.phase != .loaded {
            try? await Task.sleep(for: .milliseconds(100))
        }
        guard model.results.items.indices.contains(index) else { return }
        let item = model.results.items[index]
        selectedItemId = item.id
        // `-debugDiscoverAdd YES` also presses Add to library.
        if defaults.bool(forKey: "debugDiscoverAdd") {
            try? await Task.sleep(for: .seconds(1))
            await model.add(item, app: app)
        }
    }
    #endif
}

struct DiscoverRow: View {
    let item: DiscoverItem
    let isExpanded: Bool
    let toggleExpanded: () -> Void

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            CoverView(url: item.coverUrl, title: item.title, cornerRadius: 4, targetWidth: 64)
                .frame(width: 64)
            VStack(alignment: .leading, spacing: 4) {
                Text(item.title)
                    .font(.headline)
                    .lineLimit(2)
                if let author = item.author {
                    Text(author).font(.subheadline).foregroundStyle(.secondary).lineLimit(1)
                }
                HStack(spacing: 6) {
                    if item.inLibrary { InLibraryBadge() }
                    if let facts = item.facts {
                        Text(facts).font(.caption).foregroundStyle(.secondary).lineLimit(1)
                    }
                }
                if let synopsis = item.synopsis {
                    Text(synopsis)
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                        .lineLimit(isExpanded ? nil : 3)
                        .fixedSize(horizontal: false, vertical: true)
                        .padding(.top, 2)
                    if synopsis.count > 140 {
                        Button(isExpanded ? "Less" : "More", action: toggleExpanded)
                            .font(.caption.weight(.semibold))
                            .buttonStyle(.borderless)
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(.vertical, 6)
        .accessibilityElement(children: .combine)
    }
}

struct InLibraryBadge: View {
    var body: some View {
        Label("In library", systemImage: "checkmark")
            .labelStyle(.titleAndIcon)
            .font(.caption2.weight(.semibold))
            .padding(.horizontal, 7)
            .padding(.vertical, 2)
            .foregroundStyle(Color.accentColor)
            .background(Color.accentColor.opacity(0.14), in: Capsule())
    }
}
