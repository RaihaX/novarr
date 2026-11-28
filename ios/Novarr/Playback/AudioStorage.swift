import Foundation

/// Where one session's (server and user's) audiobook files live: the listening
/// state, downloaded parts and uploads. A sibling tree of the offline store's
/// folder (`Application Support/Novarr/audio/<store folder name>/`), so
/// "Clear cache" never drops unsent listening positions, uploads in progress
/// or downloads the reader asked for (those are removed from their own rows).
/// Excluded from iCloud / device backups: it is all large and re-fetchable.
struct AudioStorage: Sendable, Equatable {
    let root: URL

    init(storeRoot: URL) {
        let base = storeRoot.deletingLastPathComponent().deletingLastPathComponent()
        root = base.appending(path: "audio/\(storeRoot.lastPathComponent)", directoryHint: .isDirectory)
        Self.prepare(root)
    }

    init(root: URL) {
        self.root = root
        Self.prepare(root)
    }

    var listeningFile: URL { root.appending(path: ListeningStore.fileName) }
    var uploadsFile: URL { root.appending(path: AudiobookUploadStore.fileName) }
    var downloadsDirectory: URL { root.appending(path: "downloads", directoryHint: .isDirectory) }

    /// Audiobook `id`'s downloaded parts (and its manifest).
    func downloadDirectory(_ id: Int) -> URL {
        downloadsDirectory.appending(path: String(id), directoryHint: .isDirectory)
    }

    /// Copies of files handed to the app for upload `localId` (deleted once it is over).
    func uploadCopies(_ localId: String) -> URL {
        root.appending(path: "upload-files/\(localId)", directoryHint: .isDirectory)
    }

    private static func prepare(_ url: URL) {
        try? FileManager.default.createDirectory(at: url, withIntermediateDirectories: true)
        var values = URLResourceValues()
        values.isExcludedFromBackup = true
        var mutable = url
        try? mutable.setResourceValues(values)
    }
}
