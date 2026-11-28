import Foundation
import os

/// This device's listening state for one session (server and user), in one
/// small JSON file: the latest position per audiobook (sent or not), which of
/// them still have to be sent, and the audiobook last played (restored at the
/// next launch). Mirrors android store/ListeningStore.kt.
///
/// Progress is absolute (a whole-book position), so only an audiobook's
/// newest position matters: a new one replaces an older unsent one, and an
/// older one never replaces a newer one (last write wins by recorded time).
actor ListeningStore {
    struct Entry: Codable, Sendable, Equatable {
        var record: ListeningRecord
        var pending: Bool
    }

    struct Snapshot: Codable, Sendable, Equatable {
        var entries: [Entry] = []
        var lastAudiobookId: Int?
    }

    enum FlushOutcome: Sendable, Equatable {
        case drained(sent: Int, dropped: Int)
        case stalled(sent: Int, remaining: Int)
        case unauthenticated(sent: Int, remaining: Int)
        case busy
    }

    static let fileName = "listening.json"

    let fileURL: URL
    private var state: Snapshot
    private var flushing = false
    private let log = Logger(subsystem: "au.com.atomicinsights.novarr", category: "Listening")

    init(fileURL: URL) {
        self.fileURL = fileURL
        state = (try? Data(contentsOf: fileURL)).flatMap { try? JSONDecoder().decode(Snapshot.self, from: $0) } ?? Snapshot()
    }

    /// Records a position to send. False when a newer one was already recorded (it is kept).
    @discardableResult
    func record(_ record: ListeningRecord) -> Bool {
        let current = state.entries.first { $0.record.audiobookId == record.audiobookId }
        guard ListeningSync.supersedes(record, current?.record) else { return false }
        state.entries.removeAll { $0.record.audiobookId == record.audiobookId }
        state.entries.append(Entry(record: record, pending: true))
        persist()
        return true
    }

    /// This device's latest position for `audiobookId` (sent or not).
    func latest(_ audiobookId: Int) -> ListeningRecord? {
        state.entries.first { $0.record.audiobookId == audiobookId }?.record
    }

    /// Positions still to be sent, oldest first.
    func pending() -> [ListeningRecord] {
        state.entries.filter(\.pending).map(\.record).sorted { $0.recordedAt < $1.recordedAt }
    }

    func pendingCount() -> Int { state.entries.count(where: \.pending) }

    func lastAudiobookId() -> Int? { state.lastAudiobookId }

    func setLastAudiobookId(_ id: Int?) {
        guard state.lastAudiobookId != id else { return }
        state.lastAudiobookId = id
        persist()
    }

    /// Sends every pending position, oldest first. A position recorded while
    /// one was being sent stays pending (only the one sent is marked done).
    /// Stops at a retryable failure or a 401, keeping the rest; a position the
    /// server refuses outright (a deleted audiobook) is dropped.
    func flush(_ send: @Sendable (ListeningRecord) async throws -> Void) async -> FlushOutcome {
        guard !flushing else { return .busy }
        flushing = true
        defer { flushing = false }
        var sent = 0
        var dropped = 0
        for record in pending() {
            do {
                try await send(record)
                markSent(record)
                sent += 1
            } catch {
                let apiError = APIError.from(error)
                if apiError.isUnauthenticated { return .unauthenticated(sent: sent, remaining: pendingCount()) }
                if apiError.isRetryable { return .stalled(sent: sent, remaining: pendingCount()) }
                log.error("Dropping a listening position the server refused: \(String(describing: apiError), privacy: .public)")
                dropped += 1
                markSent(record)
            }
        }
        return .drained(sent: sent, dropped: dropped)
    }

    private func markSent(_ record: ListeningRecord) {
        for i in state.entries.indices where state.entries[i].record == record {
            state.entries[i].pending = false
        }
        persist()
    }

    private func persist() {
        do {
            try FileManager.default.createDirectory(at: fileURL.deletingLastPathComponent(), withIntermediateDirectories: true)
            try JSONEncoder().encode(state).write(to: fileURL, options: .atomic)
        } catch {
            log.error("Could not save listening state: \(error.localizedDescription, privacy: .public)")
        }
    }
}
