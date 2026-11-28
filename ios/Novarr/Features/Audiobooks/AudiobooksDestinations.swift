import SwiftUI

// The audiobooks area: the Listen tab, the player, uploads and their
// controls elsewhere (series screen, Settings, the mini-player).

extension View {
    /// Every audiobook, the player and the upload screen.
    func audiobooksDestinations() -> some View {
        navigationDestination(for: AudiobooksRoute.self) { _ in
            AudiobooksView()
        }
        .navigationDestination(for: PlayerRoute.self) { route in
            PlayerView(audiobookId: route.id)
        }
        .navigationDestination(for: AudiobookUploadRoute.self) { route in
            AudiobookUploadView(route: route)
        }
    }
}

/// The series screen's audiobook controls: Listen (when the book has one)
/// and, for a book without one, Upload the audiobook. ("Ask for the
/// audiobook" belongs to the Requests area.)
struct NovelAudiobookActions: View {
    @Environment(AppModel.self) private var app
    let summary: NovelSummary

    var body: some View {
        if app.serverMeta?.audiobooks == true {
            if let audiobook = summary.audiobook {
                NavigationLink(value: PlayerRoute(id: audiobook.id)) {
                    Label(audiobook.durationMs > 0 ? "Listen · \(ListeningSync.length(audiobook.durationMs))" : "Listen",
                          systemImage: "headphones")
                        .font(.ui(.subheadline, .semibold))
                        .frame(maxWidth: .infinity, minHeight: 44)
                }
                .buttonStyle(.bordered)
            } else if summary.isBook {
                NavigationLink(value: AudiobookUploadRoute(novelId: summary.id, title: summary.name)) {
                    Label("Upload the audiobook", systemImage: "square.and.arrow.up")
                        .font(.ui(.subheadline))
                        .frame(maxWidth: .infinity, minHeight: 44)
                }
                .buttonStyle(.borderless)
            }
        }
    }
}

/// The mini-player shown at the bottom of every tab while an audiobook is
/// loaded: tap for the full player, play / pause, close (when paused).
struct MiniPlayerBar: View {
    @Environment(AppModel.self) private var app
    @State private var presented: PlayerRoute?

    var body: some View {
        let listening = app.listening
        // One stable container, so the full player stays up while the bar hides under it.
        VStack(spacing: 0) {
            if let now = listening.nowPlaying, listening.playerScreens == 0 {
                MiniPlayerContent(now: now, listening: listening) { presented = PlayerRoute(id: now.id) }
            }
        }
        .fullScreenCover(item: $presented) { route in
            NavigationStack {
                PlayerView(audiobookId: route.id, closable: true)
                    .novarrDestinations()
            }
            .environment(app)
        }
    }
}

private struct MiniPlayerContent: View {
    let now: NowPlaying
    let listening: ListeningModel
    let open: () -> Void

    var body: some View {
        let total = now.timeline.totalMs
        VStack(spacing: 0) {
            ProgressLine(fraction: total > 0 ? Double(now.positionMs) / Double(total) : 0, tint: .brandAccent,
                         track: .clear, height: 2)
            HStack(spacing: 12) {
                Button(action: open) {
                    HStack(spacing: 12) {
                        CoverView(url: now.detail.summary.coverUrl, title: now.detail.summary.title, cornerRadius: 4,
                                  targetWidth: 36)
                            .frame(width: 34)
                        VStack(alignment: .leading, spacing: 2) {
                            Text(now.detail.summary.title)
                                .font(.ui(.subheadline, .semibold))
                                .lineLimit(1)
                            if let chapter = now.chapterTitle {
                                Text(chapter)
                                    .font(.ui(.caption))
                                    .foregroundStyle(.secondary)
                                    .lineLimit(1)
                            }
                        }
                        Spacer(minLength: 0)
                    }
                    .contentShape(Rectangle())
                }
                .buttonStyle(.plain)
                .accessibilityElement(children: .combine)
                .accessibilityHint("Opens the player")

                Button { listening.togglePlay() } label: {
                    Group {
                        if now.buffering {
                            ProgressView()
                        } else {
                            Image(systemName: now.playing ? "pause.fill" : "play.fill")
                                .font(.title3)
                        }
                    }
                    .frame(width: 44, height: 44)
                }
                .buttonStyle(.plain)
                .accessibilityLabel(now.playing ? "Pause" : "Play")

                if !now.playing {
                    Button { listening.close() } label: {
                        Image(systemName: "xmark")
                            .font(.body)
                            .foregroundStyle(.secondary)
                            .frame(width: 36, height: 44)
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel("Close the player")
                }
            }
            .padding(.leading, 12)
            .padding(.trailing, 6)
            .padding(.vertical, 6)
            Divider()
        }
        .background(.bar)
    }
}
