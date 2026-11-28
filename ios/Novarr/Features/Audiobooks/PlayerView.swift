import SwiftUI

private enum PlayerSheet: String, Identifiable {
    case speed, sleep, chapters, bookmarks
    var id: String { rawValue }
}

/// The full player for audiobook `audiobookId`; it opens it (paused, at its
/// resume position) if another is loaded. Mirrors android ui/audiobooks/PlayerScreen.kt.
struct PlayerView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    let audiobookId: Int
    /// Presented over everything (from the mini-player): a close button instead of back.
    var closable = false

    @State private var sheet: PlayerSheet?
    @State private var confirmRemove = false
    @State private var toast: String?

    var body: some View {
        let listening = app.listening
        let now = listening.nowPlaying.flatMap { $0.id == audiobookId ? $0 : nil }
        Group {
            if let now {
                PlayerBody(now: now, listening: listening, prefs: listening.prefs,
                           download: listening.downloads.state(audiobookId), sheet: $sheet) {
                    Task { await bookmarkHere() }
                }
            } else if let error = listening.lastError {
                LoadFailedView(title: "Couldn't open this audiobook", message: error) {
                    listening.open(audiobookId, play: false)
                }
            } else {
                ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .brandBackground()
        .navigationTitle("Now playing")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar(.hidden, for: .tabBar)
        .toolbar { toolbar(now) }
        .overlay(alignment: .bottom) {
            if let toast {
                Text(toast)
                    .font(.ui(.subheadline))
                    .padding(.horizontal, 16)
                    .padding(.vertical, 10)
                    .background(.thickMaterial, in: Capsule())
                    .padding(.bottom, 24)
                    .transition(.opacity)
                    .accessibilityAddTraits(.updatesFrequently)
            }
        }
        .sheet(item: $sheet) { which in
            if let now = listening.nowPlaying, now.id == audiobookId {
                Group {
                    switch which {
                    case .speed: SpeedSheet(listening: listening)
                    case .sleep: SleepSheet(listening: listening) { sheet = nil }
                    case .chapters: ChaptersSheet(listening: listening) { sheet = nil }
                    case .bookmarks: BookmarksSheet(listening: listening, show: show) { sheet = nil }
                    }
                }
                .environment(app)
            }
        }
        .confirmationDialog("Remove the download?", isPresented: $confirmRemove, titleVisibility: .visible) {
            Button("Remove", role: .destructive) { listening.downloads.remove(audiobookId) }
        } message: {
            Text("The audiobook stays on your server; only this device's copy is removed.")
        }
        .onAppear { listening.playerAppeared() }
        .onDisappear { listening.playerDisappeared() }
        .task(id: audiobookId) {
            if listening.nowPlaying?.id != audiobookId { listening.open(audiobookId, play: false) }
            #if DEBUG
            // Debug builds only: `-debugPlay YES` starts playing, for simulator checks without UI automation.
            if UserDefaults.standard.bool(forKey: "debugPlay") {
                try? await Task.sleep(for: .seconds(2))
                listening.play()
            }
            #endif
        }
    }

    @ToolbarContentBuilder
    private func toolbar(_ now: NowPlaying?) -> some ToolbarContent {
        if closable {
            ToolbarItem(placement: .cancellationAction) {
                Button { dismiss() } label: { Image(systemName: "chevron.down") }
                    .accessibilityLabel("Close player")
            }
        }
        if let now {
            ToolbarItemGroup(placement: .primaryAction) {
                DownloadButton(state: app.listening.downloads.state(audiobookId),
                               start: { app.listening.downloads.start(audiobookId, title: now.detail.summary.title) },
                               cancel: { app.listening.downloads.cancel(audiobookId) },
                               remove: { confirmRemove = true })
                if let novelId = now.detail.summary.novelId {
                    Menu {
                        NavigationLink(value: NovelRoute(id: novelId, summary: nil)) {
                            Label("Open the book", systemImage: "book")
                        }
                    } label: {
                        Image(systemName: "ellipsis.circle")
                    }
                    .accessibilityLabel("More")
                }
            }
        }
    }

    private func bookmarkHere() async {
        show(await app.listening.addBookmark(note: nil) ?? "Bookmark added.")
    }

    private func show(_ message: String) {
        withAnimation { toast = message }
        Task {
            try? await Task.sleep(for: .seconds(2.5))
            withAnimation { if toast == message { toast = nil } }
        }
    }
}

private struct DownloadButton: View {
    let state: AudiobookDownloadState
    let start: () -> Void
    let cancel: () -> Void
    let remove: () -> Void

    var body: some View {
        switch state {
        case .done(let bytes):
            Button(action: remove) { Image(systemName: "arrow.down.circle.fill") }
                .accessibilityLabel("Downloaded (\(bytes.byteString)). Remove download")
        case .running:
            Button(action: cancel) {
                ProgressView(value: state.fraction)
                    .progressViewStyle(.circular)
                    .frame(width: 22, height: 22)
            }
            .accessibilityLabel("Downloading, \(Int(state.fraction * 100))%. Cancel")
        case .waiting(let wifiOnly):
            Button(wifiOnly ? "Waiting for Wi-Fi" : "Waiting…", action: cancel)
                .font(.ui(.footnote))
                .accessibilityHint("Cancels the download")
        case .none, .failed:
            Button(action: start) { Image(systemName: "arrow.down.circle") }
                .accessibilityLabel("Download for offline")
        }
    }
}

private struct PlayerBody: View {
    let now: NowPlaying
    let listening: ListeningModel
    let prefs: ListeningPrefs
    let download: AudiobookDownloadState
    @Binding var sheet: PlayerSheet?
    let onBookmark: () -> Void

    /// While a thumb is dragged the slider shows where it will go; the player moves on release.
    @State private var dragging: (book: Bool, value: Double)?

    var body: some View {
        let summary = now.detail.summary
        let timeline = now.timeline
        let chapterStart = timeline.chapterAt(now.positionMs)?.startMs ?? 0
        let position: Int64 = dragging.map { $0.book ? Int64($0.value) : chapterStart + Int64($0.value) } ?? now.positionMs
        let chapter = timeline.chapterProgress(position)

        ScrollView {
            VStack(spacing: 10) {
                CoverView(url: summary.coverUrl, title: summary.title, cornerRadius: 12, targetWidth: 280)
                    .frame(maxWidth: 280)
                    .padding(.horizontal, 40)
                    .shadow(color: .black.opacity(0.25), radius: 12, y: 6)
                    .padding(.bottom, 4)
                Text(summary.title)
                    .font(.literata(.title2, .semibold))
                    .multilineTextAlignment(.center)
                    .lineLimit(2)
                if let series = summary.seriesLabel {
                    Text(series).font(.ui(.subheadline)).foregroundStyle(.secondary).lineLimit(1)
                }
                let byline = [summary.authorOrNil, summary.narratorOrNil.map { "read by \($0)" }].compactMap { $0 }
                if !byline.isEmpty {
                    Text(byline.joined(separator: " · "))
                        .font(.ui(.subheadline))
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                        .lineLimit(2)
                }
                if let current = timeline.chapterAt(position) {
                    Button { sheet = .chapters } label: {
                        Text(current.label).font(.ui(.subheadline, .semibold)).lineLimit(1)
                    }
                    .buttonStyle(.plain)
                    .foregroundStyle(Color.brandAccent)
                    .accessibilityHint("Shows the chapters")
                }
                if let error = now.error {
                    Text(error).font(.ui(.footnote)).foregroundStyle(.red).multilineTextAlignment(.center)
                }

                // The chapter scrubber.
                VStack(spacing: 2) {
                    Slider(value: Binding(get: { Double(chapter.elapsed) }, set: { dragging = (false, $0) }),
                           in: 0...Double(max(chapter.length, 1))) { editing in
                        if !editing, let drag = dragging {
                            listening.seekTo(chapterStart + Int64(drag.value))
                            dragging = nil
                        }
                    }
                    .accessibilityLabel("Position in chapter")
                    .accessibilityValue("\(ListeningSync.clock(chapter.elapsed)) of \(ListeningSync.clock(chapter.length))")
                    HStack {
                        Text(ListeningSync.clock(chapter.elapsed))
                        Spacer()
                        Text("-" + ListeningSync.clock(chapter.length - chapter.elapsed))
                    }
                    .font(.uiMono(.caption))
                    .foregroundStyle(.secondary)
                }
                .frame(maxWidth: 560)

                transport(timeline)

                // The whole book.
                VStack(spacing: 2) {
                    Slider(value: Binding(get: { Double(position) }, set: { dragging = (true, $0) }),
                           in: 0...Double(max(timeline.totalMs, 1))) { editing in
                        if !editing, let drag = dragging {
                            listening.seekTo(Int64(drag.value))
                            dragging = nil
                        }
                    }
                    .accessibilityLabel("Position in book")
                    .accessibilityValue("\(ListeningSync.clock(position)) of \(ListeningSync.clock(timeline.totalMs))")
                    HStack {
                        Text(ListeningSync.clock(position))
                        Spacer()
                        let left = Int64(Double(timeline.totalMs - position) / max(now.speed, 0.5))
                        Text("\(ListeningSync.length(left)) left" + (now.speed != 1 ? " at \(ListeningSync.speedLabel(now.speed))" : ""))
                    }
                    .font(.ui(.caption2))
                    .foregroundStyle(.secondary)
                }
                .frame(maxWidth: 560)
                .tint(.secondary)

                tools
                downloadNote
            }
            .padding(.horizontal, 20)
            .padding(.vertical, 8)
            .frame(maxWidth: .infinity)
        }
    }

    private func transport(_ timeline: AudiobookTimeline) -> some View {
        HStack(spacing: 14) {
            Button { listening.previousChapter() } label: {
                Image(systemName: "backward.end.fill").font(.title3).frame(width: 44, height: 44)
            }
            .accessibilityLabel("Previous chapter")
            Button { listening.skipBack() } label: {
                Image(systemName: "gobackward.\(prefs.skipBackSeconds)").font(.title).frame(width: 56, height: 56)
            }
            .accessibilityLabel("Back \(prefs.skipBackSeconds) seconds")
            Button { listening.togglePlay() } label: {
                ZStack {
                    Circle().fill(Color.brandAccent)
                    if now.buffering {
                        ProgressView().tint(.onAccent)
                    } else {
                        Image(systemName: now.playing ? "pause.fill" : "play.fill")
                            .font(.system(size: 32))
                            .foregroundStyle(Color.onAccent)
                            .offset(x: now.playing ? 0 : 2)
                    }
                }
                .frame(width: 72, height: 72)
            }
            .accessibilityLabel(now.playing ? "Pause" : "Play")
            Button { listening.skipForward() } label: {
                Image(systemName: "goforward.\(prefs.skipForwardSeconds)").font(.title).frame(width: 56, height: 56)
            }
            .accessibilityLabel("Forward \(prefs.skipForwardSeconds) seconds")
            Button { listening.nextChapter() } label: {
                Image(systemName: "forward.end.fill").font(.title3).frame(width: 44, height: 44)
            }
            .disabled(timeline.nextChapterTarget(now.positionMs) == nil)
            .accessibilityLabel("Next chapter")
        }
        .buttonStyle(.plain)
        .padding(.vertical, 6)
    }

    private var tools: some View {
        HStack(spacing: 4) {
            ToolButton(systemImage: "speedometer", label: ListeningSync.speedLabel(now.speed),
                       description: "Playback speed \(ListeningSync.speedLabel(now.speed))") { sheet = .speed }
            TimelineView(.periodic(from: .now, by: 1)) { _ in
                let label = SleepTimer.label(now.sleep, now: SleepTimer.monotonicNow())
                ToolButton(systemImage: "moon.zzz", label: label ?? "Sleep",
                           description: label.map { "Sleep timer: \($0)" } ?? "Sleep timer") { sheet = .sleep }
            }
            ToolButton(systemImage: "list.bullet", label: "Chapters", description: "Chapters") { sheet = .chapters }
            ToolButton(systemImage: "bookmark", label: "Bookmark", description: "Add a bookmark here", action: onBookmark)
            ToolButton(systemImage: "bookmark.fill", label: "Bookmarks",
                       description: "Bookmarks (\(now.bookmarks.count))") { sheet = .bookmarks }
        }
        .frame(maxWidth: 560)
    }

    @ViewBuilder
    private var downloadNote: some View {
        switch download {
        case .running:
            VStack(alignment: .leading, spacing: 4) {
                Text("Downloading for offline…").font(.ui(.footnote))
                ProgressView(value: download.fraction)
            }
            .frame(maxWidth: 560)
        case .failed(let message):
            Text(message).font(.ui(.footnote)).foregroundStyle(.red)
        default:
            if now.local {
                Text("Playing from this device").font(.ui(.caption)).foregroundStyle(.secondary)
            }
        }
    }
}

private struct ToolButton: View {
    let systemImage: String
    let label: String
    let description: String
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            VStack(spacing: 4) {
                Image(systemName: systemImage).font(.title3)
                Text(label).font(.ui(.caption2)).lineLimit(1).minimumScaleFactor(0.8)
            }
            .frame(maxWidth: .infinity, minHeight: 52)
        }
        .buttonStyle(.plain)
        .accessibilityLabel(description)
    }
}

// MARK: Sheets

private struct SpeedSheet: View {
    let listening: ListeningModel
    @State private var value: Double = 1

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            Text("Playback speed").font(.ui(.headline))
            Text(ListeningSync.speedLabel(value))
                .font(.ui(.largeTitle, .semibold))
                .frame(maxWidth: .infinity)
            HStack {
                Button("−") { set(value - ListeningPrefs.speedStep) }
                    .font(.title2)
                    .accessibilityLabel("Slower")
                Slider(value: $value, in: ListeningPrefs.minSpeed...ListeningPrefs.maxSpeed, step: ListeningPrefs.speedStep) { editing in
                    if !editing { set(value) }
                }
                .accessibilityLabel("Playback speed")
                Button("+") { set(value + ListeningPrefs.speedStep) }
                    .font(.title2)
                    .accessibilityLabel("Faster")
            }
            LazyVGrid(columns: [GridItem(.adaptive(minimum: 64), spacing: 8)], spacing: 8) {
                ForEach(ListeningPrefs.speedPresets, id: \.self) { preset in
                    Button(ListeningSync.speedLabel(preset)) { set(preset) }
                        .buttonStyle(.bordered)
                        .tint(abs(value - preset) < 0.001 ? .brandAccent : .secondary)
                }
            }
            Spacer(minLength: 0)
        }
        .padding(20)
        .presentationDetents([.height(320)])
        .onAppear { value = listening.prefs.speed }
    }

    private func set(_ speed: Double) {
        value = ListeningPrefs.validSpeed(speed)
        listening.setSpeed(value)
    }
}

private struct SleepSheet: View {
    let listening: ListeningModel
    let close: () -> Void

    var body: some View {
        let sleep = listening.nowPlaying?.sleep ?? .off
        NavigationStack {
            List {
                if !sleep.isOff {
                    Section {
                        Button("Turn off") { listening.cancelSleep(); close() }
                        if sleep.durationMinutes != nil {
                            Button("5 more minutes") { listening.extendSleep(5); close() }
                        }
                    }
                }
                Section {
                    row("End of chapter", selected: sleep.chapterEndMs != nil) { listening.sleepAtChapterEnd() }
                    ForEach(SleepTimer.presetMinutes, id: \.self) { minutes in
                        row("\(minutes) minutes", selected: sleep.durationMinutes == minutes) { listening.sleepIn(minutes) }
                    }
                }
            }
            .navigationTitle("Sleep timer")
            .navigationBarTitleDisplayMode(.inline)
        }
        .presentationDetents([.medium, .large])
    }

    private func row(_ title: String, selected: Bool, action: @escaping () -> Void) -> some View {
        Button {
            action()
            close()
        } label: {
            HStack {
                Text(title).foregroundStyle(selected ? Color.brandAccent : .primary)
                Spacer()
                if selected { Image(systemName: "checkmark").foregroundStyle(Color.brandAccent) }
            }
        }
        .accessibilityAddTraits(selected ? .isSelected : [])
    }
}

private struct ChaptersSheet: View {
    let listening: ListeningModel
    let close: () -> Void

    var body: some View {
        let now = listening.nowPlaying
        let current = now?.chapterIndex ?? -1
        NavigationStack {
            ScrollViewReader { proxy in
                List(now?.timeline.chapters ?? [], id: \.index) { chapter in
                    Button {
                        listening.seekTo(chapter.startMs)
                        close()
                    } label: {
                        HStack {
                            Text(chapter.label)
                                .font(.ui(.body, chapter.index == current ? .semibold : nil))
                                .foregroundStyle(chapter.index == current ? Color.brandAccent : .primary)
                                .lineLimit(2)
                            Spacer()
                            Text(ListeningSync.clock(chapter.endMs - chapter.startMs))
                                .font(.uiMono(.caption))
                                .foregroundStyle(.secondary)
                        }
                    }
                    .id(chapter.index)
                    .accessibilityAddTraits(chapter.index == current ? .isSelected : [])
                }
                .onAppear { if current >= 0 { proxy.scrollTo(current, anchor: .center) } }
            }
            .navigationTitle("Chapters")
            .navigationBarTitleDisplayMode(.inline)
        }
        .presentationDetents([.medium, .large])
    }
}

private struct BookmarksSheet: View {
    let listening: ListeningModel
    let show: (String) -> Void
    let close: () -> Void
    @State private var note = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        let now = listening.nowPlaying
        NavigationStack {
            List {
                Section {
                    TextField("Note (optional)", text: $note, axis: .vertical)
                        .lineLimit(1...4)
                        .onChange(of: note) { _, value in if value.count > 500 { note = String(value.prefix(500)) } }
                    Button("Bookmark \(ListeningSync.clock(now?.positionMs ?? 0))") {
                        busy = true
                        Task {
                            error = await listening.addBookmark(note: note)
                            if error == nil { note = "" }
                            busy = false
                        }
                    }
                    .disabled(busy)
                    if let error {
                        Text(error).font(.ui(.footnote)).foregroundStyle(.red)
                    }
                }
                Section {
                    let bookmarks = (now?.bookmarks ?? []).sorted { $0.positionMs < $1.positionMs }
                    if bookmarks.isEmpty {
                        Text("No bookmarks yet.").foregroundStyle(.secondary)
                    }
                    ForEach(bookmarks) { bookmark in
                        Button {
                            listening.seekTo(bookmark.positionMs)
                            close()
                        } label: {
                            VStack(alignment: .leading, spacing: 2) {
                                let chapter = now?.timeline.chapterAt(bookmark.positionMs)?.label
                                Text(ListeningSync.clock(bookmark.positionMs) + (chapter.map { " · \($0)" } ?? ""))
                                    .foregroundStyle(.primary)
                                    .lineLimit(1)
                                if let text = bookmark.noteOrNil {
                                    Text(text).font(.ui(.footnote)).foregroundStyle(.secondary).lineLimit(3)
                                }
                            }
                        }
                        .swipeActions {
                            Button("Delete", role: .destructive) {
                                Task { if let message = await listening.deleteBookmark(bookmark.id) { show(message) } }
                            }
                        }
                    }
                }
            }
            .navigationTitle("Bookmarks")
            .navigationBarTitleDisplayMode(.inline)
        }
        .presentationDetents([.medium, .large])
    }
}
