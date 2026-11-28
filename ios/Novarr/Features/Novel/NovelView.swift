import SwiftUI

struct NovelView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var model: NovelModel
    @State private var synopsisExpanded = false
    @State private var confirmRemove = false
    @State private var bulkConfirmation: BulkReadRequest?
    /// "Continue reading": pushed through `navigationDestination(item:)`.
    @State private var openReader: ReaderRoute?
    @Environment(\.openURL) private var openURL

    init(route: NovelRoute) {
        _model = State(initialValue: NovelModel(route: route))
    }

    var body: some View {
        @Bindable var model = model
        ScrollViewReader { proxy in
            List {
                if let summary = model.summary {
                    Section {
                        NovelHeader(summary: summary, detail: model.detail, synopsisExpanded: $synopsisExpanded)
                            .listRowSeparator(.hidden)
                        progressBlock(summary)
                            .listRowSeparator(.hidden)
                        primaryButton(summary)
                            .listRowSeparator(.hidden)
                        // 2.0: each area's controls (categories, audiobook, book).
                        NovelCategoryActions(summary: summary)
                            .listRowSeparator(.hidden)
                        NovelAudiobookActions(summary: summary)
                            .listRowSeparator(.hidden)
                        NovelBookActions(summary: summary, detail: model.detail)
                            .listRowSeparator(.hidden)
                        CheckForChaptersControl(novelId: model.novelId)
                            .listRowSeparator(.hidden)
                        downloadBlock
                            .listRowSeparator(.hidden)
                        if let unavailable = model.detail?.sourceUnavailable, unavailable.count > 0 {
                            Label(unavailable.message.isEmpty
                                  ? "\(unavailable.count) chapters can't be fetched from the source."
                                  : unavailable.message,
                                  systemImage: "exclamationmark.triangle")
                                .font(.ui(.footnote))
                                .foregroundStyle(.orange)
                                .listRowSeparator(.hidden)
                        }
                    }
                    .listRowBackground(Color.clear)
                } else if case .failed(let message) = model.phase {
                    LoadFailedView(title: "Couldn't open this novel", message: message) { await model.load(app: app) }
                        .listRowSeparator(.hidden)
                } else {
                    ProgressView().frame(maxWidth: .infinity).padding(.vertical, 60)
                        .listRowSeparator(.hidden)
                }

                highlightsSection
                chaptersSection(proxy: proxy)
            }
            .listStyle(.plain)
            .brandBackground()
            // On iPad a readable column, not rows stretched across the screen.
            .frame(maxWidth: sizeClass == .regular ? 860 : .infinity)
            .frame(maxWidth: .infinity)
            .searchable(text: $model.chapterSearch, placement: .navigationBarDrawer(displayMode: .automatic),
                        prompt: "Search chapters")
            .toolbar { toolbarContent(proxy: proxy) }
        }
        .navigationTitle(model.summary?.name ?? "")
        .navigationBarTitleDisplayMode(.inline)
        .navigationDestination(item: $openReader) { route in ReaderDestination(route: route) }
        .refreshable { await model.load(app: app) }
        .task {
            await model.load(app: app)
            #if DEBUG
            // Debug builds only: starts a check on open, for simulator screenshots.
            if UserDefaults.standard.bool(forKey: "debugStartCheck") { app.startCheck(novelId: model.novelId) }
            // Debug builds only: `-debugDownloadAll YES` downloads every chapter on open.
            if UserDefaults.standard.bool(forKey: "debugDownloadAll") { await model.download(.all, app: app) }
            #endif
        }
        .task(id: app.downloads.revision) { await model.refreshCachedIDs(app: app) }
        // A check listed or downloaded chapters: show them.
        .onChange(of: app.checks.revision(for: model.novelId)) {
            Task { await model.load(app: app) }
        }
        .overlay(alignment: .bottom) {
            if model.isShowingCache && model.phase == .loaded {
                OfflinePill().padding(.bottom, 12)
            }
        }
        .confirmationDialog("Remove offline chapters?", isPresented: $confirmRemove, titleVisibility: .visible) {
            Button("Remove Downloads", role: .destructive) { Task { await model.removeDownloads(app: app) } }
        } message: {
            Text("Chapters stay on your server; only this device's copies are removed.")
        }
        .confirmationDialog(bulkTitle, isPresented: Binding(
            get: { bulkConfirmation != nil }, set: { if !$0 { bulkConfirmation = nil } }
        ), titleVisibility: .visible) {
            if let request = bulkConfirmation {
                Button(request.read ? "Mark Read" : "Mark Unread") {
                    Task { await model.bulk(request, app: app) }
                }
            }
        }
    }

    /// List row ids: highlight and chapter ids are both plain Ints.
    private enum RowID: Hashable {
        case highlight(Int)
        case chapter(Int)
    }

    private var bulkTitle: String {
        guard let request = bulkConfirmation else { return "" }
        return request.read ? "Mark every chapter as read?" : "Mark every chapter as unread?"
    }

    // MARK: Sections

    @ViewBuilder
    private func progressBlock(_ summary: NovelSummary) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text("Read").font(.ui(.footnote, .semibold))
                    Spacer()
                    Text("\(summary.chaptersRead.formatted()) / \(summary.chaptersTotal.formatted()) · \(summary.readPercent)%")
                        .font(.ui(.footnote).monospacedDigit())
                        .foregroundStyle(.secondary)
                }
                ProgressLine(fraction: summary.readFraction)
            }
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text("On server").font(.ui(.footnote, .semibold))
                    Spacer()
                    Text("\(summary.chaptersDownloaded.formatted()) / \(summary.chaptersTotal.formatted()) · \(summary.downloadPercent)%")
                        .font(.ui(.footnote).monospacedDigit())
                        .foregroundStyle(.secondary)
                }
                ProgressLine(fraction: Double(summary.downloadPercent) / 100, tint: .secondary)
            }
        }
        .accessibilityElement(children: .combine)
    }

    @ViewBuilder
    private func primaryButton(_ summary: NovelSummary) -> some View {
        if let resume = model.resumeChapterId {
            // A real button (not an invisible link) so VoiceOver can activate
            // it; the destination is pushed on this stack.
            Button {
                openReader = model.readerRoute(chapterId: resume)
            } label: {
                Text(summary.chaptersRead > 0 ? "Continue reading" : "Start reading")
                    .font(.ui(.headline))
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                    .foregroundStyle(Color.onAccent)
                    .background(Color.brandAccent, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
            }
            .buttonStyle(.plain)
            .accessibilityHint("Opens the reader")
        } else {
            Text(summary.finished ? "You've read everything here." : "No chapters downloaded on the server yet.")
                .font(.ui(.callout))
                .foregroundStyle(.secondary)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 8)
        }
    }

    @ViewBuilder
    private var downloadBlock: some View {
        let offline = model.cachedIDs.count
        if let job = app.downloads.jobs[model.novelId] {
            VStack(alignment: .leading, spacing: 6) {
                HStack {
                    Text("Saving for offline… \(job.done + job.failed) of \(job.total)")
                        .font(.ui(.footnote))
                    Spacer()
                    Button("Cancel") { app.downloads.cancel(novelId: model.novelId) }
                        .font(.ui(.footnote, .semibold))
                        .buttonStyle(.borderless)
                }
                ProgressView(value: job.fraction)
            }
        } else {
            HStack {
                Label(offline == 0 ? "Not available offline" : "\(offline.formatted()) chapters on this device",
                      systemImage: offline == 0 ? "icloud" : "arrow.down.circle.fill")
                    .font(.ui(.footnote))
                    .foregroundStyle(.secondary)
                Spacer()
                Menu {
                    downloadMenuItems
                } label: {
                    Label("Download", systemImage: "arrow.down.circle")
                        .font(.ui(.footnote, .semibold))
                }
                .disabled(model.chapters.isEmpty || !app.isReachable)
            }
            if let message = app.downloads.lastMessage[model.novelId] {
                Text(message).font(.ui(.caption)).foregroundStyle(.secondary)
            }
        }
    }

    @ViewBuilder
    private var downloadMenuItems: some View {
        Button("Download All Chapters", systemImage: "arrow.down.circle") {
            Task { await model.download(.all, app: app) }
        }
        Button("Download Unread Chapters", systemImage: "arrow.down.circle.dotted") {
            Task { await model.download(.unread, app: app) }
        }
        if !model.cachedIDs.isEmpty {
            Button("Remove Downloads", systemImage: "trash", role: .destructive) { confirmRemove = true }
        }
    }

    @ViewBuilder
    private var highlightsSection: some View {
        if !model.highlights.isEmpty && model.chapterSearch.isEmpty {
            Section {
                ForEach(model.highlights) { highlight in
                    NavigationLink(value: ReaderRoute(novelId: model.novelId, chapterId: highlight.chapterId,
                                                     highlight: highlight)) {
                        HighlightRow(highlight: highlight, showsNovel: false)
                    }
                    // Highlights and chapters share this List: their ids must not collide.
                    .id(RowID.highlight(highlight.id))
                    .listRowBackground(Color.appBackground)
                }
                if model.hasMoreHighlights {
                    NavigationLink(value: HighlightsRoute(novelId: model.novelId, novelName: model.summary?.name)) {
                        Text("All highlights")
                            .font(.ui(.callout, .semibold))
                            .foregroundStyle(Color.brandAccent)
                    }
                }
            } header: {
                Text("Highlights")
                    .font(.ui(.subheadline, .semibold))
                    .textCase(nil)
            }
        }
    }

    @ViewBuilder
    private func chaptersSection(proxy: ScrollViewProxy) -> some View {
        let visible = model.visibleChapters
        Section {
            if model.chapters.isEmpty && model.phase == .loaded {
                Text("No chapters yet.").foregroundStyle(.secondary)
            } else if visible.isEmpty && !model.chapterSearch.isEmpty {
                Text("No chapters match “\(model.chapterSearch)”.").foregroundStyle(.secondary)
            }
            ForEach(visible) { chapter in
                NavigationLink(value: model.readerRoute(chapterId: chapter.id)) {
                    ChapterRow(chapter: chapter, isOffline: model.cachedIDs.contains(chapter.id))
                }
                .id(RowID.chapter(chapter.id))
                .listRowBackground(Color.appBackground)
                .swipeActions(edge: .leading) {
                    Button {
                        Task { await model.setRead(chapter, read: !chapter.read, app: app) }
                    } label: {
                        Label(chapter.read ? "Unread" : "Read", systemImage: chapter.read ? "circle" : "checkmark.circle")
                    }
                    .tint(chapter.read ? .gray : .brandAccent)
                }
                .contextMenu { chapterMenu(chapter) }
            }
        } header: {
            HStack {
                Text("Chapters")
                Spacer()
                if !model.chapters.isEmpty {
                    Text("\(model.readCount.formatted()) of \(model.chapters.count.formatted()) read")
                        .font(.ui(.footnote).monospacedDigit())
                }
            }
            .font(.ui(.subheadline, .semibold))
            .textCase(nil)
        }
    }

    @ViewBuilder
    private func chapterMenu(_ chapter: ChapterListItem) -> some View {
        Button(chapter.read ? "Mark as Unread" : "Mark as Read",
               systemImage: chapter.read ? "circle" : "checkmark.circle") {
            Task { await model.setRead(chapter, read: !chapter.read, app: app) }
        }
        Button("Mark Read Up to Here", systemImage: "text.badge.checkmark") {
            Task { await model.bulk(.upTo(chapter.id, novelId: model.novelId, read: true), app: app) }
        }
        Button("Mark Unread From Here", systemImage: "text.badge.xmark") {
            Task { await model.bulk(.from(chapter.id, novelId: model.novelId, read: false), app: app) }
        }
    }

    @ToolbarContentBuilder
    private func toolbarContent(proxy: ScrollViewProxy) -> some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            Menu {
                if let unread = model.firstUnread {
                    Button("Jump to First Unread", systemImage: "arrow.down.to.line") {
                        model.chapterSearch = ""
                        withAnimation { proxy.scrollTo(RowID.chapter(unread.id), anchor: .center) }
                    }
                }
                Button("Check for New Chapters", systemImage: "arrow.clockwise") {
                    app.startCheck(novelId: model.novelId)
                }
                .disabled(app.checks.isRunning(model.novelId))
                if let source = model.detail?.sourceUrl {
                    Button("Open Source Page", systemImage: "safari") { openURL(source) }
                }
                Divider()
                Button("Mark All Read", systemImage: "checkmark.circle") {
                    bulkConfirmation = .all(novelId: model.novelId, read: true)
                }
                Button("Mark All Unread", systemImage: "circle") {
                    bulkConfirmation = .all(novelId: model.novelId, read: false)
                }
                Divider()
                downloadMenuItems
                    .disabled(model.chapters.isEmpty || !app.isReachable)
            } label: {
                Label("More", systemImage: "ellipsis.circle")
            }
        }
    }
}

private struct NovelHeader: View {
    let summary: NovelSummary
    let detail: NovelDetail?
    @Binding var synopsisExpanded: Bool
    @Environment(\.horizontalSizeClass) private var sizeClass
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            // At accessibility text sizes the title gets the full width under the cover.
            let layout = dynamicTypeSize.isAccessibilitySize
                ? AnyLayout(VStackLayout(alignment: .leading, spacing: 14))
                : AnyLayout(HStackLayout(alignment: .top, spacing: 16))
            layout {
                CoverView(url: summary.coverLargeUrl ?? summary.coverUrl, title: summary.name,
                          cornerRadius: 8, targetWidth: coverWidth)
                    .frame(width: coverWidth)
                    .shadow(color: .black.opacity(0.2), radius: 10, y: 5)
                VStack(alignment: .leading, spacing: 8) {
                    Text(summary.name)
                        .font(.literata(sizeClass == .regular ? .largeTitle : .title2, .semibold))
                        .fixedSize(horizontal: false, vertical: true)
                    if let author = summary.author {
                        Text(author).font(.ui(.headline)).foregroundStyle(.secondary)
                    }
                    HStack(spacing: 6) {
                        StateBadge(state: summary.state)
                        if summary.isManga {
                            Text("MANGA")
                                .font(.ui(.caption2, .semibold))
                                .tracking(0.6)
                                .foregroundStyle(.secondary)
                        }
                        if let origin = summary.origin {
                            Text(origin).font(.ui(.caption)).foregroundStyle(.secondary).lineLimit(1)
                        }
                    }
                    if let names = detail?.altNames, !names.isEmpty {
                        Text(names.joined(separator: " · "))
                            .font(.ui(.caption))
                            .foregroundStyle(.tertiary)
                            .lineLimit(2)
                    }
                }
            }
            if !summary.tags.isEmpty {
                ScrollView(.horizontal, showsIndicators: false) {
                    HStack(spacing: 6) {
                        ForEach(summary.tags) { tag in
                            Text(tag.name)
                                .font(.ui(.caption))
                                .padding(.horizontal, 9)
                                .padding(.vertical, 4)
                                .background(Color(.secondarySystemFill), in: Capsule())
                        }
                    }
                }
                .scrollClipDisabled()
            }
            if let synopsis = detail?.description {
                VStack(alignment: .leading, spacing: 4) {
                    Text(synopsis)
                        .font(.literata(.callout))
                        .lineLimit(synopsisExpanded ? nil : 4)
                        .fixedSize(horizontal: false, vertical: true)
                    Button(synopsisExpanded ? "Less" : "More") {
                        withAnimation(.easeInOut(duration: 0.2)) { synopsisExpanded.toggle() }
                    }
                    .font(.ui(.footnote, .semibold))
                    .buttonStyle(.borderless)
                }
            }
        }
        .padding(.top, 8)
    }

    private var coverWidth: CGFloat { sizeClass == .regular ? 180 : 120 }
}

struct ChapterRow: View {
    let chapter: ChapterListItem
    let isOffline: Bool

    var body: some View {
        HStack(spacing: 10) {
            Circle()
                .fill(chapter.read || !chapter.downloaded ? Color.clear : Color.brandAccent)
                .frame(width: 7, height: 7)
            VStack(alignment: .leading, spacing: 2) {
                Text(chapter.label)
                    .font(.ui(.body))
                    .foregroundStyle(chapter.read ? Color.secondary : Color.primary)
                    .lineLimit(2)
                if let subtitle {
                    Text(subtitle)
                        .font(.ui(.caption))
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            Spacer(minLength: 4)
            if let progress = chapter.readProgress, progress > 0, progress < 100, !chapter.read {
                Text("\(progress)%")
                    .font(.ui(.caption).monospacedDigit())
                    .foregroundStyle(.secondary)
            }
            if isOffline {
                Image(systemName: "arrow.down.circle.fill")
                    .font(.ui(.caption))
                    .foregroundStyle(.secondary)
                    .accessibilityLabel("Available offline")
            }
        }
        .accessibilityElement(children: .combine)
        .accessibilityValue(chapter.read ? "Read" : "Unread")
    }

    private var subtitle: String? {
        var parts: [String] = []
        if chapter.note { parts.append("Note") }
        if !chapter.downloaded { parts.append(chapter.reason ?? "Not downloaded yet") }
        if let words = chapter.wordCount, words > 0 { parts.append("\(words.formatted()) words") }
        return parts.isEmpty ? nil : parts.joined(separator: " · ")
    }
}
