import Foundation

/// Where "Continue" opens a series — the rule every device shares (the
/// server's `App\Services\ResumePoint`, which answers `resume_chapter_id`),
/// applied here to the server's answer plus this device's reading that has
/// not reached the server yet (the unsent writes in the queue), so Continue
/// is right offline too and agrees with what the server will answer once
/// the writes arrive.
///
/// Over the downloaded chapters in reading order:
/// 1. The anchor is this device's most recent unsent reading: the chapter of
///    the newest pending write (ties: the furthest in reading order) that is
///    now read, or unread with a position of 1 % or more. A chapter marked
///    unread, or opened at 0 %, is not reading.
/// 2. No anchor: the server's chapter (it applied the same rule); without
///    one, the first downloaded unread chapter.
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
        var position: [Int: Int] = [:]
        for (index, chapter) in chapters.enumerated() where position[chapter.id] == nil {
            position[chapter.id] = index
        }
        var read: [Int: Bool] = [:]
        var progress: [Int: Int?] = [:]
        for chapter in chapters {
            read[chapter.id] = chapter.read
            progress[chapter.id] = chapter.readProgress
        }
        func set(_ id: Int, read isRead: Bool?, progress value: Int??) {
            guard position[id] != nil else { return }
            if let isRead { read[id] = isRead }
            if let value { progress[id] = value }
        }

        // Replay the unsent writes, oldest first, as the server will apply them.
        var touched: [Int: Date] = [:]
        for write in pending {
            let at = write.recordedAt
            switch write.kind {
            case .progress(let id, let value, let isRead):
                set(id, read: isRead ? true : nil, progress: value.map { .some($0) })
                touched[id] = at
            case .setRead(let id, let isRead):
                set(id, read: isRead, progress: .some(isRead ? 100 : nil))
                touched[id] = at
            case .lookupRead(let id):
                // The server marks it read; a look-up leaves a read chapter as it was.
                if read[id] == false {
                    set(id, read: true, progress: nil)
                    touched[id] = at
                }
            case .bulkRead(let request):
                for id in bulkIDs(request, chapters: chapters, position: position) {
                    set(id, read: request.read, progress: .some(request.read ? 100 : nil))
                    touched[id] = at
                }
            case .highlight:
                break
            }
        }

        var anchor: (index: Int, at: Date)?
        for (id, at) in touched {
            guard let index = position[id], chapters[index].downloaded else { continue }
            guard read[id] == true || ((progress[id] ?? nil) ?? 0) >= 1 else { continue }
            if let current = anchor, at < current.at || (at == current.at && index < current.index) { continue }
            anchor = (index, at)
        }
        let isRead = { (chapter: ChapterListItem) in read[chapter.id] ?? chapter.read }

        guard let anchor else {
            return serverId ?? chapters.first { $0.downloaded && !isRead($0) }?.id
        }
        let anchorChapter = chapters[anchor.index]
        if isUnfinished(read: isRead(anchorChapter), progress: progress[anchorChapter.id] ?? nil) {
            return anchorChapter.id
        }
        let after = chapters[(anchor.index + 1)...].filter(\.downloaded)
        return (after.first { !isRead($0) } ?? after.last ?? anchorChapter).id
    }

    /// The chapters a bulk mark covers, as the server selects them.
    private static func bulkIDs(_ request: BulkReadRequest, chapters: [ChapterListItem], position: [Int: Int]) -> [Int] {
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
