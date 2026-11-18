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
                    .font(.footnote)
                    .foregroundStyle(.secondary)
                    .frame(maxWidth: .infinity)
                    .padding(.top, 8)
            }
        } else if case .failed(let message) = model.phase {
            LoadFailedView(title: "Can't reach your server", message: message) { await model.load(app: app) }
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
                NavigationLink(value: ReaderRoute(novelId: first.novel.id, chapterId: first.chapter.id, offerLatest: true)) {
                    HeroCard(item: first, large: sizeClass == .regular)
                }
                .buttonStyle(.plain)

                if items.count > 1 {
                    ScrollView(.horizontal, showsIndicators: false) {
                        LazyHStack(alignment: .top, spacing: 14) {
                            ForEach(items.dropFirst()) { item in
                                NavigationLink(value: ReaderRoute(novelId: item.novel.id, chapterId: item.chapter.id, offerLatest: true)) {
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
                            NavigationLink(value: ReaderRoute(novelId: item.novel.id, chapterId: first.id, offerLatest: true)) {
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
            .font(.system(.title3, design: .serif).weight(.semibold))
            .accessibilityAddTraits(.isHeader)
    }
}

private struct HeroCard: View {
    let item: ContinueItem
    let large: Bool

    var body: some View {
        HStack(alignment: .top, spacing: 16) {
            CoverView(url: item.novel.coverLargeUrl ?? item.novel.coverUrl, title: item.novel.name,
                      cornerRadius: 8, targetWidth: large ? 170 : 116)
                .frame(width: large ? 170 : 116)
                .shadow(color: .black.opacity(0.18), radius: 8, y: 4)

            VStack(alignment: .leading, spacing: 8) {
                Text(item.novel.name)
                    .font(.system(large ? .title : .title3, design: .serif).weight(.semibold))
                    .lineLimit(3)
                if let author = item.novel.author {
                    Text(author).font(.subheadline).foregroundStyle(.secondary).lineLimit(1)
                }
                Text(item.chapter.label)
                    .font(.callout)
                    .lineLimit(2)
                    .padding(.top, 2)
                Spacer(minLength: 4)
                VStack(alignment: .leading, spacing: 5) {
                    ProgressLine(fraction: item.novel.readFraction)
                    Text("\(item.novel.chaptersRead) of \(item.novel.chaptersTotal) chapters · \(item.novel.readPercent)%")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
                Label("Continue", systemImage: "book.fill")
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(.white)
                    .padding(.horizontal, 14)
                    .padding(.vertical, 8)
                    .background(Color.accentColor, in: Capsule())
                    .padding(.top, 4)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(14)
        .background(Color(.secondarySystemGroupedBackground), in: RoundedRectangle(cornerRadius: 16, style: .continuous))
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
                    ProgressLine(fraction: item.novel.readFraction, tint: .white, track: .black.opacity(0.35))
                        .padding(6)
                }
            Text(item.novel.name)
                .font(.footnote.weight(.medium))
                .lineLimit(2)
            Text(item.chapter.label)
                .font(.caption2)
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
                    .font(.body.weight(.medium))
                    .lineLimit(2)
                if let first = item.firstChapter {
                    Text("From \(first.label)")
                        .font(.footnote)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
            }
            Spacer(minLength: 8)
            Text("\(item.count) NEW")
                .font(.caption.weight(.bold))
                .foregroundStyle(Color.accentColor)
                .padding(.horizontal, 8)
                .padding(.vertical, 4)
                .background(Color.accentColor.opacity(0.12), in: Capsule())
        }
        .padding(.vertical, 8)
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
    }
}
