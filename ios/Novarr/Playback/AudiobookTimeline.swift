import Foundation

/// An audiobook's whole-book timeline: parts (files, played back to back as
/// one playlist) and chapters (positions in the whole book, across parts).
/// Everything the player shows or saves is a whole-book position; the
/// player itself only knows (part index, offset in that part). Pure; mirrors
/// android domain/AudiobookTimeline.kt.
struct AudiobookTimeline: Sendable, Equatable {
    /// One part: where it starts in the whole book and how long it is.
    struct Span: Sendable, Equatable {
        var index: Int
        var startMs: Int64
        var durationMs: Int64
        var endMs: Int64 { startMs + durationMs }
    }

    /// A position in one part.
    struct PartPosition: Sendable, Equatable {
        var partIndex: Int
        var offsetMs: Int64
    }

    /// Within this much of a chapter's start, "previous" goes to the chapter before.
    static let restartWindowMs: Int64 = 3_000
    /// A few ms of rounding between a part's start and the one before's end is not an inconsistency.
    private static let toleranceMs: Int64 = 1_000

    let parts: [Span]
    let totalMs: Int64
    /// The server's chapters (sorted, clamped to the book), or one per part when it sent none.
    let chapters: [AudiobookChapter]

    init(parts: [Span], chapters: [AudiobookChapter]) {
        self.parts = parts
        let total = parts.last?.endMs ?? 0
        totalMs = total
        self.chapters = Self.normalise(chapters, parts: parts, total: total)
    }

    static func of(_ detail: AudiobookDetail) -> AudiobookTimeline { from(detail.parts, detail.chapters) }

    /// Builds the parts' spans. The server's start_ms is used when it is
    /// consistent (first at 0, never going back); otherwise the parts are
    /// laid end to end by their durations.
    static func from(_ parts: [AudiobookPart], _ chapters: [AudiobookChapter]) -> AudiobookTimeline {
        let sorted = parts.sorted { $0.index < $1.index }
        let starts = sorted.map(\.startMs)
        var consistent = !sorted.isEmpty && starts.first == 0
        if consistent {
            for i in 0..<max(sorted.count - 1, 0) {
                if starts[i + 1] < starts[i] { consistent = false; break }
                if sorted[i].startMs + sorted[i].durationMs > sorted[i + 1].startMs + toleranceMs { consistent = false; break }
            }
            if sorted.count > 1, (starts.last ?? 0) <= 0 { consistent = false }
        }
        var cursor: Int64 = 0
        var spans: [Span] = []
        for (i, part) in sorted.enumerated() {
            let start = consistent ? part.startMs : cursor
            let duration = max(part.durationMs, 0)
            cursor = start + duration
            let length = consistent && i < sorted.count - 1 ? max(sorted[i + 1].startMs - start, 0) : duration
            spans.append(Span(index: i, startMs: start, durationMs: length))
        }
        return AudiobookTimeline(parts: spans, chapters: chapters)
    }

    /// Whole-book position → part and offset. Clamped to the book; the very end is the last part's end.
    func toPart(_ positionMs: Int64) -> PartPosition {
        guard !parts.isEmpty else { return PartPosition(partIndex: 0, offsetMs: 0) }
        let position = min(max(positionMs, 0), totalMs)
        // The last part that starts at or before the position (zero-length parts are skipped over).
        var found = 0
        for (i, span) in parts.enumerated() {
            if span.startMs <= position && (span.durationMs > 0 || i == parts.count - 1) {
                found = i
            } else if span.startMs > position {
                break
            }
        }
        let span = parts[found]
        return PartPosition(partIndex: found, offsetMs: min(max(position - span.startMs, 0), max(span.durationMs, 0)))
    }

    /// Part and offset → whole-book position, clamped to the part and the book.
    func toBook(_ partIndex: Int, _ offsetMs: Int64) -> Int64 {
        guard parts.indices.contains(partIndex) else { return partIndex < 0 ? 0 : totalMs }
        let span = parts[partIndex]
        let offset = span.durationMs > 0 ? min(max(offsetMs, 0), span.durationMs) : max(offsetMs, 0)
        return span.startMs + offset
    }

    /// The chapter playing at `positionMs` (the last one starting at or before it); -1 when there are none.
    func chapterIndexAt(_ positionMs: Int64) -> Int {
        guard !chapters.isEmpty else { return -1 }
        var found = 0
        for (i, chapter) in chapters.enumerated() {
            if chapter.startMs <= positionMs { found = i } else { break }
        }
        return found
    }

    func chapterAt(_ positionMs: Int64) -> AudiobookChapter? {
        let i = chapterIndexAt(positionMs)
        return chapters.indices.contains(i) ? chapters[i] : nil
    }

    /// Where "previous chapter" goes: the current chapter's start when more than
    /// `restartWindowMs` into it, else the previous chapter's start.
    func previousChapterTarget(_ positionMs: Int64, restartWindowMs: Int64 = AudiobookTimeline.restartWindowMs) -> Int64 {
        let i = chapterIndexAt(positionMs)
        guard i >= 0 else { return 0 }
        let current = chapters[i]
        return positionMs - current.startMs > restartWindowMs || i == 0 ? current.startMs : chapters[i - 1].startMs
    }

    /// Where "next chapter" goes: the next chapter's start; nil in the last chapter.
    func nextChapterTarget(_ positionMs: Int64) -> Int64? {
        let i = chapterIndexAt(positionMs) + 1
        return chapters.indices.contains(i) ? chapters[i].startMs : nil
    }

    /// The chapter's end at `positionMs` (the end of chapter sleep timer); the book's end without chapters.
    func chapterEndAt(_ positionMs: Int64) -> Int64 { chapterAt(positionMs)?.endMs ?? totalMs }

    /// Elapsed and length of the chapter at `positionMs`, for the chapter scrubber.
    func chapterProgress(_ positionMs: Int64) -> (elapsed: Int64, length: Int64) {
        guard let chapter = chapterAt(positionMs) else { return (min(max(positionMs, 0), totalMs), totalMs) }
        let length = max(chapter.endMs - chapter.startMs, 0)
        return (min(max(positionMs - chapter.startMs, 0), length), length)
    }

    /// A skip of `deltaMs` (negative: back) from `positionMs`, kept inside the book.
    func skip(_ positionMs: Int64, _ deltaMs: Int64) -> Int64 { min(max(positionMs + deltaMs, 0), totalMs) }

    private static func normalise(_ chapters: [AudiobookChapter], parts: [Span], total: Int64) -> [AudiobookChapter] {
        let usable = chapters.filter { $0.startMs >= 0 && (total == 0 || $0.startMs < total) }
            .enumerated().sorted { ($0.element.startMs, $0.offset) < ($1.element.startMs, $1.offset) }.map(\.element)
        if usable.isEmpty {
            return parts.enumerated().map { i, span in
                AudiobookChapter(index: i, title: "Part \(i + 1)", startMs: span.startMs, endMs: span.endMs)
            }
        }
        // A missing or bad end becomes the next chapter's start (or the book's end).
        return usable.enumerated().map { i, chapter in
            let nextStart = i + 1 < usable.count ? usable[i + 1].startMs : total
            let end = chapter.endMs > chapter.startMs && chapter.endMs <= max(nextStart, chapter.startMs) ? chapter.endMs : nextStart
            var copy = chapter
            copy.index = i
            copy.endMs = end
            return copy
        }
    }
}
