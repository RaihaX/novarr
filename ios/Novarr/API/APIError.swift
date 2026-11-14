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

    /// Whether a queued write that failed this way should be kept and retried.
    /// Client errors (validation, unknown id) will never succeed, so they are dropped.
    var isRetryable: Bool {
        switch self {
        case .unreachable, .timedOut: true
        case .http(let status, _): status >= 500 || status == 408 || status == 429
        case .invalidServerURL, .decoding, .notNovarr, .apiTooOld: false
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
            else if let message, !message.isEmpty { "\(message) (HTTP \(status))" }
            else { "The server answered with an error (HTTP \(status))." }
        case .decoding:
            "The server's answer wasn't in the expected format."
        case .notNovarr:
            "That address answered, but it isn't a Novarr server (or its API isn't enabled)."
        case .apiTooOld(let server, let required):
            "This Novarr server speaks API v\(server); the app needs v\(required) or newer. Update the server."
        }
    }
}
