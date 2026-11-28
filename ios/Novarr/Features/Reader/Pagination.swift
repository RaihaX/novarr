import CoreGraphics

/// Lays a measured chapter out into screen-sized pages, never cutting a line
/// in half. Pure (no UIKit): the reader measures the text and hands over line
/// geometry; this decides where pages break. Same rules as Android's
/// `Pagination` so both apps break pages alike.
///
/// Positions are global UTF-16 offsets into the chapter: paragraph `i` starts
/// at the sum of the lengths of the paragraphs before it (see `TextOffsets`).
/// A page is identified by the offset of its first line, so a re-pagination
/// (new font size, rotation…) finds the page holding the same text.
enum Pagination {
    /// One flow element (the chapter heading or a paragraph), measured at the
    /// page's text width. Line `i` spans `lineTops[i]..<lineBottoms[i]`
    /// (points, relative to the block's own top) and starts at character
    /// `startOffset + lineStarts[i]`. `spacingAfter` is the gap to the next
    /// block; it is dropped at a page break. A `keepTogether` block is never split.
    struct Block: Sendable, Equatable {
        var startOffset: Int
        var lineTops: [CGFloat]
        var lineBottoms: [CGFloat]
        var lineStarts: [Int]
        var spacingAfter: CGFloat = 0
        var keepTogether = false

        var lineCount: Int { lineTops.count }

        func height(from: Int, to: Int) -> CGFloat {
            to <= from ? 0 : lineBottoms[to - 1] - lineTops[from]
        }

        /// A block drawn as one piece (the heading), `height` points tall.
        static func single(startOffset: Int, height: CGFloat, spacingAfter: CGFloat = 0) -> Block {
            Block(startOffset: startOffset, lineTops: [0], lineBottoms: [height], lineStarts: [0],
                  spacingAfter: spacingAfter, keepTogether: true)
        }
    }

    /// Lines `fromLine..<toLine` of block `block`, drawn with their top at `y` on the page.
    struct Slice: Sendable, Equatable {
        var block: Int
        var fromLine: Int
        var toLine: Int
        var y: CGFloat
        var height: CGFloat
    }

    /// One screen: what it shows and the offset of its first line.
    struct Page: Sendable, Equatable {
        var slices: [Slice]
        var startOffset: Int
    }

    /// Breaks `blocks` into pages of at most `pageHeight` points. A line (or a
    /// keep-together block) taller than a whole page gets a page of its own.
    /// There is always at least one page.
    static func paginate(_ blocks: [Block], pageHeight: CGFloat) -> [Page] {
        precondition(pageHeight > 0, "page height must be positive")
        var pages: [Page] = []
        var slices: [Slice] = []
        var y: CGFloat = 0
        var pageStart = -1
        // Spacing owed by the previous block; only paid if more follows on this page.
        var gap: CGFloat = 0

        func breakPage() {
            if !slices.isEmpty { pages.append(Page(slices: slices, startOffset: pageStart)) }
            slices = []
            y = 0
            pageStart = -1
            gap = 0
        }

        for (index, block) in blocks.enumerated() where block.lineCount > 0 {
            if block.keepTogether {
                let h = block.height(from: 0, to: block.lineCount)
                if !slices.isEmpty && y + gap + h > pageHeight { breakPage() }
                let top = slices.isEmpty ? 0 : y + gap
                if pageStart < 0 { pageStart = block.startOffset + block.lineStarts[0] }
                slices.append(Slice(block: index, fromLine: 0, toLine: block.lineCount, y: top, height: h))
                y = top + h
                gap = block.spacingAfter
                continue
            }
            var from = 0
            while from < block.lineCount {
                // Fit as many whole lines of this block as the page has room for.
                let top = slices.isEmpty ? 0 : y + gap
                var to = from
                while to < block.lineCount && top + block.height(from: from, to: to + 1) <= pageHeight { to += 1 }
                if to == from {
                    if slices.isEmpty {
                        to = from + 1 // a line taller than the page: alone, clipped
                    } else {
                        breakPage()
                        continue
                    }
                }
                if pageStart < 0 { pageStart = block.startOffset + block.lineStarts[from] }
                let h = block.height(from: from, to: to)
                slices.append(Slice(block: index, fromLine: from, toLine: to, y: top, height: h))
                y = top + h
                gap = 0
                from = to
                if from < block.lineCount { breakPage() }
            }
            gap = block.spacingAfter
        }
        breakPage()
        if pages.isEmpty { pages.append(Page(slices: [], startOffset: 0)) }
        return pages
    }

    /// The page showing `offset`: the last page starting at or before it.
    static func page(for offset: Int, in pages: [Page]) -> Int {
        guard !pages.isEmpty else { return 0 }
        var lo = 0
        var hi = pages.count - 1
        while lo < hi {
            let mid = (lo + hi + 1) / 2
            if pages[mid].startOffset <= offset { lo = mid } else { hi = mid - 1 }
        }
        return lo
    }
}

/// Global UTF-16 offsets of a chapter's paragraphs, so a place in the text
/// survives re-pagination, rotation and switching between Scroll and Pages.
struct TextOffsets: Sendable, Equatable {
    private var starts: [Int]
    private(set) var total: Int

    init(lengths: [Int]) {
        var sum = 0
        starts = lengths.map { length in
            defer { sum += max(length, 0) }
            return sum
        }
        total = sum
    }

    init(paragraphs: [String]) {
        self.init(lengths: paragraphs.map { $0.utf16.count })
    }

    var paragraphCount: Int { starts.count }

    func paragraphStart(_ index: Int) -> Int {
        guard !starts.isEmpty else { return 0 }
        return starts[min(max(index, 0), starts.count - 1)]
    }

    /// The paragraph holding `offset` (a paragraph's own start belongs to it).
    func paragraphAt(_ offset: Int) -> Int {
        guard !starts.isEmpty else { return 0 }
        var lo = 0
        var hi = starts.count - 1
        while lo < hi {
            let mid = (lo + hi + 1) / 2
            if starts[mid] <= offset { lo = mid } else { hi = mid - 1 }
        }
        return lo
    }
}

/// What a tap or swipe on the reading surface does.
enum TapZones {
    enum Action: Equatable {
        case previous, next, toggleChrome
    }

    /// The top of the screen, across its whole width, shows / hides the chrome
    /// (as on a Kindle): with only the middle third doing it, a tap a little
    /// off-centre turned a page instead and showing the controls felt hit and miss.
    static let menuBand: CGFloat = 0.2

    /// The top band and the middle third toggle the chrome; the rest of the
    /// outer thirds turn pages. Left-to-right reading goes forward on the
    /// right; right-to-left (manga) goes forward on the left. `y`/`height`
    /// may be left out (no top band).
    static func action(x: CGFloat, width: CGFloat, y: CGFloat? = nil, height: CGFloat? = nil,
                       rightToLeft: Bool = false) -> Action {
        guard width > 0 else { return .toggleChrome }
        if let y, let height, height > 0, y < height * menuBand { return .toggleChrome }
        let third = width / 3
        let side = x < third ? -1 : (x >= width - third ? 1 : 0)
        guard side != 0 else { return .toggleChrome }
        let forward = rightToLeft ? side < 0 : side > 0
        return forward ? .next : .previous
    }

    /// A horizontal swipe of `dx` points (positive = finger moved right);
    /// nil when too short. Dragging the page leftwards shows the next one
    /// in left-to-right reading, rightwards in right-to-left.
    static func swipe(dx: CGFloat, threshold: CGFloat, rightToLeft: Bool = false) -> Action? {
        guard abs(dx) >= threshold else { return nil }
        let forward = rightToLeft ? dx > 0 : dx < 0
        return forward ? .next : .previous
    }
}
