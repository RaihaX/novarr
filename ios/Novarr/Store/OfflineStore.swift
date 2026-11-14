import Foundation
import os

/// On-disk cache for one server: list responses as JSON plus chapter content
/// for offline reading. Lives in Application Support (not Caches) so the
/// system doesn't purge chapters the user downloaded on purpose.
///
/// Layout under `root`:
///   novels.json, home.json
///   novels/<id>/detail.json, novels/<id>/chapters.json
///   novels/<id>/content/<chapterId>.json
actor OfflineStore {
    enum Key: Sendable {
        case novels, home, novel(Int), chapters(Int)

        var relativePath: String {
            switch self {
            case .novels: "novels.json"
            case .home: "home.json"
            case .novel(let id): "novels/\(id)/detail.json"
            case .chapters(let id): "novels/\(id)/chapters.json"
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
    private let log = Logger(subsystem: "studio.visns.novarr", category: "OfflineStore")

    init(root: URL) {
        self.root = root
        try? FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        var mutableRoot = root
        try? mutableRoot.setResourceValues(values)
    }

    static func directory(for server: URL) -> URL {
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)
            .first ?? FileManager.default.temporaryDirectory
        return base.appending(path: "Novarr/servers/\(ServerURL.storageKey(for: server))", directoryHint: .isDirectory)
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
    }

    /// Keeps the cached copy's read state in step with what the user did, so
    /// reopening offline restores the right position.
    func updateChapterState(id: Int, novelId: Int, progress: Int?, read: Bool?) {
        guard var chapter = chapter(id: id, novelId: novelId) else { return }
        if let progress { chapter.readProgress = progress }
        if let read { chapter.read = read }
        write(chapter, to: contentURL(chapterId: id, novelId: novelId))
    }

    func cachedChapterIDs(novelId: Int) -> Set<Int> {
        let names = (try? FileManager.default.contentsOfDirectory(atPath: contentDirectory(novelId: novelId).path)) ?? []
        return Set(names.compactMap { name in
            name.hasSuffix(".json") ? Int(name.dropLast(5)) : nil
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
            let files = ((try? FileManager.default.contentsOfDirectory(atPath: dir.path)) ?? [])
                .filter { $0.hasSuffix(".json") }
            guard !files.isEmpty else { return nil }
            let name = names[novelId]
                ?? load(NovelDetail.self, as: .novel(novelId))?.summary.name
                ?? files.first.flatMap { read(ChapterContent.self, from: dir.appending(path: $0))?.novelName }
                ?? "Novel \(novelId)"
            return NovelDownloads(novelId: novelId, name: name, chapterCount: files.count, bytes: Self.size(of: dir))
        }
        .sorted { $0.bytes > $1.bytes }
    }

    func totalSize() -> Int64 { Self.size(of: root) }

    func offlineChaptersSize() -> Int64 {
        downloads().reduce(0) { $0 + $1.bytes }
    }

    /// Removes everything cached for this server (lists and chapters), but
    /// never the files named in `keeping` (the unsynced write queue).
    func clearAll(keeping: Set<String> = [OfflineStore.writeQueueFileName]) {
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
