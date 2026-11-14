import SwiftUI

struct NovelView: View {
    @Environment(AppModel.self) private var app
    @State private var model: NovelModel
    @State private var synopsisExpanded = false
    @State private var confirmRemove = false
    @State private var bulkConfirmation: BulkReadRequest?

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
                        downloadBlock
                            .listRowSeparator(.hidden)
                        if let unavailable = model.detail?.sourceUnavailable, unavailable.count > 0 {
                            Label(unavailable.message.isEmpty
                                  ? "\(unavailable.count) chapters can't be fetched from the source."
                                  : unavailable.message,
                                  systemImage: "exclamationmark.triangle")
                                .font(.footnote)
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

                chaptersSection(proxy: proxy)
            }
            .listStyle(.plain)
            .searchable(text: $model.chapterSearch, placement: .navigationBarDrawer(displayMode: .automatic),
                        prompt: "Search chapters")
            .toolbar { toolbarContent(proxy: proxy) }
        }
        .navigationTitle(model.summary?.name ?? "")
        .navigationBarTitleDisplayMode(.inline)
        .refreshable { await model.load(app: app) }
        .task { await model.load(app: app) }
        .task(id: app.downloads.revision) { await model.refreshCachedIDs(app: app) }
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
                    Text("Read").font(.footnote.weight(.semibold))
                    Spacer()
                    Text("\(summary.chaptersRead.formatted()) / \(summary.chaptersTotal.formatted()) · \(summary.readPercent)%")
                        .font(.footnote.monospacedDigit())
                        .foregroundStyle(.secondary)
                }
                ProgressLine(fraction: summary.readFraction)
            }
            VStack(alignment: .leading, spacing: 4) {
                HStack {
                    Text("On server").font(.footnote.weight(.semibold))
                    Spacer()
                    Text("\(summary.chaptersDownloaded.formatted()) / \(summary.chaptersTotal.formatted()) · \(summary.downloadPercent)%")
                        .font(.footnote.monospacedDigit())
                        .foregroundStyle(.secondary)
                }
                ProgressLine(fraction: Double(summary.downloadPercent) / 100, tint: .secondary)
            }
        }
        .accessibilityElement(children: .combine)
    }

    @ViewBuilder
    private func primaryButton(_ summary: NovelSummary) -> some View {
        if let resume = summary.resumeChapterId {
            // The invisible link keeps List from drawing a disclosure chevron.
            ZStack {
                Text(summary.chaptersRead > 0 ? "Continue reading" : "Start reading")
                    .font(.headline)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                    .foregroundStyle(.white)
                    .background(Color.accentColor, in: RoundedRectangle(cornerRadius: 12, style: .continuous))
                NavigationLink(value: ReaderRoute(novelId: model.novelId, chapterId: resume)) { EmptyView() }
                    .opacity(0)
            }
            .accessibilityElement(children: .combine)
            .accessibilityAddTraits(.isButton)
        } else {
            Text(summary.finished ? "You've read everything here." : "No chapters downloaded on the server yet.")
                .font(.callout)
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
                        .font(.footnote)
                    Spacer()
                    Button("Cancel") { app.downloads.cancel(novelId: model.novelId) }
                        .font(.footnote.weight(.semibold))
                        .buttonStyle(.borderless)
                }
                ProgressView(value: job.fraction)
            }
        } else {
            HStack {
                Label(offline == 0 ? "Not available offline" : "\(offline.formatted()) chapters on this device",
                      systemImage: offline == 0 ? "icloud" : "arrow.down.circle.fill")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                Spacer()
                Menu {
                    downloadMenuItems
                } label: {
                    Label("Download", systemImage: "arrow.down.circle")
                        .font(.footnote.weight(.semibold))
                }
                .disabled(model.chapters.isEmpty || !app.isReachable)
            }
            if let message = app.downloads.lastMessage[model.novelId] {
                Text(message).font(.caption).foregroundStyle(.secondary)
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
    private func chaptersSection(proxy: ScrollViewProxy) -> some View {
        let visible = model.visibleChapters
        Section {
            if model.chapters.isEmpty && model.phase == .loaded {
                Text("No chapters yet.").foregroundStyle(.secondary)
            } else if visible.isEmpty && !model.chapterSearch.isEmpty {
                Text("No chapters match “\(model.chapterSearch)”.").foregroundStyle(.secondary)
            }
            ForEach(visible) { chapter in
                NavigationLink(value: ReaderRoute(novelId: model.novelId, chapterId: chapter.id)) {
                    ChapterRow(chapter: chapter, isOffline: model.cachedIDs.contains(chapter.id))
                }
                .swipeActions(edge: .leading) {
                    Button {
                        Task { await model.setRead(chapter, read: !chapter.read, app: app) }
                    } label: {
                        Label(chapter.read ? "Unread" : "Read", systemImage: chapter.read ? "circle" : "checkmark.circle")
                    }
                    .tint(chapter.read ? .gray : .accentColor)
                }
                .contextMenu { chapterMenu(chapter) }
            }
        } header: {
            HStack {
                Text("Chapters")
                Spacer()
                if !model.chapters.isEmpty {
                    Text("\(model.readCount.formatted()) of \(model.chapters.count.formatted()) read")
                        .font(.footnote.monospacedDigit())
                }
            }
            .font(.subheadline.weight(.semibold))
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
                        withAnimation { proxy.scrollTo(unread.id, anchor: .center) }
                    }
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

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(alignment: .top, spacing: 16) {
                CoverView(url: summary.coverLargeUrl ?? summary.coverUrl, title: summary.name,
                          cornerRadius: 8, targetWidth: coverWidth)
                    .frame(width: coverWidth)
                    .shadow(color: .black.opacity(0.2), radius: 10, y: 5)
                VStack(alignment: .leading, spacing: 8) {
                    Text(summary.name)
                        .font(.system(sizeClass == .regular ? .largeTitle : .title2, design: .serif).weight(.semibold))
                        .fixedSize(horizontal: false, vertical: true)
                    if let author = summary.author {
                        Text(author).font(.headline).foregroundStyle(.secondary)
                    }
                    HStack(spacing: 6) {
                        StateBadge(state: summary.state)
                        if let origin = summary.origin {
                            Text(origin).font(.caption).foregroundStyle(.secondary).lineLimit(1)
                        }
                    }
                    if let names = detail?.altNames, !names.isEmpty {
                        Text(names.joined(separator: " · "))
                            .font(.caption)
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
                                .font(.caption)
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
                        .font(.system(.callout, design: .serif))
                        .lineLimit(synopsisExpanded ? nil : 4)
                        .fixedSize(horizontal: false, vertical: true)
                    Button(synopsisExpanded ? "Less" : "More") {
                        withAnimation(.easeInOut(duration: 0.2)) { synopsisExpanded.toggle() }
                    }
                    .font(.footnote.weight(.semibold))
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
                .fill(chapter.read || !chapter.downloaded ? Color.clear : Color.accentColor)
                .frame(width: 7, height: 7)
            VStack(alignment: .leading, spacing: 2) {
                Text(chapter.label)
                    .font(.body)
                    .foregroundStyle(chapter.read ? Color.secondary : Color.primary)
                    .lineLimit(2)
                if let subtitle {
                    Text(subtitle)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            Spacer(minLength: 4)
            if let progress = chapter.readProgress, progress > 0, progress < 100, !chapter.read {
                Text("\(progress)%")
                    .font(.caption.monospacedDigit())
                    .foregroundStyle(.secondary)
            }
            if isOffline {
                Image(systemName: "arrow.down.circle.fill")
                    .font(.caption)
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
