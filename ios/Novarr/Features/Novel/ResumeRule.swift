import Foundation

/// Where "Continue" opens a series — the rule every device shares (the
/// server's `App\Services\ResumePoint`, which answers `resume_chapter_id`),
/// applied here to the server's answer plus this device's reading that has
/// not reached the server yet (the unsent writes in the queue), so Continue
/// is right offline too and agrees with what the server will answer once
/// the writes arrive.
///
/// Over the downloaded chapters in reading order:
/// 1. The anchor is this device's most recent unsent REAL reading: the
///    chapter of the newest pending position write (ties: the furthest in
///    reading order) that is now read, or unread with a position of 1 % or
///    more. A chapter marked unread, or opened at 0 %, is not reading. Only
///    reading counts: a hand mark (mark read / unread, "mark to here", "mark
///    all read") and a look-up (a chapter opened from Highlights or search)
///    change the replayed read state but never make a chapter the anchor. A
///    real position write after a hand mark makes the chapter a candidate
///    again; a hand mark after one keeps that write's time.
/// 2. No anchor: the server's chapter (it applied the same rule) — unless
///    the unsent writes finished it (it was unread, or read but unfinished,
///    and is now read and finished, e.g. a bulk mark covered it): then it
///    stands in for the anchor in rule 4, which is what the server will
///    answer. Without a server chapter, the first downloaded unread chapter.
/// 3. An unfinished anchor is resumed: unread at 1–99 %, or read below 90 %
///    (a read chapter being re-read; the web reader marks read on open).
/// 4. Otherwise the first downloaded unread chapter after it; none (caught
///    up): the last downloaded chapter, as the server answers.
/// Earlier unread chapters and older part-read ones never pull back.
enum ResumeRule {
    /// A read chapter below this position is still being read.
    static let readUnfinishedBelow = 90

    static func isUnfinished(read: Bool, progress: Int?) -> Bool {
        guard let progress else { return false }
        return read ? progress < readUnfinishedBelow : (1...99).contains(progress)
    }

    /// - Parameters:
    ///   - chapters: the chapter list in reading order, with the read state this device knows.
    ///   - serverId: the server's `resume_chapter_id`.
    ///   - pending: this series' unsent writes, oldest first (queue order).
    static func resolve(chapters: [ChapterListItem], serverId: Int?, pending: [PendingWrite]) -> Int? {
        guard !chapters.isEmpty else { return serverId }
        let state = Replay(chapters: chapters, pending: pending)
        let position = state.position
        let read = state.read
        let progress = state.progress
        let readingAt = state.readingAt

        var anchor: (index: Int, at: Date)?
        for (id, at) in readingAt {
            guard let index = position[id], chapters[index].downloaded else { continue }
            guard read[id] == true || ((progress[id] ?? nil) ?? 0) >= 1 else { continue }
            if let current = anchor, at < current.at || (at == current.at && index < current.index) { continue }
            anchor = (index, at)
        }
        let isRead = { (chapter: ChapterListItem) in read[chapter.id] ?? chapter.read }

        let anchorIndex: Int
        if let anchor {
            anchorIndex = anchor.index
        } else {
            guard let serverId else { return chapters.first { $0.downloaded && !isRead($0) }?.id }
            // The server's chapter, unless the unsent writes finished it (rule 2).
            guard let serverIndex = position[serverId] else { return serverId }
            let row = chapters[serverIndex]
            let wasFinished = row.read && !isUnfinished(read: true, progress: row.readProgress)
            let isFinished = isRead(row) && !isUnfinished(read: true, progress: progress[serverId] ?? nil)
            if wasFinished || !isFinished { return serverId }
            anchorIndex = serverIndex
        }
        let anchorChapter = chapters[anchorIndex]
        if isUnfinished(read: isRead(anchorChapter), progress: progress[anchorChapter.id] ?? nil) {
            return anchorChapter.id
        }
        let after = chapters[(anchorIndex + 1)...].filter(\.downloaded)
        return (after.first { !isRead($0) } ?? after.last ?? anchorChapter).id
    }

    /// "Continue reading" rather than "Start reading": something is read or
    /// part-read (1 % or more), on the server or in this device's unsent writes.
    static func hasStarted(chapters: [ChapterListItem], serverReadCount: Int, pending: [PendingWrite]) -> Bool {
        if serverReadCount > 0 { return true }
        let state = Replay(chapters: chapters, pending: pending)
        return chapters.contains { chapter in
            state.read[chapter.id] == true || ((state.progress[chapter.id] ?? nil) ?? 0) >= 1
        }
    }

    /// The chapter list's read state with the unsent writes applied, oldest
    /// first, as the server will apply them. Only real reading (position
    /// writes) is recorded as activity; hand marks and look-ups change the
    /// read state only.
    private struct Replay {
        var position: [Int: Int] = [:]
        var read: [Int: Bool] = [:]
        var progress: [Int: Int?] = [:]
        var readingAt: [Int: Date] = [:]

        init(chapters: [ChapterListItem], pending: [PendingWrite]) {
            for (index, chapter) in chapters.enumerated() where position[chapter.id] == nil {
                position[chapter.id] = index
            }
            for chapter in chapters {
                read[chapter.id] = chapter.read
                progress[chapter.id] = chapter.readProgress
            }
            for write in pending {
                let at = write.recordedAt
                switch write.kind {
                case .progress(let id, let value, let isRead):
                    set(id, read: isRead ? true : nil, progress: value.map { .some($0) })
                    if position[id] != nil { readingAt[id] = at }
                case .setRead(let id, let isRead):
                    set(id, read: isRead, progress: .some(isRead ? 100 : nil))
                case .lookupRead(let id):
                    // The server marks it read; a look-up leaves a read chapter as it was.
                    if read[id] == false { set(id, read: true, progress: nil) }
                case .bulkRead(let request):
                    for id in ResumeRule.bulkIDs(request, chapters: chapters, position: position) {
                        set(id, read: request.read, progress: .some(request.read ? 100 : nil))
                    }
                case .highlight:
                    break
                }
            }
        }

        private mutating func set(_ id: Int, read isRead: Bool?, progress value: Int??) {
            guard position[id] != nil else { return }
            if let isRead { read[id] = isRead }
            if let value { progress[id] = value }
        }
    }

    /// The chapters a bulk mark covers, as the server selects them.
    fileprivate static func bulkIDs(_ request: BulkReadRequest, chapters: [ChapterListItem], position: [Int: Int]) -> [Int] {
        switch request.scope {
        case .all:
            return chapters.map(\.id)
        case .ids:
            return request.ids ?? []
        case .upTo, .from:
            guard let anchor = request.anchorId, let index = position[anchor] else { return [] }
            let range = request.scope == .upTo ? chapters[...index] : chapters[index...]
            return range.map(\.id)
        }
    }
}
