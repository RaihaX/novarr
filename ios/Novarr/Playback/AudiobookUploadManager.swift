import Foundation
import Observation
import os
import UIKit

/// One upload as the screens show it: the saved entry plus live progress.
struct AudiobookUploadItem: Sendable, Equatable, Identifiable {
    var entry: AudiobookUploadEntry
    /// Bytes the server has (live while it runs, else as last saved).
    var doneBytes: Int64
    /// Being sent (or waiting to be) right now.
    var running: Bool
    var waitingForNetwork: Bool

    var id: String { entry.localId }
    var percent: Int { AudiobookUploads.percent(doneBytes, entry.totalBytes) }
}

/// A file chosen for an upload (Files, or handed in from another app).
struct PickedUpload: Sendable, Equatable, Identifiable, Hashable {
    var url: URL
    var picked: AudiobookUploads.Picked
    var uploadName: String
    var id: URL { url }
    var problem: AudiobookUploads.Problem? { AudiobookUploads.problem(picked) }

    /// Name, type and size of a file (security-scoped URLs are opened for the look); never throws.
    static func describe(_ url: URL, index: Int) -> PickedUpload {
        let scoped = url.startAccessingSecurityScopedResource()
        defer { if scoped { url.stopAccessingSecurityScopedResource() } }
        let values = try? url.resourceValues(forKeys: [.fileSizeKey, .contentTypeKey, .isReadableKey, .nameKey])
        let name = values?.name ?? url.lastPathComponent
        let size = values?.fileSize.map(Int64.init)
        let mime = values?.contentType?.preferredMIMEType
        let readable = values?.isReadable ?? FileManager.default.isReadableFile(atPath: url.path)
        let picked = AudiobookUploads.Picked(name: name, mime: mime, size: size, readable: readable)
        return PickedUpload(url: url, picked: picked, uploadName: AudiobookUploads.uploadName(name, index: index, mime: mime))
    }
}

/// An upload that can't start, with the sentence to show.
struct UploadStartError: LocalizedError {
    var message: String
    var errorDescription: String? { message }
}

/// Audiobook uploads (`/api/v1/audiobook-uploads`, android app/AudiobookUploadManager.kt):
/// creates the upload on the server, streams every file in chunks (three at a
/// time, each retried with backoff), resumes from the chunks the server says
/// it has, then completes it and waits for the server's verdict. State lives
/// in the session's `AudiobookUploadStore`, so the next launch carries on.
///
/// iOS: it runs while the app is alive (and for the short time iOS allows
/// after it goes to the background, or all along while an audiobook plays);
/// an upload stopped by the app being suspended continues where the server
/// says it is at the next launch or return to the app.
@MainActor
@Observable
final class AudiobookUploadManager {
    /// This session's uploads, newest first (cancelled ones are not listed).
    private(set) var uploads: [AudiobookUploadItem] = []

    @ObservationIgnored private var entries: [AudiobookUploadEntry] = []
    @ObservationIgnored private var live: [String: Int64] = [:]
    @ObservationIgnored private var waiting: Set<String> = []
    @ObservationIgnored private var tasks: [String: Task<Void, Never>] = [:]
    @ObservationIgnored private var store: AudiobookUploadStore?
    @ObservationIgnored private var storage: AudioStorage?
    @ObservationIgnored private var client: APIClient?
    @ObservationIgnored private let network: NetworkConditions
    @ObservationIgnored private let prefs: () -> ListeningPrefs
    @ObservationIgnored private let log = Logger(subsystem: "au.com.atomicinsights.novarr", category: "AudiobookUploads")

    private static let maxAttempts = 10
    private static let chunkTries = 5
    private static let maxRounds = 6
    private static let polls = 120
    private static let pollMs: Int64 = 5_000
    private static let pruneAfterMs: Int64 = 30 * 24 * 60 * 60 * 1000

    init(network: NetworkConditions, prefs: @escaping () -> ListeningPrefs) {
        self.network = network
        self.prefs = prefs
    }

    /// Another session (or none): its uploads stop here and the new one's carry on.
    func sessionChanged(storage: AudioStorage?, client: APIClient?) {
        tasks.values.forEach { $0.cancel() }
        tasks = [:]
        live = [:]
        waiting = []
        entries = []
        uploads = []
        self.storage = storage
        self.client = client
        store = storage.map { AudiobookUploadStore(fileURL: $0.uploadsFile) }
        Task { await refresh() }
    }

    // MARK: Starting and stopping

    /// Saves a new upload and starts it; answers its local id (nil when nobody
    /// is signed in). Files in the app's own storage (handed in from another
    /// app) are moved into the upload's folder; files picked in Files are read
    /// in place through a bookmark (copied only when that can't be made).
    func start(files: [PickedUpload], novelId: Int?, bookTitle: String?, note: String?) async throws -> String? {
        guard let store, let storage, !files.isEmpty else { return nil }
        let localId = UUID().uuidString
        let refs = try Self.keepReadable(localId: localId, files: files, storage: storage)
        let now = ListeningSync.nowMs()
        let trimmed = note?.trimmingCharacters(in: .whitespacesAndNewlines)
        let entry = AudiobookUploadEntry(
            localId: localId, novelId: novelId.flatMap { $0 > 0 ? $0 : nil },
            bookTitle: bookTitle.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 },
            note: trimmed.flatMap { $0.isEmpty ? nil : AudiobookUploads.clampNote($0) },
            files: refs, wifiOnly: prefs().wifiOnlyUploads, createdAt: now, updatedAt: now)
        await store.put(entry)
        await reload()
        launch(localId, retryFailed: false)
        return localId
    }

    /// Cancels an upload: the server is told (its chunks are deleted), then the entry is forgotten.
    func cancel(_ localId: String) async {
        guard let store else { return }
        tasks[localId]?.cancel()
        tasks[localId] = nil
        guard await store.change(localId, { var e = $0; e.status = AudiobookUploads.Status.cancelled.rawValue; e.message = nil; return e }) != nil
        else { return }
        await reload()
        await sendCancel(localId)
    }

    /// Starts a failed (or stalled) upload again, from the chunks the server still has.
    func retry(_ localId: String) async {
        guard let store else { return }
        let wifiOnly = prefs().wifiOnlyUploads
        guard await store.change(localId, { e in
            var e = e
            e.status = AudiobookUploads.Status.queued.rawValue
            e.message = nil
            e.wifiOnly = wifiOnly
            return e
        }) != nil else { return }
        tasks[localId]?.cancel()
        tasks[localId] = nil
        await reload()
        launch(localId, retryFailed: true)
    }

    /// Takes a finished upload off the list (nothing on the server changes).
    func remove(_ localId: String) async {
        guard let store, let entry = await store.get(localId), entry.state.finished else { return }
        await store.remove(localId)
        release(entry)
        await reload()
    }

    /// Brings the list up to date: an upload waiting for the owner is asked
    /// for its status (approved, declined); one that should be running but
    /// isn't (the app was closed meanwhile) starts again; a cancel that never
    /// reached the server is sent. Old finished entries (30 days) are dropped.
    func refresh() async {
        guard let store, let client else { return }
        let now = ListeningSync.nowMs()
        for entry in await store.all() {
            let running = tasks[entry.localId] != nil
            switch entry.state {
            case let state where state.finished && state != .pending && state != .cancelled
                && now - entry.updatedAt > Self.pruneAfterMs:
                await store.remove(entry.localId)
                release(entry)
            case .cancelled where !running:
                Task { await self.sendCancel(entry.localId) }
            case let state where !state.finished && !running:
                launch(entry.localId, retryFailed: false)
            case .pending:
                guard let serverId = entry.serverId,
                      let server = try? await client.audiobookUpload(id: serverId),
                      let status = AudiobookUploads.Status.fromServer(server.status),
                      status != entry.state, status.finished else { continue }
                let message = AudiobookUploads.serverMessage(server)
                await store.change(entry.localId) { e in
                    var e = e
                    e.status = status.rawValue
                    e.message = message ?? e.message
                    return e
                }
                if status != .pending { release(entry) }
            default:
                break
            }
        }
        await reload()
    }

    /// Re-reads the saved entries into `uploads`.
    func reload() async {
        guard let store else { return }
        entries = await store.all()
        publish()
    }

    private func publish() {
        uploads = entries.filter { $0.state != .cancelled }
            .sorted { $0.createdAt > $1.createdAt }
            .map { entry in
                AudiobookUploadItem(entry: entry, doneBytes: live[entry.localId] ?? entry.doneBytes,
                                    running: tasks[entry.localId] != nil, waitingForNetwork: waiting.contains(entry.localId))
            }
    }

    // MARK: The work

    private func launch(_ localId: String, retryFailed: Bool) {
        guard tasks[localId] == nil, let store, let client, let storage else { return }
        tasks[localId] = Task { [weak self] in
            let background = BackgroundTime("Audiobook upload")
            await self?.run(localId, retryFailed: retryFailed, store: store, client: client, storage: storage)
            background.end()
            guard let self, self.store === store else { return }
            self.tasks[localId] = nil
            self.live[localId] = nil
            self.waiting.remove(localId)
            await self.reload()
        }
        publish()
    }

    /// A server answer that ends the upload (refused, declined…): not retried.
    private struct Final: Error {
        var status: AudiobookUploads.Status
        var message: String?
    }

    /// The server no longer has this upload (404): start a new one.
    private struct Gone: Error {}

    /// A picked file can't be read any more (access gone, deleted, changed).
    struct SourceUnreadable: Error, LocalizedError {
        var name: String
        var changed = false
        var errorDescription: String? {
            changed ? "\(name) changed since it was picked. Pick the files again."
                : "Novarr can no longer read \(name). Pick the files again (or share them again)."
        }
    }

    private struct StillChecking: Error {}

    private func run(_ localId: String, retryFailed initialRetry: Bool, store: AudiobookUploadStore,
                     client: APIClient, storage: AudioStorage) async {
        var retryFailed = initialRetry
        var restarted = false
        for attempt in 0..<Self.maxAttempts {
            guard !Task.isCancelled, let entry = await store.get(localId), !entry.state.finished else { return }
            do {
                try await upload(entry, retryFailed: &retryFailed, restarted: &restarted, store: store, client: client)
                return
            } catch is CancellationError {
                return
            } catch {
                if Task.isCancelled { return }
                if await store.get(localId)?.state == .cancelled { return }
                switch error {
                case let final as Final:
                    await finish(localId, final.status, final.message, store: store)
                    return
                case is Gone:
                    if restarted {
                        await finish(localId, .failed, "The server lost this upload. Try again.", store: store)
                        return
                    }
                    restarted = true
                    await save(localId, store) { $0.serverId = nil; $0.doneBytes = 0; $0.status = AudiobookUploads.Status.queued.rawValue }
                case let unreadable as SourceUnreadable:
                    await finish(localId, .failed, unreadable.errorDescription, store: store)
                    return
                default:
                    let apiError = error is StillChecking ? APIError.timedOut : APIError.from(error)
                    guard apiError.isRetryable, attempt < Self.maxAttempts - 1 else {
                        await finish(localId, .failed, AudiobookUploads.failureMessage(apiError), store: store)
                        return
                    }
                    log.info("Upload \(localId, privacy: .public) paused: \(String(describing: apiError), privacy: .public)")
                    await save(localId, store) { $0.status = AudiobookUploads.Status.queued.rawValue; $0.message = nil }
                    let wait = AudiobookUploads.backoffMillis(attempt, jitter: .random(in: 0...1), baseMs: 5_000, maxMs: 300_000)
                    try? await Task.sleep(for: .milliseconds(wait))
                }
            }
        }
    }

    private func upload(_ start: AudiobookUploadEntry, retryFailed: inout Bool, restarted: inout Bool,
                        store: AudiobookUploadStore, client: APIClient) async throws {
        var entry = start
        let localId = entry.localId
        let total = entry.totalBytes
        try await Self.checkFiles(entry)
        var rounds = 0
        // Chunks complete asked for again (422 checksum / incomplete), sent in the next round.
        var resend: [Int: Set<Int>] = [:]
        while true {
            try Task.checkCancellation()
            rounds += 1
            if rounds > Self.maxRounds { throw Final(status: .failed, message: "The server keeps asking for the same chunks.") }
            await waitForNetwork(entry)
            // 1. Create it on the server (once; the id is saved before anything else).
            if entry.serverId == nil {
                let created = try await client.createAudiobookUpload(files: entry.files.map { ($0.name, $0.size) },
                                                                     novelId: entry.novelId, note: entry.note)
                guard let saved = await save(localId, store, { e in
                    e.serverId = created.id
                    e.chunkSize = AudiobookUploads.chunkSize(created.chunkSize)
                    e.status = AudiobookUploads.Status.uploading.rawValue
                    e.doneBytes = 0
                }) else { return }
                entry = saved
            }
            guard let serverId = entry.serverId else { return }
            // 2. What it already has (after a drop, a restart, another attempt).
            var server: AudiobookUploadResource?
            do {
                server = try await client.audiobookUpload(id: serverId)
            } catch let error as APIError where error.status == 404 {
                server = nil
            }
            let status = AudiobookUploads.Status.fromServer(server?.status)
            if server == nil || (retryFailed && [.failed, .declined, .cancelled].contains(status)) {
                if restarted { throw Final(status: .failed, message: "The server lost this upload. Try again.") }
                restarted = true
                retryFailed = false
                guard let saved = await save(localId, store, { $0.serverId = nil; $0.doneBytes = 0 }) else { return }
                entry = saved
                continue
            }
            retryFailed = false
            guard let server else { return }
            switch status {
            case .pending, .approved, .declined, .failed, .cancelled:
                await finish(localId, status ?? .failed, AudiobookUploads.serverMessage(server), store: store)
                return
            case .completing:
                if try await waitForVerdict(localId, serverId: serverId, store: store, client: client) { continue }
                return
            default:
                break
            }
            let serverChunk = AudiobookUploads.chunkSize(server.chunkSize)
            if serverChunk != entry.chunkSize {
                guard let saved = await save(localId, store, { $0.chunkSize = serverChunk }) else { return }
                entry = saved
            }
            // 3. The missing chunks, three at a time (plus any complete said to send again).
            let plan = AudiobookUploads.plan(entry.files.map(\.size), chunkSize: entry.chunkSize)
            var seen = Set<AudiobookUploads.Chunk>()
            let missing = (AudiobookUploads.missing(plan, present: AudiobookUploads.present(server),
                                                    doneFiles: AudiobookUploads.doneFiles(server))
                + AudiobookUploads.named(plan, resend)).filter { seen.insert($0).inserted }
            resend = [:]
            let confirmed = total - AudiobookUploads.bytesOf(missing)
            guard let saved = await save(localId, store, { $0.status = AudiobookUploads.Status.uploading.rawValue; $0.doneBytes = confirmed })
            else { return }
            entry = saved
            try await sendAll(entry, serverId: serverId, missing: missing, confirmed: confirmed, store: store, client: client)
            // 4. Done: the server assembles, verifies and probes.
            guard await save(localId, store, { $0.status = AudiobookUploads.Status.completing.rawValue; $0.doneBytes = total }) != nil
            else { return }
            var answer: AudiobookUploadResource?
            do {
                answer = try await client.completeAudiobookUpload(id: serverId)
            } catch let error as APIError {
                if let status = error.status {
                    if case .rejected(let failure) = error,
                       let named = AudiobookUploads.resendAfterComplete(status: status, code: failure.code, body: failure.body) {
                        resend = named
                        continue
                    }
                    switch status {
                    case 404: throw Gone()
                    // Refused (not audio, a size or checksum mismatch, a limit): the server says why.
                    case 422, 409: throw Final(status: .failed, message: AudiobookUploads.failureMessage(error))
                    default: throw error
                    }
                }
                // No answer in time (it reads gigabytes): ask how it went.
                guard error.isConnectivity else { throw error }
                answer = nil
            }
            let verdict = AudiobookUploads.Status.fromServer(answer?.status)
            if verdict == .uploading { continue } // it still misses chunks: send them
            if let verdict, verdict.finished, let answer {
                await finish(localId, verdict, AudiobookUploads.serverMessage(answer), store: store)
                return
            }
            if try await waitForVerdict(localId, serverId: serverId, store: store, client: client) { continue }
            return
        }
    }

    /// Polls until the server has a verdict; true when it says chunks are missing (send them), false when finished.
    private func waitForVerdict(_ localId: String, serverId: Int, store: AudiobookUploadStore,
                                client: APIClient) async throws -> Bool {
        for poll in 0..<Self.polls {
            try await Task.sleep(for: .milliseconds(poll == 0 ? 2_000 : Self.pollMs))
            let server: AudiobookUploadResource
            do {
                server = try await client.audiobookUpload(id: serverId)
            } catch let error as APIError {
                if error.status == 404 { throw Gone() }
                if !error.isRetryable { throw error }
                continue
            }
            switch AudiobookUploads.Status.fromServer(server.status) {
            case .uploading: return true
            case nil, .queued, .completing: continue
            case let status?:
                await finish(localId, status, AudiobookUploads.serverMessage(server), store: store)
                return false
            }
        }
        // Still checking after a long while: tried again later (and asked again).
        throw StillChecking()
    }

    /// Sends `missing` with up to three chunks in flight; each chunk is retried with backoff before giving up.
    private func sendAll(_ entry: AudiobookUploadEntry, serverId: Int, missing: [AudiobookUploads.Chunk],
                         confirmed start: Int64, store: AudiobookUploadStore, client: APIClient) async throws {
        guard !missing.isEmpty else { return }
        let localId = entry.localId
        var confirmed = start
        live[localId] = confirmed
        publish()
        try await withThrowingTaskGroup(of: Int64.self) { group in
            var queue = missing.makeIterator()
            func addNext() -> Bool {
                guard let chunk = queue.next() else { return false }
                let file = entry.files[chunk.file]
                group.addTask { [weak self] in
                    try await self?.sendChunk(entry, file: file, chunk: chunk, serverId: serverId, client: client)
                    return chunk.length
                }
                return true
            }
            for _ in 0..<AudiobookUploads.parallelChunks { if !addNext() { break } }
            while let length = try await group.next() {
                confirmed += length
                live[localId] = confirmed
                let done = confirmed
                _ = await save(localId, store) { $0.doneBytes = done }
                _ = addNext()
            }
        }
    }

    private func sendChunk(_ entry: AudiobookUploadEntry, file: UploadFileRef, chunk: AudiobookUploads.Chunk,
                           serverId: Int, client: APIClient) async throws {
        var attempt = 0
        while true {
            try Task.checkCancellation()
            await waitForNetwork(entry)
            let data = try await Self.readChunk(file, chunk)
            do {
                try await client.putAudiobookChunk(id: serverId, file: chunk.file, chunk: chunk.index, offset: chunk.offset,
                                                   fileSize: chunk.fileSize, data: data)
                return
            } catch {
                let apiError = APIError.from(error)
                if apiError.status == 404 { throw Gone() }
                if !AudiobookUploads.isRetryable(apiError) || attempt >= Self.chunkTries - 1 { throw apiError }
                let retryAfter = apiError.retryAfter.map { String(Int($0)) }
                try await Task.sleep(for: .milliseconds(AudiobookUploads.backoffMillis(attempt, jitter: .random(in: 0...1),
                                                                                     retryAfter: retryAfter)))
                attempt += 1
            }
        }
    }

    /// Waits for a network (Wi-Fi for a Wi-Fi only upload), showing "waiting" meanwhile.
    private func waitForNetwork(_ entry: AudiobookUploadEntry) async {
        let localId = entry.localId
        guard !network.allows(wifiOnly: entry.wifiOnly) else { return }
        waiting.insert(localId)
        publish()
        await network.waitUntilAllowed { [weak self] in
            self?.entries.first { $0.localId == localId }?.wifiOnly ?? entry.wifiOnly
        }
        waiting.remove(localId)
        publish()
    }

    @discardableResult
    private func save(_ localId: String, _ store: AudiobookUploadStore,
                      _ change: @escaping @Sendable (inout AudiobookUploadEntry) -> Void) async -> AudiobookUploadEntry? {
        // Cancelled meanwhile: never written over (the cancel owns it now).
        let result = await store.change(localId) { current in
            guard current.state != .cancelled else { return current }
            var next = current
            change(&next)
            return next
        }
        if self.store === store {
            if let result, let i = entries.firstIndex(where: { $0.localId == localId }) { entries[i] = result }
            publish()
        }
        return result?.state == .cancelled ? nil : result
    }

    private func finish(_ localId: String, _ status: AudiobookUploads.Status, _ message: String?,
                        store: AudiobookUploadStore) async {
        guard let entry = await save(localId, store, { e in
            e.status = status.rawValue
            e.message = message
            if status != .failed { e.doneBytes = e.totalBytes }
        }) else { return }
        // Sent (or over): the files are no longer needed. A failed one keeps them for "Try again".
        if status != .failed { release(entry) }
    }

    private func sendCancel(_ localId: String) async {
        guard let store, let client, let entry = await store.get(localId) else { return }
        if let serverId = entry.serverId {
            for attempt in 0..<4 {
                do {
                    try await client.cancelAudiobookUpload(id: serverId)
                    break
                } catch {
                    let apiError = APIError.from(error)
                    // Not retryable (or gave up): the server's nightly tidy-up removes abandoned uploads.
                    guard apiError.isRetryable, attempt < 3 else { break }
                    try? await Task.sleep(for: .milliseconds(AudiobookUploads.backoffMillis(attempt, baseMs: 2_000)))
                }
            }
        }
        guard self.store === store else { return }
        await store.remove(localId)
        release(entry)
        await reload()
    }

    /// The upload no longer needs its files: copies are deleted.
    private func release(_ entry: AudiobookUploadEntry) {
        guard let storage else { return }
        try? FileManager.default.removeItem(at: storage.uploadCopies(entry.localId))
    }

    // MARK: Files

    /// The files as the upload will read them: files in the app's own storage
    /// moved into the upload's folder, others by bookmark (copied when one
    /// can't be made).
    nonisolated static func keepReadable(localId: String, files: [PickedUpload], storage: AudioStorage) throws -> [UploadFileRef] {
        let fm = FileManager.default
        let dir = storage.uploadCopies(localId)
        let home = URL(fileURLWithPath: NSHomeDirectory()).standardizedFileURL.path
        var refs: [UploadFileRef] = []
        do {
            for (index, file) in files.enumerated() {
                let size = file.picked.size ?? 0
                let url = file.url
                let ours = url.standardizedFileURL.path.hasPrefix(home)
                if !ours {
                    let scoped = url.startAccessingSecurityScopedResource()
                    defer { if scoped { url.stopAccessingSecurityScopedResource() } }
                    if let bookmark = try? url.bookmarkData() {
                        refs.append(UploadFileRef(bookmark: bookmark, name: file.uploadName, size: size, mime: file.picked.mime))
                        continue
                    }
                }
                try fm.createDirectory(at: dir, withIntermediateDirectories: true)
                let safe = String(file.uploadName.filter { $0.isLetter || $0.isNumber || "._- ".contains($0) }.prefix(80))
                let target = dir.appending(path: String(format: "%03d-%@", index, safe))
                try? fm.removeItem(at: target)
                if ours {
                    try fm.moveItem(at: url, to: target)
                } else {
                    let scoped = url.startAccessingSecurityScopedResource()
                    defer { if scoped { url.stopAccessingSecurityScopedResource() } }
                    try fm.copyItem(at: url, to: target)
                }
                refs.append(UploadFileRef(path: target.path, name: file.uploadName, size: size, mime: file.picked.mime))
            }
        } catch {
            try? fm.removeItem(at: dir)
            throw UploadStartError(message: "Couldn't read these files. Save them to Files first, then pick them in Upload an audiobook.")
        }
        return refs
    }

    /// A file's URL to read (and whether it needs security-scoped access).
    nonisolated static func resolve(_ file: UploadFileRef) throws -> (url: URL, scoped: Bool) {
        if let path = file.path { return (URL(fileURLWithPath: path), false) }
        guard let bookmark = file.bookmark else { throw SourceUnreadable(name: file.name) }
        var stale = false
        guard let url = try? URL(resolvingBookmarkData: bookmark, bookmarkDataIsStale: &stale) else {
            throw SourceUnreadable(name: file.name)
        }
        return (url, true)
    }

    /// Every file still there, readable, and the size it had when picked.
    nonisolated static func checkFiles(_ entry: AudiobookUploadEntry) async throws {
        for file in entry.files {
            let (url, scoped) = try resolve(file)
            let opened = scoped && url.startAccessingSecurityScopedResource()
            defer { if opened { url.stopAccessingSecurityScopedResource() } }
            guard let attributes = try? FileManager.default.attributesOfItem(atPath: url.path),
                  let size = (attributes[.size] as? NSNumber)?.int64Value else { throw SourceUnreadable(name: file.name) }
            if size != file.size { throw SourceUnreadable(name: file.name, changed: true) }
        }
    }

    /// The chunk's bytes, read from the file (never the whole file in memory).
    nonisolated static func readChunk(_ file: UploadFileRef, _ chunk: AudiobookUploads.Chunk) async throws -> Data {
        let (url, scoped) = try resolve(file)
        let opened = scoped && url.startAccessingSecurityScopedResource()
        defer { if opened { url.stopAccessingSecurityScopedResource() } }
        guard let handle = try? FileHandle(forReadingFrom: url) else { throw SourceUnreadable(name: file.name) }
        defer { try? handle.close() }
        do {
            try handle.seek(toOffset: UInt64(chunk.offset))
            let data = try handle.read(upToCount: Int(chunk.length)) ?? Data()
            guard data.count == Int(chunk.length) else { throw SourceUnreadable(name: file.name, changed: true) }
            return data
        } catch let error as SourceUnreadable {
            throw error
        } catch {
            throw SourceUnreadable(name: file.name)
        }
    }
}

/// A little time after the app goes to the background, to finish what is on the wire.
@MainActor
final class BackgroundTime {
    private var id = UIBackgroundTaskIdentifier.invalid

    init(_ name: String) {
        id = UIApplication.shared.beginBackgroundTask(withName: name) { [weak self] in self?.end() }
    }

    func end() {
        guard id != .invalid else { return }
        UIApplication.shared.endBackgroundTask(id)
        id = .invalid
    }
}
