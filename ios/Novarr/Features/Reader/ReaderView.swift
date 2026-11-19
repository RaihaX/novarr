import SwiftUI

struct ReaderView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.dismiss) private var dismiss
    @State private var model: ReaderModel
    private let route: ReaderRoute
    /// Set once the chapter turned out to be manga: the manga reader takes over.
    @State private var mangaRoute: ReaderRoute?
    @State private var chromeHidden = false
    @State private var showSettings = false
    @State private var highlightRequest: HighlightRequest?

    @AppStorage(ReaderKeys.fontSize) private var fontSize = 19.0
    @AppStorage(ReaderKeys.font) private var font = ReaderFont.standard
    @AppStorage(ReaderKeys.spacing) private var spacing = ReaderSpacing.normal
    @AppStorage(ReaderKeys.margins) private var margins = ReaderMargins.medium
    @AppStorage(ReaderKeys.width) private var width = ReaderWidth.standard
    @AppStorage(ReaderKeys.theme) private var theme = ReaderTheme.system
    @AppStorage(ReaderKeys.mode) private var mode = ReaderMode.scroll
    @AppStorage(ReaderKeys.justify) private var justify = false
    @AppStorage(ReaderKeys.keepScreenOn) private var keepScreenOn = true
    @AppStorage(AppAppearance.defaultsKey) private var appAppearance = AppAppearance.dark
    /// Scales the chosen size with the system Dynamic Type setting.
    @ScaledMetric(relativeTo: .body) private var dynamicBody: CGFloat = 17

    init(route: ReaderRoute) {
        var route = route
        #if DEBUG
        // Debug builds only: `-debugLookup "<excerpt>"` opens the route's
        // chapter as if from a highlight with that excerpt.
        if route.highlight == nil, let excerpt = UserDefaults.standard.string(forKey: "debugLookup") {
            route.highlight = Highlight(id: 0, novelId: route.novelId, novelName: "", chapterId: route.chapterId,
                                        chapterLabel: "", excerpt: excerpt, note: nil, createdAt: nil)
        }
        #endif
        _model = State(initialValue: ReaderModel(route: route))
        self.route = route
    }

    var body: some View {
        if let mangaRoute {
            MangaReaderView(route: mangaRoute)
        } else {
            textReader
        }
    }

    private var textSize: CGFloat { CGFloat(fontSize) * dynamicBody / 17 }

    private var textReader: some View {
        ZStack {
            theme.background.ignoresSafeArea()
            switch model.phase {
            case .loaded:
                if let content = model.content { reader(content) }
            case .failed(let message):
                if model.isMangaChapter {
                    mangaNotice
                } else {
                    failure(message)
                }
            case .idle, .loading:
                ProgressView().tint(theme.secondaryText)
            }
        }
        .modifier(ThemeScheme(scheme: theme.colorScheme))
        // The window follows a themed reader (light or dark) while it is
        // shown, so the navigation title, bar buttons and status bar stay
        // readable on it whatever the app's own appearance (the Black theme
        // on a light app hid the title).
        .onAppear { AppAppearance.applyToWindows(theme.interfaceStyle(app: appAppearance)) }
        .onChange(of: theme) { _, value in AppAppearance.applyToWindows(value.interfaceStyle(app: appAppearance)) }
        .onDisappear { AppAppearance.applyToWindows(appAppearance.interfaceStyle) }
        .navigationTitle(model.content?.label ?? "")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar(.hidden, for: .tabBar)
        .toolbar(chromeHidden ? .hidden : .visible, for: .navigationBar, .bottomBar)
        .toolbarBackground(theme == .system ? AnyShapeStyle(.bar) : AnyShapeStyle(theme.background),
                           for: .navigationBar, .bottomBar)
        .toolbarBackground(.visible, for: .navigationBar, .bottomBar)
        .toolbar { toolbar }
        .statusBarHidden(chromeHidden)
        .animation(.easeInOut(duration: 0.2), value: chromeHidden)
        .overlay(alignment: .bottom) {
            if let offer = model.latestOffer {
                LatestPositionBanner(
                    message: offer.message, theme: theme,
                    onGo: { Task { await model.acceptLatestOffer(app: app) } },
                    onStay: { model.declineLatestOffer(app: app) })
                .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .animation(.easeInOut(duration: 0.25), value: model.latestOffer)
        .sheet(isPresented: $showSettings) { ReaderSettingsSheet() }
        .sheet(item: $highlightRequest) { request in
            HighlightSheet(request: request) { excerpt, note in
                await model.saveHighlight(excerpt: excerpt, note: note, app: app)
            }
        }
        .task { await model.load(app: app) }
        #if DEBUG
        .task(id: model.phase) {
            await debugSaveHighlight()
            await debugHighlightSheet()
        }
        .task { await debugSwitchMode() }
        #endif
        // Keep the screen awake while reading (setting, on by default).
        .onAppear { setScreenAwake(keepScreenOn) }
        .onChange(of: keepScreenOn) { _, on in setScreenAwake(on) }
        // Concurrent with loading: never delays opening the chapter.
        .task { await model.checkLatestPosition(app: app) }
        .onDisappear {
            setScreenAwake(false)
            Task { await model.leave(app: app) }
        }
        // A check found chapters after this one: offer "Next chapter" without reloading the page.
        .onChange(of: app.checks.revision(for: model.novelId)) {
            Task { await model.refreshNext(app: app) }
        }
        .onChange(of: scenePhase) { _, phase in
            switch phase {
            case .active:
                setScreenAwake(keepScreenOn)
                // Back after a while: another device may have read further meanwhile.
                Task { await model.becameActive(app: app) }
            case .background:
                setScreenAwake(false)
                model.enteredBackground()
                Task { await model.leave(app: app) }
            default:
                Task { await model.leave(app: app) }
            }
        }
    }

    // MARK: Reading surface

    private var typography: ReaderTypography {
        ReaderTypography(font: font, size: textSize, spacing: spacing, justify: justify)
    }

    @ViewBuilder
    private func reader(_ content: ChapterContent) -> some View {
        Group {
            if mode == .pages && content.downloaded && !content.paragraphs.isEmpty {
                PagedChapterView(
                    content: content, model: model, typography: typography, theme: theme,
                    columnWidth: width.maxWidth, margin: margins.padding,
                    onToggleChrome: { chromeHidden.toggle() },
                    onTurn: { chromeHidden = true },
                    onLongPress: { index in
                        guard content.paragraphs.indices.contains(index) else { return }
                        highlightRequest = HighlightRequest(text: content.paragraphs[index], narrowable: true)
                    },
                    header: { header(content) },
                    footer: { footer(content) })
            } else {
                ScrollChapterView(
                    content: content, model: model, typography: typography, theme: theme,
                    columnWidth: width.maxWidth, margin: margins.padding,
                    onToggleChrome: { chromeHidden.toggle() },
                    onHighlight: { text in highlightRequest = HighlightRequest(text: text, narrowable: false) },
                    header: { header(content) },
                    footer: { footer(content) },
                    missing: { notDownloaded })
            }
        }
        // A new chapter (or mode) starts from fresh view state: no scroll
        // position or page carried over from the one before.
        .id(ChapterSurface(chapterId: content.id, mode: mode))
        .overlay(alignment: .top) {
            if model.isShowingCache && !chromeHidden {
                OfflinePill(text: "Offline · saved copy").padding(.top, 6)
            }
        }
    }

    private struct ChapterSurface: Hashable {
        var chapterId: Int
        var mode: ReaderMode
    }

    private func header(_ content: ChapterContent) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if !content.novelName.isEmpty {
                Text(content.novelName.uppercased())
                    .font(.ui(.caption, .semibold))
                    .tracking(1)
                    .foregroundStyle(theme.secondaryText)
                    .lineLimit(2)
            }
            Text(content.label)
                .font(font.font(size: textSize * 1.45, weight: .semibold))
                .foregroundStyle(theme.text)
                .fixedSize(horizontal: false, vertical: true)
            Rectangle()
                .fill(Color.brandAccent)
                .frame(width: 36, height: 3)
                .padding(.top, 4)
        }
        .padding(.top, 20)
        .padding(.bottom, 12)
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(.isHeader)
    }

    private var notDownloaded: some View {
        VStack(spacing: 12) {
            Image(systemName: "icloud.and.arrow.down")
                .font(.system(size: 40))
                .foregroundStyle(theme.secondaryText)
            Text("Not downloaded yet")
                .font(.ui(.title3, .semibold))
                .foregroundStyle(theme.text)
            Text("Your Novarr server hasn't fetched this chapter yet. It will be readable here once the server downloads it.")
                .font(.ui(.callout))
                .multilineTextAlignment(.center)
                .foregroundStyle(theme.secondaryText)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 60)
    }

    private func footer(_ content: ChapterContent) -> some View {
        VStack(spacing: 16) {
            if content.downloaded && !content.paragraphs.isEmpty {
                BrandMark(color: theme.secondaryText.opacity(0.5), lastBar: theme.secondaryText.opacity(0.5)).frame(width: 22)
                    .padding(.top, 24)
            }
            HStack(spacing: 12) {
                if let prev = content.prev {
                    chapterButton(prev, title: "Previous", systemImage: "chevron.left", prominent: false)
                }
                if let next = content.next {
                    chapterButton(next, title: "Next chapter", systemImage: "chevron.right", prominent: true)
                }
            }
            if let next = content.next {
                Text(next.label + (next.downloaded == false ? " · not downloaded yet" : ""))
                    .font(.ui(.footnote))
                    .foregroundStyle(theme.secondaryText)
                    .multilineTextAlignment(.center)
            } else if content.downloaded {
                Text("You're all caught up.")
                    .font(.ui(.footnote))
                    .foregroundStyle(theme.secondaryText)
                CheckForChaptersControl(novelId: model.novelId, compact: true)
                    .foregroundStyle(theme.secondaryText)
                Button("Back to novel") { dismiss() }
                    .font(.ui(.footnote, .semibold))
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.top, 16)
    }

    private func chapterButton(_ ref: ChapterRef, title: String, systemImage: String, prominent: Bool) -> some View {
        Button {
            Task { await model.go(to: ref, app: app) }
        } label: {
            Label(title, systemImage: systemImage)
                .font(.ui(.callout, .semibold))
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
                .foregroundStyle(prominent ? Color.onAccent : theme.text)
                .background(prominent ? AnyShapeStyle(Color.brandAccent) : AnyShapeStyle(theme.text.opacity(0.08)),
                            in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
        .accessibilityHint(ref.label)
    }

    /// Shown for a moment before the manga reader takes over.
    private var mangaNotice: some View {
        ContentUnavailableView {
            Label("This is a manga chapter", systemImage: "photo.on.rectangle")
        } description: {
            Text("Opening it in the manga reader…")
        }
        .foregroundStyle(theme.text)
        .task {
            try? await Task.sleep(for: .milliseconds(700))
            var manga = route
            manga.chapterId = model.chapterId
            manga.highlight = nil
            manga.manga = true
            mangaRoute = manga
        }
    }

    private func failure(_ message: String) -> some View {
        VStack(spacing: 16) {
            ContentUnavailableView {
                Label("Couldn't open this chapter", systemImage: "book.closed")
            } description: {
                Text(message)
            } actions: {
                Button("Try again") { Task { await model.load(app: app) } }
                    .buttonStyle(.borderedProminent)
            }
        }
        .foregroundStyle(theme.text)
    }

    // MARK: Chrome

    @ToolbarContentBuilder
    private var toolbar: some ToolbarContent {
        ToolbarItemGroup(placement: .bottomBar) {
            Button {
                if let prev = model.content?.prev { Task { await model.go(to: prev, app: app) } }
            } label: {
                Label("Previous chapter", systemImage: "chevron.left")
            }
            .disabled(model.content?.prev == nil)
            Spacer()
            if let position = positionLabel {
                Text(position)
                    .font(.ui(.caption).monospacedDigit())
                    .foregroundStyle(theme.secondaryText)
                    .lineLimit(1)
                    .minimumScaleFactor(0.8)
                    .accessibilityLabel(positionAccessibilityLabel ?? position)
            }
            Button {
                showSettings = true
            } label: {
                Label("Reading settings", systemImage: "textformat.size")
            }
            Spacer()
            Button {
                if let next = model.content?.next { Task { await model.go(to: next, app: app) } }
            } label: {
                Label("Next chapter", systemImage: "chevron.right")
            }
            .disabled(model.content?.next == nil)
        }
    }
}

extension ReaderView {
    /// "Chapter 12 of 323 · 45%", or just "45%" when the chapter count isn't known.
    private var positionLabel: String? {
        guard let content = model.content, content.downloaded, !content.paragraphs.isEmpty else { return nil }
        let percent = "\(model.shownProgress)%"
        guard let position = model.chapterPosition else { return percent }
        return "Chapter \(position.number) of \(position.count) · \(percent)"
    }

    private var positionAccessibilityLabel: String? {
        guard let content = model.content, content.downloaded, !content.paragraphs.isEmpty else { return nil }
        let percent = "\(model.shownProgress) percent through the chapter"
        guard let position = model.chapterPosition else { return percent }
        return "Chapter \(position.number) of \(position.count), \(percent)"
    }

    /// The idle timer is app-wide: it is turned off only while the reader
    /// is on screen and the app active, and back on whenever that ends.
    private func setScreenAwake(_ awake: Bool) {
        UIApplication.shared.isIdleTimerDisabled = awake
    }

    #if DEBUG
    /// Debug builds only: `-debugHighlightSheet <paragraph>` opens the
    /// highlight sheet for that paragraph once the chapter is loaded.
    private func debugHighlightSheet() async {
        guard model.phase == .loaded, highlightRequest == nil, let content = model.content,
              let raw = UserDefaults.standard.string(forKey: "debugHighlightSheet"), let index = Int(raw),
              content.paragraphs.indices.contains(index) else { return }
        try? await Task.sleep(for: .seconds(2))
        highlightRequest = HighlightRequest(text: content.paragraphs[index], narrowable: true)
    }

    /// Debug builds only: `-debugSwitchMode YES` flips Scroll/Pages after
    /// five seconds and back after five more (screenshots of each keep the text).
    private func debugSwitchMode() async {
        guard UserDefaults.standard.bool(forKey: "debugSwitchMode") else { return }
        for _ in 0..<2 {
            try? await Task.sleep(for: .seconds(5))
            mode = mode == .pages ? .scroll : .pages
        }
    }

    /// Debug builds only: `-debugSaveHighlight "<excerpt>"` saves that
    /// highlight (through the write queue) once the chapter is loaded.
    private func debugSaveHighlight() async {
        guard model.phase == .loaded,
              let excerpt = UserDefaults.standard.string(forKey: "debugSaveHighlight") else { return }
        try? await Task.sleep(for: .seconds(1))
        await model.saveHighlight(excerpt: excerpt, note: "saved by debug hook", app: app)
    }
    #endif
}

extension ReaderTheme {
    /// The window style while this theme is shown: its own, or the app's for System.
    func interfaceStyle(app: AppAppearance) -> UIUserInterfaceStyle {
        switch colorScheme {
        case .light: .light
        case .dark: .dark
        default: app.interfaceStyle
        }
    }
}

/// Forces light/dark for themed reading surfaces; leaves the system choice alone otherwise.
private struct ThemeScheme: ViewModifier {
    let scheme: ColorScheme?

    func body(content: Content) -> some View {
        if let scheme {
            content
                .environment(\.colorScheme, scheme)
                .toolbarColorScheme(scheme, for: .navigationBar, .bottomBar)
        } else {
            content
        }
    }
}
