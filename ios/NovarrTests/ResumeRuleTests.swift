import Foundation
import Testing
@testable import Novarr

/// The shared resume rule (server: App\Services\ResumePoint) applied to the
/// server's answer plus this device's unsent writes.
@Suite("Resume rule")
struct ResumeRuleTests {
    /// Chapters 101…100+n, all downloaded unless listed in `missing`; the first `read` read.
    private func list(_ n: Int, read: Int = 0, missing: Set<Int> = [], progress: [Int: Int] = [:]) -> [ChapterListItem] {
        (1...n).map { i in
            ChapterListItem(id: 100 + i, chapter: Double(i), book: 0, label: "Chapter \(i)", downloaded: !missing.contains(i),
                            read: i <= read, readProgress: progress[i] ?? (i <= read ? 100 : nil), note: false,
                            state: nil, reason: nil, downloadedAt: nil, wordCount: nil)
        }
    }

    private let t0 = Date(timeIntervalSince1970: 1_800_000_000)

    private func write(_ kind: PendingWrite.Kind, at seconds: TimeInterval) -> PendingWrite {
        PendingWrite(id: UUID(), kind: kind, recordedAt: t0.addingTimeInterval(seconds), novelId: 1)
    }

    @Test func nothingUnsentKeepsTheServersChoice() {
        #expect(ResumeRule.resolve(chapters: list(10, read: 4), serverId: 103, pending: []) == 103)
        #expect(ResumeRule.resolve(chapters: [], serverId: 103, pending: []) == 103)
        // No server answer: the first downloaded unread chapter.
        #expect(ResumeRule.resolve(chapters: list(10, read: 4, missing: [5]), serverId: nil, pending: []) == 106)
    }

    @Test func finishingAChapterMovesToTheNextUnreadAfterIt() {
        let pending = [write(.progress(chapterId: 105, progress: 100, read: true), at: 1)]
        #expect(ResumeRule.resolve(chapters: list(10, read: 4), serverId: 105, pending: pending) == 106)
    }

    @Test func aChapterPartReadOnThisDeviceIsResumed() {
        let pending = [write(.progress(chapterId: 105, progress: 40, read: false), at: 1)]
        #expect(ResumeRule.resolve(chapters: list(10, read: 4), serverId: 105, pending: pending) == 105)
    }

    @Test func reReadingAnEarlyChapterPartWayResumesIt() {
        // As the server will: the read chapter read last is below 90 %.
        let pending = [write(.progress(chapterId: 102, progress: 30, read: false), at: 5)]
        #expect(ResumeRule.resolve(chapters: list(10, read: 6), serverId: 107, pending: pending) == 102)
        let finished = [write(.progress(chapterId: 102, progress: 95, read: false), at: 5)]
        #expect(ResumeRule.resolve(chapters: list(10, read: 6), serverId: 107, pending: finished) == 107)
    }

    @Test func anOlderPartReadChapterNeverPullsBack() {
        let pending = [
            write(.progress(chapterId: 103, progress: 20, read: false), at: 1),
            write(.progress(chapterId: 106, progress: 100, read: true), at: 2),
        ]
        #expect(ResumeRule.resolve(chapters: list(10, read: 2), serverId: 103, pending: pending) == 107)
    }

    @Test func earlierUnreadChaptersDoNotPullBack() {
        let pending = [write(.progress(chapterId: 105, progress: 100, read: true), at: 1)]
        #expect(ResumeRule.resolve(chapters: list(10), serverId: 101, pending: pending) == 106)
    }

    @Test func theNextChapterTheServerLacksIsSkipped() {
        let pending = [write(.progress(chapterId: 105, progress: 100, read: true), at: 1)]
        #expect(ResumeRule.resolve(chapters: list(10, read: 4, missing: [6]), serverId: 105, pending: pending) == 107)
    }

    @Test func caughtUpGoesToTheLastDownloadedChapter() {
        let pending = [write(.progress(chapterId: 105, progress: 100, read: true), at: 1)]
        #expect(ResumeRule.resolve(chapters: list(6, read: 4, missing: [6]), serverId: 105, pending: pending) == 105)
    }

    @Test func markingUnreadOrOpeningAtZeroIsNotReading() {
        let unread = [write(.setRead(chapterId: 103, read: false), at: 9)]
        #expect(ResumeRule.resolve(chapters: list(10, read: 6), serverId: 107, pending: unread) == 107)
        let opened = [write(.progress(chapterId: 108, progress: 0, read: false), at: 9)]
        #expect(ResumeRule.resolve(chapters: list(10, read: 6), serverId: 107, pending: opened) == 107)
    }

    @Test func aTieGoesToTheFurthestChapter() {
        let pending = [
            write(.progress(chapterId: 104, progress: 100, read: true), at: 3),
            write(.progress(chapterId: 106, progress: 100, read: true), at: 3),
            write(.progress(chapterId: 105, progress: 100, read: true), at: 3),
        ]
        #expect(ResumeRule.resolve(chapters: list(10, read: 2), serverId: 103, pending: pending) == 107)
    }

    @Test func aBulkMarkOverTheServersChapterGoesToTheFirstUnreadAfterIt() {
        // Not an anchor (a hand mark), but it finished the server's chapter: as the server will answer.
        let pending = [write(.bulkRead(.upTo(106, novelId: 1, read: true)), at: 3)]
        #expect(ResumeRule.resolve(chapters: list(10), serverId: 101, pending: pending) == 107)
        // Server says ch 11; "mark to here" through ch 50 covers it: the first downloaded unread after it.
        let through50 = [write(.bulkRead(.upTo(150, novelId: 1, read: true)), at: 3)]
        #expect(ResumeRule.resolve(chapters: list(60, read: 10), serverId: 111, pending: through50) == 151)
        #expect(ResumeRule.resolve(chapters: list(60, read: 10, missing: [51]), serverId: 111, pending: through50) == 152)
        // Everything marked read: caught up, the last downloaded chapter.
        let all = [write(.bulkRead(.upTo(160, novelId: 1, read: true)), at: 3)]
        #expect(ResumeRule.resolve(chapters: list(60, read: 10, missing: [60]), serverId: 111, pending: all) == 159)
    }

    @Test func aBulkMarkNotCoveringTheServersChapterKeepsIt() {
        let pending = [write(.bulkRead(.upTo(105, novelId: 1, read: true)), at: 3)]
        #expect(ResumeRule.resolve(chapters: list(60, read: 10), serverId: 111, pending: pending) == 111)
    }

    @Test func aHandMarkIsNotReading() {
        // Server says ch 11; ch 40 marked read by hand: Continue stays ch 11.
        let pending = [write(.setRead(chapterId: 140, read: true), at: 9)]
        #expect(ResumeRule.resolve(chapters: list(60, read: 10), serverId: 111, pending: pending) == 111)
    }

    @Test func aLaterHandMarkDoesNotTakeOverFromReading() {
        let pending = [
            write(.progress(chapterId: 110, progress: 40, read: false), at: 1),
            write(.setRead(chapterId: 130, read: true), at: 2),
        ]
        #expect(ResumeRule.resolve(chapters: list(60, read: 9), serverId: 110, pending: pending) == 110)
    }

    @Test func aHandMarkLaterOnAReadChapterKeepsItsReadingTime() {
        // Ch 10 read at 2, ch 12 read to 40 % at 3, ch 10 then marked read by hand at 9:
        // ch 12 is still the newest reading.
        let pending = [
            write(.progress(chapterId: 110, progress: 100, read: true), at: 2),
            write(.progress(chapterId: 112, progress: 40, read: false), at: 3),
            write(.setRead(chapterId: 110, read: true), at: 9),
        ]
        #expect(ResumeRule.resolve(chapters: list(60, read: 9), serverId: 110, pending: pending) == 112)
    }

    @Test func readingAfterAHandMarkCounts() {
        // Marked read, then really read to 50 %: a read chapter below 90 % is unfinished.
        let pending = [
            write(.setRead(chapterId: 140, read: true), at: 1),
            write(.progress(chapterId: 140, progress: 50, read: false), at: 2),
        ]
        #expect(ResumeRule.resolve(chapters: list(60, read: 10), serverId: 111, pending: pending) == 140)
    }

    @Test func aLookUpReadIsNotReading() {
        // Ch 40 opened from Highlights/search (marked read by the look-up): Continue stays ch 11.
        let pending = [write(.lookupRead(chapterId: 140), at: 9)]
        #expect(ResumeRule.resolve(chapters: list(60, read: 10), serverId: 111, pending: pending) == 111)
    }

    @Test func theNewestWriteIsTheAnchor() {
        let pending = [
            write(.progress(chapterId: 108, progress: 100, read: true), at: 1),
            write(.progress(chapterId: 104, progress: 60, read: false), at: 2),
        ]
        #expect(ResumeRule.resolve(chapters: list(10, read: 3), serverId: 104, pending: pending) == 104)
    }

    @Test func aLookUpOfAReadChapterDoesNotMoveIt() {
        let pending = [write(.lookupRead(chapterId: 102), at: 9)]
        #expect(ResumeRule.resolve(chapters: list(10, read: 6, progress: [6: 40]), serverId: 106, pending: pending) == 106)
    }

    @Test func unfinished() {
        #expect(!ResumeRule.isUnfinished(read: false, progress: nil))
        #expect(!ResumeRule.isUnfinished(read: false, progress: 0))
        #expect(ResumeRule.isUnfinished(read: false, progress: 1))
        #expect(ResumeRule.isUnfinished(read: false, progress: 99))
        #expect(!ResumeRule.isUnfinished(read: false, progress: 100))
        #expect(!ResumeRule.isUnfinished(read: true, progress: nil))
        #expect(ResumeRule.isUnfinished(read: true, progress: 89))
        #expect(!ResumeRule.isUnfinished(read: true, progress: 90))
    }

    @Test func homeContinueItemTakesTheLocalChoice() throws {
        let item = try Fixture.decode(ContinueItem.self, json: """
        {"novel": {"id": 1, "name": "N", "resume_chapter_id": 105}, "chapter": {"id": 105, "label": "Chapter 5"}}
        """)
        let pending = [write(.progress(chapterId: 105, progress: 100, read: true), at: 1)]
        let adjusted = HomeModel.adjusted(item, chapters: list(10, read: 4), unsent: pending)
        #expect(adjusted.chapter.id == 106)
        #expect(adjusted.chapter.label == "Chapter 6")
        #expect(adjusted.novel.resumeChapterId == 106)
        #expect(HomeModel.adjusted(item, chapters: list(10, read: 4), unsent: []).chapter.id == 105)
    }
}
