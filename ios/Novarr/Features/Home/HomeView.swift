import SwiftUI

/// Home (1.18) is a summary of the reader's categories: a search over
/// everything, the stats card, then one row of covers per category (in
/// their order, hidden and empty ones left out) with "Manage categories",
/// then New chapters and a way into the full Library. Settings › Home brings
/// back 1.16's Continue, Favourites, Want to read and Reading & listening; a
/// reader whose categories hold nothing yet keeps 1.16's layout, with
/// "Create your first category" on top. `HomeShelves.sections` decides the
/// order. Mirrors android ui/home/HomeScreen.kt.
struct HomeView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var model = HomeModel()
    @State private var query = ""
    @State private var newCategory = false

    @AppStorage(HomeLayout.Key.showContinue) private var showContinue = false
    @AppStorage(HomeLayout.Key.continueReading) private var continueReading = true
    @AppStorage(HomeLayout.Key.continueListening) private var continueListening = true
    @AppStorage(HomeLayout.Key.showFavourites) private var showFavourites = false
    @AppStorage(HomeLayout.Key.showWantToRead) private var showWantToRead = false
    @AppStorage(HomeLayout.Key.showInProgress) private var showInProgress = false

    private var layout: HomeLayout {
        HomeLayout(continueReading: continueReading, continueListening: continueListening, showContinue: showContinue,
                   showFavourites: showFavourites, showWantToRead: showWantToRead, showInProgress: showInProgress)
    }

    private var wide: Bool { sizeClass == .regular }
    private var listsOffered: Bool { app.serverMeta?.lists == true }
    private var audiobooksOffered: Bool { app.serverMeta?.audiobooks == true }
    private var requestsOffered: Bool { app.serverMeta?.requests == true }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 0) {
                if model.isShowingCache {
                    OfflinePill().frame(maxWidth: .infinity).padding(.bottom, 12)
                }
                if !model.query.trimmingCharacters(in: .whitespaces).isEmpty {
                    searchResults
                } else {
                    content
                }
            }
            .padding(.horizontal, wide ? 28 : 16)
            .padding(.vertical, 8)
            .frame(maxWidth: 1000)
            .frame(maxWidth: .infinity)
        }
        .brandBackground()
        .navigationTitle("Home")
        .searchable(text: $query, prompt: "Search books, manga, web novels, audiobooks")
        .onChange(of: query) { _, text in model.search(text, app: app) }
        .toolbar {
            if requestsOffered {
                ToolbarItem(placement: .topBarTrailing) { RequestsToolbarButton() }
            }
        }
        .refreshable {
            await model.load(app: app)
            await model.refreshStatsIfStale(app: app)
        }
        .task {
            await model.refreshIfStale(app: app)
            if listsOffered, !app.lists.loaded { await app.lists.refresh() }
            await model.refreshStatsIfStale(app: app)
        }
        .onChange(of: app.readStateRevision) {
            Task {
                await model.refreshIfStale(app: app)
                await model.refreshStatsIfStale(app: app)
            }
        }
        .onChange(of: listsOffered) { _, offered in
            if offered, !app.lists.loaded { Task { await app.lists.refresh() } }
        }
        // The categories' covers come from the library: fetched again when a list holds a series it doesn't know.
        .task(id: app.lists.lists.flatMap(\.novelIds)) {
            let ids = app.lists.lists.flatMap(\.novelIds)
            if listsOffered, model.library.loaded, ids.contains(where: { model.library.novels[$0] == nil }) {
                await model.refreshLibrary(app: app)
            }
        }
        // Want to read gained a series the feed's shelf doesn't have yet: the feed again.
        .task(id: app.lists.wantToRead?.novelIds) {
            if let feed = model.feed,
               ReadingLists.needsRefetch(feed.wantToRead, list: app.lists.wantToRead, limit: HomeShelves.wantToReadShelf) {
                await model.load(app: app)
            }
        }
        .sheet(isPresented: $newCategory) { CategoryNameSheet(mode: .create) }
    }

    // MARK: Sections

    @ViewBuilder
    private var content: some View {
        if let feed = model.feed {
            // Phones: a labelled way into Requests besides the toolbar's icon.
            if requestsOffered && !wide {
                RequestsEntry().padding(.bottom, 12)
            }
            let listening = audiobooksOffered ? model.listening : []
            let stats = model.stats.flatMap { $0.isEmpty ? nil : $0 }
            let sections = HomeShelves.sections(
                lists: app.lists.lists,
                novels: model.library.novels,
                layout: layout,
                listsOffered: listsOffered,
                listsLoaded: app.lists.loaded,
                hasStats: stats != nil,
                wantToReadShelf: !feed.wantToRead.isEmpty
            )
            let quick = sections.contains(.continueStrip)
                ? HomeShelves.quickResume(reading: feed.continueReading, listening: listening,
                                          showReading: layout.continueReading, showListening: layout.continueListening)
                : []
            ForEach(sections) { section in
                sectionView(section, feed: feed, quick: quick, listening: listening, stats: stats)
            }
            if let stats = feed.stats {
                Text("\(stats.novels) series · \(stats.chaptersDownloaded.formatted()) chapters on the server"
                     + (stats.chaptersQueued > 0 ? " · \(stats.chaptersQueued.formatted()) queued" : ""))
                    .font(.ui(.footnote))
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 24)
            }
        } else if case .failed(let message) = model.phase {
            LoadFailedView(title: model.failure?.failureTitle ?? "Can't reach your server", message: message) { await model.load(app: app) }
                .padding(.top, 60)
        } else {
            ProgressView().frame(maxWidth: .infinity).padding(.top, 120)
        }
    }

    @ViewBuilder
    private func sectionView(_ section: HomeShelves.Section, feed: HomeFeed, quick: [HomeShelves.InProgress],
                             listening: [AudiobookSummary], stats: ReadingStats?) -> some View {
        switch section {
        case .search:
            EmptyView()
        case .stats:
            if let stats {
                NavigationLink(value: StatsRoute()) { HomeStatsCard(stats: stats) }
                    .buttonStyle(.plain)
                    .padding(.bottom, 12)
            }
        case .firstCategory:
            FirstCategoryCard(hasOwn: !ReadingLists.own(app.lists.lists).isEmpty) { newCategory = true }
                .padding(.bottom, 4)
        case .continueStrip:
            if !quick.isEmpty {
                QuickResumeStrip(items: quick).padding(.top, 4)
            }
        case .category(let list, let novels, let count):
            CategoryShelf(list: list, novels: novels, count: count, wide: wide)
                .padding(.top, 24)
        case .manageCategories:
            NavigationLink(value: CategoriesRoute()) {
                Label("Manage categories", systemImage: "tag")
                    .font(.ui(.subheadline, .medium))
                    .frame(minHeight: 44)
            }
            .padding(.top, 12)
        case .favourites:
            if let favourites = app.serverMeta?.favourites == true ? app.lists.favourites : nil {
                CoverShelf(
                    title: ReadingLists.favouritesName,
                    novels: Array(HomeShelves.shelf(favourites, novels: model.library.novels).prefix(HomeShelves.shelfMax)),
                    total: favourites.count,
                    wide: wide,
                    empty: "Tap the heart on any book, web novel or manga and it lives here.",
                    seeAll: FolderRoute(id: favourites.id)
                )
                .padding(.top, 24)
            }
        case .wantToRead:
            let want = app.lists.wantToRead
            WantToReadShelf(items: ReadingLists.shown(feed.wantToRead, list: want),
                            total: want?.count ?? feed.wantToReadCount, wide: wide)
                .padding(.top, 28)
        case .inProgress:
            let progress = HomeShelves.inProgress(reading: feed.continueReading, listening: listening)
                .filter { item in !quick.contains { $0.key == item.key } }
            if !progress.isEmpty {
                InProgressShelf(items: progress, wide: wide, audiobooks: audiobooksOffered)
                    .padding(.top, 24)
            } else if quick.isEmpty && feed.continueReading.isEmpty {
                Text("Nothing in progress. Pick something from your Library to start.")
                    .foregroundStyle(.secondary)
                    .padding(.top, 20)
            }
        case .folders:
            if app.lists.loaded {
                FoldersSection(folders: HomeShelves.folders(app.lists.lists, novels: model.library.novels), wide: wide) {
                    newCategory = true
                }
                .padding(.top, 24)
            }
        case .newChapters:
            newChaptersSection(NewChapters.shown(feed.newChapters))
        case .library:
            NavigationLink {
                LibraryView()
            } label: {
                Label("Open the full Library", systemImage: "books.vertical")
                    .font(.ui(.body, .semibold))
                    .frame(maxWidth: .infinity, minHeight: 48)
                    .background(Color.brandAccent.opacity(0.14), in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            }
            .buttonStyle(.plain)
            .foregroundStyle(Color.brandAccent)
            .padding(.top, 28)
        }
    }

    @ViewBuilder
    private func newChaptersSection(_ items: [NewChaptersItem]) -> some View {
        if !items.isEmpty {
            VStack(alignment: .leading, spacing: 4) {
                SectionTitle("New chapters").padding(.bottom, 8)
                ForEach(items) { item in
                    Group {
                        if let first = item.firstChapter {
                            NavigationLink(value: ReaderRoute(novelId: item.novel.id, chapterId: first.id, offerLatest: true,
                                                              manga: item.novel.isPaged)) {
                                NewChaptersRow(item: item)
                            }
                        } else {
                            NavigationLink(value: NovelRoute(id: item.novel.id, summary: item.novel)) {
                                NewChaptersRow(item: item)
                            }
                        }
                    }
                    .buttonStyle(.plain)
                    if item.id != items.last?.id { Divider().padding(.leading, 64) }
                }
            }
            .padding(.top, 28)
        }
    }

    // MARK: Search

    @ViewBuilder
    private var searchResults: some View {
        let series = model.seriesResults
        let audiobooks = model.audiobookResults
        if series.isEmpty && audiobooks.isEmpty {
            Text(model.searchingAudiobooks ? "Searching…" : "Nothing matches “\(model.query.trimmingCharacters(in: .whitespaces))”.")
                .foregroundStyle(.secondary)
                .padding(.vertical, 16)
        } else {
            if !series.isEmpty {
                SectionTitle("Series").padding(.top, 8).padding(.bottom, 4)
                ForEach(series) { novel in
                    NavigationLink(value: NovelRoute(id: novel.id, summary: novel)) {
                        ResultRow(cover: novel.coverUrl, title: novel.name,
                                  subtitle: [novel.author, Medium.label(novel.medium)].compactMap { $0 }.joined(separator: " · "))
                    }
                    .buttonStyle(.plain)
                }
            }
            if !audiobooks.isEmpty {
                SectionTitle("Audiobooks").padding(.top, 16).padding(.bottom, 4)
                ForEach(audiobooks) { book in
                    NavigationLink(value: PlayerRoute(id: book.id)) {
                        ResultRow(cover: book.coverUrl, title: book.title,
                                  subtitle: [book.authorOrNil, listeningCaption(book)].compactMap { $0 }.joined(separator: " · "))
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }
}

/// "2 h 10 min left", "Finished", "Not started": an audiobook's place, in short.
func listeningCaption(_ book: AudiobookSummary) -> String {
    if book.progress?.finished == true { return "Finished" }
    guard book.started else { return "Not started" }
    let leftMinutes = max(Int((book.durationMs - (book.progress?.positionMs ?? 0)) / 60_000), 0)
    if leftMinutes >= 60 { return "\(leftMinutes / 60) h \(leftMinutes % 60) min left" }
    return "\(leftMinutes) min left"
}

// MARK: - Home's pieces

private struct SectionTitle: View {
    let text: String
    init(_ text: String) { self.text = text }

    var body: some View {
        Text(text)
            .font(.literata(.title3, .semibold))
            .accessibilityAddTraits(.isHeader)
    }
}

/// A section's title row: the name, an optional count and "See all".
private struct ShelfHeader<Route: Hashable>: View {
    let title: String
    var count: Int?
    var seeAll: Route?
    var seeAllLabel: String?

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: 8) {
            SectionTitle(title).lineLimit(1)
            if let count {
                Text("\(count)").font(.ui(.subheadline)).foregroundStyle(.secondary).accessibilityHidden(true)
            }
            Spacer(minLength: 8)
            if let seeAll {
                NavigationLink(value: seeAll) {
                    Text("See all").font(.ui(.subheadline, .medium))
                }
                .accessibilityLabel(seeAllLabel ?? "See all")
            }
        }
    }
}

/// One category on Home: its name, how many series, "See all" (the
/// category's screen), and a row of covers with progress and unread counts.
private struct CategoryShelf: View {
    let list: ReadingList
    let novels: [NovelSummary]
    let count: Int
    let wide: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            ShelfHeader(title: list.name, count: count, seeAll: FolderRoute(id: list.id),
                        seeAllLabel: "See all \(seriesCount(count)) in \(list.name)")
            if novels.isEmpty {
                // The library copy hasn't these series yet (still loading, or offline): the count stands in.
                Text(seriesCount(count)).foregroundStyle(.secondary)
            } else {
                ScrollView(.horizontal, showsIndicators: false) {
                    LazyHStack(alignment: .top, spacing: 14) {
                        ForEach(novels.prefix(HomeShelves.shelfMax)) { novel in
                            NavigationLink(value: NovelRoute(id: novel.id, summary: novel)) {
                                LibraryCell(novel: novel).frame(width: wide ? 120 : 96)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
                .scrollClipDisabled()
            }
        }
    }
}

/// A titled row of covers with "See all" (Favourites).
private struct CoverShelf: View {
    let title: String
    let novels: [NovelSummary]
    let total: Int
    let wide: Bool
    let empty: String
    let seeAll: FolderRoute

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            ShelfHeader(title: title, seeAll: total > 0 ? seeAll : nil, seeAllLabel: "See all \(total) in \(title)")
            if novels.isEmpty {
                Text(empty).foregroundStyle(.secondary)
            } else {
                ScrollView(.horizontal, showsIndicators: false) {
                    LazyHStack(alignment: .top, spacing: 14) {
                        ForEach(novels) { novel in
                            NavigationLink(value: NovelRoute(id: novel.id, summary: novel)) {
                                VStack(alignment: .leading, spacing: 6) {
                                    CoverView(url: novel.coverUrl, title: novel.name, targetWidth: 120)
                                    Text(novel.name).font(.ui(.footnote, .medium)).lineLimit(2)
                                    Text([Medium.label(novel.medium).uppercased(), novel.audiobook != nil ? "AUDIO" : nil]
                                        .compactMap { $0 }.joined(separator: " · "))
                                        .font(.ui(.caption2, .semibold))
                                        .foregroundStyle(.secondary)
                                }
                                .frame(width: wide ? 120 : 96)
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
                .scrollClipDisabled()
            }
        }
    }
}

private struct WantToReadShelf: View {
    let items: [WantToReadItem]
    let total: Int
    let wide: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            ShelfHeader(title: ReadingLists.wantToReadName, seeAll: total > 0 ? WantToReadRoute() : nil,
                        seeAllLabel: "See all \(total) in Want to read")
            if items.isEmpty {
                Text("Shortlist what to read next: tap Want to read on a series and it waits here.")
                    .foregroundStyle(.secondary)
            } else {
                ScrollView(.horizontal, showsIndicators: false) {
                    LazyHStack(alignment: .top, spacing: 14) {
                        ForEach(items) { item in
                            let started = ReadingLists.started(item)
                            NavigationLink(value: NovelRoute(id: item.novel.id, summary: item.novel)) {
                                VStack(alignment: .leading, spacing: 6) {
                                    CoverView(url: item.novel.coverUrl, title: item.novel.name, targetWidth: 130)
                                    Text(item.novel.name).font(.ui(.footnote, .medium)).lineLimit(2)
                                    Text(started ? "STARTED" : Medium.label(item.novel.medium).uppercased())
                                        .font(.ui(.caption2, .semibold))
                                        .foregroundStyle(started ? Color.brandAccent : Color.secondary)
                                }
                                .frame(width: wide ? 130 : 108)
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }
                .scrollClipDisabled()
            }
        }
    }
}

/// Opens an in-progress item: the reader, or the audiobook player.
private struct InProgressLink<Label: View>: View {
    let item: HomeShelves.InProgress
    @ViewBuilder let label: Label

    var body: some View {
        switch item {
        case .reading(let reading):
            NavigationLink(value: ReaderRoute(novelId: reading.novel.id, chapterId: reading.chapter.id, offerLatest: true,
                                              manga: reading.novel.isPaged)) { label }
        case .listening(let book):
            NavigationLink(value: PlayerRoute(id: book.id)) { label }
        }
    }
}

private func detail(_ item: HomeShelves.InProgress) -> String {
    switch item {
    case .reading(let reading): reading.chapter.label
    case .listening(let book): listeningCaption(book)
    }
}

/// The compact Continue strip: one small card each for the latest read and
/// the latest listen, side by side, scrolling sideways on narrow screens.
private struct QuickResumeStrip: View {
    let items: [HomeShelves.InProgress]

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            SectionTitle("Continue")
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 10) {
                    ForEach(items) { item in
                        InProgressLink(item: item) { QuickResumeCard(item: item).frame(width: 300) }
                            .buttonStyle(.plain)
                    }
                }
            }
            .scrollClipDisabled()
        }
    }
}

private struct QuickResumeCard: View {
    let item: HomeShelves.InProgress

    var body: some View {
        let listening: Bool = if case .listening = item { true } else { false }
        HStack(spacing: 12) {
            CoverView(url: item.coverUrl, title: item.title, cornerRadius: 4, targetWidth: 40)
                .frame(width: 40)
            VStack(alignment: .leading, spacing: 3) {
                Text(item.title).font(.ui(.subheadline, .semibold)).lineLimit(1)
                Text(detail(item)).font(.ui(.caption)).foregroundStyle(.secondary).lineLimit(1)
                ProgressLine(fraction: item.fraction)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            Image(systemName: listening ? "play.fill" : "book.fill")
                .foregroundStyle(Color.onAccent)
                .frame(width: 44, height: 44)
                .background(Color.brandAccent, in: Circle())
        }
        .padding(8)
        .background(Color.surface, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
        .accessibilityLabel("\(listening ? "Continue listening" : "Continue reading"): \(item.title)")
    }
}

/// "Reading & listening": every series and audiobook in progress, as covers with a progress line.
private struct InProgressShelf: View {
    let items: [HomeShelves.InProgress]
    let wide: Bool
    let audiobooks: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                SectionTitle(audiobooks ? "Reading & listening" : "Reading")
                Spacer()
                if audiobooks {
                    NavigationLink(value: AudiobooksRoute()) { Text("All audiobooks").font(.ui(.subheadline, .medium)) }
                }
            }
            ScrollView(.horizontal, showsIndicators: false) {
                LazyHStack(alignment: .top, spacing: 14) {
                    ForEach(items) { item in
                        InProgressLink(item: item) {
                            VStack(alignment: .leading, spacing: 6) {
                                CoverView(url: item.coverUrl, title: item.title, targetWidth: 120)
                                    .overlay(alignment: .bottom) {
                                        ProgressLine(fraction: item.fraction, tint: .white, track: .black.opacity(0.35))
                                            .padding(6)
                                    }
                                    .overlay(alignment: .topTrailing) {
                                        if case .listening = item {
                                            Image(systemName: "headphones")
                                                .font(.system(size: 11, weight: .semibold))
                                                .foregroundStyle(.white)
                                                .padding(5)
                                                .background(Color.black.opacity(0.55), in: Circle())
                                                .padding(4)
                                        }
                                    }
                                Text(item.title).font(.ui(.footnote, .medium)).lineLimit(2)
                                Text(detail(item)).font(.ui(.caption2)).foregroundStyle(.secondary).lineLimit(1)
                            }
                            .frame(width: wide ? 120 : 96)
                            .contentShape(Rectangle())
                            .accessibilityElement(children: .combine)
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
            .scrollClipDisabled()
        }
    }
}

/// The reader's folders as tiles (a 2×2 collage of covers, the name and count), then "New category".
private struct FoldersSection: View {
    let folders: [HomeShelves.Folder]
    let wide: Bool
    let onNew: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                SectionTitle("Categories")
                Spacer()
                Button("New category", action: onNew).font(.ui(.subheadline, .medium))
            }
            if folders.isEmpty {
                Text("Make categories for anything — “Manga – Action”, “Comfort reads” — then select series in the Library to put them in.")
                    .foregroundStyle(.secondary)
            }
            LazyVGrid(columns: Array(repeating: GridItem(.flexible(), spacing: 14, alignment: .top), count: wide ? 4 : 2),
                      alignment: .leading, spacing: 14) {
                ForEach(folders) { folder in
                    NavigationLink(value: FolderRoute(id: folder.id)) { FolderTile(folder: folder) }
                        .buttonStyle(.plain)
                }
                Button(action: onNew) {
                    VStack(alignment: .leading, spacing: 6) {
                        RoundedRectangle(cornerRadius: 12, style: .continuous)
                            .strokeBorder(Color.primary.opacity(0.15))
                            .aspectRatio(1, contentMode: .fit)
                            .overlay {
                                Image(systemName: "folder.badge.plus").font(.system(size: 30)).foregroundStyle(Color.brandAccent)
                            }
                        Text("New category").font(.ui(.footnote, .medium)).foregroundStyle(Color.brandAccent)
                    }
                }
                .buttonStyle(.plain)
                .accessibilityLabel("Make a new category")
            }
        }
    }
}

private struct FolderTile: View {
    let folder: HomeShelves.Folder

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            RoundedRectangle(cornerRadius: 12, style: .continuous)
                .fill(Color.surface)
                .aspectRatio(1, contentMode: .fit)
                .overlay {
                    if folder.covers.isEmpty {
                        Image(systemName: "folder").font(.system(size: 34)).foregroundStyle(.secondary)
                    } else {
                        Grid(horizontalSpacing: 4, verticalSpacing: 4) {
                            ForEach(0..<2, id: \.self) { row in
                                GridRow {
                                    ForEach(0..<2, id: \.self) { column in
                                        let index = row * 2 + column
                                        if index < folder.covers.count {
                                            CoverView(url: folder.covers[index], title: folder.name, cornerRadius: 6, targetWidth: 70)
                                                .aspectRatio(contentMode: .fill)
                                                .frame(maxWidth: .infinity, maxHeight: .infinity)
                                                .clipped()
                                        } else {
                                            Color.clear.frame(maxWidth: .infinity, maxHeight: .infinity)
                                        }
                                    }
                                }
                            }
                        }
                        .padding(6)
                    }
                }
                .clipShape(RoundedRectangle(cornerRadius: 12, style: .continuous))
            Text(folder.name).font(.ui(.footnote, .medium)).lineLimit(1)
            Text(seriesCount(folder.count)).font(.ui(.caption2)).foregroundStyle(.secondary)
        }
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
    }
}

/// The first run after 1.18 (no category holds a series yet): what Home is
/// now, with one tap to make the first category (`hasOwn`: there is one
/// already, empty, so the tap goes to the Library to fill it).
private struct FirstCategoryCard: View {
    let hasOwn: Bool
    let onCreate: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text("Home is your categories now")
                .font(.ui(.headline))
                .accessibilityAddTraits(.isHeader)
            Text(hasOwn
                 ? "Touch and hold series in the Library, choose Select, then Set categories, and each category gets its own row here."
                 : "Make categories like “Action” or “Comfort reads”, then select series in the Library to put them in. Each one gets its own row here.")
                .font(.ui(.subheadline))
                .foregroundStyle(.secondary)
            Group {
                if hasOwn {
                    NavigationLink { LibraryView() } label: {
                        Label("Open the Library", systemImage: "tag")
                    }
                } else {
                    Button(action: onCreate) { Label("Create your first category", systemImage: "tag") }
                }
            }
            .buttonStyle(.bordered)
            .tint(Color.brandAccent)
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
    }
}

/// Home's compact "Your stats" card: today's chapters and listening and the
/// current streak; tapping opens the Stats screen.
private struct HomeStatsCard: View {
    let stats: ReadingStats

    var body: some View {
        let h = stats.headline
        let minutes = Int(h.listeningSecondsToday / 60)
        VStack(alignment: .leading, spacing: 8) {
            HStack {
                Image(systemName: "chart.bar.xaxis").foregroundStyle(Color.brandAccent)
                Text("Your stats").font(.ui(.subheadline, .semibold))
                Spacer()
                Text("Today").font(.ui(.caption)).foregroundStyle(.secondary)
            }
            HStack(alignment: .top) {
                figure(h.chaptersToday.formatted(), h.chaptersToday == 1 ? "chapter" : "chapters")
                figure("\(minutes)", "min listened")
                figure("\(h.currentStreak)", h.currentStreak == 1 ? "day streak" : "days streak",
                       accent: h.activeToday && h.currentStreak > 0)
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(Color.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .contentShape(Rectangle())
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Your stats. Today \(h.chaptersToday) chapters and \(minutes) minutes of listening. Streak \(h.currentStreak) days.")
        .accessibilityHint("Opens your stats")
    }

    private func figure(_ value: String, _ label: String, accent: Bool = false) -> some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(value).font(.uiMono(.title2)).foregroundStyle(accent ? Color.brandAmber : Color.primary)
            Text(label).font(.ui(.caption2)).foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
}

/// Home's labelled way into Requests (phones).
private struct RequestsEntry: View {
    var body: some View {
        NavigationLink(value: RequestsRoute()) {
            HStack(spacing: 16) {
                Image(systemName: "tray.and.arrow.down").font(.title3).foregroundStyle(Color.brandAccent)
                VStack(alignment: .leading, spacing: 2) {
                    Text("Requests").font(.ui(.subheadline, .semibold))
                    Text("Ask for a book, web novel or manga").font(.ui(.caption)).foregroundStyle(.secondary)
                }
                Spacer()
                Image(systemName: "chevron.right").font(.caption).foregroundStyle(.secondary)
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 12)
            .background(Color.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}

private struct ResultRow: View {
    let cover: URL?
    let title: String
    let subtitle: String

    var body: some View {
        HStack(spacing: 14) {
            CoverView(url: cover, title: title, cornerRadius: 4, targetWidth: 40).frame(width: 40)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).font(.ui(.body)).lineLimit(2)
                if !subtitle.isEmpty {
                    Text(subtitle).font(.ui(.footnote)).foregroundStyle(.secondary).lineLimit(1)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(.vertical, 6)
        .frame(minHeight: 56)
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
    }
}

private struct NewChaptersRow: View {
    let item: NewChaptersItem

    var body: some View {
        HStack(spacing: 14) {
            CoverView(url: item.novel.coverUrl, title: item.novel.name, cornerRadius: 4, targetWidth: 50)
                .frame(width: 50)
            VStack(alignment: .leading, spacing: 3) {
                Text(item.novel.name)
                    .font(.ui(.body, .medium))
                    .lineLimit(2)
                if let first = item.firstChapter {
                    Text("From \(first.label)")
                        .font(.ui(.footnote))
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            Spacer(minLength: 8)
            VStack(alignment: .trailing, spacing: 4) {
                if let badge = NewChapters.badge(item.count) {
                    Text(badge)
                        .font(.ui(.caption, .bold))
                        .foregroundStyle(Color.brandAccent)
                        .padding(.horizontal, 8)
                        .padding(.vertical, 4)
                        .background(Color.brandAccent.opacity(0.12), in: Capsule())
                }
                if let origin = SeriesOrigin.label(item.novel) {
                    Text(origin).font(.ui(.caption2)).foregroundStyle(.secondary)
                }
            }
        }
        .padding(.vertical, 8)
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
    }
}
