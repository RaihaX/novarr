import Foundation
import Network
import Observation
import os

/// Result of a read that may have come from the offline cache.
struct Loaded<Value: Sendable>: Sendable {
    var value: Value?
    var fromCache: Bool
    var error: APIError?
}

/// App-wide state: the configured server, its client, offline store and
/// write queue, plus connectivity.
@MainActor
@Observable
final class AppModel {
    static let serverDefaultsKey = "serverURL"

    private(set) var serverURL: URL?
    private(set) var client: APIClient?
    private(set) var store: OfflineStore?
    private(set) var queue: WriteQueue?
    private(set) var serverMeta: ServerMeta?

    /// Whether the last request reached the server.
    private(set) var isReachable = true
    private(set) var pendingWrites = 0
    /// Bumped after read-state changes so list screens know to reload.
    private(set) var readStateRevision = 0

    let downloads = DownloadManager()

    @ObservationIgnored private let defaults: UserDefaults
    @ObservationIgnored private let monitor = NWPathMonitor()
    @ObservationIgnored private let log = Logger(subsystem: "studio.visns.novarr", category: "App")

    init(defaults: UserDefaults = .standard) {
        self.defaults = defaults
        // Also honours `-serverURL http://…` as a launch argument (argument domain).
        if let raw = defaults.string(forKey: Self.serverDefaultsKey), let url = ServerURL.normalize(raw) {
            configure(url)
        }
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            Task { @MainActor in await self?.flushWrites() }
        }
        monitor.start(queue: DispatchQueue(label: "studio.visns.novarr.path"))
    }

    // MARK: Server

    /// Saves a server that `APIClient.meta()` already verified.
    func connect(to url: URL, meta: ServerMeta) {
        defaults.set(url.absoluteString, forKey: Self.serverDefaultsKey)
        configure(url)
        serverMeta = meta
        isReachable = true
    }

    private func configure(_ url: URL) {
        serverURL = url
        client = APIClient(baseURL: url)
        let directory = OfflineStore.directory(for: url)
        store = OfflineStore(root: directory)
        let queue = WriteQueue(fileURL: directory.appending(path: OfflineStore.writeQueueFileName))
        self.queue = queue
        readStateRevision += 1
        Task { [weak self] in
            self?.pendingWrites = await queue.count
            await self?.flushWrites()
        }
    }

    func refreshMeta() async {
        guard let client else { return }
        do {
            serverMeta = try await client.meta()
            isReachable = true
        } catch {
            noteFailure(APIError.from(error))
        }
    }

    // MARK: Reads with offline fallback

    /// Fetches from the server and caches the result; if the server can't be
    /// reached, returns the cached copy (marked `fromCache`) with the error.
    func load<Value: Codable & Sendable>(
        _ key: OfflineStore.Key,
        fetch: @Sendable (APIClient) async throws -> Value
    ) async -> Loaded<Value> {
        guard let client, let store else {
            return Loaded(value: nil, fromCache: false, error: .invalidServerURL)
        }
        do {
            let value = try await fetch(client)
            noteSuccess()
            await store.save(value, as: key)
            return Loaded(value: value, fromCache: false, error: nil)
        } catch {
            let apiError = APIError.from(error)
            noteFailure(apiError)
            let cached = await store.load(Value.self, as: key)
            return Loaded(value: cached, fromCache: cached != nil, error: apiError)
        }
    }

    func cached<Value: Codable & Sendable>(_ type: Value.Type, _ key: OfflineStore.Key) async -> Value? {
        await store?.load(type, as: key)
    }

    // MARK: Writes

    /// Queues a read-state write (persisted immediately) and tries to send it.
    func record(_ write: PendingWrite.Kind) async {
        guard let queue else { return }
        await queue.enqueue(write)
        pendingWrites = await queue.count
        switch write {
        case .progress(_, _, let read) where !read: break
        default: readStateRevision += 1
        }
        await flushWrites()
    }

    func flushWrites() async {
        guard let queue, let client else { return }
        guard await queue.count > 0 else {
            pendingWrites = 0
            return
        }
        let outcome = await queue.flush(using: client)
        pendingWrites = await queue.count
        switch outcome {
        case .drained(let sent, _) where sent > 0:
            noteSuccess()
            readStateRevision += 1
        case .stalled:
            isReachable = false
        default:
            break
        }
    }

    // MARK: Connectivity bookkeeping

    func noteSuccess() {
        let wasUnreachable = !isReachable
        isReachable = true
        if wasUnreachable, pendingWrites > 0 {
            Task { await flushWrites() }
        }
    }

    func noteFailure(_ error: APIError) {
        if error.isConnectivity { isReachable = false }
    }

    // MARK: Storage

    func clearCaches() async {
        await store?.clearAll()
        await CoverCache.shared.clear()
        URLSession.novarr.configuration.urlCache?.removeAllCachedResponses()
        downloads.contentChanged()
    }
}
