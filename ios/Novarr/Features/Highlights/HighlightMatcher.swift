import Foundation

/// Finds saved highlights in a chapter's paragraphs, so the reader can tint
/// them and open at one. A highlight is stored as plain excerpt text (the
/// website keeps whatever the browser selected, often with line breaks
/// between paragraphs), so it is matched by text: whitespace runs count as
/// one space on both sides and an excerpt may run across paragraphs. The
/// first occurrence wins; an excerpt that isn't found is skipped.
enum HighlightMatcher {
    /// A place in the chapter: paragraph index and UTF-16 offset inside it.
    struct Location: Equatable, Comparable, Sendable {
        var paragraph: Int
        var offset: Int

        static func < (a: Location, b: Location) -> Bool {
            (a.paragraph, a.offset) < (b.paragraph, b.offset)
        }
    }

    /// Where one highlight sits: `start` up to (not including) `end`.
    struct Match: Equatable, Sendable {
        var id: Int
        var start: Location
        var end: Location
    }

    /// The chapter's text with whitespace collapsed, and where each of its
    /// scalars came from, so a match maps back to the paragraphs.
    struct Index: Sendable {
        fileprivate var text: String
        /// For scalar `i` of `text`: its paragraph and UTF-16 range there.
        /// A space standing for a paragraph break points at the end of the
        /// paragraph before it.
        fileprivate var sources: [(paragraph: Int, from: Int, to: Int)]

        init(paragraphs: [String]) {
            var scalars = String.UnicodeScalarView()
            var sources: [(paragraph: Int, from: Int, to: Int)] = []
            var lastWasSpace = true // no leading space
            for (p, paragraph) in paragraphs.enumerated() {
                var offset = 0
                for scalar in paragraph.unicodeScalars {
                    let width = scalar.utf16.count
                    defer { offset += width }
                    if scalar.properties.isWhitespace {
                        guard !lastWasSpace else { continue }
                        scalars.append(" ")
                        sources.append((p, offset, offset + width))
                        lastWasSpace = true
                    } else {
                        scalars.append(scalar)
                        sources.append((p, offset, offset + width))
                        lastWasSpace = false
                    }
                }
                // A paragraph break reads as one space.
                if !lastWasSpace {
                    scalars.append(" ")
                    sources.append((p, offset, offset))
                    lastWasSpace = true
                }
            }
            text = String(scalars)
            self.sources = sources
        }

        /// Where `excerpt` first occurs, or nil.
        func locate(_ excerpt: String) -> (start: Location, end: Location)? {
            let needle = HighlightMatcher.normalize(excerpt)
            guard !needle.isEmpty, let range = text.range(of: needle) else { return nil }
            let first = text.unicodeScalars.distance(from: text.startIndex, to: range.lowerBound)
            let last = text.unicodeScalars.distance(from: text.startIndex, to: range.upperBound) - 1
            guard first >= 0, last >= first, last < sources.count else { return nil }
            let a = sources[first], b = sources[last]
            return (Location(paragraph: a.paragraph, offset: a.from), Location(paragraph: b.paragraph, offset: b.to))
        }
    }

    /// Whitespace runs (spaces, line breaks, no-break spaces…) become one
    /// space; leading and trailing whitespace goes.
    static func normalize(_ text: String) -> String {
        var scalars = String.UnicodeScalarView()
        var pendingSpace = false
        for scalar in text.unicodeScalars {
            if scalar.properties.isWhitespace {
                pendingSpace = !scalars.isEmpty
            } else {
                if pendingSpace { scalars.append(" ") }
                pendingSpace = false
                scalars.append(scalar)
            }
        }
        return String(scalars)
    }

    /// Where `excerpt` first occurs in `paragraphs`, or nil.
    static func locate(_ excerpt: String, in paragraphs: [String]) -> (start: Location, end: Location)? {
        Index(paragraphs: paragraphs).locate(excerpt)
    }

    /// The highlights found in `paragraphs`, in the order given; ones not found are left out.
    static func matches(_ highlights: [Highlight], in paragraphs: [String]) -> [Match] {
        guard !highlights.isEmpty, !paragraphs.isEmpty else { return [] }
        let index = Index(paragraphs: paragraphs)
        return highlights.compactMap { highlight in
            index.locate(highlight.excerpt).map { Match(id: highlight.id, start: $0.start, end: $0.end) }
        }
    }

    /// The UTF-16 ranges to tint in each paragraph, sorted, with overlapping
    /// or touching ranges merged. `lengths` are the paragraphs' UTF-16 lengths.
    static func ranges(for matches: [Match], lengths: [Int]) -> [Int: [NSRange]] {
        var raw: [Int: [NSRange]] = [:]
        for match in matches {
            guard match.start.paragraph < lengths.count, match.end.paragraph < lengths.count else { continue }
            for p in match.start.paragraph...match.end.paragraph {
                let from = p == match.start.paragraph ? match.start.offset : 0
                let to = p == match.end.paragraph ? match.end.offset : lengths[p]
                let lower = min(max(from, 0), lengths[p]), upper = min(max(to, 0), lengths[p])
                if upper > lower { raw[p, default: []].append(NSRange(location: lower, length: upper - lower)) }
            }
        }
        return raw.mapValues { ranges in
            var merged: [NSRange] = []
            for range in ranges.sorted(by: { $0.location < $1.location }) {
                if let last = merged.last, range.location <= NSMaxRange(last) {
                    merged[merged.count - 1] = NSRange(
                        location: last.location, length: max(NSMaxRange(last), NSMaxRange(range)) - last.location)
                } else {
                    merged.append(range)
                }
            }
            return merged
        }
    }
}
