import SwiftUI

/// The manga reader (Android's `MangaReaderScreen`): a chapter's page
/// images, paged left to right or right to left, or as a vertical strip;
/// the series' mode unless this device overrides it. Pages read on black
/// whatever the text reader's theme. Taps follow the text reader's Pages
/// mode (outer thirds turn, the middle toggles the controls), mirrored for
/// right-to-left series; pinch and double-tap zoom.
struct MangaReaderView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.dismiss) private var dismiss
    @State private var model: MangaReaderModel
    @State private var chromeHidden = false
    @AppStorage(ReaderKeys.keepScreenOn) private var keepScreenOn = true

    init(route: ReaderRoute) {
        _model = State(initialValue: MangaReaderModel(route: route))
    }

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            switch model.phase {
            case .loaded:
                if let pages = model.pages { reader(pages) }
            case .failed(let message):
                failure(message)
            case .idle, .loading:
                ProgressView().tint(Color(white: 0.6))
            }
        }
        .environment(\.colorScheme, .dark)
        // Pages read on black: the window (bars, status bar) goes dark with them.
        .onAppear { AppAppearance.applyToWindows(.dark) }
        .onDisappear {
            AppAppearance.applyToWindows((UserDefaults.standard.string(forKey: AppAppearance.defaultsKey)
                .flatMap(AppAppearance.init(rawValue:)) ?? .dark).interfaceStyle)
        }
        .navigationTitle(model.pages?.chapter.label ?? "")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar(.hidden, for: .tabBar)
        .toolbar(chromeHidden ? .hidden : .visible, for: .navigationBar, .bottomBar)
        .toolbarBackground(Color.black, for: .navigationBar, .bottomBar)
        .toolbarBackground(.visible, for: .navigationBar, .bottomBar)
        .toolbarColorScheme(.dark, for: .navigationBar, .bottomBar)
        .toolbar { toolbar }
        .statusBarHidden(chromeHidden)
        .animation(.easeInOut(duration: 0.2), value: chromeHidden)
        .overlay(alignment: .bottom) {
            if let offer = model.latestOffer {
                LatestPositionBanner(
                    message: offer.message, theme: .black,
                    onGo: { Task { await model.acceptLatestOffer(app: app) } },
                    onStay: { model.declineLatestOffer(app: app) })
                .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .animation(.easeInOut(duration: 0.25), value: model.latestOffer)
        .task { await model.load(app: app) }
        .task { await model.checkLatestPosition(app: app) }
        // Keep the screen awake while reading, as the text reader's setting says.
        .onAppear { UIApplication.shared.isIdleTimerDisabled = keepScreenOn }
        .onChange(of: keepScreenOn) { _, on in UIApplication.shared.isIdleTimerDisabled = on }
        .onDisappear {
            UIApplication.shared.isIdleTimerDisabled = false
            Task { await model.leave(app: app) }
        }
        .onChange(of: scenePhase) { _, phase in
            switch phase {
            case .active:
                UIApplication.shared.isIdleTimerDisabled = keepScreenOn
                Task { await model.becameActive(app: app) }
            case .background:
                UIApplication.shared.isIdleTimerDisabled = false
                model.enteredBackground()
                Task { await model.leave(app: app) }
            default:
                Task { await model.leave(app: app) }
            }
        }
    }

    @ViewBuilder
    private func reader(_ pages: MangaPages) -> some View {
        Group {
            if pages.pages.isEmpty {
                Text("This chapter's pages haven't been downloaded on the server yet.")
                    .font(.ui(.callout))
                    .foregroundStyle(Color(white: 0.7))
                    .multilineTextAlignment(.center)
                    .padding(32)
            } else if model.mode.isPaged {
                PagedManga(pages: pages, model: model, onToggleChrome: { chromeHidden.toggle() },
                           onTurn: { chromeHidden = true }, endPage: { endOfChapter(pages) })
            } else {
                VerticalManga(pages: pages, model: model, onToggleChrome: { chromeHidden.toggle() },
                              endPage: { endOfChapter(pages) })
            }
        }
        // A new chapter or layout starts from fresh view state.
        .id(Surface(chapterId: pages.chapter.id, mode: model.mode))
        .overlay(alignment: .top) {
            if model.isShowingCache && !chromeHidden {
                OfflinePill(text: "Offline · saved copy").padding(.top, 6)
            }
        }
    }

    private struct Surface: Hashable {
        var chapterId: Int
        var mode: MangaReadingMode
    }

    private func endOfChapter(_ pages: MangaPages) -> some View {
        VStack(spacing: 16) {
            Text("End of \(pages.chapter.label)")
                .font(.ui(.title3, .semibold))
                .foregroundStyle(Color(white: 0.9))
                .multilineTextAlignment(.center)
            HStack(spacing: 12) {
                if let prev = pages.prev {
                    chapterButton(prev, title: "Previous", systemImage: "chevron.left", prominent: false)
                }
                if let next = pages.next {
                    chapterButton(next, title: "Next chapter", systemImage: "chevron.right", prominent: true)
                }
            }
            if let next = pages.next {
                Text(next.label + (next.downloaded == false ? " · not downloaded yet" : ""))
                    .font(.ui(.footnote))
                    .foregroundStyle(Color(white: 0.6))
                    .multilineTextAlignment(.center)
            } else {
                Text("You're all caught up.")
                    .font(.ui(.footnote))
                    .foregroundStyle(Color(white: 0.6))
                CheckForChaptersControl(novelId: model.novelId, compact: true)
                    .foregroundStyle(Color(white: 0.6))
                Button("Back to series") { dismiss() }
                    .font(.ui(.footnote, .semibold))
            }
        }
        .frame(maxWidth: 520)
        .padding(24)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }

    private func chapterButton(_ ref: ChapterRef, title: String, systemImage: String, prominent: Bool) -> some View {
        Button {
            Task { await model.go(to: ref, app: app) }
        } label: {
            Label(title, systemImage: systemImage)
                .font(.ui(.callout, .semibold))
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
                .foregroundStyle(prominent ? Color.onAccent : Color(white: 0.9))
                .background(prominent ? AnyShapeStyle(Color.brandAccent) : AnyShapeStyle(Color.white.opacity(0.1)),
                            in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
        .accessibilityHint(ref.label)
    }

    private func failure(_ message: String) -> some View {
        ContentUnavailableView {
            Label("Couldn't open this chapter", systemImage: "book.closed")
        } description: {
            Text(message)
        } actions: {
            Button("Try again") { Task { await model.load(app: app) } }
                .buttonStyle(.borderedProminent)
        }
        .foregroundStyle(Color(white: 0.9))
    }

    // MARK: Chrome

    @ToolbarContentBuilder
    private var toolbar: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            Menu {
                Picker("Reading mode", selection: Binding(get: { model.override }, set: { model.choose($0) })) {
                    ForEach(MangaModeOverride.allCases) { option in
                        if option == .follow {
                            Text(option.title + " · " + model.seriesMode.seriesTitle).tag(option)
                        } else {
                            Text(option.title).tag(option)
                        }
                    }
                }
            } label: {
                Label("Reading mode: \(model.override.title)", systemImage: "rectangle.split.3x1")
            }
        }
        ToolbarItemGroup(placement: .bottomBar) {
            Button {
                if let prev = model.pages?.prev { Task { await model.go(to: prev, app: app) } }
            } label: {
                Label("Previous chapter", systemImage: "chevron.left")
            }
            .disabled(model.pages?.prev == nil)
            Spacer()
            if model.pageCount > 0 {
                VStack(spacing: 1) {
                    Text("\(min(model.currentPage, model.pageCount - 1) + 1) / \(model.pageCount)")
                        .font(.uiMono(.caption))
                    Text(model.mode.title)
                        .font(.ui(.caption2))
                        .foregroundStyle(Color(white: 0.6))
                }
                .foregroundStyle(Color(white: 0.85))
                .accessibilityElement(children: .ignore)
                .accessibilityLabel("Page \(min(model.currentPage, model.pageCount - 1) + 1) of \(model.pageCount), \(model.mode.title)")
            }
            Spacer()
            Button {
                if let next = model.pages?.next { Task { await model.go(to: next, app: app) } }
            } label: {
                Label("Next chapter", systemImage: "chevron.right")
            }
            .disabled(model.pages?.next == nil)
        }
    }
}

/// Paged reading (ltr / rtl): one page per screen, then an end page with
/// the chapter links. Right to left lays the pages out mirrored, so a swipe
/// to the right and a tap on the left go forward.
private struct PagedManga<End: View>: View {
    @Environment(AppModel.self) private var app
    let pages: MangaPages
    let model: MangaReaderModel
    var onToggleChrome: () -> Void
    var onTurn: () -> Void
    @ViewBuilder var endPage: () -> End

    /// The page settled on (0-based; `count` is the end page).
    @State private var page = 0
    /// The scroll view's position, by page id.
    @State private var shown: Int?
    @State private var started = false

    private var count: Int { pages.pages.count }
    private var rightToLeft: Bool { model.mode.isRightToLeft }

    var body: some View {
        // A paging scroll view rather than a page-style TabView, which jumped
        // a page when the chrome hid (its safe area changed mid-turn).
        ScrollView(.horizontal) {
            LazyHStack(spacing: 0) {
                ForEach(0...count, id: \.self) { index in
                    Group {
                        if index < count {
                            let item = pages.pages[index]
                            ZoomablePage(onTap: { x, width in
                                perform(TapZones.action(x: x, width: width, rightToLeft: rightToLeft))
                            }) {
                                PageImage(url: model.source(for: item), index: index, count: count)
                            }
                        } else {
                            endPage()
                                .contentShape(Rectangle())
                                .onTapGesture(coordinateSpace: .global) { location in
                                    perform(TapZones.action(x: location.x, width: UIScreen.main.bounds.width,
                                                            rightToLeft: rightToLeft))
                                }
                        }
                    }
                    // Pages keep their own (left-to-right) layout inside a mirrored strip.
                    .environment(\.layoutDirection, .leftToRight)
                    .containerRelativeFrame([.horizontal, .vertical])
                    .id(index)
                }
            }
            .scrollTargetLayout()
        }
        .scrollTargetBehavior(.paging)
        .scrollPosition(id: $shown)
        .scrollIndicators(.hidden)
        // Right to left: page 2 sits to the left of page 1.
        .environment(\.layoutDirection, rightToLeft ? .rightToLeft : .leftToRight)
        .ignoresSafeArea()
        .accessibilityAction(named: "Next page") { turn(forward: true) }
        .accessibilityAction(named: "Previous page") { turn(forward: false) }
        .onAppear(perform: start)
        #if DEBUG
        .task { await debugTurns() }
        #endif
        .onChange(of: shown) { _, value in
            guard let value, value != page else { return }
            page = value
            if model.isTracking { onTurn() }
            // The end page is past the last image: the last image already marked the chapter read.
            model.pageShown(min(value, count - 1), app: app)
        }
        .onChange(of: model.scrollRequest) { _, request in
            guard let request else { return }
            page = min(max(request.index, 0), count - 1)
            shown = page
            settle()
        }
    }

    private func start() {
        guard !started else { return }
        started = true
        page = min(max(model.currentPage, 0), count - 1)
        shown = page
        settle()
    }

    /// Positions count once the restored page has settled. As in the text
    /// reader the restored page isn't a new position; only the end counts.
    private func settle() {
        guard !model.isTracking else { return }
        Task {
            try? await Task.sleep(for: .milliseconds(600))
            model.isTracking = true
            if page >= count - 1 { model.reachedEnd(app: app) }
        }
    }

    private func turn(forward: Bool) {
        let target = page + (forward ? 1 : -1)
        if (0...count).contains(target) {
            withAnimation(.easeOut(duration: 0.25)) { shown = target }
        } else if forward, let next = pages.next, next.downloaded != false {
            // Past the end page: the next chapter, as its button would.
            Task { await model.go(to: next, app: app) }
        }
    }

    private func perform(_ action: TapZones.Action) {
        switch action {
        case .next: turn(forward: true)
        case .previous: turn(forward: false)
        case .toggleChrome: onToggleChrome()
        }
    }

    #if DEBUG
    /// Debug builds only: `-debugMangaTurns <n>` turns n pages forward, one a
    /// second, once the first chapter is shown (through the tap-zone path).
    private func debugTurns() async {
        let turns = UserDefaults.standard.integer(forKey: "debugMangaTurns")
        // Once per launch, on the first chapter shown.
        guard turns > 0, !DebugMangaTurns.done else { return }
        DebugMangaTurns.done = true
        try? await Task.sleep(for: .seconds(1.5))
        for _ in 0..<turns {
            try? await Task.sleep(for: .seconds(1))
            turn(forward: true)
        }
    }
    #endif
}

#if DEBUG
@MainActor private enum DebugMangaTurns {
    static var done = false
}
#endif

/// Vertical (webtoon) reading: pages stacked full width, then the end of
/// the chapter. Pinch (or double-tap) zooms the strip by laying it out
/// wider, so the scroll view pans it in both directions.
private struct VerticalManga<End: View>: View {
    @Environment(AppModel.self) private var app
    let pages: MangaPages
    let model: MangaReaderModel
    var onToggleChrome: () -> Void
    @ViewBuilder var endPage: () -> End

    @State private var topPage: Int?
    @State private var zoom: CGFloat = 1
    @State private var pinchBase: CGFloat?
    @State private var started = false

    private var count: Int { pages.pages.count }
    private static var maxZoom: CGFloat { 3 }

    var body: some View {
        GeometryReader { geo in
            let width = min(geo.size.width, 900) * zoom
            ScrollView(zoom > 1.01 ? [.vertical, .horizontal] : .vertical) {
                LazyVStack(spacing: 0) {
                    ForEach(Array(pages.pages.enumerated()), id: \.element.page) { index, item in
                        PageImage(url: model.source(for: item), index: index, count: count,
                                  fillWidth: true, aspect: item.aspect)
                            .frame(width: width)
                            .id(index)
                    }
                    endPage()
                        .frame(width: min(geo.size.width, 900), height: max(geo.size.height * 0.7, 320))
                        .frame(width: width)
                        .id(count)
                        // The last page counts as read once the end comes into view.
                        .onAppear { if started { model.pageShown(count - 1, app: app) } }
                }
                .scrollTargetLayout()
                .frame(minWidth: geo.size.width)
            }
            .scrollPosition(id: $topPage, anchor: .top)
            .contentMargins(.top, 56, for: .scrollContent)
            .simultaneousGesture(
                MagnifyGesture()
                    .onChanged { value in
                        let base = pinchBase ?? zoom
                        pinchBase = base
                        zoom = min(max(base * value.magnification, 1), Self.maxZoom)
                    }
                    .onEnded { _ in pinchBase = nil }
            )
            .onTapGesture(count: 2) {
                withAnimation(.easeInOut(duration: 0.2)) { zoom = zoom > 1.01 ? 1 : 2 }
            }
            .onTapGesture { onToggleChrome() }
        }
        .accessibilityAction(named: "Zoom in or out") { zoom = zoom > 1.01 ? 1 : 2 }
        .onAppear(perform: start)
        #if DEBUG
        // Debug builds only: `-debugMangaScrollTo <index>` scrolls the strip
        // there two seconds after opening (`count` is the end of the chapter).
        .task {
            let target = UserDefaults.standard.integer(forKey: "debugMangaScrollTo")
            guard target > 0 else { return }
            try? await Task.sleep(for: .seconds(2))
            withAnimation { topPage = min(target, count) }
        }
        #endif
        .onChange(of: topPage) { _, index in
            guard let index, started, index < count else { return }
            model.pageShown(min(index, count - 1), app: app)
        }
        .onChange(of: model.scrollRequest) { _, request in
            guard let request else { return }
            topPage = min(max(request.index, 0), count - 1)
            settle()
        }
    }

    private func start() {
        guard !started else { return }
        topPage = min(max(model.currentPage, 0), count - 1)
        settle()
    }

    private func settle() {
        Task {
            try? await Task.sleep(for: .milliseconds(600))
            started = true
            model.isTracking = true
        }
    }
}
