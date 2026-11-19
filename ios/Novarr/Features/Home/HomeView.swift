import SwiftUI

struct HomeView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.horizontalSizeClass) private var sizeClass
    @State private var model = HomeModel()

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 28) {
                if model.isShowingCache {
                    OfflinePill().frame(maxWidth: .infinity)
                }
                content
            }
            .padding(.horizontal, sizeClass == .regular ? 28 : 16)
            .padding(.vertical, 8)
            .frame(maxWidth: 1000)
            .frame(maxWidth: .infinity)
        }
        .brandBackground()
        .navigationTitle("Home")
        .refreshable { await model.load(app: app) }
        .task { await model.refreshIfStale(app: app) }
    }

    @ViewBuilder
    private var content: some View {
        if let feed = model.feed {
            continueSection(feed.continueReading)
            newChaptersSection(feed.newChapters)
            if let stats = feed.stats {
                Text("\(stats.novels) novels · \(stats.chaptersDownloaded.formatted()) chapters on the server"
                     + (stats.chaptersQueued > 0 ? " · \(stats.chaptersQueued.formatted()) queued" : ""))
                    .font(.ui(.footnote))
                    .foregroundStyle(.secondary)
                    .frame(maxWidth: .infinity)
                    .padding(.top, 8)
            }
        } else if case .failed(let message) = model.phase {
            LoadFailedView(title: model.failure?.failureTitle ?? "Can't reach your server", message: message) { await model.load(app: app) }
                .padding(.top, 60)
        } else {
            ProgressView().frame(maxWidth: .infinity).padding(.top, 120)
        }
    }

    @ViewBuilder
    private func continueSection(_ items: [ContinueItem]) -> some View {
        if let first = items.first {
            VStack(alignment: .leading, spacing: 16) {
                SectionTitle("Continue reading")
                NavigationLink(value: ReaderRoute(novelId: first.novel.id, chapterId: first.chapter.id, offerLatest: true,
                                                   manga: first.novel.isManga)) {
                    HeroCard(item: first, large: sizeClass == .regular)
                }
                .buttonStyle(.plain)

                if items.count > 1 {
                    ScrollView(.horizontal, showsIndicators: false) {
                        LazyHStack(alignment: .top, spacing: 14) {
                            ForEach(items.dropFirst()) { item in
                                NavigationLink(value: ReaderRoute(novelId: item.novel.id, chapterId: item.chapter.id,
                                                                     offerLatest: true, manga: item.novel.isManga)) {
                                    ShelfItem(item: item)
                                        .frame(width: sizeClass == .regular ? 130 : 108)
                                }
                                .buttonStyle(.plain)
                            }
                        }
                    }
                    .scrollClipDisabled()
                }
            }
        } else {
            VStack(alignment: .leading, spacing: 12) {
                SectionTitle("Continue reading")
                Text("Nothing in progress. Pick something from your Library to start reading.")
                    .foregroundStyle(.secondary)
            }
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
                                                                     manga: item.novel.isManga)) {
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
        }
    }
}

private struct SectionTitle: View {
    let text: String
    init(_ text: String) { self.text = text }

    var body: some View {
        Text(text)
            .font(.literata(.title3, .semibold))
            .accessibilityAddTraits(.isHeader)
    }
}

private struct HeroCard: View {
    let item: ContinueItem
    let large: Bool
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        // At accessibility text sizes the text gets the full width under the cover.
        let layout = dynamicTypeSize.isAccessibilitySize
            ? AnyLayout(VStackLayout(alignment: .leading, spacing: 16))
            : AnyLayout(HStackLayout(alignment: .top, spacing: 16))
        layout {
            CoverView(url: item.novel.coverLargeUrl ?? item.novel.coverUrl, title: item.novel.name,
                      cornerRadius: 8, targetWidth: large ? 170 : 116)
                .frame(width: large ? 170 : 116)
                .shadow(color: .black.opacity(0.18), radius: 8, y: 4)

            VStack(alignment: .leading, spacing: 8) {
                Text(item.novel.name)
                    .font(.literata(large ? .title : .title3, .semibold))
                    .lineLimit(3)
                if let author = item.novel.author {
                    Text(author).font(.ui(.subheadline)).foregroundStyle(.secondary).lineLimit(1)
                }
                Text(item.chapter.label)
                    .font(.ui(.callout))
                    .lineLimit(2)
                    .padding(.top, 2)
                Spacer(minLength: 4)
                VStack(alignment: .leading, spacing: 5) {
                    ProgressLine(fraction: item.novel.readFraction)
                    Text("\(item.novel.chaptersRead) of \(item.novel.chaptersTotal) chapters · \(item.novel.readPercent)%")
                        .font(.ui(.caption))
                        .foregroundStyle(.secondary)
                }
                Label("Continue", systemImage: "book.fill")
                    .font(.ui(.subheadline, .semibold))
                    .foregroundStyle(Color.onAccent)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 8)
                    .background(Color.brandAccent, in: Capsule())
                    .padding(.top, 4)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(14)
        .background(Color.surface, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(RoundedRectangle(cornerRadius: 16, style: .continuous).strokeBorder(Color.primary.opacity(0.06)))
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
        .accessibilityHint("Opens the reader")
    }
}

private struct ShelfItem: View {
    let item: ContinueItem

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            CoverView(url: item.novel.coverUrl, title: item.novel.name, targetWidth: 130)
                .overlay(alignment: .bottom) {
                    ProgressLine(fraction: item.novel.readFraction, tint: .brandAmber, track: .black.opacity(0.35))
                        .padding(6)
                }
            Text(item.novel.name)
                .font(.ui(.footnote, .medium))
                .lineLimit(2)
            Text(item.chapter.label)
                .font(.ui(.caption2))
                .foregroundStyle(.secondary)
                .lineLimit(1)
        }
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
            Text("\(item.count) NEW")
                .font(.ui(.caption, .bold))
                .foregroundStyle(Color.brandAccent)
                .padding(.horizontal, 8)
                .padding(.vertical, 4)
                .background(Color.brandAccent.opacity(0.12), in: Capsule())
        }
        .padding(.vertical, 8)
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
    }
}
