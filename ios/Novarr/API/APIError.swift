import Foundation

enum APIError: Error, Equatable, Sendable, LocalizedError {
    case invalidServerURL
    /// The request never got an HTTP response (no route, DNS, refused, offline…).
    case unreachable(String)
    case timedOut
    case http(status: Int, message: String?)
    case decoding(String)
    case notNovarr
    case apiTooOld(serverVersion: Int, required: Int)
    /// The text endpoint was asked for a manga chapter (422, `medium: manga`):
    /// it has page images, read in the manga reader.
    case mangaChapter

    static func from(_ error: Error) -> APIError {
        if let api = error as? APIError { return api }
        if let url = error as? URLError {
            switch url.code {
            case .timedOut: return .timedOut
            case .cancelled: return .unreachable("The request was cancelled.")
            default: return .unreachable(url.localizedDescription)
            }
        }
        if error is DecodingError { return .decoding(String(describing: error)) }
        return .unreachable(error.localizedDescription)
    }

    /// True when no response came back at all: the cue to fall back to the
    /// offline cache and keep queued writes for later.
    var isConnectivity: Bool {
        switch self {
        case .unreachable, .timedOut: true
        default: false
        }
    }

    /// 401: the token is missing, invalid or revoked. The app forgets the
    /// token and asks for a sign-in; queued writes are kept.
    var isUnauthenticated: Bool {
        if case .http(401, _) = self { return true }
        return false
    }

    /// 403: signed in, but this account may not do that.
    var isForbidden: Bool {
        if case .http(403, _) = self { return true }
        return false
    }

    /// Whether a queued write that failed this way should be kept and retried.
    /// Client errors (validation, unknown id) will never succeed, so they are dropped.
    var isRetryable: Bool {
        switch self {
        case .unreachable, .timedOut: true
        case .http(let status, _): status >= 500 || status == 408 || status == 429
        case .invalidServerURL, .decoding, .notNovarr, .apiTooOld, .mangaChapter: false
        }
    }

    /// A short title for a screen that couldn't load (Home's failure state).
    var failureTitle: String {
        switch self {
        case .unreachable, .timedOut, .invalidServerURL: "Can't reach your server"
        case .http(401, _): "You're signed out"
        case .http: "Server error"
        case .decoding, .notNovarr, .apiTooOld, .mangaChapter: "Unexpected response"
        }
    }

    var errorDescription: String? {
        switch self {
        case .invalidServerURL:
            "That doesn't look like a server address. Try something like http://192.168.0.10:8080."
        case .unreachable:
            "Couldn't reach the server. Check the address, that the server is running, and that this device is on the same network (or Tailscale)."
        case .timedOut:
            "The server took too long to answer."
        case .http(let status, let message):
            if status == 404 { "Not found on the server (404)." }
            else if status == 401 { "You've been signed out. Sign in again to continue." }
            else if status == 403 { message.flatMap { $0.isEmpty ? nil : $0 } ?? "Your account isn't allowed to do that." }
            else if status == 429 { message.flatMap { $0.isEmpty ? nil : $0 } ?? "Too many attempts. Wait a minute and try again." }
            else if let message, !message.isEmpty { "\(message) (HTTP \(status))" }
            else { "The server answered with an error (HTTP \(status))." }
        case .decoding:
            "The server's answer wasn't in the expected format."
        case .notNovarr:
            "That address answered, but it isn't a Novarr server (or its API isn't enabled)."
        case .apiTooOld(let server, let required):
            "This Novarr server speaks API v\(server); the app needs v\(required) or newer. Update the server."
        case .mangaChapter:
            "This is a manga chapter."
        }
    }
}
