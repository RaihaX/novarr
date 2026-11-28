import Foundation

/// Readers uploading audiobooks (`/api/v1/audiobook-uploads`): which picked
/// files can go, how each file is cut into chunks, which chunks are still
/// missing after the server says what it has, retry timing and what to say
/// about the server's answers. Pure; mirrors android domain/AudiobookUploads.kt.
enum AudiobookUploads {
    /// The plan's chunk size; the server's `chunk_size` wins when it sends one.
    static let defaultChunkBytes: Int64 = 8 * 1024 * 1024
    static let maxFileBytes: Int64 = 3 * 1024 * 1024 * 1024
    static let maxTotalBytes: Int64 = 6 * 1024 * 1024 * 1024
    static let maxFiles = 200
    static let noteMax = 500
    static let parallelChunks = 3
    static let extensions: Set<String> = ["m4b", "m4a", "mp3", "aac", "ogg", "opus", "flac"]

    // MARK: Picked files

    struct Picked: Sendable, Equatable, Hashable {
        var name: String?
        var mime: String?
        var size: Int64?
        var readable = true
    }

    enum Problem: Sendable, Equatable {
        case notAudio, tooLarge, empty, unknownSize, unreadable
    }

    static func fileExtension(_ name: String?) -> String? {
        guard let base = name?.trimmingCharacters(in: .whitespaces).split(separator: "/", omittingEmptySubsequences: false).last,
              let dot = base.lastIndex(of: ".") else { return nil }
        let ext = base[base.index(after: dot)...].lowercased()
        return ext.isEmpty ? nil : ext
    }

    /// An audiobook file by its extension (the server checks it); a name without one counts by an audio type.
    static func isAudio(name: String?, mime: String?) -> Bool {
        if let ext = fileExtension(name) { return extensions.contains(ext) }
        return mime?.lowercased().hasPrefix("audio/") == true
    }

    /// What stops this file from being uploaded; nil when it can go. The size must be known (it is sent first).
    static func problem(_ file: Picked) -> Problem? {
        if !file.readable { return .unreadable }
        if !isAudio(name: file.name, mime: file.mime) { return .notAudio }
        guard let size = file.size else { return .unknownSize }
        if size == 0 { return .empty }
        if size > maxFileBytes { return .tooLarge }
        return nil
    }

    static func message(_ problem: Problem) -> String {
        switch problem {
        case .notAudio: "Not an audiobook file (.m4b, .m4a, .mp3, .aac, .ogg, .opus or .flac)."
        case .tooLarge: "Too large: each file can be up to 3 GB."
        case .empty: "This file is empty."
        case .unknownSize: "Couldn't tell this file's size. Save it to Files first, then pick it from there."
        case .unreadable: "Couldn't read this file. Save it to Files first, then pick it from there."
        }
    }

    /// Nil when these sendable files can go together; otherwise why not.
    static func tooMuch(count: Int, totalBytes: Int64) -> String? {
        if count > maxFiles { return "Up to \(maxFiles) files at once (you picked \(count))." }
        if totalBytes > maxTotalBytes { return "Up to 6 GB at once. Pick fewer files." }
        return nil
    }

    /// The name sent for a file: no folders or quotes, a fallback when blank, the extension kept.
    static func uploadName(_ displayName: String?, index: Int, mime: String?) -> String {
        let last = displayName?.split(separator: "/", omittingEmptySubsequences: false).last
            .flatMap { $0.split(separator: "\\", omittingEmptySubsequences: false).last }
            .map(String.init) ?? ""
        let base = String(last.trimmingCharacters(in: .whitespaces).unicodeScalars
            .filter { $0.value >= 0x20 && $0 != "\"" }.map(Character.init))
        if !base.trimmingCharacters(in: .whitespaces).isEmpty, let ext = fileExtension(base), extensions.contains(ext) {
            return base
        }
        let ext = extensionForMime(mime) ?? "mp3"
        let stem = base.trimmingCharacters(in: .whitespaces).isEmpty ? String(format: "part-%03d", index + 1) : base
        return "\(stem).\(ext)"
    }

    static func extensionForMime(_ mime: String?) -> String? {
        let ext = AudiobookDownloads.extensionFor(mime)
        return ext == "audio" ? nil : ext
    }

    static func clampNote(_ note: String) -> String { String(note.prefix(noteMax)) }

    /// The order files go in: a folder of parts picked in any order is sent
    /// "01 …", "02 …", "10 …" (numbers compared as numbers).
    static func naturalOrder(_ a: String, _ b: String) -> Bool { naturalCompare(a, b) < 0 }

    static func naturalCompare(_ a: String, _ b: String) -> Int {
        let left = runs(a.lowercased()), right = runs(b.lowercased())
        for i in 0..<min(left.count, right.count) {
            let x = left[i], y = right[i]
            let c: Int
            if let fx = x.first, let fy = y.first, fx.isNumber, fy.isNumber {
                let tx = String(x.drop { $0 == "0" }), ty = String(y.drop { $0 == "0" })
                c = tx.count != ty.count ? (tx.count < ty.count ? -1 : 1) : compare(tx, ty)
            } else {
                c = compare(x, y)
            }
            if c != 0 { return c }
        }
        return left.count == right.count ? 0 : (left.count < right.count ? -1 : 1)
    }

    private static func compare(_ a: String, _ b: String) -> Int {
        // Code-unit order, as Kotlin's String.compareTo.
        let x = Array(a.utf16), y = Array(b.utf16)
        for i in 0..<min(x.count, y.count) where x[i] != y[i] { return x[i] < y[i] ? -1 : 1 }
        return x.count == y.count ? 0 : (x.count < y.count ? -1 : 1)
    }

    /// Digit runs and non-digit runs, in order.
    private static func runs(_ text: String) -> [String] {
        var result: [String] = []
        var current = ""
        var digits = false
        for ch in text {
            let isDigit = ch.isASCII && ch.isNumber
            if !current.isEmpty && isDigit != digits {
                result.append(current)
                current = ""
            }
            digits = isDigit
            current.append(ch)
        }
        if !current.isEmpty { result.append(current) }
        return result
    }

    // MARK: Chunks

    /// Chunk `index` of file `file`: bytes [offset, offset + length).
    struct Chunk: Sendable, Equatable, Hashable {
        var file: Int
        var index: Int
        var offset: Int64
        var length: Int64
        var fileSize: Int64
        var end: Int64 { offset + length }
        /// `bytes 0-8388607/20000000` (the last byte inclusive).
        var contentRange: String { "bytes \(offset)-\(end - 1)/\(fileSize)" }
    }

    /// A server `chunk_size`: bytes; a small number (1–64) is read as megabytes; nonsense is the default.
    static func chunkSize(_ server: Int64?) -> Int64 {
        guard let server, server > 0 else { return defaultChunkBytes }
        if server <= 64 { return server * 1024 * 1024 }
        if server < 64 * 1024 { return defaultChunkBytes }
        return server
    }

    static func chunkCount(_ size: Int64, _ chunkSize: Int64) -> Int {
        size <= 0 ? 0 : Int((size + chunkSize - 1) / chunkSize)
    }

    /// Every chunk of every file, file by file, in order.
    static func plan(_ sizes: [Int64], chunkSize: Int64) -> [Chunk] {
        sizes.enumerated().flatMap { file, size in
            (0..<chunkCount(size, chunkSize)).map { n in
                let offset = Int64(n) * chunkSize
                return Chunk(file: file, index: n, offset: offset, length: min(chunkSize, size - offset), fileSize: size)
            }
        }
    }

    /// The chunks the server doesn't have yet (`present`: file index → chunk numbers, `doneFiles`: files it has whole).
    static func missing(_ plan: [Chunk], present: [Int: Set<Int>], doneFiles: Set<Int> = []) -> [Chunk] {
        plan.filter { !doneFiles.contains($0.file) && !(present[$0.file]?.contains($0.index) ?? false) }
    }

    static func bytesOf(_ chunks: [Chunk]) -> Int64 { chunks.reduce(0) { $0 + $1.length } }

    /// The planned chunks named in `named` (file index → chunk numbers): what to re-send, even if listed as present.
    static func named(_ plan: [Chunk], _ named: [Int: Set<Int>]) -> [Chunk] {
        plan.filter { named[$0.file]?.contains($0.index) ?? false }
    }

    /// What the server has, from its upload resource (`received` per file).
    static func present(_ upload: AudiobookUploadResource) -> [Int: Set<Int>] {
        Dictionary(upload.files.map { ($0.index, Set($0.received)) }, uniquingKeysWith: { $0.union($1) })
    }

    /// Files the server has whole.
    static func doneFiles(_ upload: AudiobookUploadResource) -> Set<Int> {
        Set(upload.files.filter(\.done).map(\.index))
    }

    /// The codes of a 422 from complete that mean "send these chunks again", not "refused".
    static let resendCodes: Set<String> = ["checksum", "incomplete"]

    /// A 422 from `POST /complete` with code `checksum` (a chunk arrived
    /// damaged; the upload is back to uploading) or `incomplete` (some never
    /// arrived), carrying `missing: {"<file index>": [chunk numbers]}`: those
    /// chunks, to send (again) before completing once more. Nil for any other
    /// answer, or when nothing readable is listed (then it is a real refusal).
    static func resendAfterComplete(status: Int, code: String?, body: Data?) -> [Int: Set<Int>]? {
        guard status == 422, let code = code?.lowercased(), resendCodes.contains(code) else { return nil }
        let root = body.flatMap { try? JSONSerialization.jsonObject(with: $0) } as? [String: Any]
        let missing = root?["missing"] ?? (root?["errors"] as? [String: Any])?["missing"]
        var byFile: [Int: Set<Int>] = [:]
        if let object = missing as? [String: Any] {
            for (key, value) in object {
                if let index = Int(key.trimmingCharacters(in: .whitespaces)), index >= 0 { byFile[index] = chunkSet(value) }
            }
        } else if let array = missing as? [Any], array.allSatisfy({ $0 is [Any] || $0 is [String: Any] }) {
            for (i, value) in array.enumerated() { byFile[i] = chunkSet(value) }
        }
        byFile = byFile.filter { !$0.value.isEmpty }
        return byFile.isEmpty ? nil : byFile
    }

    private static func chunkSet(_ value: Any) -> Set<Int> {
        if let array = value as? [Any] {
            return Set(array.compactMap(intValue).filter { $0 >= 0 })
        }
        if let object = value as? [String: Any] {
            return Set(object.filter { flag($0.value) }.keys.compactMap { Int($0.trimmingCharacters(in: .whitespaces)) }
                .filter { $0 >= 0 })
        }
        return []
    }

    private static func intValue(_ value: Any) -> Int? {
        if value is NSNull { return nil }
        if let number = value as? NSNumber, CFGetTypeID(number) != CFBooleanGetTypeID() { return number.intValue }
        if let text = value as? String { return Int(text.trimmingCharacters(in: .whitespaces)) }
        return nil
    }

    private static func flag(_ value: Any) -> Bool {
        if let bool = value as? Bool { return bool }
        if let number = value as? NSNumber { return number.intValue == 1 }
        if let text = value as? String { return ["true", "1"].contains(text.lowercased()) }
        return false
    }

    static func percent(_ done: Int64, _ total: Int64) -> Int {
        total <= 0 ? 0 : min(max(Int(done * 100 / total), 0), 100)
    }

    // MARK: Retries

    /// Wait before retry `attempt` (0-based): 1 s, 2 s, 4 s … up to 60 s, with
    /// up to 25% taken off at random (`jitter` 0...1) so parallel chunks don't
    /// retry in step. A Retry-After in seconds wins (capped at 5 min).
    static func backoffMillis(_ attempt: Int, jitter: Double = 0, retryAfter: String? = nil,
                              baseMs: Int64 = 1_000, maxMs: Int64 = 60_000) -> Int64 {
        if let seconds = retryAfter.flatMap({ Int64($0.trimmingCharacters(in: .whitespaces)) }), seconds >= 0 {
            return min(seconds * 1000, 300_000)
        }
        let exp = baseMs * (Int64(1) << Int64(min(max(attempt, 0), 20)))
        let capped = min(exp, maxMs)
        return max(capped - Int64(Double(capped) * 0.25 * min(max(jitter, 0), 1)), 0)
    }

    /// Worth trying the same chunk again: no answer, a timeout, 408/429/5xx.
    static func isRetryable(_ error: APIError) -> Bool { error.isRetryable }

    // MARK: The server's answers

    /// The server's `uploading|processing|pending|approved|declined|failed|cancelled`, plus the app's own while it works.
    enum Status: String, Codable, Sendable, CaseIterable {
        case queued, uploading, completing, pending, approved, declined, failed, cancelled

        var label: String {
            switch self {
            case .queued: "Waiting to upload"
            case .uploading: "Uploading"
            case .completing: "Checking the files"
            case .pending: "Waiting for the owner"
            case .approved: "In the library"
            case .declined: "Declined"
            case .failed: "Failed"
            case .cancelled: "Cancelled"
            }
        }

        /// Nothing more for this device to do.
        var finished: Bool {
            switch self {
            case .queued, .uploading, .completing: false
            default: true
            }
        }

        static func fromServer(_ value: String?) -> Status? {
            switch value?.trimmingCharacters(in: .whitespaces).lowercased() {
            case "uploading", "receiving": .uploading
            case "assembling", "processing", "verifying", "probing", "completing": .completing
            case "pending", "waiting", "review": .pending
            case "approved", "imported", "available", "done": .approved
            case "declined", "rejected": .declined
            case "failed", "refused", "error": .failed
            case "cancelled", "canceled": .cancelled
            default: nil
            }
        }
    }

    /// The server's sentence about a finished upload (why it failed or was declined).
    static func serverMessage(_ upload: AudiobookUploadResource) -> String? {
        [upload.declineReason, upload.error].compactMap { $0?.trimmingCharacters(in: .whitespacesAndNewlines) }
            .first { !$0.isEmpty }
    }

    /// The one sentence for a failed call (create, a chunk, complete).
    static func failureMessage(_ error: APIError) -> String {
        guard let status = error.status else { return error.errorDescription ?? "The upload failed." }
        switch status {
        case 401: return "You've been signed out. Sign in to Novarr again, then try the upload again."
        case 403: return error.serverMessage ?? "Your account can't upload audiobooks."
        case 404:
            if error.code != nil, let message = error.serverMessage { return message }
            return "This server doesn't take audiobook uploads yet — update Novarr."
        case 413: return "The server refused a piece of the upload as too large."
        case 422, 409, 507:
            return validationMessage(error) ?? error.serverMessage ?? "The server didn't accept these files."
        default:
            return error.serverMessage ?? error.errorDescription ?? "The upload failed."
        }
    }

    /// The first sentence of a Laravel validation answer (`errors: {field: [..]}`).
    static func validationMessage(_ error: APIError) -> String? {
        guard case .rejected(let failure) = error,
              let root = try? JSONSerialization.jsonObject(with: failure.body) as? [String: Any],
              let errors = root["errors"] as? [String: Any] else { return nil }
        for key in errors.keys.sorted() {
            if let list = errors[key] as? [Any], let first = list.compactMap({ $0 as? String }).first { return first }
            if let text = errors[key] as? String { return text }
        }
        return nil
    }
}
