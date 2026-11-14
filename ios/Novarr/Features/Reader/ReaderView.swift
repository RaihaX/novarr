import SwiftUI

struct ReaderView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.dismiss) private var dismiss
    @State private var model: ReaderModel
    @State private var chromeHidden = false
    @State private var showSettings = false
    @State private var topParagraph: Int?
    @State private var footerVisible = false

    @AppStorage(ReaderKeys.fontSize) private var fontSize = 19.0
    @AppStorage(ReaderKeys.font) private var font = ReaderFont.newYork
    @AppStorage(ReaderKeys.spacing) private var spacing = ReaderSpacing.normal
    @AppStorage(ReaderKeys.margins) private var margins = ReaderMargins.medium
    @AppStorage(ReaderKeys.width) private var width = ReaderWidth.standard
    @AppStorage(ReaderKeys.theme) private var theme = ReaderTheme.system
    /// Scales the chosen size with the system Dynamic Type setting.
    @ScaledMetric(relativeTo: .body) private var dynamicBody: CGFloat = 17

    private enum Anchor {
        static let header = -1
        static let footer = -2
        static let missing = -3
    }

    init(route: ReaderRoute) {
        _model = State(initialValue: ReaderModel(route: route))
    }

    private var textSize: CGFloat { CGFloat(fontSize) * dynamicBody / 17 }

    var body: some View {
        ZStack {
            theme.background.ignoresSafeArea()
            switch model.phase {
            case .loaded:
                if let content = model.content { reader(content) }
            case .failed(let message):
                failure(message)
            case .idle, .loading:
                ProgressView().tint(theme.secondaryText)
            }
        }
        .modifier(ThemeScheme(scheme: theme.colorScheme))
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
        .sheet(isPresented: $showSettings) { ReaderSettingsSheet() }
        .task { await model.load(app: app) }
        .onDisappear { Task { await model.leave(app: app) } }
        .onChange(of: scenePhase) { _, phase in
            if phase != .active { Task { await model.leave(app: app) } }
        }
    }

    // MARK: Reading surface

    private func reader(_ content: ChapterContent) -> some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(alignment: .leading, spacing: textSize * 0.9) {
                    header(content).id(Anchor.header)
                    if content.downloaded && !content.paragraphs.isEmpty {
                        ForEach(content.paragraphs.indices, id: \.self) { index in
                            Text(content.paragraphs[index])
                                .font(font.font(size: textSize))
                                .lineSpacing(textSize * spacing.factor)
                                .foregroundStyle(theme.text)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .fixedSize(horizontal: false, vertical: true)
                                .id(index)
                        }
                    } else {
                        notDownloaded.id(Anchor.missing)
                    }
                    footer(content)
                        .id(Anchor.footer)
                        .onAppear {
                            footerVisible = true
                            model.reachedEnd(app: app)
                        }
                        .onDisappear { footerVisible = false }
                }
                .scrollTargetLayout()
                .frame(maxWidth: width.maxWidth, alignment: .leading)
                .padding(.horizontal, margins.padding)
                .padding(.top, 12)
                .padding(.bottom, 40)
                .frame(maxWidth: .infinity)
            }
            .scrollPosition(id: $topParagraph, anchor: .top)
            .onChange(of: topParagraph) { _, index in
                if let index, index >= 0 { model.scrolled(toParagraph: index, app: app) }
            }
            .contentShape(Rectangle())
            .onTapGesture { chromeHidden.toggle() }
            .task(id: content.id) {
                // Let the lazy stack lay out before jumping to the saved spot.
                if let index = model.restoreIndex {
                    try? await Task.sleep(for: .milliseconds(80))
                    proxy.scrollTo(index, anchor: .top)
                }
                try? await Task.sleep(for: .milliseconds(600))
                model.isTracking = true
                // Short chapters show the end straight away.
                if footerVisible { model.reachedEnd(app: app) }
                #if DEBUG
                // Debug builds only: exercises progress + mark-read without UI automation.
                if UserDefaults.standard.bool(forKey: "debugScrollTest") {
                    try? await Task.sleep(for: .seconds(1))
                    topParagraph = model.paragraphCount / 2
                    try? await Task.sleep(for: .seconds(5))
                    withAnimation { proxy.scrollTo(Anchor.footer, anchor: .bottom) }
                }
                #endif
            }
        }
        .id(content.id)
        .overlay(alignment: .top) {
            if model.isShowingCache && !chromeHidden {
                OfflinePill(text: "Offline · saved copy").padding(.top, 6)
            }
        }
    }

    private func header(_ content: ChapterContent) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if !content.novelName.isEmpty {
                Text(content.novelName.uppercased())
                    .font(.caption.weight(.semibold))
                    .tracking(1)
                    .foregroundStyle(theme.secondaryText)
                    .lineLimit(2)
            }
            Text(content.label)
                .font(font.font(size: textSize * 1.45).weight(.semibold))
                .foregroundStyle(theme.text)
                .fixedSize(horizontal: false, vertical: true)
            Rectangle()
                .fill(Color.accentColor)
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
                .font(.title3.weight(.semibold))
                .foregroundStyle(theme.text)
            Text("Your Novarr server hasn't fetched this chapter yet. It will be readable here once the server downloads it.")
                .font(.callout)
                .multilineTextAlignment(.center)
                .foregroundStyle(theme.secondaryText)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 60)
    }

    private func footer(_ content: ChapterContent) -> some View {
        VStack(spacing: 16) {
            if content.downloaded && !content.paragraphs.isEmpty {
                BrandMark(color: theme.secondaryText.opacity(0.5)).frame(width: 22)
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
                    .font(.footnote)
                    .foregroundStyle(theme.secondaryText)
                    .multilineTextAlignment(.center)
            } else if content.downloaded {
                Text("You're all caught up.")
                    .font(.footnote)
                    .foregroundStyle(theme.secondaryText)
                Button("Back to novel") { dismiss() }
                    .font(.footnote.weight(.semibold))
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
                .font(.callout.weight(.semibold))
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
                .foregroundStyle(prominent ? Color.white : theme.text)
                .background(prominent ? AnyShapeStyle(Color.accentColor) : AnyShapeStyle(theme.text.opacity(0.08)),
                            in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
        .accessibilityHint(ref.label)
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
