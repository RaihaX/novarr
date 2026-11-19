import SwiftUI

struct LibraryView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var model = LibraryModel()

    private var columns: [GridItem] {
        [GridItem(.adaptive(minimum: sizeClass == .regular ? 150 : 104, maximum: 200),
                  spacing: sizeClass == .regular ? 22 : 14, alignment: .top)]
    }

    var body: some View {
        @Bindable var model = model
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                shelfPicker
                filterChips
                if model.isShowingCache {
                    OfflinePill().frame(maxWidth: .infinity)
                }
                content
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
                } label: {
                    Label("Sort", systemImage: "arrow.up.arrow.down")
                }
            }
        }
        .refreshable { await model.load(app: app) }
        .task { await model.refreshIfStale(app: app) }
    }

    /// Novels / Manga, as Android's shelf tabs.
    private var shelfPicker: some View {
        @Bindable var model = model
        return Picker("Shelf", selection: $model.shelf) {
            ForEach(LibraryFilter.Shelf.allCases) { shelf in
                Text(model.novels.isEmpty ? shelf.title : "\(shelf.title) (\(model.count(for: shelf)))").tag(shelf)
            }
        }
        .pickerStyle(.segmented)
        .frame(maxWidth: 420)
    }

    private var filterChips: some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                ForEach(LibraryFilter.Filter.allCases) { filter in
                    let selected = model.filter == filter
                    Button {
                        model.filter = filter
                    } label: {
                        HStack(spacing: 4) {
                            Text(filter.title)
                            if !model.novels.isEmpty {
                                Text("\(model.count(for: filter))")
                                    .foregroundStyle(selected ? Color.onAccent.opacity(0.8) : Color.secondary)
                            }
                        }
                        .font(.ui(.subheadline, .medium))
                        .padding(.horizontal, 12)
                        .padding(.vertical, 7)
                        .foregroundStyle(selected ? Color.onAccent : Color.primary)
                        .background(selected ? Color.brandAccent : Color(.secondarySystemFill), in: Capsule())
                    }
                    .buttonStyle(.plain)
                    .accessibilityAddTraits(selected ? .isSelected : [])
                }
            }
            .padding(.vertical, 2)
        }
        .scrollClipDisabled()
    }

    @ViewBuilder
    private var content: some View {
        let visible = model.visible
        if model.novels.isEmpty, case .failed(let message) = model.phase {
            LoadFailedView(title: "Library unavailable", message: message) { await model.load(app: app) }
                .padding(.top, 40)
        } else if model.novels.isEmpty, model.phase != .loaded {
            ProgressView().frame(maxWidth: .infinity).padding(.top, 80)
        } else if visible.isEmpty {
            emptyState.padding(.top, 40)
        } else {
            LazyVGrid(columns: columns, alignment: .leading, spacing: 20) {
                ForEach(visible) { novel in
                    NavigationLink(value: NovelRoute(id: novel.id, summary: novel)) {
                        LibraryCell(novel: novel)
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    @ViewBuilder
    private var emptyState: some View {
        if !model.search.isEmpty {
            ContentUnavailableView.search(text: model.search)
        } else if model.onShelf.isEmpty {
            ContentUnavailableView(model.shelf == .manga ? "No manga yet" : "No novels yet", systemImage: "books.vertical",
                                   description: Text("Nothing here yet. Series are added on the Novarr website."))
        } else {
            ContentUnavailableView("Nothing here", systemImage: "line.3.horizontal.decrease.circle",
                                   description: Text("Nothing on this shelf matches “\(model.filter.title)”."))
        }
    }
}

struct LibraryCell: View {
    let novel: NovelSummary

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
                .overlay(alignment: .topTrailing) {
                    if novel.chaptersNew > 0 {
                        Circle().fill(Color.brandAccent)
                            .frame(width: 10, height: 10)
                            .overlay(Circle().strokeBorder(.white, lineWidth: 1.5))
                            .padding(6)
                    }
                }
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
    }
}
