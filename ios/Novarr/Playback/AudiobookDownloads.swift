import Foundation
import Observation
import os

/// An audiobook's download, as the UI shows it.
enum AudiobookDownloadState: Sendable, Equatable {
    case none
    /// Waiting for a network (`wifiOnly`: for Wi-Fi) or for its turn.
    case waiting(wifiOnly: Bool)
    case running(done: Int64, total: Int64)
    case done(bytes: Int64)
    case failed(String)

    var fraction: Double {
        guard case .running(let done, let total) = self, total > 0 else { return 0 }
        return min(max(Double(done) / Double(total), 0), 1)
    }

    var isActive: Bool {
        switch self {
        case .waiting, .running: true
        default: false
        }
    }
}

/// A finished download: which file is each part. Written last, so its
/// presence means every part is on this device.
struct AudiobookDownloadManifest: Codable, Sendable, Equatable, Identifiable {
    var id: Int
    var title: String
    /// Part index → file name in the audiobook's folder.
    var files: [Int: String]
    var bytes: Int64
}

/// "Download for offline" for audiobooks (android playback/AudiobookDownloads.kt):
/// one task per audiobook, parts one after another into Application Support
/// (excluded from backup), resumable (a stopped part continues from its
/// resume data), optionally only on Wi-Fi. The player uses the files when
/// every part is there. Runs while the app is alive (in the background too
/// while an audiobook plays); a download stopped by the app being suspended
/// or closed continues from where it was when started again.
@MainActor
@Observable
final class AudiobookDownloads {
    static let manifestName = "download.json"
    static let detailName = "detail.json"
    private static let maxAttempts = 8

    private(set) var states: [Int: AudiobookDownloadState] = [:]
    /// Bumped when a download finished or was removed.
    private(set) var revision = 0

    @ObservationIgnored private var tasks: [Int: Task<Void, Never>] = [:]
    @ObservationIgnored private var storage: AudioStorage?
    @ObservationIgnored private var client: APIClient?
    @ObservationIgnored private let network: NetworkConditions
    @ObservationIgnored private let prefs: () -> ListeningPrefs
    @ObservationIgnored private let downloader = PartDownloader()
    @ObservationIgnored var onChange: () -> Void = {}
    @ObservationIgnored private let log = Logger(subsystem: "au.com.atomicinsights.novarr", category: "AudiobookDownloads")

    init(network: NetworkConditions, prefs: @escaping () -> ListeningPrefs) {
        self.network = network
        self.prefs = prefs
    }

    /// Another session: its downloads are another folder; running ones stop.
    func sessionChanged(storage: AudioStorage?, client: APIClient?) {
        tasks.values.forEach { $0.cancel() }
        tasks = [:]
        states = [:]
        self.storage = storage
        self.client = client
        revision += 1
    }

    func state(_ id: Int) -> AudiobookDownloadState {
        _ = revision // observed: a finished or removed download changes the answer
        if let state = states[id] { return state }
        if let manifest = manifest(id) { return .done(bytes: manifest.bytes) }
        return .none
    }

    /// Every part's file when the whole audiobook is on this device (else nil).
    func localFiles(_ id: Int, partCount: Int) -> [Int: URL]? {
        guard let storage, let manifest = manifest(id), partCount > 0, manifest.files.count == partCount else { return nil }
        let dir = storage.downloadDirectory(id)
        var files: [Int: URL] = [:]
        for (index, name) in manifest.files {
            let url = dir.appending(path: name)
            guard FileManager.default.fileExists(atPath: url.path) else { return nil }
            files[index] = url
        }
        return files
    }

    /// The detail saved with a download (plays offline even after "Clear cache").
    func savedDetail(_ id: Int) -> AudiobookDetail? {
        guard let storage else { return nil }
        let url = storage.downloadDirectory(id).appending(path: Self.detailName)
        return (try? Data(contentsOf: url)).flatMap { try? JSONCoding.decoder().decode(AudiobookDetail.self, from: $0) }
    }

    /// Every finished download, largest first.
    func all() -> [AudiobookDownloadManifest] {
        _ = revision
        guard let storage else { return [] }
        let names = (try? FileManager.default.contentsOfDirectory(atPath: storage.downloadsDirectory.path)) ?? []
        return names.compactMap(Int.init).compactMap(manifest).sorted { $0.bytes > $1.bytes }
    }

    func totalBytes() -> Int64 { all().reduce(0) { $0 + $1.bytes } }

    func start(_ id: Int, title: String) {
        guard tasks[id] == nil, let storage, let client else { return }
        if case .done = state(id) { return }
        states[id] = .waiting(wifiOnly: prefs().wifiOnlyDownloads)
        tasks[id] = Task { [weak self] in
            await self?.run(id: id, title: title, storage: storage, client: client)
        }
    }

    func cancel(_ id: Int) {
        tasks[id]?.cancel()
        tasks[id] = nil
        states[id] = nil
    }

    /// Stops a running download and deletes the files (partial ones too).
    func remove(_ id: Int) {
        cancel(id)
        if let storage { try? FileManager.default.removeItem(at: storage.downloadDirectory(id)) }
        revision += 1
        onChange()
    }

    // The work

    private func run(id: Int, title: String, storage: AudioStorage, client: APIClient) async {
        var attempt = 0
        while !Task.isCancelled {
            let wifiOnly = prefs().wifiOnlyDownloads
            if !network.allows(wifiOnly: wifiOnly) {
                states[id] = .waiting(wifiOnly: wifiOnly)
                await network.waitUntilAllowed { [weak self] in self?.prefs().wifiOnlyDownloads ?? true }
                continue
            }
            do {
                try await download(id: id, title: title, storage: storage, client: client, wifiOnly: wifiOnly)
                guard !Task.isCancelled, self.storage == storage else { return }
                states[id] = nil
                tasks[id] = nil
                revision += 1
                onChange()
                return
            } catch is CancellationError {
                return
            } catch {
                let apiError = APIError.from(error)
                guard !Task.isCancelled, self.storage == storage else { return }
                attempt += 1
                if (apiError.isRetryable || error is URLError) && attempt < Self.maxAttempts {
                    states[id] = .waiting(wifiOnly: wifiOnly)
                    let wait = AudiobookUploads.backoffMillis(attempt, jitter: .random(in: 0...1), baseMs: 2_000)
                    try? await Task.sleep(for: .milliseconds(wait))
                    continue
                }
                log.error("Audiobook \(id) download failed: \(String(describing: apiError), privacy: .public)")
                states[id] = .failed(apiError.errorDescription ?? "The download failed.")
                tasks[id] = nil
                return
            }
        }
    }

    private func download(id: Int, title: String, storage: AudioStorage, client: APIClient, wifiOnly: Bool) async throws {
        let detail = try await client.audiobook(id: id)
        let parts = detail.parts
        guard !parts.isEmpty else { throw APIError.decoding("This audiobook has no audio files.") }
        let dir = storage.downloadDirectory(id)
        try FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        try JSONCoding.encoder().encode(detail).write(to: dir.appending(path: Self.detailName), options: .atomic)
        let total = parts.reduce(Int64(0)) { $0 + $1.sizeBytes }
        var done: Int64 = 0
        var files: [Int: String] = [:]
        states[id] = .running(done: 0, total: total)
        for part in parts {
            try Task.checkCancellation()
            let name = Self.fileName(part)
            let target = dir.appending(path: name)
            if !Self.isComplete(target, size: part.sizeBytes) {
                guard let url = part.url else { throw APIError.decoding("A part has no address.") }
                var request = URLRequest(url: url, timeoutInterval: 60)
                request.allowsExpensiveNetworkAccess = !wifiOnly
                for (key, value) in Self.headersFor(url, server: client.baseURL, token: client.token,
                                                    device: client.device?.headers ?? [:]) {
                    request.setValue(value, forHTTPHeaderField: key)
                }
                let base = done
                try await downloader.download(request, to: target, resumeFile: dir.appending(path: name + ".resume")) {
                    [weak self] written in
                    Task { @MainActor in
                        guard let self, self.tasks[id] != nil else { return }
                        self.states[id] = .running(done: base + written, total: total)
                    }
                }
                if part.sizeBytes > 0, !Self.isComplete(target, size: part.sizeBytes) {
                    try? FileManager.default.removeItem(at: target)
                    throw URLError(.networkConnectionLost)
                }
            }
            files[part.index] = name
            done += Self.fileSize(target)
            states[id] = .running(done: done, total: total)
        }
        let manifest = AudiobookDownloadManifest(id: id, title: detail.summary.title.isEmpty ? title : detail.summary.title,
                                                 files: files, bytes: done)
        try JSONEncoder().encode(manifest).write(to: dir.appending(path: Self.manifestName), options: .atomic)
    }

    private func manifest(_ id: Int) -> AudiobookDownloadManifest? {
        guard let storage else { return nil }
        let url = storage.downloadDirectory(id).appending(path: Self.manifestName)
        return (try? Data(contentsOf: url)).flatMap { try? JSONDecoder().decode(AudiobookDownloadManifest.self, from: $0) }
    }

    private static func isComplete(_ url: URL, size: Int64) -> Bool {
        guard FileManager.default.fileExists(atPath: url.path) else { return false }
        return size <= 0 || fileSize(url) == size
    }

    private static func fileSize(_ url: URL) -> Int64 {
        ((try? FileManager.default.attributesOfItem(atPath: url.path))?[.size] as? NSNumber)?.int64Value ?? 0
    }

    /// A file name for a part, by its type (players sniff the content anyway).
    nonisolated static func fileName(_ part: AudiobookPart) -> String {
        String(format: "part-%03d.%@", part.index, extensionFor(part.mime))
    }

    nonisolated static func extensionFor(_ mime: String?) -> String {
        let type = mime?.lowercased().split(separator: ";").first.map { $0.trimmingCharacters(in: .whitespaces) }
        switch type {
        case "audio/mpeg", "audio/mp3": return "mp3"
        case "audio/mp4", "audio/m4a", "audio/x-m4a": return "m4a"
        case "audio/x-m4b", "audio/m4b": return "m4b"
        case "audio/aac", "audio/aacp": return "aac"
        case "audio/ogg", "application/ogg": return "ogg"
        case "audio/opus": return "opus"
        case "audio/flac", "audio/x-flac": return "flac"
        default: return "audio"
        }
    }

    /// The token and device headers go only to the reader's own server (same
    /// scheme, host and port); a part on another host gets nothing. Pre-signed
    /// part URLs need none, but a server that wants them gets them.
    nonisolated static func headersFor(_ url: URL, server: URL, token: String?, device: [String: String]) -> [String: String] {
        guard let scheme = url.scheme?.lowercased(), scheme == "http" || scheme == "https",
              scheme == server.scheme?.lowercased(),
              url.host?.lowercased() == server.host?.lowercased(),
              effectivePort(url) == effectivePort(server)
        else { return [:] }
        var headers = device
        if let token, !token.isEmpty { headers[APIClient.authorizationHeader] = "Bearer \(token)" }
        return headers
    }

    private nonisolated static func effectivePort(_ url: URL) -> Int? {
        url.port ?? (url.scheme?.lowercased() == "https" ? 443 : url.scheme?.lowercased() == "http" ? 80 : nil)
    }
}

/// One file at a time through a delegate session: progress as bytes arrive,
/// and resume data kept on disk when it stops, so the next try continues.
final class PartDownloader: NSObject, URLSessionDownloadDelegate, @unchecked Sendable {
    private struct Job {
        var destination: URL
        var resumeFile: URL
        var continuation: CheckedContinuation<Void, Error>
        var progress: @Sendable (Int64) -> Void
        var failure: Error?
    }

    private let lock = NSLock()
    private var jobs: [Int: Job] = [:]
    private lazy var session: URLSession = {
        let config = URLSessionConfiguration.default
        config.timeoutIntervalForRequest = 60
        config.waitsForConnectivity = false
        config.requestCachePolicy = .reloadIgnoringLocalCacheData
        config.urlCache = nil
        return URLSession(configuration: config, delegate: self, delegateQueue: nil)
    }()

    func download(_ request: URLRequest, to destination: URL, resumeFile: URL,
                  progress: @escaping @Sendable (Int64) -> Void) async throws {
        let resume = try? Data(contentsOf: resumeFile)
        try? FileManager.default.removeItem(at: resumeFile)
        let holder = TaskHolder(resumeFile: resumeFile)
        try await withTaskCancellationHandler {
            try await withCheckedThrowingContinuation { (continuation: CheckedContinuation<Void, Error>) in
                let task = resume.map { session.downloadTask(withResumeData: $0) } ?? session.downloadTask(with: request)
                lock.withLock {
                    jobs[task.taskIdentifier] = Job(destination: destination, resumeFile: resumeFile,
                                                    continuation: continuation, progress: progress)
                }
                holder.set(task)
                task.resume()
            }
        } onCancel: {
            holder.cancel()
        }
    }

    func urlSession(_ session: URLSession, downloadTask: URLSessionDownloadTask, didWriteData bytesWritten: Int64,
                    totalBytesWritten: Int64, totalBytesExpectedToWrite: Int64) {
        let progress = lock.withLock { jobs[downloadTask.taskIdentifier]?.progress }
        progress?(totalBytesWritten)
    }

    func urlSession(_ session: URLSession, downloadTask: URLSessionDownloadTask, didFinishDownloadingTo location: URL) {
        guard let job = lock.withLock({ jobs[downloadTask.taskIdentifier] }) else { return }
        var failure: Error?
        if let http = downloadTask.response as? HTTPURLResponse, !(200..<300).contains(http.statusCode) {
            failure = APIError.http(status: http.statusCode, message: nil)
        } else {
            do {
                try? FileManager.default.removeItem(at: job.destination)
                try FileManager.default.moveItem(at: location, to: job.destination)
            } catch {
                failure = error
            }
        }
        lock.withLock { jobs[downloadTask.taskIdentifier]?.failure = failure }
    }

    func urlSession(_ session: URLSession, task: URLSessionTask, didCompleteWithError error: Error?) {
        guard let job = lock.withLock({ jobs.removeValue(forKey: task.taskIdentifier) }) else { return }
        if let error {
            if let data = (error as NSError).userInfo[NSURLSessionDownloadTaskResumeData] as? Data {
                try? data.write(to: job.resumeFile, options: .atomic)
            }
            if (error as? URLError)?.code == .cancelled {
                job.continuation.resume(throwing: CancellationError())
            } else {
                job.continuation.resume(throwing: error)
            }
        } else if let failure = job.failure {
            job.continuation.resume(throwing: failure)
        } else {
            job.continuation.resume()
        }
    }

    /// The task, once made, for the cancellation handler (which may run first).
    private final class TaskHolder: @unchecked Sendable {
        private let lock = NSLock()
        private var task: URLSessionDownloadTask?
        private var cancelled = false
        private let resumeFile: URL

        init(resumeFile: URL) { self.resumeFile = resumeFile }

        private func stop(_ task: URLSessionDownloadTask) {
            let file = resumeFile
            task.cancel(byProducingResumeData: { data in
                if let data { try? data.write(to: file, options: .atomic) }
            })
        }

        func set(_ task: URLSessionDownloadTask) {
            let cancel = lock.withLock { () -> Bool in
                self.task = task
                return cancelled
            }
            if cancel { stop(task) }
        }

        func cancel() {
            let task = lock.withLock { () -> URLSessionDownloadTask? in
                cancelled = true
                return self.task
            }
            if let task { stop(task) }
        }
    }
}
