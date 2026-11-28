import Foundation
import Testing
@testable import Novarr

/// Whole-book position <-> (part, offset), chapters and skips across parts (android AudiobookTimelineTest).
@Suite("Audiobook timeline")
struct AudiobookTimelineTests {
    typealias PartPosition = AudiobookTimeline.PartPosition

    static func part(_ index: Int, _ start: Int64, _ duration: Int64) -> AudiobookPart {
        AudiobookPart(index: index, url: URL(string: "https://h/p/\(index)"), durationMs: duration, startMs: start)
    }

    // Three files: 0-10 min, 10-25 min, 25-30 min.
    let parts = [part(0, 0, 600_000), part(1, 600_000, 900_000), part(2, 1_500_000, 300_000)]
    let chapters = [
        AudiobookChapter(index: 0, title: "Opening", startMs: 0, endMs: 400_000),
        AudiobookChapter(index: 1, title: "Two", startMs: 400_000, endMs: 1_000_000),
        AudiobookChapter(index: 2, title: "Three", startMs: 1_000_000, endMs: 1_800_000),
    ]
    var timeline: AudiobookTimeline { AudiobookTimeline.from(parts, chapters) }

    @Test func totalIsTheEndOfTheLastPart() {
        #expect(timeline.totalMs == 1_800_000)
    }

    @Test func bookPositionsMapToTheirPart() {
        #expect(timeline.toPart(0) == PartPosition(partIndex: 0, offsetMs: 0))
        #expect(timeline.toPart(599_999) == PartPosition(partIndex: 0, offsetMs: 599_999))
        // A part's exact end is the next part's start.
        #expect(timeline.toPart(600_000) == PartPosition(partIndex: 1, offsetMs: 0))
        #expect(timeline.toPart(1_000_000) == PartPosition(partIndex: 1, offsetMs: 400_000))
        #expect(timeline.toPart(1_600_000) == PartPosition(partIndex: 2, offsetMs: 100_000))
    }

    @Test func positionsOutsideTheBookAreClamped() {
        #expect(timeline.toPart(-5_000) == PartPosition(partIndex: 0, offsetMs: 0))
        #expect(timeline.toPart(1_800_000) == PartPosition(partIndex: 2, offsetMs: 300_000))
        #expect(timeline.toPart(9_999_999) == PartPosition(partIndex: 2, offsetMs: 300_000))
    }

    @Test func partOffsetsMapBackToTheBook() {
        #expect(timeline.toBook(0, 0) == 0)
        #expect(timeline.toBook(1, 400_000) == 1_000_000)
        #expect(timeline.toBook(2, 100_000) == 1_600_000)
        // An offset past a part's length is clamped to it.
        #expect(timeline.toBook(1, 2_000_000) == 1_500_000)
        // An unknown part: the start or the end of the book.
        #expect(timeline.toBook(-1, 10) == 0)
        #expect(timeline.toBook(7, 10) == 1_800_000)
    }

    @Test func roundTripsForEveryPosition() {
        let t = timeline
        for position in stride(from: Int64(0), through: 1_800_000, by: 7_919) {
            let part = t.toPart(position)
            #expect(t.toBook(part.partIndex, part.offsetMs) == position)
        }
    }

    @Test func inconsistentStartsFallBackToDurationsLaidEndToEnd() {
        // An older or buggy payload: every start_ms 0.
        let laid = AudiobookTimeline.from([Self.part(0, 0, 1_000), Self.part(1, 0, 2_000), Self.part(2, 0, 500)], [])
        #expect(laid.parts.map(\.startMs) == [0, 1_000, 3_000])
        #expect(laid.totalMs == 3_500)
        #expect(laid.toPart(1_500) == PartPosition(partIndex: 1, offsetMs: 500))
        // Out of order in the payload: sorted by index first.
        let shuffled = AudiobookTimeline.from([Self.part(1, 1_000, 2_000), Self.part(0, 0, 1_000)], [])
        #expect(shuffled.parts.map(\.startMs) == [0, 1_000])
    }

    @Test func chapterLookup() {
        let t = timeline
        #expect(t.chapterIndexAt(0) == 0)
        #expect(t.chapterIndexAt(399_999) == 0)
        #expect(t.chapterIndexAt(400_000) == 1)
        #expect(t.chapterIndexAt(1_799_000) == 2)
        #expect(t.chapterAt(1_200_000)?.title == "Three")
        #expect(t.chapterEndAt(500_000) == 1_000_000)
        let progress = t.chapterProgress(500_000)
        #expect(progress.elapsed == 100_000 && progress.length == 600_000)
    }

    @Test func previousChapterRestartsTheCurrentOneUnlessAtItsStart() {
        // 10 s into chapter 2: back to its start.
        #expect(timeline.previousChapterTarget(410_000) == 400_000)
        // 2 s into chapter 2: the chapter before.
        #expect(timeline.previousChapterTarget(402_000) == 0)
        // In the first chapter: its start.
        #expect(timeline.previousChapterTarget(1_000) == 0)
    }

    @Test func nextChapter() {
        #expect(timeline.nextChapterTarget(10) == 400_000)
        #expect(timeline.nextChapterTarget(400_000) == 1_000_000)
        #expect(timeline.nextChapterTarget(1_200_000) == nil)
    }

    @Test func skipsCrossPartsAndStayInTheBook() {
        #expect(timeline.skip(600_000, -30_000) == 570_000)
        #expect(timeline.toPart(timeline.skip(600_000, -30_000)) == PartPosition(partIndex: 0, offsetMs: 570_000))
        #expect(timeline.skip(10_000, -30_000) == 0)
        #expect(timeline.skip(1_790_000, 30_000) == 1_800_000)
    }

    @Test func withoutChaptersEachPartIsOne() {
        let plain = AudiobookTimeline.from(parts, [])
        #expect(plain.chapters.map(\.title) == ["Part 1", "Part 2", "Part 3"])
        #expect(plain.chapters.map(\.startMs) == [0, 600_000, 1_500_000])
        #expect(plain.chapterIndexAt(700_000) == 1)
    }

    @Test func badChapterEndsBecomeTheNextStart() {
        let messy = AudiobookTimeline.from(parts, [
            AudiobookChapter(index: 5, title: "B", startMs: 600_000, endMs: 0),
            AudiobookChapter(index: 4, title: "A", startMs: 0, endMs: 900_000),
            // Past the end of the book: dropped.
            AudiobookChapter(index: 6, title: "Ghost", startMs: 5_000_000, endMs: 6_000_000),
        ])
        #expect(messy.chapters.map(\.title) == ["A", "B"])
        #expect(messy.chapters.map(\.index) == [0, 1])
        #expect(messy.chapters.map(\.endMs) == [600_000, 1_800_000])
    }

    @Test func anEmptyBookIsSafe() {
        let empty = AudiobookTimeline.from([], [])
        #expect(empty.totalMs == 0)
        #expect(empty.toPart(1_000) == PartPosition(partIndex: 0, offsetMs: 0))
        #expect(empty.chapterIndexAt(0) == -1)
        #expect(empty.nextChapterTarget(0) == nil)
        #expect(empty.previousChapterTarget(0) == 0)
    }

    @Test func aSingleFileBook() {
        let single = AudiobookTimeline.from([Self.part(0, 0, 3_600_000)],
                                            [AudiobookChapter(index: 0, title: "Only", startMs: 0, endMs: 3_600_000)])
        #expect(single.toPart(1_800_000) == PartPosition(partIndex: 0, offsetMs: 1_800_000))
        #expect(single.totalMs == 3_600_000)
    }
}

/// The sleep timer (android SleepTimerTest).
@Suite("Sleep timer")
struct SleepTimerTests {
    let timeline = AudiobookTimeline.from(
        [AudiobookPart(index: 0, url: URL(string: "u"), durationMs: 1_000_000, startMs: 0)],
        [AudiobookChapter(index: 0, title: "A", startMs: 0, endMs: 300_000),
         AudiobookChapter(index: 1, title: "B", startMs: 300_000, endMs: 1_000_000)])

    @Test func offNeverStops() {
        #expect(SleepTimer.decide(.off, now: 10_000_000, positionMs: 999_999) == .keep)
        #expect(SleepTimer.label(.off, now: 0) == nil)
    }

    @Test func aDurationRunsFadesAndStops() throws {
        let timer = SleepTimer.minutes(15, now: 1_000)
        let endsAt = try #require(timer.endsAt)
        #expect(endsAt == 1_000 + 15 * 60_000)
        #expect(SleepTimer.decide(timer, now: 1_000 + 14 * 60_000, positionMs: 0) == .keep)
        guard case .fade(let volume) = SleepTimer.decide(timer, now: endsAt - 5_000, positionMs: 0) else {
            Issue.record("expected a fade")
            return
        }
        #expect(volume >= 0.4 && volume <= 0.6)
        #expect(SleepTimer.decide(timer, now: endsAt, positionMs: 0) == .stop)
        #expect(SleepTimer.decide(timer, now: endsAt + 60_000, positionMs: 0) == .stop)
    }

    @Test func minutesAreKeptWithin5And120() {
        #expect(SleepTimer.minutes(1, now: 0).durationMinutes == 5)
        #expect(SleepTimer.minutes(500, now: 0).durationMinutes == 120)
        #expect(SleepTimer.minutes(500, now: 0).endsAt == 120 * 60_000)
    }

    @Test func remainingAndLabels() {
        let timer = SleepTimer.minutes(30, now: 0)
        #expect(SleepTimer.remaining(timer, now: 0) == 30 * 60_000)
        #expect(SleepTimer.remaining(timer, now: 99 * 60_000) == 0)
        #expect(SleepTimer.label(timer, now: 0) == "30 min")
        #expect(SleepTimer.label(timer, now: 30 * 60_000 - 11 * 60_000 - 30_000) == "12 min")
        #expect(SleepTimer.label(timer, now: 30 * 60_000 - 45_000) == "45 s")
        #expect(SleepTimer.label(.endOfChapter(chapterEndMs: 5), now: 0) == "End of chapter")
        #expect(SleepTimer.remaining(.endOfChapter(chapterEndMs: 5), now: 0) == nil)
    }

    @Test func extendingAddsFromTheEndOrFromNowAndIsCapped() {
        let timer = SleepTimer.minutes(10, now: 0)
        #expect(SleepTimer.extend(timer, minutes: 5, now: 60_000).endsAt == 15 * 60_000)
        // Already run out: five minutes from now.
        #expect(SleepTimer.extend(timer, minutes: 5, now: 20 * 60_000).endsAt == 25 * 60_000)
        // Never more than 120 minutes ahead.
        #expect(SleepTimer.extend(SleepTimer.minutes(120, now: 0), minutes: 30, now: 0).endsAt == 120 * 60_000)
        // Only duration timers extend.
        #expect(SleepTimer.extend(.off, minutes: 5, now: 0) == .off)
    }

    @Test func endOfChapterStopsAtTheChapterThatWasPlaying() {
        let timer = SleepTimer.endOfChapter(timeline, positionMs: 100_000)
        #expect(timer.chapterEndMs == 300_000)
        #expect(SleepTimer.decide(timer, now: 0, positionMs: 250_000) == .keep)
        #expect(SleepTimer.decide(timer, now: 0, positionMs: 299_800) == .stop)
        #expect(SleepTimer.decide(timer, now: 0, positionMs: 300_500) == .stop)
    }

    @Test func aSeekMovesAnEndOfChapterTimerToTheNewChapter() {
        let timer = SleepTimer.endOfChapter(timeline, positionMs: 100_000)
        let moved = SleepTimer.retarget(timer, timeline, positionMs: 400_000)
        #expect(moved.chapterEndMs == 1_000_000)
        #expect(SleepTimer.decide(moved, now: 0, positionMs: 400_000) == .keep)
        // Duration timers ignore seeks.
        let duration = SleepTimer.minutes(10, now: 0)
        #expect(SleepTimer.retarget(duration, timeline, positionMs: 999) == duration)
    }
}
