import SwiftUI
import UIKit

/// Scroll reading: the chapter as one column of selectable paragraphs. The
/// selection menu offers "Highlight" next to Copy; a tap on the middle third
/// toggles the chrome (the outer thirds do nothing here).
///
/// Its scroll state lives here, and the reader gives it the chapter's id as
/// its identity, so a new chapter starts from fresh state: it opens at its
/// saved position, or at the header when there is none.
struct ScrollChapterView<Header: View, Footer: View, Missing: View>: View {
    @Environment(AppModel.self) private var app
    let content: ChapterContent
    let model: ReaderModel
    let typography: ReaderTypography
    let theme: ReaderTheme
    let columnWidth: CGFloat
    let margin: CGFloat
    var onToggleChrome: () -> Void
    var onHighlight: (String) -> Void
    @ViewBuilder var header: () -> Header
    @ViewBuilder var footer: () -> Footer
    @ViewBuilder var missing: () -> Missing

    @State private var topParagraph: Int?
    @State private var footerVisible = false
    /// Paragraphs (from the 95% mark on) whose bottom is on screen.
    @State private var bottomsVisible: Set<Int> = []
    @State private var width: CGFloat = 0
    @State private var viewportHeight: CGFloat = 0

    private static var space: String { "readerScroll" }

    /// The end of the chapter is in view: its footer, or the bottom of 95%
    /// of its paragraphs (`ReadMark`).
    private var endInView: Bool {
        if footerVisible { return true }
        guard content.downloaded else { return false }
        return ReadMark.textEndReached(lastFullyVisible: bottomsVisible.max() ?? -1,
                                       paragraphCount: content.paragraphs.count)
    }

    var body: some View {
        ScrollViewReader { proxy in
            ScrollView {
                LazyVStack(alignment: .leading, spacing: typography.paragraphGap) {
                    header().id(Anchor.header)
                    if content.downloaded && !content.paragraphs.isEmpty {
                        ForEach(content.paragraphs.indices, id: \.self) { index in
                            ParagraphTextView(
                                text: content.paragraphs[index], typography: typography,
                                textColor: theme.uiText, tint: theme.highlightTint,
                                highlights: model.highlightRanges[index] ?? [],
                                onHighlight: onHighlight)
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .modifier(BottomOnScreen(
                                active: index >= ReadMark.firstFinishingParagraph(paragraphCount: content.paragraphs.count),
                                space: Self.space, viewportHeight: viewportHeight) { visible in
                                    if visible { bottomsVisible.insert(index) } else { bottomsVisible.remove(index) }
                                })
                            .id(index)
                        }
                    } else {
                        missing().id(Anchor.missing)
                    }
                    footer()
                        .id(Anchor.footer)
                        .onAppear { footerVisible = true }
                        .onDisappear { footerVisible = false }
                }
                .scrollTargetLayout()
                .frame(maxWidth: columnWidth, alignment: .leading)
                .padding(.horizontal, margin)
                .padding(.top, 12)
                .padding(.bottom, 40)
                .frame(maxWidth: .infinity)
            }
            .scrollPosition(id: $topParagraph, anchor: .top)
            .coordinateSpace(.named(Self.space))
            .onChange(of: endInView) { _, visible in model.endVisible(visible, app: app) }
            .onChange(of: topParagraph) { _, index in
                if let index, index >= 0 { model.scrolled(toParagraph: index, app: app) }
            }
            .onChange(of: model.scrollRequest) { _, request in
                guard let request else { return }
                withAnimation { proxy.scrollTo(request.index, anchor: .top) }
            }
            .onGeometryChange(for: CGSize.self) { $0.size } action: {
                width = $0.width
                viewportHeight = $0.height
            }
            // Middle third: chrome (buttons in the footer keep their taps).
            .onTapGesture(coordinateSpace: .local) { location in
                if TapZones.action(x: location.x, width: width) == .toggleChrome { onToggleChrome() }
            }
            .accessibilityAction(named: "Show or hide controls") { onToggleChrome() }
            .task {
                // Let the lazy stack lay out before jumping to the saved spot
                // (or, after a mode switch, the same text as before).
                if let index = model.restoreParagraph {
                    try? await Task.sleep(for: .milliseconds(80))
                    proxy.scrollTo(index, anchor: .top)
                    // Rows far down are placed by estimate the first time;
                    // once they're measured a second jump lands exactly.
                    try? await Task.sleep(for: .milliseconds(60))
                    proxy.scrollTo(index, anchor: .top)
                }
                if !model.isTracking {
                    try? await Task.sleep(for: .milliseconds(600))
                    model.isTracking = true
                }
                // Short chapters show the end straight away.
                if endInView { model.endVisible(true, app: app) }
                #if DEBUG
                await debugScroll(proxy)
                #endif
            }
        }
    }

    #if DEBUG
    /// Debug builds only: exercises progress, mark-read and chapter changes
    /// without UI automation (`-debugScrollTest YES`, `-debugNextChapter YES`).
    private func debugScroll(_ proxy: ScrollViewProxy) async {
        let defaults = UserDefaults.standard
        if defaults.bool(forKey: "debugScrollTest") {
            try? await Task.sleep(for: .seconds(1))
            topParagraph = model.paragraphCount / 2
            try? await Task.sleep(for: .seconds(5))
            withAnimation { proxy.scrollTo(Anchor.footer, anchor: .bottom) }
        }
        // Scrolls far down an odd-numbered chapter, then opens the next one
        // (which has no saved position, so it must open at its header).
        if defaults.bool(forKey: "debugNextChapter"), content.id % 2 == 1, let next = content.next {
            try? await Task.sleep(for: .seconds(1))
            topParagraph = model.paragraphCount - 3
            try? await Task.sleep(for: .seconds(1))
            let model = model, app = app
            Task { await model.go(to: next, app: app) }
        }
    }
    #endif
}

/// Reports whether a row's bottom edge is on screen (inside the scroll
/// view's viewport), for the rows that can finish a chapter.
private struct BottomOnScreen: ViewModifier {
    let active: Bool
    let space: String
    let viewportHeight: CGFloat
    let onChange: (Bool) -> Void

    func body(content: Content) -> some View {
        if active {
            content
                .onGeometryChange(for: Bool.self) { proxy in
                    let bottom = proxy.frame(in: .named(space)).maxY
                    return bottom > 0 && bottom <= viewportHeight + 2
                } action: { onChange($0) }
                .onDisappear { onChange(false) }
        } else {
            content
        }
    }
}

/// Scroll ids of the non-paragraph rows (paragraphs use their index).
private enum Anchor {
    static let header = -1
    static let footer = -2
    static let missing = -3
}

/// One paragraph as a selectable, non-scrolling TextKit 1 text view (the
/// same text system the paged layout measures with). Its edit menu adds
/// "Highlight" to the system actions.
struct ParagraphTextView: UIViewRepresentable {
    let text: String
    let typography: ReaderTypography
    let textColor: UIColor
    let tint: UIColor
    let highlights: [NSRange]
    var onHighlight: (String) -> Void

    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> UITextView {
        // TextKit 1 with a layout manager that draws the highlights.
        let storage = NSTextStorage()
        let manager = HighlightingLayoutManager()
        let container = NSTextContainer(size: CGSize(width: 0, height: CGFloat.greatestFiniteMagnitude))
        container.widthTracksTextView = true
        manager.addTextContainer(container)
        storage.addLayoutManager(manager)
        let view = UITextView(frame: .zero, textContainer: container)
        view.isEditable = false
        view.isSelectable = true
        view.isScrollEnabled = false
        view.backgroundColor = .clear
        view.textContainerInset = .zero
        view.textContainer.lineFragmentPadding = 0
        view.dataDetectorTypes = []
        view.delegate = context.coordinator
        view.setContentCompressionResistancePriority(.defaultLow, for: .horizontal)
        view.setContentHuggingPriority(.defaultLow, for: .horizontal)
        return view
    }

    func updateUIView(_ view: UITextView, context: Context) {
        context.coordinator.onHighlight = onHighlight
        // Only when something shown changed: resetting the text would drop a selection.
        let key = Coordinator.Key(text: text, typography: typography, textColor: textColor,
                                  tint: tint, highlights: highlights)
        guard context.coordinator.shown != key else { return }
        let textChanged = context.coordinator.shown.map {
            $0.text != text || $0.typography != typography || $0.textColor != textColor
        } ?? true
        context.coordinator.shown = key
        if let manager = view.layoutManager as? HighlightingLayoutManager {
            manager.highlights = highlights
            manager.tint = tint
            manager.lineHeight = typography.bodyFont.lineHeight
        }
        if textChanged {
            view.attributedText = typography.attributed(text, color: textColor)
        } else {
            view.layoutManager.invalidateDisplay(forCharacterRange: NSRange(location: 0, length: view.textStorage.length))
            view.setNeedsDisplay()
        }
    }

    func sizeThatFits(_ proposal: ProposedViewSize, uiView: UITextView, context: Context) -> CGSize? {
        guard let width = proposal.width, width.isFinite, width > 0 else { return nil }
        let size = uiView.sizeThatFits(CGSize(width: width, height: .greatestFiniteMagnitude))
        return CGSize(width: width, height: ceil(size.height))
    }

    @MainActor
    final class Coordinator: NSObject, UITextViewDelegate {
        struct Key: Equatable {
            var text: String
            var typography: ReaderTypography
            var textColor: UIColor
            var tint: UIColor
            var highlights: [NSRange]
        }

        var shown: Key?
        var onHighlight: ((String) -> Void)?

        func textView(_ textView: UITextView, editMenuForTextIn range: NSRange,
                      suggestedActions: [UIMenuElement]) -> UIMenu? {
            let highlight = UIAction(title: "Highlight", image: UIImage(systemName: "highlighter")) { [weak self, weak textView] _ in
                guard let textView, let self else { return }
                let text = (textView.text as NSString).substring(with: range)
                textView.selectedRange = NSRange(location: 0, length: 0)
                self.onHighlight?(text)
            }
            // Right after the first group (Copy and friends).
            var children = suggestedActions
            children.insert(highlight, at: min(1, children.count))
            return UIMenu(children: children)
        }
    }
}
