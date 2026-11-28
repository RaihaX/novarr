import Foundation

/// A mark waiting out its Undo time; `BulkActions.undo` calls it off.
@MainActor
final class PendingMark {
    let ids: [Int]
    let read: Bool
    fileprivate var task: Task<Void, Never>?
    /// Sent (or being sent): too late for Undo.
    fileprivate(set) var sending = false

    init(ids: [Int], read: Bool) {
        self.ids = ids
        self.read = read
    }
}

/// The multi-select's actions on many series at once (Library, a category):
/// mark read / unread with Undo, and offline downloads. The mark runs in its
/// own task, so leaving the screen during the Undo wait still sends it.
/// Mirrors android app/BulkActions.kt.
@MainActor
final class BulkActions {
    /// Sends a mark: nil when fine, else the message for a toast. `revert`
    /// puts the screen back when the server refuses it.
    typealias Send = @MainActor (_ ids: [Int], _ read: Bool, _ revert: @escaping @MainActor () -> Void) async -> String?

    private weak var app: AppModel?
    private let wait: @Sendable () async throws -> Void
    private let sender: Send?

    /// The app's own: waits `Categories.undoSeconds`, then sends to the server.
    init(app: AppModel?) {
        self.app = app
        wait = { try await Task.sleep(for: .seconds(Categories.undoSeconds)) }
        sender = nil
    }

    /// The app, once the screen has it (a screen's `@State` is made before its environment).
    func attach(_ app: AppModel) { if sender == nil { self.app = app } }

    /// Tests: their own wait and sender.
    init(wait: @escaping @Sendable () async throws -> Void, send: @escaping Send) {
        app = nil
        self.wait = wait
        sender = send
    }

    /// Marks every chapter of `ids` read (or unread) once the Undo time is
    /// up, unless `undo` comes first. The screen shows the change itself
    /// straight away; `revert` puts it back if the server refuses, and
    /// `onDone` gets the message for a toast (nil: fine).
    func markRead(ids: [Int], read: Bool, revert: @escaping @MainActor () -> Void,
                  onDone: @escaping @MainActor (String?) -> Void) -> PendingMark {
        var seen = Set<Int>()
        let mark = PendingMark(ids: ids.filter { seen.insert($0).inserted }, read: read)
        let wait = wait
        // Holds the mark (and this object) until it is sent or called off, even if the screen goes.
        mark.task = Task { @MainActor in
            do { try await wait() } catch { return }
            guard !Task.isCancelled, !mark.sending else { return }
            mark.sending = true
            onDone(await self.send(mark.ids, read, revert))
        }
        return mark
    }

    /// True when the mark was called off in time (nothing was sent).
    func undo(_ mark: PendingMark) -> Bool {
        if mark.sending { return false }
        mark.task?.cancel()
        mark.sending = true // never sent now
        return true
    }

    /// Sends a waiting mark now (another mark came along, so the first isn't kept waiting behind it).
    func flush(_ mark: PendingMark, revert: @escaping @MainActor () -> Void, onDone: @escaping @MainActor (String?) -> Void) {
        if mark.sending { return }
        mark.task?.cancel()
        mark.sending = true
        Task { @MainActor in onDone(await self.send(mark.ids, mark.read, revert)) }
    }

    private func send(_ ids: [Int], _ read: Bool, _ revert: @escaping @MainActor () -> Void) async -> String? {
        if let sender { return await sender(ids, read, revert) }
        guard let app, let client = app.client else { return nil }
        do {
            for start in stride(from: 0, to: ids.count, by: Categories.bulkMax) {
                _ = try await client.bulkReadNovels(ids: Array(ids[start..<min(start + Categories.bulkMax, ids.count)]), read: read)
            }
            app.noteSuccess()
            // Every screen showing read state reloads (Home, Library, the series pages).
            app.libraryChanged()
            return nil
        } catch {
            let error = APIError.from(error)
            app.noteFailure(error)
            switch Self.failure(error, read: read) {
            case .queued(let message):
                // Kept on this device and sent when the server is reachable: one "mark all" per series.
                for id in ids { await app.record(.bulkRead(.all(novelId: id, read: read)), novelId: id) }
                return message
            case .reverted(let message):
                revert()
                return message
            }
        }
    }

    /// What a failed mark does.
    enum Failure: Equatable {
        /// Queued on this device (offline, or a server without the bulk call: then silently).
        case queued(String?)
        /// Refused: the screen goes back, with this message.
        case reverted(String)
    }

    nonisolated static func failure(_ error: APIError, read: Bool) -> Failure {
        let older = error.status == 404 || error.status == 405
        if error.isConnectivity || older {
            return .queued(older ? nil : "You're offline: saved on this device, sent once the server is reachable.")
        }
        return .reverted("Couldn't mark them as \(read ? "read" : "unread"). \(error.localizedDescription)")
    }

    /// Saves the unread chapters of each series for offline reading (what
    /// the server has and this device doesn't yet); a message for the toast.
    func download(_ novels: [NovelSummary]) async -> String {
        guard let app, let client = app.client, let store = app.store else { return "Not connected." }
        var started = 0
        var failed = 0
        for novel in novels {
            let id = novel.id
            guard let chapters = await app.load(.chapters(id), fetch: { try await $0.chapters(novelId: id) }).value else {
                failed += 1
                continue
            }
            let cached = await store.cachedChapterIDs(novelId: id)
            let ids = chapters.filter { $0.downloaded && !$0.read && !cached.contains($0.id) }.map(\.id)
            if !ids.isEmpty {
                app.downloads.start(novelId: id, chapterIds: ids, manga: novel.isPaged, client: client, store: store)
                started += 1
            }
        }
        return Self.downloadMessage(started: started, failed: failed)
    }

    nonisolated static func downloadMessage(started: Int, failed: Int) -> String {
        if started == 0 && failed > 0 { return "Couldn't fetch the chapter lists. Try again when the server is reachable." }
        if started == 0 { return "Their unread chapters are already on this device." }
        if failed > 0 { return "Downloading \(seriesCount(started)); \(seriesCount(failed)) couldn't be checked." }
        return "Downloading the unread chapters of \(seriesCount(started))."
    }

    /// Deletes what `ids` have on this device.
    func removeDownloads(_ ids: [Int]) async -> String {
        guard let app, let store = app.store else { return "Not connected." }
        for id in ids { await app.downloads.remove(novelId: id, store: store) }
        return "Removed the downloads of \(seriesCount(ids.count))."
    }
}

/// "1 series", "3 series".
func seriesCount(_ count: Int) -> String { count == 1 ? "1 series" : "\(count) series" }
