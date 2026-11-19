import Foundation
import Testing
@testable import Novarr

private func highlight(_ id: Int, _ excerpt: String, chapterId: Int = 1) -> Highlight {
    Highlight(id: id, novelId: 1, novelName: "", chapterId: chapterId, chapterLabel: "", excerpt: excerpt,
              note: nil, createdAt: nil)
}

private typealias Location = HighlightMatcher.Location

@Suite("Highlight excerpt matching")
struct HighlightMatcherTests {
    let paragraphs = [
        "The keeper climbed the stairs.",
        "Waves broke on the rocks below. He counted them.",
        "Morning came at last.",
    ]

    @Test func singleParagraph() throws {
        let found = try #require(HighlightMatcher.locate("broke on the rocks", in: paragraphs))
        #expect(found.start == Location(paragraph: 1, offset: 6))
        #expect(found.end == Location(paragraph: 1, offset: 24))
    }

    @Test func spansTwoParagraphs() throws {
        // As a browser copies it: a blank line between the paragraphs.
        let found = try #require(HighlightMatcher.locate("He counted them.\n\nMorning came", in: paragraphs))
        #expect(found.start == Location(paragraph: 1, offset: 32))
        #expect(found.end == Location(paragraph: 2, offset: 12))
        let ranges = HighlightMatcher.ranges(
            for: [HighlightMatcher.Match(id: 1, start: found.start, end: found.end)],
            lengths: paragraphs.map { $0.utf16.count })
        #expect(ranges[1] == [NSRange(location: 32, length: 16)])
        #expect(ranges[2] == [NSRange(location: 0, length: 12)])
        #expect(ranges[0] == nil)
    }

    @Test func whitespaceDifferencesAreIgnored() throws {
        let text = ["A  line\u{00A0}with\tspaces", "and  more."]
        let found = try #require(HighlightMatcher.locate("  line with \n spaces and more.  ", in: text))
        #expect(found.start == Location(paragraph: 0, offset: 3))
        #expect(found.end == Location(paragraph: 1, offset: 10))
        #expect(HighlightMatcher.normalize(" a \n\n b\u{00A0}\u{00A0}c ") == "a b c")
    }

    @Test func notFoundIsSkipped() {
        #expect(HighlightMatcher.locate("the lighthouse", in: paragraphs) == nil)
        #expect(HighlightMatcher.locate("   ", in: paragraphs) == nil)
        #expect(HighlightMatcher.locate("anything", in: []) == nil)
        let matches = HighlightMatcher.matches([highlight(1, "not in this chapter"), highlight(2, "Morning came")],
                                               in: paragraphs)
        #expect(matches.map(\.id) == [2])
    }

    @Test func overlappingHighlightsMerge() {
        let matches = HighlightMatcher.matches(
            [highlight(1, "Waves broke on"), highlight(2, "on the rocks below."), highlight(3, "He counted")],
            in: paragraphs)
        #expect(matches.map(\.id) == [1, 2, 3])
        let ranges = HighlightMatcher.ranges(for: matches, lengths: paragraphs.map { $0.utf16.count })
        // 0..<14 and 12..<31 overlap into one; "He counted" stays apart.
        #expect(ranges[1] == [NSRange(location: 0, length: 31), NSRange(location: 32, length: 10)])
    }

    @Test func firstOccurrenceWins() throws {
        let found = try #require(HighlightMatcher.locate("the", in: ["Climb the stairs", "the end"]))
        #expect(found.start == Location(paragraph: 0, offset: 6))
    }

    @Test func offsetsAreUTF16() throws {
        // An emoji is two UTF-16 units: offsets after it shift by two.
        let found = try #require(HighlightMatcher.locate("waves", in: ["🌊 waves"]))
        #expect(found.start == Location(paragraph: 0, offset: 3))
        #expect(found.end == Location(paragraph: 0, offset: 8))
    }
}

@Suite("Pages")
struct PaginationTests {
    /// A paragraph of `lines` lines, each `height` tall.
    private func block(start: Int, lines: Int, height: CGFloat = 10, chars: Int = 5, gap: CGFloat = 4) -> Pagination.Block {
        Pagination.Block(startOffset: start,
                         lineTops: (0..<lines).map { CGFloat($0) * height },
                         lineBottoms: (0..<lines).map { CGFloat($0 + 1) * height },
                         lineStarts: (0..<lines).map { $0 * chars },
                         spacingAfter: gap)
    }

    @Test func breaksBetweenWholeLines() {
        let blocks = [Pagination.Block.single(startOffset: 0, height: 25, spacingAfter: 4),
                      block(start: 0, lines: 6), block(start: 30, lines: 3)]
        let pages = Pagination.paginate(blocks, pageHeight: 50)
        // Page 1: heading (25) + gap + two lines (20) = 49. Page 2: four lines,
        // gap, then no room for another. Page 3: the second paragraph.
        #expect(pages.count == 3)
        #expect(pages[0].slices.map(\.block) == [0, 1])
        #expect(pages[0].slices[1].toLine == 2)
        #expect(pages[1].startOffset == 10)
        #expect(pages[1].slices == [Pagination.Slice(block: 1, fromLine: 2, toLine: 6, y: 0, height: 40)])
        #expect(pages[2].startOffset == 30)
    }

    @Test func gapIsDroppedAtAPageBreak() {
        let pages = Pagination.paginate([block(start: 0, lines: 5), block(start: 25, lines: 1)], pageHeight: 50)
        #expect(pages.count == 2)
        #expect(pages[1].slices.first?.y == 0)
    }

    @Test func tallLineGetsAPageOfItsOwn() {
        let tall = Pagination.Block(startOffset: 0, lineTops: [0], lineBottoms: [80], lineStarts: [0])
        let pages = Pagination.paginate([tall, block(start: 9, lines: 1)], pageHeight: 50)
        #expect(pages.count == 2)
        #expect(pages[0].slices.first?.height == 80)
    }

    @Test func alwaysAtLeastOnePage() {
        #expect(Pagination.paginate([], pageHeight: 100).count == 1)
    }

    @Test func pageForAnOffsetIsTheLastStartingAtOrBeforeIt() {
        let pages = [0, 40, 95].map { Pagination.Page(slices: [], startOffset: $0) }
        #expect(Pagination.page(for: 0, in: pages) == 0)
        #expect(Pagination.page(for: 39, in: pages) == 0)
        #expect(Pagination.page(for: 40, in: pages) == 1)
        #expect(Pagination.page(for: 500, in: pages) == 2)
        #expect(Pagination.page(for: 3, in: []) == 0)
    }

    @Test func textOffsets() {
        let offsets = TextOffsets(paragraphs: ["abc", "", "de", "🌊"])
        #expect(offsets.total == 7)
        #expect(offsets.paragraphStart(2) == 3)
        #expect(offsets.paragraphStart(9) == 5) // clamped
        #expect(offsets.paragraphAt(0) == 0)
        #expect(offsets.paragraphAt(3) == 2) // an empty paragraph shares its start with the next
        #expect(offsets.paragraphAt(6) == 3)
        #expect(TextOffsets(lengths: []).paragraphAt(4) == 0)
    }

    @Test func tapZonesAndSwipes() {
        #expect(TapZones.action(x: 10, width: 300) == .previous)
        #expect(TapZones.action(x: 150, width: 300) == .toggleChrome)
        #expect(TapZones.action(x: 299, width: 300) == .next)
        #expect(TapZones.action(x: 10, width: 0) == .toggleChrome)
        #expect(TapZones.swipe(dx: -80, threshold: 48) == .next)
        #expect(TapZones.swipe(dx: 80, threshold: 48) == .previous)
        #expect(TapZones.swipe(dx: 20, threshold: 48) == nil)
    }
}

@Suite("Highlight look-ups and writes")
struct HighlightWriteTests {
    @Test func lookupHoldsUntilMovingOn() {
        var hold = PositionHold()
        hold.startLookup()
        #expect(hold.isHolding)
        // Nothing about the look-up chapter goes out, leaving included.
        #expect(hold.leaving() == .discard)
        // Moving to another chapter drops the look-up's position and ends it.
        #expect(hold.moveOn() == .discard)
        #expect(!hold.lookup)
        #expect(!hold.isHolding)
        #expect(hold.leaving() == .send)
        #expect(hold.moveOn() == .send)
    }

    @Test func draftValidation() throws {
        let draft = try #require(HighlightDraft(chapterId: 3, excerpt: "  Some text \n", note: "  "))
        #expect(draft.excerpt == "Some text")
        #expect(draft.note == nil)
        #expect(HighlightDraft(chapterId: 3, excerpt: " \n ", note: nil) == nil)
        let limit = String(repeating: "a", count: HighlightDraft.maxLength)
        #expect(HighlightDraft(chapterId: 3, excerpt: limit, note: limit) != nil)
        #expect(HighlightDraft(chapterId: 3, excerpt: limit + "a", note: nil) == nil)
        #expect(HighlightDraft(chapterId: 3, excerpt: "ok", note: limit + "a") == nil)
        // Counted in code points, as Laravel does: an emoji is one.
        #expect(HighlightDraft.length("🌊🌊") == 2)
    }

    @Test func highlightIsNotAPositionWrite() throws {
        let draft = try #require(HighlightDraft(chapterId: 5, excerpt: "x", note: nil))
        let kind = PendingWrite.Kind.highlight(draft)
        #expect(!kind.movesPosition)
        let write = PendingWrite(id: UUID(), kind: kind, recordedAt: Date())
        #expect(!PendingWrite.containsChapterWrite([write], chapterId: 5))
        #expect(PendingWrite.highlightDrafts([write], chapterId: 5) == [draft])
        #expect(PendingWrite.highlightDrafts([write], chapterId: 6).isEmpty)
    }

    @Test func highlightWritesPersistAndOlderQueuesStillDecode() async throws {
        // A queue file written before highlights existed.
        let file = temporaryFile()
        try FileManager.default.createDirectory(at: file.deletingLastPathComponent(), withIntermediateDirectories: true)
        let legacy = """
        [{"id":"6A1C2C1E-1D52-4C1B-8E59-1E4C5B7A9F01","kind":{"progress":{"chapterId":7,"progress":40,"read":false}},"recordedAt":1800000000000,"novelId":2},
         {"id":"6A1C2C1E-1D52-4C1B-8E59-1E4C5B7A9F02","kind":{"setRead":{"chapterId":8,"read":true}},"recordedAt":1800000001000}]
        """
        try Data(legacy.utf8).write(to: file)
        let queue = WriteQueue(fileURL: file)
        #expect(await queue.items.map(\.kind) == [.progress(chapterId: 7, progress: 40, read: false),
                                                   .setRead(chapterId: 8, read: true)])

        let draft = try #require(HighlightDraft(chapterId: 7, excerpt: "A passage", note: "why"))
        await queue.enqueue(.highlight(draft), novelId: 2)
        let reopened = WriteQueue(fileURL: file)
        #expect(await reopened.count == 3)
        #expect(await reopened.items.last?.kind == .highlight(draft))
    }

    @Test func clientPostsTheHighlight() async throws {
        let client = StubURLProtocol.client { request, body in
            #expect(request.httpMethod == "POST")
            #expect(request.url?.path == "/api/v1/highlights")
            let json = try JSONSerialization.jsonObject(with: body ?? Data()) as? [String: Any]
            #expect(json?["chapter_id"] as? Int == 7)
            #expect(json?["excerpt"] as? String == "A passage")
            #expect(json?["note"] as? String == "why")
            #expect(json?["age"] == nil) // a highlight isn't a position
            return (201, Data(#"{"highlight":{"id":9,"chapter_id":7,"excerpt":"A passage"}}"#.utf8))
        }
        let draft = try #require(HighlightDraft(chapterId: 7, excerpt: "A passage", note: "why"))
        try await client.send(.highlight(draft), age: 1200)
    }
}
