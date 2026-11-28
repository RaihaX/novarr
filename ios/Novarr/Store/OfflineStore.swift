import Foundation
import os

/// On-disk cache for one server: list responses as JSON plus chapter content
/// for offline reading. Lives in Application Support (not Caches) so the
/// system doesn't purge chapters the user downloaded on purpose.
///
/// Layout under `root`:
///   novels.json, home.json
///   novels/<id>/detail.json, novels/<id>/chapters.json
///   novels/<id>/highlights.json (the series' highlights, for the reader offline)
///   novels/<id>/content/<chapterId>.json
///   novels/<id>/content/<chapterId>.manga/ a manga chapter: page images
///       named by page number, and pages.json (written last: its presence
///       marks the chapter complete)
actor OfflineStore {
    enum Key: Sendable {
        case novels, home, novel(Int), chapters(Int), highlights(Int)
        /// The reader's categories, saved with each refresh so they show offline (read-only).
        case lists
        /// The whole "Want to read" (its screen).
        case wantToRead
        /// The last stats answer per range (30, 90, 365).
        case stats(Int)
        /// A feature's own file under the store root (e.g. "audiobooks/continue.json").
        case custom(String)

        var relativePath: String {
            switch self {
            case .novels: "novels.json"
            case .home: "home.json"
            case .lists: "lists.json"
            case .wantToRead: "want-to-read.json"
            case .stats(let range): "stats-\(range).json"
            case .custom(let path): path
            case .novel(let id): "novels/\(id)/detail.json"
            case .chapters(let id): "novels/\(id)/chapters.json"
            case .highlights(let id): "novels/\(id)/highlights.json"
            }
        }
    }

    struct NovelDownloads: Identifiable, Sendable, Hashable {
        var novelId: Int
        var name: String
        var chapterCount: Int
        var bytes: Int64
        var id: Int { novelId }
    }

    nonisolated let root: URL
    private let log = Logger(subsystem: "au.com.atomicinsights.novarr", category: "OfflineStore")

    init(root: URL) {
        self.root = root
        try? FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        var mutableRoot = root
        try? mutableRoot.setResourceValues(values)
    }

    static var defaultBase: URL {
        FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)
            .first ?? FileManager.default.temporaryDirectory
    }

    /// The per-server directory used with auth off (and by every version
    /// before accounts), so existing caches and queued writes stay put.
    static func directory(for server: URL, base: URL = defaultBase) -> URL {
        base.appending(path: "Novarr/servers/\(ServerURL.storageKey(for: server))", directoryHint: .isDirectory)
    }

    /// A signed-in user's own directory: a sibling of the server's, so
    /// clearing one never touches another and two accounts never mix.
    static func directory(for server: URL, userId: Int, base: URL = defaultBase) -> URL {
        base.appending(path: "Novarr/servers/\(ServerURL.storageKey(for: server))@user-\(userId)",
                       directoryHint: .isDirectory)
    }

    /// Marks the server directory as belonging to one signed-in user; holds their id.
    static let ownerMarkerFileName = ".owner-user-id"

    /// Where a session's cache and write queue live.
    ///
    /// - Auth off (`user == nil`): the server directory, as before.
    /// - Signed in: the user's own directory, except that the first admin
    ///   (the owner) to sign in on a server that already has data from before
    ///   accounts adopts that directory: it is marked with their id and used
    ///   as theirs, so their offline chapters and unsent writes carry over.
    ///   Readers never adopt it (its queued writes are the owner's). With auth
    ///   switched off again the same directory is used, which is right since
    ///   auth off means "everything is the owner".
    static func resolveDirectory(for server: URL, user: AuthUser?, base: URL = defaultBase) -> URL {
        let shared = directory(for: server, base: base)
        guard let user else { return shared }

        let own = directory(for: server, userId: user.id, base: base)
        let fm = FileManager.default
        let marker = shared.appending(path: ownerMarkerFileName)
        let claimedBy = (try? String(contentsOf: marker, encoding: .utf8))
            .flatMap { Int($0.trimmingCharacters(in: .whitespacesAndNewlines)) }

        if claimedBy == user.id { return shared }
        if fm.fileExists(atPath: own.path) { return own }

        let sharedHasData = !((try? fm.contentsOfDirectory(atPath: shared.path)) ?? []).isEmpty
        if claimedBy == nil, user.role == .admin, sharedHasData {
            do {
                try Data(String(user.id).utf8).write(to: marker, options: .atomic)
                return shared
            } catch {
                Logger(subsystem: "au.com.atomicinsights.novarr", category: "OfflineStore")
                    .error("Could not adopt the existing cache: \(error.localizedDescription, privacy: .public)")
            }
        }
        return own
    }

    // MARK: Responses

    func save<T: Encodable & Sendable>(_ value: T, as key: Key) {
        write(value, to: root.appending(path: key.relativePath))
    }

    func load<T: Decodable & Sendable>(_ type: T.Type, as key: Key) -> T? {
        read(type, from: root.appending(path: key.relativePath))
    }

    // MARK: Chapter content

    private func contentDirectory(novelId: Int) -> URL {
        root.appending(path: "novels/\(novelId)/content", directoryHint: .isDirectory)
    }

    private func contentURL(chapterId: Int, novelId: Int) -> URL {
        contentDirectory(novelId: novelId).appending(path: "\(chapterId).json")
    }

    func saveChapter(_ chapter: ChapterContent) {
        // Only real content is worth keeping offline.
        guard chapter.downloaded, !chapter.paragraphs.isEmpty else { return }
        write(chapter, to: contentURL(chapterId: chapter.id, novelId: chapter.novelId))
    }

    func chapter(id: Int, novelId: Int) -> ChapterContent? {
        read(ChapterContent.self, from: contentURL(chapterId: id, novelId: novelId))
    }

    func hasChapter(id: Int, novelId: Int) -> Bool {
        FileManager.default.fileExists(atPath: contentURL(chapterId: id, novelId: novelId).path)
            || FileManager.default.fileExists(atPath: mangaIndexURL(chapterId: id, novelId: novelId).path)
    }

    /// Keeps the cached copy's read state in step with what the user did, so
    /// reopening offline restores the right position.
    func updateChapterState(id: Int, novelId: Int, progress: Int?, read: Bool?) {
        if var chapter = chapter(id: id, novelId: novelId) {
            if let progress { chapter.readProgress = progress }
            if let read { chapter.read = read }
            write(chapter, to: contentURL(chapterId: id, novelId: novelId))
        }
        if var pages = mangaChapter(id: id, novelId: novelId) {
            if let progress { pages.chapter.progress = progress }
            if let read { pages.chapter.read = read }
            write(pages, to: mangaIndexURL(chapterId: id, novelId: novelId))
        }
    }

    // MARK: Manga chapters

    /// Where a manga chapter's page images and index live.
    nonisolated func mangaDirectory(chapterId: Int, novelId: Int) -> URL {
        root.appending(path: "novels/\(novelId)/content/\(chapterId).manga", directoryHint: .isDirectory)
    }

    private func mangaIndexURL(chapterId: Int, novelId: Int) -> URL {
        mangaDirectory(chapterId: chapterId, novelId: novelId).appending(path: Self.mangaIndexName)
    }

    /// The local image of a page (present once downloaded).
    nonisolated func pageFile(novelId: Int, chapterId: Int, page: Int) -> URL {
        mangaDirectory(chapterId: chapterId, novelId: novelId).appending(path: String(page))
    }

    /// A downloaded manga chapter's pages index.
    func mangaChapter(id: Int, novelId: Int) -> MangaPages? {
        read(MangaPages.self, from: mangaIndexURL(chapterId: id, novelId: novelId))
    }

    /// Marks a manga chapter complete: call after every page image is saved.
    func saveMangaIndex(_ pages: MangaPages, novelId: Int) {
        write(pages, to: mangaIndexURL(chapterId: pages.chapter.id, novelId: novelId))
    }

    /// Text chapters (`<id>.json`) and complete manga chapters (`<id>.manga/pages.json`).
    func cachedChapterIDs(novelId: Int) -> Set<Int> {
        Self.chapterIDs(in: contentDirectory(novelId: novelId))
    }

    private static let mangaIndexName = "pages.json"

    private static func chapterIDs(in directory: URL) -> Set<Int> {
        let fm = FileManager.default
        let names = (try? fm.contentsOfDirectory(atPath: directory.path)) ?? []
        return Set(names.compactMap { name -> Int? in
            if name.hasSuffix(".json") { return Int(name.dropLast(5)) }
            if name.hasSuffix(".manga"),
               fm.fileExists(atPath: directory.appending(path: name).appending(path: mangaIndexName).path) {
                return Int(name.dropLast(6))
            }
            return nil
        })
    }

    func removeChapters(novelId: Int) {
        try? FileManager.default.removeItem(at: contentDirectory(novelId: novelId))
    }

    /// Novels with offline chapters, largest first.
    func downloads() -> [NovelDownloads] {
        let novelsDir = root.appending(path: "novels", directoryHint: .isDirectory)
        let ids = ((try? FileManager.default.contentsOfDirectory(atPath: novelsDir.path)) ?? []).compactMap(Int.init)
        let library = load([NovelSummary].self, as: .novels) ?? []
        let names = Dictionary(library.map { ($0.id, $0.name) }, uniquingKeysWith: { first, _ in first })

        return ids.compactMap { novelId -> NovelDownloads? in
            let dir = contentDirectory(novelId: novelId)
            let ids = Self.chapterIDs(in: dir)
            guard !ids.isEmpty else { return nil }
            let textName = ids.lazy.compactMap { self.chapter(id: $0, novelId: novelId)?.novelName }.first
            let name = names[novelId]
                ?? load(NovelDetail.self, as: .novel(novelId))?.summary.name
                ?? textName
                ?? "Series \(novelId)"
            return NovelDownloads(novelId: novelId, name: name, chapterCount: ids.count, bytes: Self.size(of: dir))
        }
        .sorted { $0.bytes > $1.bytes }
    }

    func totalSize() -> Int64 { Self.size(of: root) }

    func offlineChaptersSize() -> Int64 {
        downloads().reduce(0) { $0 + $1.bytes }
    }

    /// Removes everything cached for this server (lists and chapters), but
    /// never the files named in `keeping` (the unsynced write queue and who
    /// owns this directory).
    func clearAll(keeping: Set<String> = [OfflineStore.writeQueueFileName, OfflineStore.ownerMarkerFileName]) {
        let names = (try? FileManager.default.contentsOfDirectory(atPath: root.path)) ?? []
        for name in names where !keeping.contains(name) {
            try? FileManager.default.removeItem(at: root.appending(path: name))
        }
    }

    static let writeQueueFileName = "pending-writes.json"

    // MARK: Helpers

    private func write<T: Encodable>(_ value: T, to url: URL) {
        do {
            try FileManager.default.createDirectory(
                at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
            try JSONCoding.encoder().encode(value).write(to: url, options: .atomic)
        } catch {
            log.error("Cache write failed for \(url.lastPathComponent, privacy: .public): \(error.localizedDescription, privacy: .public)")
        }
    }

    private func read<T: Decodable>(_ type: T.Type, from url: URL) -> T? {
        guard let data = try? Data(contentsOf: url) else { return nil }
        return try? JSONCoding.decoder().decode(type, from: data)
    }

    static func size(of directory: URL) -> Int64 {
        guard let enumerator = FileManager.default.enumerator(
            at: directory, includingPropertiesForKeys: [.totalFileAllocatedSizeKey, .fileSizeKey])
        else { return 0 }
        var total: Int64 = 0
        for case let url as URL in enumerator {
            let values = try? url.resourceValues(forKeys: [.totalFileAllocatedSizeKey, .fileSizeKey])
            total += Int64(values?.totalFileAllocatedSize ?? values?.fileSize ?? 0)
        }
        return total
    }
}
