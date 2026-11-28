import SwiftUI

// Push destinations added in 2.0 (Android parity). Each feature area owns
// the view behind its routes and registers them in its own
// `…Destinations()` modifier (Features/<Area>/<Area>Destinations.swift),
// chained by `novarrDestinations()` (NovarrApp.swift).

/// The reader's whole "Want to read" (Home's shelf "See all").
struct WantToReadRoute: Hashable {}

/// One category (a reading list) as a grid of covers (Home's "See all").
struct FolderRoute: Hashable {
    var id: Int
}

/// Manage categories: create, rename, reorder, hide on Home, delete.
struct CategoriesRoute: Hashable {}

/// Settings › Home: which sections Home shows, and the tab the app starts on.
struct HomeSettingsRoute: Hashable {}

/// The reader's own stats (Settings › Your stats, Home's stats card).
struct StatsRoute: Hashable {}

/// Reader requests, opened on a section.
struct RequestsRoute: Hashable {
    enum Section: String, Hashable, CaseIterable {
        /// Search / paste a link / Discover.
        case search
        /// My requests (and my audiobook uploads).
        case mine
        /// The owner's review queue (admins only).
        case review
    }

    var section: Section = .search
}

/// "Send ePubs to Novarr": files picked here, or opened from Files / the Share sheet.
struct BookUploadRoute: Hashable {
    /// Files already copied into the app (from `onOpenURL`); empty: pick here.
    var files: [URL] = []
}

/// Settings › Home › "Start on": the tab the app opens on, once per launch.
enum StartOn: String, CaseIterable, Identifiable, Sendable {
    case home, library, listen
    static let defaultsKey = "homeStartOn"
    var id: String { rawValue }
    var title: String {
        switch self {
        case .home: "Home"
        case .library: "Library"
        case .listen: "Listen"
        }
    }
}

/// Files handed to the app by Files or the Share sheet ("Open in Novarr"),
/// copied into the app; ePubs go to the book upload, audio to the audiobook upload.
struct IncomingFiles: Identifiable, Hashable {
    enum Kind: Hashable { case books, audio }
    var id = UUID()
    var kind: Kind
    var files: [URL]

    static let audioExtensions: Set<String> = ["m4b", "m4a", "mp3", "aac", "ogg", "opus", "flac"]

    /// Copies an opened file into Caches/Incoming (the Inbox copy may be
    /// removed by the system) and says what it is; nil for anything else.
    static func receive(_ url: URL) -> IncomingFiles? {
        let ext = url.pathExtension.lowercased()
        let kind: Kind
        if ext == "epub" { kind = .books } else if audioExtensions.contains(ext) { kind = .audio } else { return nil }
        let scoped = url.startAccessingSecurityScopedResource()
        defer { if scoped { url.stopAccessingSecurityScopedResource() } }
        let fm = FileManager.default
        let folder = (fm.urls(for: .cachesDirectory, in: .userDomainMask).first ?? fm.temporaryDirectory)
            .appending(path: "Incoming/\(UUID().uuidString)", directoryHint: .isDirectory)
        do {
            try fm.createDirectory(at: folder, withIntermediateDirectories: true)
            let copy = folder.appending(path: url.lastPathComponent)
            try fm.copyItem(at: url, to: copy)
            return IncomingFiles(kind: kind, files: [copy])
        } catch {
            return nil
        }
    }
}

/// Every audiobook (the Listen tab's content, also pushable).
struct AudiobooksRoute: Hashable {}

/// The full-screen audiobook player, opening audiobook `id` if another is loaded.
struct PlayerRoute: Hashable, Identifiable {
    var id: Int
}

/// "Upload an audiobook": for book `novelId` (nil: none chosen, matched later),
/// with files already picked or shared in (empty: pick on the screen).
struct AudiobookUploadRoute: Hashable {
    var novelId: Int?
    var title: String = ""
    var files: [URL] = []
}
