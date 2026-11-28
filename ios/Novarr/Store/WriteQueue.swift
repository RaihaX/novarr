import Foundation
import os

struct PendingWrite: Codable, Sendable, Identifiable, Equatable {
    enum Kind: Codable, Sendable, Equatable {
        case progress(chapterId: Int, progress: Int?, read: Bool)
        case setRead(chapterId: Int, read: Bool)
        case bulkRead(BulkReadRequest)
        /// A new highlight. Added after the read-state kinds: queues persisted
        /// before it still decode, since each case is keyed by its own name.
        case highlight(HighlightDraft)
        /// The read mark for a chapter opened as a look-up (from a highlight):
        /// sent as progress 100 with `lookup: true`, so the server marks it
        /// read without moving the series' latest position. Added last, like
        /// `highlight`, so older persisted queues still decode.
        case lookupRead(chapterId: Int)
    }

    var id: UUID
    var kind: Kind
    /// Device wall-clock time when the write was recorded. Only differences
    /// against the same clock are sent (`age`), so clock skew doesn't matter.
    var recordedAt: Date
    /// The series this write belongs to, when known (for the "latest position"
    /// check); never sent. Older persisted queues decode it as nil.
    var novelId: Int? = nil

    /// Milliseconds since this write was recorded, never negative.
    func age(at now: Date) -> Int {
        let ms = now.timeIntervalSince(recordedAt) * 1000
        guard ms.isFinite, ms > 0 else { return 0 }
        return Int(min(ms, Double(Int.max / 2)).rounded())
    }

    /// Whether `writes` hold an unsent progress or read/unread write for this
    /// one chapter. Bulk marking and highlights don't count: they say nothing
    /// about where the reader stopped inside the chapter.
    static func containsChapterWrite(_ writes: [PendingWrite], chapterId: Int) -> Bool {
        writes.contains {
            switch $0.kind {
            case .progress(let id, _, _), .setRead(let id, _), .lookupRead(let id): id == chapterId
            case .bulkRead, .highlight: false
            }
        }
    }

    /// Highlights still waiting to be sent for one chapter, oldest first.
    static func highlightDrafts(_ writes: [PendingWrite], chapterId: Int) -> [HighlightDraft] {
        writes.compactMap {
            if case .highlight(let draft) = $0.kind, draft.chapterId == chapterId { return draft }
            return nil
        }
    }
}

/// A persistent FIFO of read-state writes (and new highlights). Every write goes through here:
/// it is persisted first, then sent; it leaves the queue only when the server
/// accepted it (or rejected it permanently), so nothing is lost offline.
actor WriteQueue {
    enum FlushOutcome: Equatable, Sendable {
        /// Queue emptied. `dropped` counts writes the server rejected permanently.
        case drained(sent: Int, dropped: Int)
        /// Stopped at a retryable failure; the remaining writes stay queued.
        case stalled(sent: Int, remaining: Int)
        /// The server said the sign-in is gone (401). Nothing is dropped: the
        /// remaining writes wait for the user to sign in again.
        case unauthenticated(sent: Int, remaining: Int)
        /// Another flush was already running.
        case busy
    }

    private(set) var items: [PendingWrite] = []
    private let fileURL: URL
    private let now: @Sendable () -> Date
    private var isFlushing = false
    private let log = Logger(subsystem: "au.com.atomicinsights.novarr", category: "WriteQueue")

    init(fileURL: URL, now: @escaping @Sendable () -> Date = { Date() }) {
        self.fileURL = fileURL
        self.now = now
        if let data = try? Data(contentsOf: fileURL),
           let stored = try? Self.decoder().decode([PendingWrite].self, from: data) {
            items = stored
        }
    }

    var count: Int { items.count }

    func enqueue(_ kind: Kind, novelId: Int? = nil) {
        // A newer scroll position supersedes an older unsent one for the same
        // chapter; read flags and explicit read/unread writes are never merged.
        if case .progress(let chapterId, _, false) = kind {
            items.removeAll {
                if case .progress(chapterId, _, false) = $0.kind { return true }
                return false
            }
        }
        items.append(PendingWrite(id: UUID(), kind: kind, recordedAt: now(), novelId: novelId))
        persist()
    }

    typealias Kind = PendingWrite.Kind

    /// Sends queued writes oldest-first through `send`, stopping at the first
    /// retryable failure so ordering is preserved.
    func flush(send: @Sendable (PendingWrite.Kind, Int) async throws -> Void) async -> FlushOutcome {
        guard !isFlushing else { return .busy }
        isFlushing = true
        defer { isFlushing = false }

        var sent = 0
        var dropped = 0
        while let next = items.first {
            do {
                try await send(next.kind, next.age(at: now()))
                sent += 1
                remove(next.id)
            } catch {
                let apiError = APIError.from(error)
                if apiError.isUnauthenticated {
                    return .unauthenticated(sent: sent, remaining: items.count)
                }
                if apiError.isRetryable {
                    return .stalled(sent: sent, remaining: items.count)
                }
                log.error("Dropping write the server rejected: \(String(describing: apiError), privacy: .public)")
                dropped += 1
                remove(next.id)
            }
        }
        return .drained(sent: sent, dropped: dropped)
    }

    func flush(using client: APIClient) async -> FlushOutcome {
        await flush { kind, age in try await client.send(kind, age: age) }
    }

    func removeAll() {
        items.removeAll()
        persist()
    }

    // Millisecond timestamps so `age` survives a relaunch precisely.
    private static func encoder() -> JSONEncoder {
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .millisecondsSince1970
        return encoder
    }

    private static func decoder() -> JSONDecoder {
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .millisecondsSince1970
        return decoder
    }

    private func remove(_ id: UUID) {
        items.removeAll { $0.id == id }
        persist()
    }

    private func persist() {
        do {
            try FileManager.default.createDirectory(
                at: fileURL.deletingLastPathComponent(), withIntermediateDirectories: true)
            let data = try Self.encoder().encode(items)
            try data.write(to: fileURL, options: .atomic)
        } catch {
            log.error("Could not persist write queue: \(error.localizedDescription, privacy: .public)")
        }
    }
}
