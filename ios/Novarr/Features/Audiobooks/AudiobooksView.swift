import SwiftUI

/// Every audiobook (paged, searchable) and "Continue listening"; the first
/// page is saved for offline. Mirrors android AudiobooksViewModel.
@MainActor
@Observable
final class AudiobooksModel {
    private(set) var items: [AudiobookSummary] = []
    private(set) var continueItems: [AudiobookSummary] = []
    private(set) var loaded = false
    private(set) var hasMore = false
    private(set) var loadingMore = false
    private(set) var failure: String?
    private(set) var fromCache = false
    var search = ""
    @ObservationIgnored private var page = 1
    @ObservationIgnored private var searchTask: Task<Void, Never>?

    static let listKey = OfflineStore.Key.custom("audiobooks/list.json")
    static let continueKey = OfflineStore.Key.custom("audiobooks/continue.json")

    func load(app: AppModel) async {
        let query = search.trimmingCharacters(in: .whitespaces)
        if !loaded, query.isEmpty {
            if let cached = await app.cached(AudiobooksPage.self, Self.listKey) {
                show(cached)
                fromCache = true
            }
            if let cached = await app.cached([AudiobookSummary].self, Self.continueKey) { continueItems = cached }
        }
        if query.isEmpty {
            let result = await app.load(Self.listKey) { try await $0.audiobooks(page: 1) }
            if let value = result.value { show(value) }
            fromCache = result.fromCache || (result.error != nil && loaded)
            failure = loaded ? nil : (result.error?.errorDescription ?? "Couldn't load audiobooks.")
            let more = await app.load(Self.continueKey) { try await $0.continueListening() }
            if let value = more.value { continueItems = value }
        } else {
            guard let client = app.client else { return }
            do {
                show(try await client.audiobooks(page: 1, search: query))
                failure = nil
                fromCache = false
            } catch {
                guard !Task.isCancelled else { return }
                failure = APIError.from(error).errorDescription
            }
        }
    }

    private func show(_ result: AudiobooksPage) {
        items = result.audiobooks
        hasMore = result.hasMore
        page = max(result.page, 1)
        loaded = true
    }

    func loadMore(app: AppModel) async {
        guard !loadingMore, hasMore, let client = app.client else { return }
        loadingMore = true
        defer { loadingMore = false }
        let query = search.trimmingCharacters(in: .whitespaces)
        guard let next = try? await client.audiobooks(page: page + 1, search: query.isEmpty ? nil : query) else { return }
        var seen = Set(items.map(\.id))
        items += next.audiobooks.filter { seen.insert($0.id).inserted }
        hasMore = next.hasMore
        page = max(next.page, page + 1)
    }

    /// Searches as the reader types (a short pause first).
    func searchChanged(app: AppModel) {
        searchTask?.cancel()
        searchTask = Task { [weak self] in
            try? await Task.sleep(for: .milliseconds(350))
            guard !Task.isCancelled else { return }
            await self?.load(app: app)
        }
    }
}

/// The Listen tab's root: search, this device's uploads, Continue listening and every audiobook.
struct AudiobooksView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var model = AudiobooksModel()

    var body: some View {
        @Bindable var model = model
        let wide = sizeClass == .regular
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 20) {
                if model.fromCache {
                    OfflinePill().frame(maxWidth: .infinity)
                }
                if model.search.isEmpty {
                    AudiobookUploadsList(inset: 0)
                    if !model.continueItems.isEmpty {
                        VStack(alignment: .leading, spacing: 12) {
                            Text("Continue listening")
                                .font(.ui(.title3, .semibold))
                                .accessibilityAddTraits(.isHeader)
                            ScrollView(.horizontal, showsIndicators: false) {
                                LazyHStack(alignment: .top, spacing: 14) {
                                    ForEach(model.continueItems) { item in
                                        NavigationLink(value: PlayerRoute(id: item.id)) {
                                            AudiobookTile(item: item)
                                        }
                                        .buttonStyle(.plain)
                                        .frame(width: wide ? 130 : 108)
                                    }
                                }
                            }
                        }
                        Text("All audiobooks")
                            .font(.ui(.title3, .semibold))
                            .accessibilityAddTraits(.isHeader)
                    }
                }
                content(wide: wide)
            }
            .padding(.horizontal, wide ? 24 : 16)
            .padding(.vertical, 12)
        }
        .brandBackground()
        .navigationTitle("Audiobooks")
        .searchable(text: $model.search, prompt: "Search audiobooks")
        .onChange(of: model.search) { _, _ in model.searchChanged(app: app) }
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                // Readers can upload an audiobook they have (the owner approves it).
                NavigationLink(value: AudiobookUploadRoute()) {
                    Image(systemName: "square.and.arrow.up")
                }
                .accessibilityLabel("Upload an audiobook")
            }
        }
        .refreshable {
            await model.load(app: app)
            await app.listening.uploads.refresh()
        }
        .task { await model.load(app: app) }
    }

    @ViewBuilder
    private func content(wide: Bool) -> some View {
        if !model.loaded, let failure = model.failure {
            LoadFailedView(title: "Audiobooks unavailable", message: failure) { await model.load(app: app) }
        } else if !model.loaded {
            ProgressView().frame(maxWidth: .infinity).padding(.vertical, 60)
        } else if model.items.isEmpty {
            ContentUnavailableView(
                model.search.isEmpty ? "No audiobooks yet" : "No audiobooks match",
                systemImage: "headphones",
                description: Text(model.search.isEmpty
                                  ? "Audiobooks your server's owner adds show up here, and on the book's page."
                                  : "Try another title, author or narrator."))
        } else {
            LazyVGrid(columns: [GridItem(.adaptive(minimum: wide ? 150 : 104), spacing: wide ? 22 : 14, alignment: .top)],
                      alignment: .leading, spacing: 20) {
                ForEach(model.items) { item in
                    NavigationLink(value: PlayerRoute(id: item.id)) {
                        AudiobookTile(item: item)
                    }
                    .buttonStyle(.plain)
                    .contextMenu { AudiobookTileMenu(item: item) }
                }
            }
            if model.hasMore {
                Group {
                    if model.loadingMore {
                        ProgressView()
                    } else {
                        Button("Show more") { Task { await model.loadMore(app: app) } }
                    }
                }
                .frame(maxWidth: .infinity)
                .task(id: model.items.count) { await model.loadMore(app: app) }
            }
        }
    }
}

/// A long press on an audiobook: download it (or remove the download), or open its book.
private struct AudiobookTileMenu: View {
    @Environment(AppModel.self) private var app
    let item: AudiobookSummary

    var body: some View {
        let downloads = app.listening.downloads
        switch downloads.state(item.id) {
        case .done:
            Button("Remove download", systemImage: "trash", role: .destructive) { downloads.remove(item.id) }
        case .waiting, .running:
            Button("Cancel download", systemImage: "xmark") { downloads.cancel(item.id) }
        default:
            Button("Download for offline", systemImage: "arrow.down.circle") { downloads.start(item.id, title: item.title) }
        }
        if let novelId = item.novelId {
            NavigationLink(value: NovelRoute(id: novelId, summary: nil)) {
                Label("Open the book", systemImage: "book")
            }
        }
    }
}

/// A cover with the title, the length or time left, and progress along its bottom.
struct AudiobookTile: View {
    let item: AudiobookSummary

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            CoverView(url: item.coverUrl, title: item.title, targetWidth: 130)
                .overlay(alignment: .bottom) {
                    if item.started, item.progress?.finished != true {
                        ProgressLine(fraction: item.fraction, tint: .white, track: .black.opacity(0.35))
                            .padding(6)
                    }
                }
            Text(item.title)
                .font(.ui(.subheadline, .medium))
                .lineLimit(2)
                .multilineTextAlignment(.leading)
            if let series = item.seriesLabel {
                Text(series)
                    .font(.ui(.caption2))
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }
            Text(Self.caption(item))
                .font(.ui(.caption2, .medium))
                .foregroundStyle(item.progress?.finished == true ? Color.brandAccent : .secondary)
                .lineLimit(1)
        }
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
        .accessibilityHint("Listen")
    }

    /// "FINISHED", "3 h 12 min left" or the book's length.
    static func caption(_ item: AudiobookSummary) -> String {
        if item.progress?.finished == true { return "FINISHED" }
        if item.started, item.durationMs > 0, let progress = item.progress {
            return "\(ListeningSync.length(item.durationMs - progress.positionMs)) left"
        }
        if item.durationMs > 0 { return ListeningSync.length(item.durationMs) }
        return "AUDIOBOOK"
    }
}
