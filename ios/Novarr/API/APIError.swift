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
    /// `POST auth/token` → 403 `second_step_unavailable`: the password was
    /// right, but the account has no second step it can use at this address.
    case secondStepUnavailable(String?)
    /// `POST auth/token/second-step` → 422 `wrong_code`.
    case wrongCode(message: String?, triesLeft: Int?)
    /// 404 `challenge_expired`: the second step took too long (or had too
    /// many wrong codes); sign in again from the password. Carries the
    /// server's message, which says which.
    case challengeExpired(String?)
    /// A non-2xx answer from one of the feature endpoints (requests, lists,
    /// audiobooks, uploads, stats, send…), keeping the body's machine `code`,
    /// `Retry-After` and the raw body so callers can tell answers apart.
    case rejected(HTTPFailure)

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
    var isUnauthenticated: Bool { status == 401 }

    /// 403: signed in, but this account may not do that.
    var isForbidden: Bool { status == 403 }

    /// The HTTP status of an answer that wasn't a success; nil when there was no answer.
    var status: Int? {
        switch self {
        case .http(let status, _): status
        case .rejected(let failure): failure.status
        default: nil
        }
    }

    /// The error body's machine `code` (`.rejected` only).
    var code: String? {
        if case .rejected(let failure) = self { return failure.code }
        return nil
    }

    /// The server's own sentence, if it sent one.
    var serverMessage: String? {
        switch self {
        case .http(_, let message): message.flatMap { $0.isEmpty ? nil : $0 }
        case .rejected(let failure): failure.message
        default: nil
        }
    }

    /// Seconds from `Retry-After` (`.rejected` only).
    var retryAfter: TimeInterval? {
        if case .rejected(let failure) = self { return failure.retryAfter }
        return nil
    }

    /// Whether a queued write that failed this way should be kept and retried.
    /// Client errors (validation, unknown id) will never succeed, so they are dropped.
    var isRetryable: Bool {
        switch self {
        case .unreachable, .timedOut: true
        case .http(let status, _): status >= 500 || status == 408 || status == 429
        case .rejected(let failure): failure.status >= 500 || failure.status == 408 || failure.status == 429
        case .invalidServerURL, .decoding, .notNovarr, .apiTooOld, .mangaChapter,
             .secondStepUnavailable, .wrongCode, .challengeExpired: false
        }
    }

    /// A short title for a screen that couldn't load (Home's failure state).
    var failureTitle: String {
        switch self {
        case .unreachable, .timedOut, .invalidServerURL: "Can't reach your server"
        case .http(401, _): "You're signed out"
        case .rejected(let failure) where failure.status == 401: "You're signed out"
        case .http, .rejected: "Server error"
        case .secondStepUnavailable, .wrongCode, .challengeExpired: "Couldn't sign in"
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
        case .secondStepUnavailable(let message):
            message.flatMap { $0.isEmpty ? nil : $0 }
                ?? "This account needs a second sign-in step before it can be used here. Set one up from your home network, or ask the person who runs this Novarr."
        case .wrongCode(let message, _):
            message.flatMap { $0.isEmpty ? nil : $0 } ?? "That code is not right. Check it and try again."
        case .challengeExpired(let message):
            message.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 }
                ?? "That took too long. Sign in again."
        case .rejected(let failure):
            APIError.http(status: failure.status, message: failure.message).errorDescription
        }
    }
}

/// A non-2xx answer with what the body said about it.
struct HTTPFailure: Equatable, Sendable {
    var status: Int
    var message: String?
    var code: String?
    /// Seconds, from the `Retry-After` header.
    var retryAfter: TimeInterval?
    /// The raw body (JSON for Novarr's own errors), for answers that carry more than a message.
    var body: Data

    init(status: Int, message: String? = nil, code: String? = nil, retryAfter: TimeInterval? = nil, body: Data = Data()) {
        self.status = status
        self.message = message.flatMap { $0.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty ? nil : $0 }
        self.code = code.flatMap { $0.isEmpty ? nil : $0 }
        self.retryAfter = retryAfter
        self.body = body
    }

    /// Decodes the body as `T` (an error body that carries data, like a 422 upload answer).
    func decode<T: Decodable>(_ type: T.Type) -> T? {
        try? JSONCoding.decoder().decode(T.self, from: body)
    }
}
