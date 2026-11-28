import Foundation

/// "Send ePubs to Novarr": which files can be sent, the server's limits, and
/// what to say about each failure. Pure, so it is unit tested. Mirrors
/// android domain/BookUploads.kt.
enum BookUploads {
    static let defaultMaxMb = 50
    static let defaultMaxFiles = 20
    static let noteMax = 500
    static let epubMime = "application/epub+zip"
    private static let fileExtension = ".epub"

    struct Limits: Equatable, Sendable {
        var maxMb: Int
        var maxFiles: Int
        var maxBytes: Int64 { Int64(maxMb) * 1024 * 1024 }

        /// The server's limits; the defaults for an older server (or nonsense values).
        static func from(_ meta: BookUploadsMeta?) -> Limits {
            Limits(maxMb: meta?.maxMb.flatMap { $0 > 0 ? $0 : nil } ?? defaultMaxMb,
                   maxFiles: meta?.maxFiles.flatMap { $0 > 0 ? $0 : nil } ?? defaultMaxFiles)
        }
    }

    /// A picked file; `size` nil when unknown.
    struct Picked: Equatable, Sendable {
        var name: String?
        var mime: String?
        var size: Int64?
        var readable: Bool = true
    }

    enum Problem: Equatable, Sendable { case notEpub, tooLarge, empty, unreadable }

    /// An ePub by its name, or by its type when there is no usable name. A
    /// name with another extension is not an ePub whatever the type says.
    static func isEpub(name: String?, mime: String?) -> Bool {
        let trimmed = name?.trimmingCharacters(in: .whitespaces) ?? ""
        if trimmed.lowercased().hasSuffix(fileExtension) { return true }
        let last = trimmed.split(separator: "/", omittingEmptySubsequences: false).last.map(String.init) ?? trimmed
        let hasOtherExtension = last.contains(".") && !last.hasSuffix(".")
        return !hasOtherExtension && mime?.lowercased() == epubMime
    }

    /// What stops this file from being sent, or nil when it can go.
    static func problem(_ file: Picked, limits: Limits) -> Problem? {
        if !file.readable { return .unreadable }
        if !isEpub(name: file.name, mime: file.mime) { return .notEpub }
        if file.size == 0 { return .empty }
        if let size = file.size, size > limits.maxBytes { return .tooLarge }
        return nil
    }

    static func message(_ problem: Problem, limits: Limits) -> String {
        switch problem {
        case .notEpub: "Only ePub files (.epub) can be added."
        case .tooLarge: "Too large: ePubs can be up to \(limits.maxMb) MB."
        case .empty: "This file is empty."
        case .unreadable: "Couldn't read this file. Try saving it to Files first, then pick it again."
        }
    }

    /// Nil when `count` ePubs can go in one upload.
    static func tooMany(_ count: Int, limits: Limits) -> String? {
        count > limits.maxFiles
            ? "You can send up to \(limits.maxFiles) ePubs at once (you picked \(count)). Pick fewer and try again."
            : nil
    }

    /// The filename sent with the part: no folders, always ending in .epub.
    static func uploadName(_ displayName: String?, index: Int) -> String {
        var base = displayName ?? ""
        if let slash = base.lastIndex(of: "/") { base = String(base[base.index(after: slash)...]) }
        if let backslash = base.lastIndex(of: "\\") { base = String(base[base.index(after: backslash)...]) }
        base = base.trimmingCharacters(in: .whitespaces).filter { $0 >= " " && $0 != "\"" }
        let name = base.trimmingCharacters(in: .whitespaces).isEmpty ? "book-\(index + 1)" : base
        return name.lowercased().hasSuffix(fileExtension) ? name : name + fileExtension
    }

    /// At most `noteMax` characters, as the server allows.
    static func clampNote(_ note: String) -> String { String(note.prefix(noteMax)) }

    /// The failure of an upload that got no per-file answer, as one sentence.
    static func failureMessage(_ error: Error) -> String {
        let api = APIError.from(error)
        guard case .rejected(let failure) = api else {
            return api.errorDescription ?? "Couldn't send the books."
        }
        switch failure.status {
        case 404:
            if failure.code == "requests_disabled" {
                return failure.message ?? "Requests are switched off on this server, so books can't be sent to the owner."
            }
            return "This server is too old to accept books from the app — update Novarr."
        case 401:
            return "You've been signed out. Sign in to Novarr, then send the books again."
        case 413:
            return "The server refused the upload as too large. Send fewer or smaller ePubs."
        case 422:
            return validationMessage(failure.body) ?? failure.message ?? "The server didn't accept these files."
        default:
            return failure.message ?? api.errorDescription ?? "Couldn't send the books."
        }
    }

    /// Laravel's 422: `message`, else the first sentence under `errors`.
    static func validationMessage(_ body: Data) -> String? {
        guard let object = try? JSONSerialization.jsonObject(with: body) as? [String: Any] else { return nil }
        if let message = object["message"] as? String, !message.trimmingCharacters(in: .whitespaces).isEmpty {
            return message
        }
        guard let errors = object["errors"] as? [String: Any] else { return nil }
        for key in errors.keys.sorted() {
            if let first = (errors[key] as? [Any])?.first as? String, !first.trimmingCharacters(in: .whitespaces).isEmpty {
                return first
            }
        }
        return nil
    }
}
