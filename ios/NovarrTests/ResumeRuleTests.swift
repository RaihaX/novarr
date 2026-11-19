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

    @Test func aBulkMarkTiesGoToTheFurthestChapter() {
        let pending = [write(.bulkRead(.upTo(106, novelId: 1, read: true)), at: 3)]
        #expect(ResumeRule.resolve(chapters: list(10), serverId: 101, pending: pending) == 107)
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
