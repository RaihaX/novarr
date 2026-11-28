import Foundation
import Testing
@testable import Novarr

/// Regression tests for "chapters never marked read": finishing a chapter
/// (its end on screen, 95% of a scrolled text, or moving on with Next
/// chapter) must queue exactly one `read: true` write, which survives being
/// offline and is replayed in order (android ReadMarkTest.kt).
@Suite("Read mark")
struct ReadMarkTests {
    @Test func scrolledTextCountsAsReadAtNinetyFivePercent() {
        // 40 paragraphs: the bottom of paragraph 38 (index 37) is 95%.
        #expect(!ReadMark.textEndReached(lastFullyVisible: 36, paragraphCount: 40))
        #expect(ReadMark.textEndReached(lastFullyVisible: 37, paragraphCount: 40))
        #expect(ReadMark.textEndReached(lastFullyVisible: 39, paragraphCount: 40))
        // Nothing fully on screen, or nothing to read.
        #expect(!ReadMark.textEndReached(lastFullyVisible: -1, paragraphCount: 40))
        #expect(!ReadMark.textEndReached(lastFullyVisible: 0, paragraphCount: 0))
        // A one-paragraph chapter is read once that paragraph's bottom is on screen.
        #expect(ReadMark.textEndReached(lastFullyVisible: 0, paragraphCount: 1))
        // 3 paragraphs: all three are needed (95% of 3 rounds up to 3).
        #expect(!ReadMark.textEndReached(lastFullyVisible: 1, paragraphCount: 3))
        // The scroll view only watches paragraphs from the first that can finish the chapter.
        #expect(ReadMark.firstFinishingParagraph(paragraphCount: 40) == 37)
        #expect(ReadMark.firstFinishingParagraph(paragraphCount: 3) == 2)
        #expect(ReadMark.firstFinishingParagraph(paragraphCount: 1) == 0)
    }

    @Test func movingOnToTheNextChapterFinishesTheOneLeft() {
        #expect(ReadMark.finishesOnOpen(target: 12, next: 12, loaded: true))
        // Back, or a jump elsewhere, says nothing about the chapter being left.
        #expect(!ReadMark.finishesOnOpen(target: 10, next: 12, loaded: true))
        // The last chapter has no next; a chapter still loading isn't finished.
        #expect(!ReadMark.finishesOnOpen(target: 12, next: nil, loaded: true))
        #expect(!ReadMark.finishesOnOpen(target: 12, next: 12, loaded: false))
    }

    @Test func pagingThroughAMangaChapterEndsWithOneReadMark() {
        var now = Date(timeIntervalSince1970: 1_000)
        var tracker = ProgressTracker(interval: 4)
        tracker.reset(read: false, progress: nil)
        let pages = 8
        var sent: [Int] = []
        // Pages 1…7 (indices 0…6), a page every 1.5 s: positions throttled to one per 4 s.
        for index in 0..<(pages - 1) {
            if tracker.moved(ProgressTracker.progress(index: index, count: pages), now: now) == .sendNow,
               let progress = tracker.takePending(now: now) {
                sent.append(progress)
            }
            now += 1.5
            // The scheduled send fires.
            if index % 3 == 2, let progress = tracker.takePending(now: now) { sent.append(progress) }
        }
        // The last page: the read mark, once, and nothing pending after it.
        let r1 = tracker.reachedEnd(now: now)
        #expect(r1)
        let r2 = tracker.takePending(now: now)
        #expect(r2 == nil)
        let r3 = tracker.reachedEnd(now: now)
        #expect(!r3)
        #expect(tracker.current == 100)
        #expect(!sent.isEmpty)
        #expect(sent.allSatisfy { (0...99).contains($0) })
    }

    @Test func anAlreadyReadChapterIsNotMarkedAgain() {
        var tracker = ProgressTracker()
        tracker.reset(read: true, progress: 100)
        let r4 = tracker.reachedEnd(now: Date())
        #expect(!r4)
    }

    @Test func theReadMarkIsSentAsProgressHundredWithTheReadFlag() async throws {
        let bodies = Locked<[[String: Any]]>([])
        let client = StubURLProtocol.client { _, data in
            bodies.mutate { $0.append((try? JSONSerialization.jsonObject(with: data ?? Data())) as? [String: Any] ?? [:]) }
            return (200, Data("{}".utf8))
        }
        try await client.send(.progress(chapterId: 7, progress: 100, read: true), age: 1_200)
        try await client.send(.progress(chapterId: 7, progress: 40, read: false), age: 0)
        let sent = bodies.value
        #expect(sent[0]["progress"] as? Int == 100)
        #expect(sent[0]["read"] as? Bool == true)
        #expect(sent[0]["age"] as? Int == 1_200)
        // A plain position never carries `read` (its presence means "mark read").
        #expect(sent[1]["read"] == nil)
    }

    @Test func anOfflineReadMarkIsKeptAndReplayedInOrderWithItsAge() async {
        let clock = TestClock()
        let queue = WriteQueue(fileURL: temporaryFile(), now: { clock.now })
        let position = PendingWrite.Kind.progress(chapterId: 7, progress: 60, read: false)
        let mark = PendingWrite.Kind.progress(chapterId: 7, progress: 100, read: true)
        let next = PendingWrite.Kind.progress(chapterId: 8, progress: 5, read: false)
        await queue.enqueue(position, novelId: 3)
        await queue.enqueue(mark, novelId: 3)
        // Offline: nothing leaves the queue.
        let offline = await queue.flush { _, _ in throw APIError.unreachable("offline") }
        #expect(offline == .stalled(sent: 0, remaining: 2))
        // A later position for the next chapter never replaces the read mark.
        await queue.enqueue(next, novelId: 3)
        clock.advance(60)
        let sent = Locked<[(PendingWrite.Kind, Int)]>([])
        let outcome = await queue.flush { kind, age in sent.mutate { $0.append((kind, age)) } }
        #expect(outcome == .drained(sent: 3, dropped: 0))
        #expect(sent.value.map(\.0) == [position, mark, next])
        #expect(sent.value[1].1 == 60_000)
    }
}

/// The progress-write rules both readers share (android ReaderLogicTest's ProgressTrackerTest).
@Suite("Progress tracker")
struct ProgressTrackerTests {
    private let t0 = Date(timeIntervalSince1970: 100)

    @Test func firstPositionGoesAtOnceThenThrottles() {
        var tracker = ProgressTracker(interval: 4)
        var now = t0
        tracker.reset(read: false, progress: nil)
        let r5 = tracker.moved(10, now: now)
        #expect(r5 == .sendNow)
        let r6 = tracker.takePending(now: now)
        #expect(r6 == 10)
        now += 1
        let r7 = tracker.moved(20, now: now)
        #expect(r7 == .sendAfter(3))
        now += 0.5
        // Already scheduled: the pending send will carry the newest position.
        let r8 = tracker.moved(25, now: now)
        #expect(r8 == .alreadyScheduled)
        now += 2.5
        let r9 = tracker.takePending(now: now)
        #expect(r9 == 25)
        now += 4
        let r10 = tracker.moved(30, now: now)
        #expect(r10 == .sendNow)
    }

    @Test func unchangedPositionIsNotResent() {
        var tracker = ProgressTracker()
        tracker.reset(read: false, progress: 40)
        _ = tracker.moved(40, now: t0)
        let r11 = tracker.takePending(now: t0)
        #expect(r11 == nil)
        let r12 = tracker.takePending(now: t0)
        #expect(r12 == nil)
    }

    @Test func hundredIsReservedForTheEnd() {
        var tracker = ProgressTracker()
        tracker.reset(read: false, progress: nil)
        _ = tracker.moved(150, now: t0)
        let r13 = tracker.takePending(now: t0)
        #expect(r13 == 99)
        #expect(ProgressTracker.progress(index: 9, count: 10) == 99)
        #expect(ProgressTracker.progress(index: 0, count: 10) == 0)
        #expect(ProgressTracker.progress(index: 5, count: 11) == 50)
        #expect(ProgressTracker.progress(index: 0, count: 1) == 0)
        // Manga pages follow the same rule.
        #expect(MangaProgress.progress(page: 5, count: 11) == ProgressTracker.progress(index: 5, count: 11))
    }

    @Test func reachingTheEndMarksReadOnceAndDropsThePendingPosition() {
        var tracker = ProgressTracker()
        tracker.reset(read: false, progress: 30)
        _ = tracker.moved(80, now: t0)
        let r14 = tracker.reachedEnd(now: t0)
        #expect(r14)
        let r15 = tracker.takePending(now: t0)
        #expect(r15 == nil)
        let r16 = tracker.reachedEnd(now: t0)
        #expect(!r16)
        #expect(tracker.current == 100)
    }

    @Test func pendingSinceTracksTheOldestUnsentPosition() {
        var tracker = ProgressTracker()
        var now = t0
        tracker.reset(read: false, progress: nil)
        _ = tracker.moved(5, now: now)
        _ = tracker.takePending(now: now)
        now += 1
        _ = tracker.moved(6, now: now)
        let since = tracker.pendingSince
        now += 0.5
        _ = tracker.moved(7, now: now)
        #expect(tracker.pendingSince == since)
        _ = tracker.takePending(now: now)
        #expect(tracker.pendingSince == nil)
    }

    @Test func heldPositionsAreTrackedButNotSentUntilReleased() {
        var tracker = ProgressTracker(interval: 4)
        var now = t0
        tracker.reset(read: false, progress: 10)
        let r17 = tracker.moved(30, now: now, holding: true)
        #expect(r17 == .held)
        now += 10
        let r18 = tracker.moved(35, now: now, holding: true)
        #expect(r18 == .held)
        let r19 = tracker.takePending(now: now, holding: true)
        #expect(r19 == nil)
        #expect(tracker.current == 35)
        // Released.
        let r20 = tracker.takePending(now: now)
        #expect(r20 == 35)
        let r21 = tracker.moved(40, now: now)
        #expect(r21 == .sendAfter(4))
    }

    @Test func discardingDropsTheHeldPosition() {
        var tracker = ProgressTracker()
        tracker.reset(read: false, progress: 10)
        _ = tracker.moved(60, now: t0, holding: true)
        tracker.discardPending()
        let r22 = tracker.takePending(now: t0)
        #expect(r22 == nil)
        #expect(tracker.current == 10)
        #expect(tracker.pendingSince == nil)
    }

    @Test func positionsTakenWhileHoldingDontCountAsReadHereMoreRecently() {
        var tracker = ProgressTracker()
        tracker.reset(read: false, progress: nil)
        _ = tracker.moved(20, now: t0, holding: true)
        #expect(tracker.pendingSince == nil)
        // Released: the next position is an ordinary unsent one.
        _ = tracker.moved(21, now: t0 + 1)
        #expect(tracker.pendingSince == t0 + 1)
    }

    @Test func anUnsentPositionFromBeforeTheHoldStillCounts() {
        var tracker = ProgressTracker()
        var now = t0
        tracker.reset(read: false, progress: nil)
        _ = tracker.moved(5, now: now)
        _ = tracker.takePending(now: now)
        now += 1
        _ = tracker.moved(10, now: now) // scheduled, not yet sent
        let since = now
        now += 0.5
        _ = tracker.moved(15, now: now, holding: true)
        #expect(tracker.pendingSince == since)
    }

    @Test func theReadMarkIsWrittenWhileHolding() {
        var tracker = ProgressTracker()
        tracker.reset(read: false, progress: 10)
        _ = tracker.moved(80, now: t0, holding: true)
        let r23 = tracker.reachedEnd(now: t0)
        #expect(r23)
        #expect(tracker.pendingSince == nil)
        let r24 = tracker.takePending(now: t0)
        #expect(r24 == nil)
    }
}
