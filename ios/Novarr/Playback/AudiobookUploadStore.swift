import Foundation
import os

/// One picked file: where to read it, its name and size as sent.
struct UploadFileRef: Codable, Sendable, Equatable, Hashable {
    /// A file in the app's own storage (a copy of a file handed in), read directly.
    var path: String?
    /// A security-scoped bookmark of a file picked in Files (read in place, never copied).
    var bookmark: Data?
    var name: String
    var size: Int64
    var mime: String?

    init(path: String? = nil, bookmark: Data? = nil, name: String, size: Int64, mime: String? = nil) {
        self.path = path
        self.bookmark = bookmark
        self.name = name
        self.size = size
        self.mime = mime
    }
}

/// An audiobook upload started on this device, kept so it resumes at the next
/// launch and so "Your audiobook uploads" can list it. `localId` is this
/// device's; `serverId` the server's once it was created. Mirrors android
/// store/AudiobookUploadStore.kt.
struct AudiobookUploadEntry: Codable, Sendable, Equatable, Identifiable {
    var localId: String
    var serverId: Int?
    var chunkSize: Int64 = AudiobookUploads.defaultChunkBytes
    var novelId: Int?
    var bookTitle: String?
    var note: String?
    var files: [UploadFileRef] = []
    /// An `AudiobookUploads.Status` raw value.
    var status: String = AudiobookUploads.Status.queued.rawValue
    /// Why it failed or was declined (the server's sentence).
    var message: String?
    var wifiOnly = true
    var createdAt: Int64 = 0
    var updatedAt: Int64 = 0
    /// Bytes the server confirmed, as last seen.
    var doneBytes: Int64 = 0

    var id: String { localId }
    var totalBytes: Int64 { files.reduce(0) { $0 + $1.size } }
    /// An unknown status reads as failed, never crashes.
    var state: AudiobookUploads.Status { AudiobookUploads.Status(rawValue: status) ?? .failed }

    /// A title for the list: the book, else the first file.
    var title: String {
        if let bookTitle, !bookTitle.trimmingCharacters(in: .whitespaces).isEmpty { return bookTitle }
        if let name = files.first?.name {
            let stem = name.contains(".") ? String(name[..<(name.lastIndex(of: ".") ?? name.endIndex)]) : name
            if !stem.trimmingCharacters(in: .whitespaces).isEmpty { return stem }
        }
        return "Audiobook"
    }
}

/// One session's uploads in `audiobook-uploads.json` (its AudioStorage).
actor AudiobookUploadStore {
    static let fileName = "audiobook-uploads.json"

    let fileURL: URL
    private let log = Logger(subsystem: "studio.visns.novarr", category: "AudiobookUploads")

    init(fileURL: URL) { self.fileURL = fileURL }

    func all() -> [AudiobookUploadEntry] { read() }

    func get(_ localId: String) -> AudiobookUploadEntry? { read().first { $0.localId == localId } }

    func put(_ entry: AudiobookUploadEntry) {
        update { list in list.filter { $0.localId != entry.localId } + [entry] }
    }

    /// Changes one entry (nothing when it is gone); answers the new value.
    @discardableResult
    func change(_ localId: String, _ transform: @Sendable (AudiobookUploadEntry) -> AudiobookUploadEntry) -> AudiobookUploadEntry? {
        var result: AudiobookUploadEntry?
        update { list in
            list.map { entry in
                guard entry.localId == localId else { return entry }
                var next = transform(entry)
                next.updatedAt = ListeningSync.nowMs()
                result = next
                return next
            }
        }
        return result
    }

    func remove(_ localId: String) {
        update { list in list.filter { $0.localId != localId } }
    }

    private func update(_ transform: ([AudiobookUploadEntry]) -> [AudiobookUploadEntry]) {
        let next = transform(read())
        do {
            try FileManager.default.createDirectory(at: fileURL.deletingLastPathComponent(), withIntermediateDirectories: true)
            try JSONEncoder().encode(next).write(to: fileURL, options: .atomic)
        } catch {
            log.error("Could not save uploads: \(error.localizedDescription, privacy: .public)")
        }
    }

    private func read() -> [AudiobookUploadEntry] {
        guard let data = try? Data(contentsOf: fileURL) else { return [] }
        return (try? JSONDecoder().decode([AudiobookUploadEntry].self, from: data)) ?? []
    }
}
