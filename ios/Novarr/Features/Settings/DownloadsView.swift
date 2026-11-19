import SwiftUI

/// Novels with chapters saved on this device, with the space they use.
struct DownloadsView: View {
    @Environment(AppModel.self) private var app
    @State private var items: [OfflineStore.NovelDownloads]?

    var body: some View {
        List {
            if let items {
                if items.isEmpty {
                    ContentUnavailableView("No downloads", systemImage: "arrow.down.circle",
                                           description: Text("Use “Download” on a novel to read it without a connection."))
                        .listRowSeparator(.hidden)
                } else {
                    Section {
                        ForEach(items) { item in
                            NavigationLink(value: NovelRoute(id: item.novelId, summary: nil)) {
                                VStack(alignment: .leading, spacing: 3) {
                                    Text(item.name).font(.ui(.body, .medium)).lineLimit(2)
                                    Text("\(item.chapterCount.formatted()) chapters · \(item.bytes.byteString)")
                                        .font(.ui(.footnote))
                                        .foregroundStyle(.secondary)
                                }
                                .padding(.vertical, 2)
                            }
                            .swipeActions {
                                Button("Remove", systemImage: "trash", role: .destructive) {
                                    Task { await remove(item) }
                                }
                            }
                        }
                    } footer: {
                        Text("Total \(items.reduce(0) { $0 + $1.bytes }.byteString). Swipe to remove a novel's offline chapters.")
                    }
                }
            } else {
                ProgressView().frame(maxWidth: .infinity)
            }
        }
        .brandBackground()
        .navigationTitle("Downloads")
        .task(id: app.downloads.revision) { await reload() }
    }

    private func reload() async {
        items = await app.store?.downloads() ?? []
    }

    private func remove(_ item: OfflineStore.NovelDownloads) async {
        guard let store = app.store else { return }
        await app.downloads.remove(novelId: item.novelId, store: store)
        await reload()
    }
}
