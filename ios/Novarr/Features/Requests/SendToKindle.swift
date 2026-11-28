import Foundation

/// "Send to my Kindle / email" (GET / POST /novels/{id}/send): the server
/// queues the send (202) and the app asks again until it is sent or failed.
/// Mirrors android domain/SendToKindle.kt.
enum SendToKindle {
    /// How often a running send is asked again (seconds).
    static let pollInterval: TimeInterval = 2
    /// After this long the app stops asking and says the send goes on without it.
    static let giveUpAfter: TimeInterval = 120

    static let menuLabel = "Send to my Kindle / email"

    enum UiState: Equatable {
        /// Asking the server to send it.
        case running(to: String?)
        /// Sent: the server's message (which carries the Kindle approved-sender reminder).
        case sent(String)
        case failed(String)
        /// The reader has no address yet.
        case noAddress(message: String, accountURL: String?)
        /// Still going when the app stopped asking.
        case stillSending(String)

        var isRunning: Bool { if case .running = self { true } else { false } }
    }

    enum Phase: Equatable { case none, running, sent, failed }

    /// queued / sending → running; sent; failed; none (nil). An unknown status counts as running.
    static func phase(_ state: SendState?) -> Phase {
        switch state?.status?.trimmingCharacters(in: .whitespaces).lowercased() {
        case nil, "": .none
        case "sent": .sent
        case "failed": .failed
        default: .running
        }
    }

    static let noAddressMessage = "Add your Kindle or email address on your Account page."
    static let sentFallback = "Sent. On a Kindle, make sure your server's address is an approved sender in your Amazon settings."
    static let failedFallback = "The send failed. Try again later."
    static let stillSendingMessage = "Still sending. It should arrive shortly."

    /// After a poll answered `state`, `elapsed` seconds after the send started: keep polling (nil) or what to show.
    static func next(_ state: SendState, elapsed: TimeInterval) -> UiState? {
        func nonBlank(_ s: String?) -> String? {
            guard let s, !s.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty else { return nil }
            return s
        }
        switch phase(state) {
        case .sent: return .sent(nonBlank(state.message) ?? sentFallback)
        case .failed: return .failed(nonBlank(state.message) ?? failedFallback)
        // A send the server no longer remembers (expired): it went, as far as anyone can tell.
        case .none: return .stillSending(stillSendingMessage)
        case .running: return elapsed >= giveUpAfter ? .stillSending(stillSendingMessage) : nil
        }
    }

    /// A refused start (422 no_address / manga, 429 too_many, anything else) as what to show.
    static func refusal(_ error: Error, baseURL: String?) -> UiState {
        let api = APIError.from(error)
        if api.code == "no_address" {
            struct Body: Decodable { var accountUrl: String? }
            var accountURL: String?
            if case .rejected(let failure) = api { accountURL = failure.decode(Body.self)?.accountUrl }
            return .noAddress(message: noAddressMessage, accountURL: resolve(baseURL: baseURL, url: accountURL))
        }
        return .failed(api.serverMessage ?? api.errorDescription ?? failedFallback)
    }

    /// An absolute http(s) `url` as is; a path against `baseURL`; nil for anything else.
    static func resolve(baseURL: String?, url: String?) -> String? {
        guard let u = url?.trimmingCharacters(in: .whitespaces), !u.isEmpty else { return nil }
        let lower = u.lowercased()
        if lower.hasPrefix("http://") || lower.hasPrefix("https://") { return u }
        guard u.hasPrefix("/") else { return nil }
        var base = baseURL?.trimmingCharacters(in: .whitespaces) ?? ""
        while base.hasSuffix("/") { base.removeLast() }
        return base.isEmpty ? nil : base + u
    }
}
