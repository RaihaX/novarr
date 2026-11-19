import SwiftUI

struct HighlightsView: View {
    @Environment(AppModel.self) private var app
    @State private var model: HighlightsModel
    private let route: HighlightsRoute

    init(route: HighlightsRoute) {
        self.route = route
        _model = State(initialValue: HighlightsModel(novelId: route.novelId))
    }

    var body: some View {
        List {
            Group { content }
                .listRowBackground(Color.appBackground)
        }
        .listStyle(.plain)
        .brandBackground()
        .navigationTitle("Highlights")
        .navigationBarTitleDisplayMode(route.novelId == nil ? .large : .inline)
        .refreshable { await model.load(app: app) }
        .task { await model.load(app: app) }
        .alert("Highlight", isPresented: Binding(
            get: { model.actionError != nil }, set: { if !$0 { model.actionError = nil } }
        )) {
            Button("OK", role: .cancel) {}
        } message: {
            Text(model.actionError ?? "")
        }
    }

    @ViewBuilder
    private var content: some View {
        if let name = route.novelName, route.novelId != nil {
            Text(name)
                .font(.literata(.title3, .semibold))
                .listRowSeparator(.hidden)
        }
        if model.highlights.isEmpty {
            switch model.phase {
            case .failed(let message):
                LoadFailedView(title: "Couldn't load highlights", message: message) { await model.load(app: app) }
                    .listRowSeparator(.hidden)
            case .loaded:
                ContentUnavailableView(
                    "No highlights yet", systemImage: "highlighter",
                    description: Text("Passages you highlight in the reader or on your Novarr website show up here, newest first."))
                    .listRowSeparator(.hidden)
            case .idle, .loading:
                ProgressView().frame(maxWidth: .infinity).padding(.top, 80)
                    .listRowSeparator(.hidden)
            }
        } else {
            ForEach(model.highlights) { highlight in
                NavigationLink(value: ReaderRoute(novelId: highlight.novelId, chapterId: highlight.chapterId,
                                                 highlight: highlight)) {
                    HighlightRow(highlight: highlight, showsNovel: route.novelId == nil)
                }
                .swipeActions(edge: .trailing) {
                    Button(role: .destructive) {
                        Task { await model.delete(highlight, app: app) }
                    } label: {
                        Label("Delete", systemImage: "trash")
                    }
                }
                .contextMenu {
                    Button("Copy Excerpt", systemImage: "doc.on.doc") {
                        UIPasteboard.general.string = highlight.excerpt
                    }
                    Button("Delete", systemImage: "trash", role: .destructive) {
                        Task { await model.delete(highlight, app: app) }
                    }
                }
                .task { await model.loadMoreIfNeeded(after: highlight, app: app) }
            }
            if model.isLoadingMore {
                ProgressView().frame(maxWidth: .infinity).padding(.vertical, 12)
                    .listRowSeparator(.hidden)
            } else if let message = model.moreError {
                VStack(spacing: 8) {
                    Text(message).font(.ui(.footnote)).foregroundStyle(.secondary).multilineTextAlignment(.center)
                    Button("Load more") { Task { await model.loadMore(app: app) } }
                        .font(.ui(.footnote, .semibold))
                        .buttonStyle(.borderless)
                }
                .frame(maxWidth: .infinity)
                .listRowSeparator(.hidden)
            }
        }
    }
}

struct HighlightRow: View {
    let highlight: Highlight
    var showsNovel = true

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(highlight.excerpt)
                .font(.literata(.body))
                .lineLimit(6)
                .fixedSize(horizontal: false, vertical: true)
                .padding(.leading, 10)
                .overlay(alignment: .leading) {
                    Rectangle().fill(Color.brandAccent).frame(width: 3)
                }
            if let note = highlight.note {
                Label {
                    Text(note).lineLimit(4)
                } icon: {
                    Image(systemName: "note.text")
                }
                .font(.ui(.footnote))
                .foregroundStyle(.primary)
            }
            Text(caption)
                .font(.ui(.caption))
                .foregroundStyle(.secondary)
                .lineLimit(2)
        }
        .padding(.vertical, 6)
        .accessibilityElement(children: .combine)
    }

    private var caption: String {
        var parts: [String] = []
        if showsNovel, !highlight.novelName.isEmpty { parts.append(highlight.novelName) }
        parts.append(highlight.chapterLabel)
        if let date = highlight.createdAt {
            parts.append(date.formatted(date: .abbreviated, time: .omitted))
        }
        return parts.joined(separator: " · ")
    }
}
