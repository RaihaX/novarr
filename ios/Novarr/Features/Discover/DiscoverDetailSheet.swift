import SwiftUI

/// A Discover result in full, with Add to library / Open.
struct DiscoverDetailSheet: View {
    let item: DiscoverItem
    let model: DiscoverModel
    let sourceName: String?
    /// Opens the library novel (the sheet is dismissed by the caller).
    let open: (Int) -> Void

    @Environment(AppModel.self) private var app
    @Environment(\.dismiss) private var dismiss

    private var addState: DiscoverModel.AddState? { model.addStates[item.id] }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    header
                    action
                    status
                    if let synopsis = item.synopsis {
                        Text(synopsis)
                            .font(.system(.callout, design: .serif))
                            .fixedSize(horizontal: false, vertical: true)
                    }
                    if let host = item.url.host() {
                        Link(destination: item.url) {
                            Label("View on \(host)", systemImage: "safari")
                                .font(.footnote)
                        }
                    }
                }
                .padding(20)
            }
            .navigationTitle(sourceName ?? "")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { dismiss() }
                }
            }
        }
        .presentationDetents([.medium, .large])
    }

    private var header: some View {
        HStack(alignment: .top, spacing: 16) {
            CoverView(url: item.coverUrl, title: item.title, cornerRadius: 8, targetWidth: 110)
                .frame(width: 110)
                .shadow(color: .black.opacity(0.18), radius: 8, y: 4)
            VStack(alignment: .leading, spacing: 8) {
                Text(item.title)
                    .font(.system(.title2, design: .serif).weight(.semibold))
                    .fixedSize(horizontal: false, vertical: true)
                if let author = item.author {
                    Text(author).font(.headline).foregroundStyle(.secondary)
                }
                if let facts = item.facts {
                    Text(facts).font(.subheadline).foregroundStyle(.secondary)
                }
                if item.inLibrary { InLibraryBadge() }
            }
        }
    }

    @ViewBuilder
    private var action: some View {
        if item.inLibrary, let novelId = item.novelId {
            Button {
                open(novelId)
            } label: {
                Label("Open", systemImage: "book")
                    .font(.headline)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 6)
            }
            .buttonStyle(.borderedProminent)
        } else {
            Button {
                Task { await model.add(item, app: app) }
            } label: {
                HStack(spacing: 8) {
                    if addState == .adding {
                        ProgressView().tint(.white)
                    } else {
                        Image(systemName: "plus")
                    }
                    Text(addState == .adding ? "Adding…" : "Add to library")
                }
                .font(.headline)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 6)
            }
            .buttonStyle(.borderedProminent)
            .disabled(addState == .adding)
        }
    }

    @ViewBuilder
    private var status: some View {
        switch addState {
        case .added(let novelId, let already):
            if already {
                Label("Already in your library.", systemImage: "checkmark.circle")
                    .font(.footnote)
                    .foregroundStyle(.secondary)
            } else {
                VStack(alignment: .leading, spacing: 4) {
                    Label("Added to your library.", systemImage: "checkmark.circle.fill")
                        .foregroundStyle(.green)
                    if let display = app.checks.display(for: novelId) {
                        CheckStatusLabel(display: display)
                    }
                }
                .font(.footnote)
            }
        case .failed(let message):
            Label(message, systemImage: "exclamationmark.triangle")
                .font(.footnote)
                .foregroundStyle(.orange)
        case .adding, nil:
            EmptyView()
        }
    }
}
