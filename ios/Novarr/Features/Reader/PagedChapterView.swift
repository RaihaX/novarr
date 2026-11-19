import SwiftUI
import UIKit

/// Everything the page layout depends on; a change re-paginates. Colour and
/// highlights are left out on purpose: they only change how pages are drawn.
struct PageSpec: Hashable, Sendable {
    var chapterId: Int
    var textWidth: CGFloat
    var pageHeight: CGFloat
    var typography: ReaderTypography
    /// The heading's caption size (Dynamic Type), read on the main thread.
    var captionSize: CGFloat
}

/// One paragraph laid out by TextKit 1 at the page's text width. Built off
/// the main thread, then handed over whole and only read (drawn) on it.
final class ParagraphLayout: @unchecked Sendable {
    let storage: NSTextStorage
    let manager: NSLayoutManager
    let container: NSTextContainer
    let lineTops: [CGFloat]
    let lineBottoms: [CGFloat]
    let lineStarts: [Int]
    let lineGlyphs: [NSRange]

    init(text: String, attributes: [NSAttributedString.Key: Any], width: CGFloat) {
        let storage = NSTextStorage(string: text, attributes: attributes)
        let manager = NSLayoutManager()
        let container = NSTextContainer(size: CGSize(width: width, height: .greatestFiniteMagnitude))
        container.lineFragmentPadding = 0
        manager.addTextContainer(container)
        storage.addLayoutManager(manager)
        manager.ensureLayout(for: container)
        var tops: [CGFloat] = [], bottoms: [CGFloat] = [], starts: [Int] = [], glyphs: [NSRange] = []
        manager.enumerateLineFragments(forGlyphRange: manager.glyphRange(for: container)) { rect, _, _, range, _ in
            tops.append(rect.minY)
            bottoms.append(rect.maxY)
            starts.append(manager.characterIndexForGlyph(at: range.location))
            glyphs.append(range)
        }
        self.storage = storage
        self.manager = manager
        self.container = container
        lineTops = tops
        lineBottoms = bottoms
        lineStarts = starts
        lineGlyphs = glyphs
    }

    private var color: UIColor?

    /// Text colour is set when drawing (main thread only), so a theme change
    /// doesn't re-paginate; it doesn't move a line break.
    func setColor(_ color: UIColor) {
        guard color != self.color else { return }
        self.color = color
        storage.addAttribute(.foregroundColor, value: color, range: NSRange(location: 0, length: storage.length))
    }

    /// The text of lines `from..<to`.
    func text(from: Int, to: Int) -> String {
        guard from < to, to <= lineGlyphs.count else { return "" }
        let glyphs = NSUnionRange(lineGlyphs[from], lineGlyphs[to - 1])
        let chars = manager.characterRange(forGlyphRange: glyphs, actualGlyphRange: nil)
        return (storage.string as NSString).substring(with: chars)
    }
}

/// A chapter measured and broken into pages for one `PageSpec`.
final class MeasuredChapter: @unchecked Sendable {
    let spec: PageSpec
    let pages: [Pagination.Page]
    /// Block `i + 1` of the pages is paragraph `i`; block 0 is the heading.
    let paragraphs: [ParagraphLayout]
    let headerHeight: CGFloat

    private init(spec: PageSpec, pages: [Pagination.Page], paragraphs: [ParagraphLayout], headerHeight: CGFloat) {
        self.spec = spec
        self.pages = pages
        self.paragraphs = paragraphs
        self.headerHeight = headerHeight
    }

    /// Measures every paragraph (the costly part for long chapters: call it
    /// off the main thread) and paginates. Throws when cancelled.
    static func measure(_ content: ChapterContent, spec: PageSpec) throws -> MeasuredChapter {
        let attributes = spec.typography.attributes()
        let header = ReaderHeaderMetrics.height(
            novelName: content.novelName, title: content.label, width: spec.textWidth,
            typography: spec.typography, captionSize: spec.captionSize)
        var blocks = [Pagination.Block.single(startOffset: 0, height: header)]
        var layouts: [ParagraphLayout] = []
        layouts.reserveCapacity(content.paragraphs.count)
        var offset = 0
        for (i, text) in content.paragraphs.enumerated() {
            try Task.checkCancellation()
            let layout = ParagraphLayout(text: text, attributes: attributes, width: spec.textWidth)
            layouts.append(layout)
            let last = i == content.paragraphs.count - 1
            blocks.append(Pagination.Block(
                startOffset: offset, lineTops: layout.lineTops, lineBottoms: layout.lineBottoms,
                lineStarts: layout.lineStarts, spacingAfter: last ? 0 : spec.typography.paragraphGap))
            offset += text.utf16.count
        }
        let pages = Pagination.paginate(blocks, pageHeight: spec.pageHeight)
        return MeasuredChapter(spec: spec, pages: pages, paragraphs: layouts, headerHeight: header)
    }

    /// What a page says, for VoiceOver.
    func text(of page: Pagination.Page) -> String {
        page.slices.filter { $0.block > 0 }
            .map { paragraphs[$0.block - 1].text(from: $0.fromLine, to: $0.toLine) }
            .joined(separator: "\n")
    }
}

/// Paged reading: the chapter laid out into screen-sized pages with the
/// current typography and margins. Taps on the outer thirds and swipes turn
/// pages, the middle third toggles the chrome, a long press on a paragraph
/// highlights it. Past the last page the next chapter opens (when it is
/// downloaded); back from the first, the previous one opens at its end.
/// Progress follows scroll mode's rules through the same `ReaderModel`.
/// Same behaviour as Android's `PagedChapterText`.
struct PagedChapterView<Header: View, Footer: View>: View {
    @Environment(AppModel.self) private var app
    let content: ChapterContent
    let model: ReaderModel
    let typography: ReaderTypography
    let theme: ReaderTheme
    let columnWidth: CGFloat
    let margin: CGFloat
    var onToggleChrome: () -> Void
    /// A page was turned: the chrome hides so it doesn't cover the text.
    var onTurn: () -> Void
    var onLongPress: (_ paragraph: Int) -> Void
    @ViewBuilder var header: () -> Header
    @ViewBuilder var footer: () -> Footer

    @ScaledMetric(relativeTo: .caption) private var captionSize: CGFloat = 12
    @State private var measured: MeasuredChapter?
    @State private var page = 0
    @State private var forward = true
    @State private var started = false

    var body: some View {
        GeometryReader { geo in
            let insets = Self.windowInsets
            // Clear of the navigation bar and toolbar, so the chrome never covers a line.
            let topPad = insets.top + 48
            let bottomPad = insets.bottom + 54
            let available = geo.size.width - insets.left - insets.right
            let column = min(available, columnWidth)
            let spec = PageSpec(chapterId: content.id, textWidth: max(column - margin * 2, 40),
                                pageHeight: max(geo.size.height - topPad - bottomPad, 80),
                                typography: typography, captionSize: captionSize)
            ZStack(alignment: .top) {
                if let measured {
                    pageView(measured, column: column, pageHeight: spec.pageHeight, fullWidth: geo.size.width)
                        .padding(.top, topPad)
                        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .top)
                        .id(PageID(chapter: content.id, page: page))
                        .transition(.asymmetric(
                            insertion: .opacity.combined(with: .offset(x: forward ? 40 : -40)),
                            removal: .opacity))
                    Text("\(min(page, measured.pages.count - 1) + 1) / \(measured.pages.count)")
                        .font(.ui(.caption2).monospacedDigit())
                        .foregroundStyle(theme.secondaryText.opacity(0.8))
                        .frame(maxHeight: .infinity, alignment: .bottom)
                        .padding(.bottom, insets.bottom + 14)
                        .accessibilityHidden(true)
                } else {
                    ProgressView().tint(theme.secondaryText)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                }
            }
            .task(id: spec) { await paginate(spec) }
        }
        .ignoresSafeArea()
        .onChange(of: model.scrollRequest) { _, request in
            // "Go to latest" within this chapter: the page holding that paragraph.
            guard request != nil, let measured else { return }
            show(Pagination.page(for: model.anchorOffset, in: measured.pages), in: measured, report: true)
        }
    }

    private struct PageID: Hashable {
        var chapter: Int
        var page: Int
    }

    /// The window's own insets: unlike the view's safe area they don't
    /// change as the chrome shows and hides, which would re-paginate.
    private static var windowInsets: UIEdgeInsets {
        let window = UIApplication.shared.connectedScenes
            .compactMap { ($0 as? UIWindowScene)?.keyWindow }.first
        var insets = window?.safeAreaInsets ?? .zero
        insets.top = max(insets.top, 20)
        return insets
    }

    // MARK: Layout

    private func paginate(_ spec: PageSpec) async {
        let content = content
        let work = Task.detached(priority: .userInitiated) { try MeasuredChapter.measure(content, spec: spec) }
        let result = await withTaskCancellationHandler {
            try? await work.value
        } onCancel: {
            work.cancel()
        }
        guard let result, !Task.isCancelled else { return }
        measured = result
        if model.restoreToEnd && !started {
            page = textPages(result) - 1
        } else {
            // Same text as before (never the same page number).
            page = Pagination.page(for: model.anchorOffset, in: result.pages)
        }
        if !started {
            started = true
            if !model.isTracking {
                try? await Task.sleep(for: .milliseconds(600))
                guard !Task.isCancelled else { return }
                model.isTracking = true
            }
        }
        // The restored page, or one a re-pagination moved to, is not a new
        // position (reporting its first line would creep backwards); only
        // the end still counts.
        if let current = measured, page >= textPages(current) - 1 { model.reachedEnd(app: app) }
        #if DEBUG
        await debugTurns()
        #endif
    }

    #if DEBUG
    /// Debug builds only: `-debugTurns <n>` (or `-debugTurnsBack <n>`) turns
    /// n pages forward (back) once the first chapter is shown, one a second, exercising page
    /// turns, progress and chapter changes without UI automation.
    private func debugTurns() async {
        // (A negative launch argument reads as a flag, so back turns have their own key.)
        let turns = UserDefaults.standard.integer(forKey: "debugTurns")
            - UserDefaults.standard.integer(forKey: "debugTurnsBack")
        // Only on the first chapter shown, so a chapter it opens is left alone.
        guard turns != 0, Self.debugTurned.isEmpty else { return }
        Self.debugTurned.insert(content.id)
        for _ in 0..<abs(turns) {
            try? await Task.sleep(for: .seconds(1))
            guard !Task.isCancelled else { return }
            turn(forward: turns > 0)
        }
    }

    @MainActor private static var debugTurned: Set<Int> {
        get { DebugTurned.ids }
        set { DebugTurned.ids = newValue }
    }
    #endif

    private func textPages(_ measured: MeasuredChapter) -> Int { measured.pages.count }

    /// With nowhere to go next, a last page offers the end-of-chapter actions.
    private var hasEndPage: Bool { content.next == nil || content.next?.downloaded == false }

    private func totalPages(_ measured: MeasuredChapter) -> Int {
        textPages(measured) + (hasEndPage ? 1 : 0)
    }

    // MARK: Turning

    private func turn(forward: Bool) {
        guard let measured else { return }
        if forward {
            if page < totalPages(measured) - 1 {
                show(page + 1, in: measured, report: true)
            } else if let next = content.next, next.downloaded != false {
                Task { await model.go(to: next, app: app) }
            }
        } else {
            if page > 0 {
                show(page - 1, in: measured, report: true)
            } else if let prev = content.prev {
                Task { await model.openAtEnd(prev, app: app) }
            }
        }
    }

    private func show(_ index: Int, in measured: MeasuredChapter, report: Bool) {
        let index = min(max(index, 0), totalPages(measured) - 1)
        guard index != page else { return }
        self.forward = index > page
        onTurn()
        withAnimation(.easeOut(duration: 0.22)) { page = index }
        let texts = textPages(measured)
        // The place is kept even before tracking starts, so a re-pagination stays on this text.
        model.anchorOffset = index < texts ? measured.pages[index].startOffset : model.offsets.total
        guard report else { return }
        if index >= texts - 1 {
            model.reachedEnd(app: app)
        } else {
            let start = measured.pages[index].startOffset
            model.scrolled(toParagraph: model.offsets.paragraphAt(start), offset: start, app: app)
        }
    }

    // MARK: Pages

    @ViewBuilder
    private func pageView(_ measured: MeasuredChapter, column: CGFloat, pageHeight: CGFloat,
                          fullWidth: CGFloat) -> some View {
        let index = min(page, totalPages(measured) - 1)
        if index >= textPages(measured) {
            // The end page: the footer, with taps and swipes still turning back.
            ScrollView {
                footer()
                    .frame(width: max(column - margin * 2, 40))
                    .frame(maxWidth: .infinity, minHeight: pageHeight)
            }
            .frame(height: pageHeight)
            .scrollBounceBehavior(.basedOnSize)
            .contentShape(Rectangle())
            .onTapGesture(coordinateSpace: .local) { location in
                perform(TapZones.action(x: location.x, width: fullWidth))
            }
            .gesture(DragGesture(minimumDistance: 30).onEnded { value in
                if let action = TapZones.swipe(dx: value.translation.width, threshold: 48) { perform(action) }
            })
            .accessibilityAction(named: "Previous page") { turn(forward: false) }
        } else {
            let current = measured.pages[index]
            ZStack(alignment: .topLeading) {
                PageCanvas(measured: measured, page: current, textColor: theme.uiText,
                           tint: theme.highlightTint, highlights: model.highlightRanges,
                           onAction: perform, onLongPress: onLongPress)
                    .accessibilityElement()
                    .accessibilityLabel(measured.text(of: current))
                    .accessibilityAddTraits(.causesPageTurn)
                    .accessibilityAction(named: "Next page") { turn(forward: true) }
                    .accessibilityAction(named: "Previous page") { turn(forward: false) }
                    .accessibilityAction(named: "Show or hide controls") { onToggleChrome() }
                    .accessibilityScrollAction { edge in
                        switch edge {
                        case .trailing, .bottom: turn(forward: true)
                        case .leading, .top: turn(forward: false)
                        }
                    }
                if let slice = current.slices.first(where: { $0.block == 0 }) {
                    header()
                        .frame(height: slice.height, alignment: .top)
                        .offset(y: slice.y)
                        .allowsHitTesting(false)
                }
            }
            .frame(width: max(column - margin * 2, 40), height: pageHeight, alignment: .topLeading)
            .frame(maxWidth: .infinity)
        }
    }

    private func perform(_ action: TapZones.Action) {
        switch action {
        case .next: turn(forward: true)
        case .previous: turn(forward: false)
        case .toggleChrome: onToggleChrome()
        }
    }
}

#if DEBUG
@MainActor private enum DebugTurned {
    static var ids: Set<Int> = []
}
#endif

/// Draws one page from the measured paragraph layouts (whole lines only,
/// clipped to each slice) with the highlight tints, and turns touches into
/// page actions. Taps use the reader's full width for the thirds.
private struct PageCanvas: UIViewRepresentable {
    let measured: MeasuredChapter
    let page: Pagination.Page
    let textColor: UIColor
    let tint: UIColor
    let highlights: [Int: [NSRange]]
    var onAction: (TapZones.Action) -> Void
    var onLongPress: (Int) -> Void

    func makeUIView(context: Context) -> PageCanvasView {
        let view = PageCanvasView()
        view.isOpaque = false
        view.backgroundColor = .clear
        view.contentMode = .redraw
        return view
    }

    func updateUIView(_ view: PageCanvasView, context: Context) {
        view.onAction = onAction
        view.onLongPress = onLongPress
        let changed = view.measured !== measured || view.page != page || view.textColor != textColor
            || view.tint != tint || view.highlights != highlights
        view.measured = measured
        view.page = page
        view.textColor = textColor
        view.tint = tint
        view.highlights = highlights
        if changed { view.setNeedsDisplay() }
    }
}

final class PageCanvasView: UIView {
    var measured: MeasuredChapter?
    var page: Pagination.Page?
    var textColor: UIColor = .label
    var tint: UIColor = .systemYellow
    var highlights: [Int: [NSRange]] = [:]
    var onAction: ((TapZones.Action) -> Void)?
    var onLongPress: ((Int) -> Void)?

    override init(frame: CGRect) {
        super.init(frame: frame)
        let long = UILongPressGestureRecognizer(target: self, action: #selector(longPressed(_:)))
        let tap = UITapGestureRecognizer(target: self, action: #selector(tapped(_:)))
        tap.require(toFail: long)
        addGestureRecognizer(long)
        addGestureRecognizer(tap)
        for direction in [UISwipeGestureRecognizer.Direction.left, .right] {
            let swipe = UISwipeGestureRecognizer(target: self, action: #selector(swiped(_:)))
            swipe.direction = direction
            addGestureRecognizer(swipe)
        }
        // Dynamic colours (the System theme) resolve when drawn.
        registerForTraitChanges([UITraitUserInterfaceStyle.self]) { (view: PageCanvasView, _: UITraitCollection) in
            view.setNeedsDisplay()
        }
    }

    @available(*, unavailable)
    required init?(coder: NSCoder) { fatalError("init(coder:) is not used") }

    @objc private func tapped(_ gesture: UITapGestureRecognizer) {
        // Thirds of the whole screen, not of the text column.
        let host = window ?? self
        let x = gesture.location(in: host).x
        onAction?(TapZones.action(x: x, width: host.bounds.width))
    }

    @objc private func swiped(_ gesture: UISwipeGestureRecognizer) {
        onAction?(gesture.direction == .left ? .next : .previous)
    }

    @objc private func longPressed(_ gesture: UILongPressGestureRecognizer) {
        guard gesture.state == .began, let page else { return }
        let y = gesture.location(in: self).y
        guard let slice = page.slices.first(where: { $0.block > 0 && y >= $0.y && y <= $0.y + $0.height })
        else { return }
        onLongPress?(slice.block - 1)
    }

    override func draw(_ rect: CGRect) {
        guard let measured, let page, let context = UIGraphicsGetCurrentContext() else { return }
        for slice in page.slices where slice.block > 0 {
            let layout = measured.paragraphs[slice.block - 1]
            let manager = layout.manager
            let glyphs = NSUnionRange(layout.lineGlyphs[slice.fromLine], layout.lineGlyphs[slice.toLine - 1])
            let origin = CGPoint(x: 0, y: slice.y - layout.lineTops[slice.fromLine])
            context.saveGState()
            // Only this slice's lines: the rest of the paragraph is on other pages.
            context.clip(to: CGRect(x: -bounds.width, y: slice.y, width: bounds.width * 3, height: slice.height))
            HighlightDrawing.fill(highlights[slice.block - 1] ?? [], manager: manager, container: layout.container,
                                  within: glyphs, origin: origin,
                                  lineHeight: measured.spec.typography.bodyFont.lineHeight, tint: tint)
            layout.setColor(textColor)
            manager.drawGlyphs(forGlyphRange: glyphs, at: origin)
            context.restoreGState()
        }
    }
}
