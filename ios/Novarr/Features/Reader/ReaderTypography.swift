import SwiftUI
import UIKit

/// The reader's body text in UIKit terms. Both modes set text with TextKit
/// (selectable paragraphs in Scroll, measured pages in Pages) from this one
/// value, so a paragraph looks — and measures — the same in either.
struct ReaderTypography: Hashable, Sendable {
    var font: ReaderFont
    /// Point size after Dynamic Type scaling.
    var size: CGFloat
    var spacing: ReaderSpacing
    var justify: Bool

    var bodyFont: UIFont { font.uiFont(size: size) }
    var lineSpacing: CGFloat { size * spacing.factor }
    /// Space between paragraphs (the scroll stack's spacing).
    var paragraphGap: CGFloat { size * 0.9 }

    /// Justified text is hyphenated so its lines don't open up wide gaps.
    func paragraphStyle() -> NSParagraphStyle {
        let style = NSMutableParagraphStyle()
        style.lineSpacing = lineSpacing
        style.alignment = justify ? .justified : .natural
        style.hyphenationFactor = justify ? 1 : 0
        return style
    }

    /// Layout attributes only: colour never changes where lines break.
    func attributes() -> [NSAttributedString.Key: Any] {
        [.font: bodyFont, .paragraphStyle: paragraphStyle()]
    }

    /// A paragraph as shown in Scroll mode (highlights are drawn by `HighlightDrawing`).
    func attributed(_ text: String, color: UIColor) -> NSAttributedString {
        let string = NSMutableAttributedString(string: text, attributes: attributes())
        string.addAttribute(.foregroundColor, value: color, range: NSRange(location: 0, length: string.length))
        return string
    }
}

/// Paints highlight tints behind TextKit 1 text, line by line, so each box
/// hugs the marked words (not the line spacing, nor the rest of a line
/// a highlight runs on from). Shared by both reading modes.
enum HighlightDrawing {
    static func fill(_ ranges: [NSRange], manager: NSLayoutManager, container: NSTextContainer,
                     within glyphs: NSRange, origin: CGPoint, lineHeight: CGFloat, tint: UIColor) {
        guard !ranges.isEmpty, let storage = manager.textStorage else { return }
        tint.setFill()
        for range in ranges where NSMaxRange(range) <= storage.length {
            let marked = NSIntersectionRange(manager.glyphRange(forCharacterRange: range, actualCharacterRange: nil), glyphs)
            guard marked.length > 0 else { continue }
            manager.enumerateLineFragments(forGlyphRange: marked) { _, _, _, line, _ in
                let part = NSIntersectionRange(line, marked)
                guard part.length > 0 else { return }
                var box = manager.boundingRect(forGlyphRange: part, in: container)
                    .offsetBy(dx: origin.x, dy: origin.y)
                box.size.height = min(box.height, lineHeight + 2)
                UIBezierPath(roundedRect: box.insetBy(dx: -1, dy: 0), cornerRadius: 3).fill()
            }
        }
    }
}

/// The scroll mode's layout manager: draws the paragraph's highlights
/// under its text.
final class HighlightingLayoutManager: NSLayoutManager {
    var highlights: [NSRange] = []
    var tint: UIColor = .systemYellow
    var lineHeight: CGFloat = 0

    override func drawBackground(forGlyphRange glyphsToShow: NSRange, at origin: CGPoint) {
        super.drawBackground(forGlyphRange: glyphsToShow, at: origin)
        guard let container = textContainers.first else { return }
        HighlightDrawing.fill(highlights, manager: self, container: container, within: glyphsToShow,
                              origin: origin, lineHeight: lineHeight, tint: tint)
    }
}

extension ReaderFont {
    /// UIKit twin of `font(size:)` for the TextKit-drawn text; the two must
    /// name the same face.
    func uiFont(size: CGFloat, weight: UIFont.Weight = .regular) -> UIFont {
        switch self {
        case .literata:
            let name = weight >= .bold ? BrandFont.literataBold
                : weight >= .semibold ? BrandFont.literataSemiBold : BrandFont.literataRegular
            return UIFont(name: name, size: size) ?? .systemFont(ofSize: size, weight: weight)
        case .newYork:
            let system = UIFont.systemFont(ofSize: size, weight: weight)
            return system.fontDescriptor.withDesign(.serif).map { UIFont(descriptor: $0, size: size) } ?? system
        case .sanFrancisco:
            return .systemFont(ofSize: size, weight: weight)
        case .georgia:
            let name = weight >= .semibold ? "Georgia-Bold" : "Georgia"
            return UIFont(name: name, size: size) ?? .systemFont(ofSize: size, weight: weight)
        }
    }
}

extension ReaderTheme {
    /// Tint behind highlighted passages: a warm marker colour, lighter on
    /// the light themes and dimmer on the dark ones so the text stays readable.
    var highlightTint: UIColor {
        let light = UIColor(red: 1, green: 0.82, blue: 0.2, alpha: 0.42)
        let dark = UIColor(red: 0.96, green: 0.74, blue: 0.16, alpha: 0.3)
        switch self {
        case .system: return UIColor { $0.userInterfaceStyle == .dark ? dark : light }
        case .light, .sepia: return light
        case .dark, .black: return dark
        }
    }

    var uiText: UIColor { UIColor(text) }
}

/// The chapter heading's spacing (points), shared by the SwiftUI header and
/// the paged layout's measurement of it.
enum ReaderHeaderMetrics {
    static let top: CGFloat = 20
    static let gap: CGFloat = 8
    /// 4 pt padding + the 3 pt rule.
    static let rule: CGFloat = 7
    static let bottom: CGFloat = 12

    /// The heading's height at `width`: series name (caption, up to two
    /// lines), chapter title and rule, as `ReaderView.header` lays them out.
    static func height(novelName: String, title: String, width: CGFloat,
                       typography: ReaderTypography, captionSize: CGFloat) -> CGFloat {
        let bounds = CGSize(width: max(width, 1), height: .greatestFiniteMagnitude)
        var label: CGFloat = 0
        if !novelName.isEmpty {
            // The header's caption is Geist SemiBold (`Font.ui(.caption, .semibold)`).
            let font = UIFont(name: BrandFont.geistSemiBold, size: captionSize)
                ?? .systemFont(ofSize: captionSize, weight: .semibold)
            let measured = NSAttributedString(string: novelName.uppercased(), attributes: [.font: font, .kern: 1])
                .boundingRect(with: bounds, options: [.usesLineFragmentOrigin, .usesFontLeading], context: nil)
            label = min(ceil(measured.height), ceil(font.lineHeight * 2)) + gap
        }
        let titleFont = typography.font.uiFont(size: typography.size * 1.45, weight: .semibold)
        let titleHeight = NSAttributedString(string: title, attributes: [.font: titleFont])
            .boundingRect(with: bounds, options: [.usesLineFragmentOrigin, .usesFontLeading], context: nil).height
        // A little slack: SwiftUI rounds each piece up on its own.
        return top + label + ceil(titleHeight) + gap + rule + bottom + 4
    }
}
