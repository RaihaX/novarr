import SwiftUI

/// The Library: medium tabs (Web Novels · Manga · Comics · Books), the
/// category tabs (All · each category in order · Uncategorised), origin and
/// status filters, search and sort, and the multi-select (a context menu's
/// "Select", then taps) with Set categories, Mark read / unread (with Undo)
/// and Download. Mirrors android ui/library/LibraryScreen.kt.
struct LibraryView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var model = LibraryModel()

    private var columns: [GridItem] {
        [GridItem(.adaptive(minimum: sizeClass == .regular ? 150 : 104, maximum: 200),
                  spacing: sizeClass == .regular ? 22 : 14, alignment: .top)]
    }

    private var comics: Bool { app.serverMeta?.comics == true }
    private var listsOffered: Bool { app.serverMeta?.lists == true }

    /// Without categories on the server (or the chosen one deleted elsewhere): all series.
    private var tab: Categories.Tab {
        listsOffered ? (model.tab(app.lists.lists) ?? .all) : .all
    }

    private var list: ReadingList? { Categories.asList(tab, lists: app.lists.lists, novels: model.novels) }

    var body: some View {
        @Bindable var model = model
        let list = list
        let visible = model.visible(list: list, comics: comics)
        let selection = model.selection
        ScrollView {
            VStack(alignment: .leading, spacing: 12) {
                shelfTabs(list: list)
                if listsOffered && !app.lists.lists.isEmpty { categoryTabs }
                filterChips(list: list)
                if model.isShowingCache {
                    OfflinePill().frame(maxWidth: .infinity)
                }
                content(visible: visible, list: list)
            }
            .padding(.horizontal, sizeClass == .regular ? 24 : 16)
            .padding(.bottom, 24)
            // Very wide windows: a centred shelf instead of one huge row.
            .frame(maxWidth: 1400)
            .frame(maxWidth: .infinity)
        }
        .brandBackground()
        .navigationTitle("Library")
        .searchable(text: $model.search, prompt: "Title or author")
        .toolbar {
            if !selection.active {
                ToolbarItem(placement: .topBarLeading) {
                    NavigationLink(value: HighlightsRoute(novelId: nil, novelName: nil)) {
                        Label("Highlights", systemImage: "highlighter")
                    }
                }
                ToolbarItem(placement: .topBarTrailing) {
                    Menu {
                        Picker("Sort by", selection: $model.sort) {
                            ForEach(LibraryFilter.Sort.allCases) { Text($0.title).tag($0) }
                        }
                        if listsOffered {
                            Divider()
                            NavigationLink(value: CategoriesRoute()) {
                                Label("Manage categories", systemImage: "tag")
                            }
                        }
                    } label: {
                        Label("Sort by \(model.sort.title)", systemImage: "arrow.up.arrow.down")
                    }
                }
            }
        }
        .seriesSelection(selection, shown: visible, categorise: listsOffered) { read in model.markRead(read) }
        .refreshable { await model.load(app: app) }
        .task { await model.refreshIfStale(app: app) }
        .onChange(of: app.readStateRevision) { Task { await model.refreshIfStale(app: app) } }
        // The remembered category was deleted elsewhere: back to All.
        .onChange(of: app.lists.lists) {
            if app.lists.loaded, listsOffered, model.tab(app.lists.lists) == nil { model.select(.all) }
        }
    }

    // MARK: Tabs and filters

    private func shelfTabs(list: ReadingList?) -> some View {
        let current = model.shelf(comics: comics)
        return TabStrip(
            items: model.shelves(comics: comics).map { shelf in
                TabStrip.Item(id: shelf.rawValue,
                              title: model.novels.isEmpty ? shelf.title : "\(shelf.title) (\(model.count(shelf, list: list)))")
            },
            selected: current.rawValue
        ) { id in
            if let shelf = LibraryFilter.Shelf(rawValue: id) { model.shelfChoice = shelf }
        }
    }

    /// The category tabs (Mihon style): All · each category in the reader's order · Uncategorised.
    private var categoryTabs: some View {
        let lists = app.lists.lists
        return TabStrip(
            items: Categories.tabs(lists).map { option in
                let count = model.novels.isEmpty ? nil
                    : model.count(model.shelf(comics: comics), list: Categories.asList(option, lists: lists, novels: model.novels))
                return TabStrip.Item(id: option.key, title: Categories.title(option) + (count.map { " (\($0))" } ?? ""))
            },
            selected: tab.key,
            small: true
        ) { key in
            if let option = Categories.Tab.from(key, lists: lists) { model.select(option) }
        }
    }

    private func filterChips(list: ReadingList?) -> some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                if model.originOffered(comics: comics) {
                    originMenu(list: list)
                }
                ForEach(LibraryFilter.Filter.allCases) { filter in
                    let selected = model.filter == filter
                    Button {
                        model.filter = filter
                    } label: {
                        Chip(selected: selected) {
                            Text(filter.title)
                            if !model.novels.isEmpty {
                                Text("\(model.count(filter, list: list, comics: comics))")
                                    .foregroundStyle(selected ? Color.onAccent.opacity(0.8) : Color.secondary)
                            }
                        }
                    }
                    .buttonStyle(.plain)
                    .accessibilityAddTraits(selected ? .isSelected : [])
                }
            }
            .padding(.vertical, 2)
        }
        .scrollClipDisabled()
    }

    private func originMenu(list: ReadingList?) -> some View {
        let origin = model.origin(comics: comics)
        return Menu {
            Picker("Origin", selection: Binding(get: { origin }, set: { model.originChoice = $0 })) {
                ForEach(SeriesOrigin.Filter.allCases) { option in
                    Text("\(option.title) · \(seriesCount(model.count(option, list: list, comics: comics)))").tag(option)
                }
            }
        } label: {
            Chip(selected: origin != .all) {
                Image(systemName: "globe")
                Text(origin == .all ? "Origin" : origin.title)
                Image(systemName: "chevron.down").font(.caption2)
            }
        }
        .accessibilityLabel(origin == .all ? "Filter by origin" : "Origin: \(origin.title). Change origin")
    }

    // MARK: The grid

    @ViewBuilder
    private func content(visible: [NovelSummary], list: ReadingList?) -> some View {
        if model.novels.isEmpty, case .failed(let message) = model.phase {
            LoadFailedView(title: "Library unavailable", message: message) { await model.load(app: app) }
                .padding(.top, 40)
        } else if model.novels.isEmpty, model.phase != .loaded {
            ProgressView().frame(maxWidth: .infinity).padding(.top, 80)
        } else if visible.isEmpty {
            emptyState(list: list).padding(.top, 40)
        } else {
            LazyVGrid(columns: columns, alignment: .leading, spacing: 20) {
                ForEach(visible) { novel in
                    SelectableSeriesCell(novel: novel, selection: model.selection, categorise: listsOffered) { ids, read in
                        model.markRead(read, ids: ids)
                    }
                }
            }
        }
    }

    @ViewBuilder
    private func emptyState(list: ReadingList?) -> some View {
        let shelf = model.shelf(comics: comics)
        let onShelf = model.onShelf(comics: comics)
        let reason = list.map { ReadingLists.emptyReason($0, library: model.novels, shelf: onShelf) }
        if !model.search.trimmingCharacters(in: .whitespaces).isEmpty {
            let plain = model.filter == .all && model.origin(comics: comics) == .all
            ContentUnavailableView("No results", systemImage: "magnifyingglass", description: Text(plain
                ? "Nothing on this shelf matches “\(model.search)”."
                : "Nothing on this shelf matches “\(model.search)” with “\(filtersText)”."))
        } else if let list, list.id == Categories.uncategorisedId, reason == .noSeries {
            ContentUnavailableView("Everything has a category", systemImage: "tag",
                                   description: Text("Every series in your library is in at least one category."))
        } else if let list, reason == .noSeries {
            ContentUnavailableView("“\(list.name)” is empty", systemImage: "tag", description: Text(
                list.isWantToRead ? "Open a series and tap Want to read, or select series under All and choose Set categories."
                    : list.isFavourites ? "Open a series and tap the heart, or select series under All and choose Set categories."
                    : "Select series under All (touch and hold a cover) and choose Set categories to put them here."))
        } else if let list, case .otherShelf(let count)? = reason {
            ContentUnavailableView("No \(shelf.noun) in “\(list.name)”", systemImage: "tag", description: Text(
                "Its \(seriesCount(count)) \(count == 1 ? "is" : "are") on \(otherShelves(list))."))
        } else if onShelf.isEmpty {
            ContentUnavailableView("No \(shelf.noun) yet", systemImage: "books.vertical",
                                   description: Text("Add series on your Novarr server and they'll show up here."))
        } else if let list {
            ContentUnavailableView("Nothing here", systemImage: "line.3.horizontal.decrease.circle",
                                   description: Text("Nothing in “\(list.name)” matches “\(filtersText)”."))
        } else {
            ContentUnavailableView("Nothing here", systemImage: "line.3.horizontal.decrease.circle",
                                   description: Text("Nothing matches “\(filtersText)”."))
        }
    }

    /// "the Manga tab", or "the Manga and Books tabs": the other shelves holding `list`'s series.
    private func otherShelves(_ list: ReadingList) -> String {
        let current = model.shelf(comics: comics)
        var others = model.shelves(comics: comics).filter { $0 != current && model.count($0, list: list) > 0 }.map(\.title)
        if others.isEmpty { others = ["other"] }
        if others.count == 1 { return "the \(others[0]) tab" }
        return "the \(others.dropLast().joined(separator: ", ")) and \(others.last!) tabs"
    }

    /// "Reading", "Korean", or "Reading · Korean": the filters in effect, for the empty state.
    private var filtersText: String {
        let origin = model.origin(comics: comics)
        return [
            (model.filter != .all || origin == .all) ? model.filter.title : nil,
            origin == .all ? nil : (origin == .unknown ? "Unknown origin" : origin.title),
        ].compactMap { $0 }.joined(separator: " · ")
    }
}

/// A filter chip's capsule.
struct Chip<Label: View>: View {
    let selected: Bool
    @ViewBuilder let label: Label

    var body: some View {
        HStack(spacing: 4) { label }
            .font(.ui(.subheadline, .medium))
            .padding(.horizontal, 12)
            .padding(.vertical, 7)
            .foregroundStyle(selected ? Color.onAccent : Color.primary)
            .background(selected ? Color.brandAccent : Color(.secondarySystemFill), in: Capsule())
    }
}

/// A row of underlined tabs that scrolls sideways when they don't fit
/// (the Library's shelves and categories).
struct TabStrip: View {
    struct Item: Identifiable, Hashable {
        var id: String
        var title: String
    }

    let items: [Item]
    let selected: String
    var small = false
    let onSelect: (String) -> Void

    var body: some View {
        ScrollViewReader { proxy in
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: small ? 18 : 22) {
                    ForEach(items) { item in
                        let isSelected = item.id == selected
                        Button { onSelect(item.id) } label: {
                            VStack(spacing: 6) {
                                Text(item.title)
                                    .font(.ui(small ? .subheadline : .body, isSelected ? .semibold : .regular))
                                    .foregroundStyle(isSelected ? Color.primary : Color.secondary)
                                    .lineLimit(1)
                                Capsule()
                                    .fill(isSelected ? Color.brandAccent : Color.clear)
                                    .frame(height: 3)
                            }
                            .fixedSize(horizontal: true, vertical: false)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                        .id(item.id)
                        .accessibilityAddTraits(isSelected ? [.isSelected, .isButton] : .isButton)
                    }
                }
                .padding(.top, 4)
            }
            .scrollClipDisabled()
            .onAppear { proxy.scrollTo(selected, anchor: .center) }
            .onChange(of: selected) { _, id in withAnimation { proxy.scrollTo(id, anchor: .center) } }
        }
    }
}

/// A series in a grid (Library, a category, Want to read): its cover with
/// progress, the unread count (new ones in the accent colour), the origin,
/// and — in a multi-select — the selected mark.
struct LibraryCell: View {
    let novel: NovelSummary
    var selected = false

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            CoverView(url: novel.coverUrl, title: novel.name)
                .overlay(alignment: .bottom) {
                    if novel.chaptersRead > 0 && !novel.finished {
                        ProgressLine(fraction: novel.readFraction, tint: .brandAmber,
                                     track: .black.opacity(0.35), height: 4)
                            .padding(6)
                    }
                }
                .overlay(alignment: .topTrailing) { badge }
                .overlay(alignment: .bottomTrailing) {
                    if let country = SeriesOrigin.country(novel) {
                        Text(country.short)
                            .font(.ui(.caption2, .semibold))
                            .foregroundStyle(.white)
                            .padding(.horizontal, 5)
                            .padding(.vertical, 1)
                            .background(Color.black.opacity(0.6), in: RoundedRectangle(cornerRadius: 4))
                            .padding(.trailing, 6)
                            .padding(.bottom, 16)
                            .accessibilityLabel(SeriesOrigin.label(novel) ?? country.short)
                    }
                }
                .overlay { SelectedMark(selected: selected) }
                .shadow(color: .black.opacity(0.12), radius: 4, y: 2)
            Text(novel.name)
                .font(.ui(.subheadline, .medium))
                .lineLimit(2)
                .multilineTextAlignment(.leading)
            Text(novel.caption)
                .font(.ui(.caption2, .semibold))
                .tracking(0.5)
                .foregroundStyle(novel.chaptersNew > 0 ? Color.brandAccent : Color.secondary)
        }
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
        .accessibilityValue(selected ? "Selected" : "")
    }

    @ViewBuilder
    private var badge: some View {
        let unread = Categories.unread(novel)
        if novel.chaptersRead > 0 && unread > 0 {
            // Started series: how many chapters are left (Mihon's unread badge); new ones in the accent colour.
            Text(unread > 999 ? "999+" : "\(unread)")
                .font(.ui(.caption2, .semibold))
                .foregroundStyle(novel.chaptersNew > 0 ? Color.onAccent : .white)
                .padding(.horizontal, 5)
                .padding(.vertical, 1)
                .background(novel.chaptersNew > 0 ? Color.brandAccent : Color.black.opacity(0.65),
                            in: RoundedRectangle(cornerRadius: 6))
                .padding(5)
                .accessibilityLabel("\(unread) unread")
        } else if novel.chaptersNew > 0 {
            Circle().fill(Color.brandAccent)
                .frame(width: 10, height: 10)
                .overlay(Circle().strokeBorder(.white, lineWidth: 1.5))
                .padding(6)
        }
    }
}
