import Foundation
import Observation

/// Fetches chapters into the offline store, a few at a time, per novel.
/// App-wide so a download keeps going when the user leaves the novel screen.
@MainActor
@Observable
final class DownloadManager {
    struct Job: Equatable {
        var total: Int
        var done = 0
        var failed = 0
        var fraction: Double { total == 0 ? 1 : Double(done + failed) / Double(total) }
    }

    private(set) var jobs: [Int: Job] = [:]
    /// Bumped whenever offline content changes, so screens can re-read what's cached.
    private(set) var revision = 0
    private(set) var lastMessage: [Int: String] = [:]
    @ObservationIgnored private var tasks: [Int: Task<Void, Never>] = [:]

    private static let concurrency = 4

    func isDownloading(_ novelId: Int) -> Bool { jobs[novelId] != nil }

    func start(novelId: Int, chapterIds: [Int], client: APIClient, store: OfflineStore) {
        guard jobs[novelId] == nil else { return }
        lastMessage[novelId] = nil
        guard !chapterIds.isEmpty else {
            lastMessage[novelId] = "Everything is already on this device."
            return
        }
        jobs[novelId] = Job(total: chapterIds.count)

        tasks[novelId] = Task { [weak self] in
            await withTaskGroup(of: Bool.self) { group in
                var pending = chapterIds.makeIterator()

                func addNext() -> Bool {
                    guard let id = pending.next() else { return false }
                    group.addTask {
                        guard !Task.isCancelled else { return false }
                        do {
                            let chapter = try await client.chapter(id: id)
                            await store.saveChapter(chapter)
                            return chapter.downloaded
                        } catch {
                            return false
                        }
                    }
                    return true
                }

                for _ in 0..<Self.concurrency { if !addNext() { break } }
                while let ok = await group.next() {
                    if Task.isCancelled { group.cancelAll(); break }
                    self?.record(novelId: novelId, ok: ok)
                    _ = addNext()
                }
            }
            self?.finish(novelId: novelId, cancelled: Task.isCancelled)
        }
    }

    func cancel(novelId: Int) {
        tasks[novelId]?.cancel()
    }

    /// Stops every download, e.g. when the account or server changes.
    func cancelAll() {
        for task in tasks.values { task.cancel() }
    }

    func remove(novelId: Int, store: OfflineStore) async {
        cancel(novelId: novelId)
        await store.removeChapters(novelId: novelId)
        lastMessage[novelId] = nil
        revision += 1
    }

    func contentChanged() { revision += 1 }

    private func record(novelId: Int, ok: Bool) {
        guard var job = jobs[novelId] else { return }
        if ok { job.done += 1 } else { job.failed += 1 }
        jobs[novelId] = job
        // Refresh "available offline" counts as we go, but not on every chapter.
        if job.done % 25 == 0 { revision += 1 }
    }

    private func finish(novelId: Int, cancelled: Bool) {
        if let job = jobs[novelId] {
            if cancelled {
                lastMessage[novelId] = "Download cancelled after \(job.done) chapters."
            } else if job.failed > 0 {
                lastMessage[novelId] = "Saved \(job.done) chapters; \(job.failed) couldn't be fetched."
            } else {
                lastMessage[novelId] = "Saved \(job.done) chapters for offline reading."
            }
        }
        jobs[novelId] = nil
        tasks[novelId] = nil
        revision += 1
    }
}
