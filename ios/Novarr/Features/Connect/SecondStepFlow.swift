import Foundation

/// The second sign-in step after a right password, as a pure state machine:
/// which method is in use, whether an emailed code was sent (and when it may
/// be sent again), and what the screen says after each answer. `AppModel`
/// does the requests; the challenge lives here, in memory only. The password
/// is never kept: a challenge that runs out goes back to the password form.
struct SecondStepFlow: Equatable, Sendable {
    /// Seconds before "Send again" can ask for another emailed code.
    static let resendDelay: TimeInterval = 30

    /// The server the password was checked by; an answer for another is dropped.
    let server: URL
    /// The sign-in name, remembered for the form once signed in.
    let username: String
    let challenge: SecondStepChallenge
    private(set) var method: SecondStepMethod
    /// When the last emailed code was sent; nil before "Email me a code".
    private(set) var emailSentAt: Date?
    /// The last failure, shown under the code field.
    private(set) var errorMessage: String?

    init(server: URL, username: String, challenge: SecondStepChallenge) {
        self.server = server
        self.username = username
        self.challenge = challenge
        method = Self.defaultMethod(challenge.methods)
    }

    /// The authenticator when offered, else the emailed code, else whatever is left.
    static func defaultMethod(_ methods: [SecondStepMethod]) -> SecondStepMethod {
        if methods.contains(.totp) { return .totp }
        if methods.contains(.email) { return .email }
        return methods.first ?? .recovery
    }

    var title: String { "Enter your sign-in code" }

    var prompt: String {
        challenge.message.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 }
            ?? "Enter your sign-in code to finish signing in."
    }

    /// The other methods the account can use, in the server's order.
    var otherMethods: [SecondStepMethod] { challenge.methods.filter { $0 != method } }

    mutating func select(_ method: SecondStepMethod) {
        guard challenge.methods.contains(method), method != self.method else { return }
        self.method = method
        errorMessage = nil
    }

    // MARK: Emailed code

    var emailSent: Bool { emailSentAt != nil }

    var emailSentText: String {
        if let hint = challenge.emailHint { return "We sent a code to \(hint)." }
        return "We sent a code to your email address."
    }

    /// Whether "Email me a code" / "Send again" may be pressed now.
    func canSendEmail(at now: Date) -> Bool {
        guard let emailSentAt else { return true }
        return now.timeIntervalSince(emailSentAt) >= Self.resendDelay
    }

    /// Whole seconds until "Send again" is enabled (0 when it is).
    func secondsUntilResend(at now: Date) -> Int {
        guard let emailSentAt else { return 0 }
        return max(0, Int((Self.resendDelay - now.timeIntervalSince(emailSentAt)).rounded(.up)))
    }

    mutating func emailSent(at now: Date) {
        emailSentAt = now
        errorMessage = nil
    }

    // MARK: Codes

    /// What the field holds after typing: digits only (6) for a typed code;
    /// for a recovery code lower-case letters and digits (10) with the dash
    /// put back after the fifth, so a pasted or typed code reads `xxxxx-xxxxx`.
    static func normalize(_ raw: String, for method: SecondStepMethod) -> String {
        switch method {
        case .totp, .email:
            return String(raw.filter { $0.isASCII && $0.isNumber }.prefix(6))
        case .recovery:
            let plain = String(raw.lowercased().filter { $0.isASCII && ($0.isLetter || $0.isNumber) }.prefix(10))
            guard plain.count > 5 else { return plain }
            return String(plain.prefix(5)) + "-" + String(plain.dropFirst(5))
        }
    }

    static func isComplete(_ code: String, for method: SecondStepMethod) -> Bool {
        let normalized = normalize(code, for: method)
        switch method {
        case .totp, .email: return normalized.count == 6
        case .recovery: return normalized.count == 11
        }
    }

    // MARK: Answers

    /// What a failed code (or "Email me a code") leads to.
    enum Outcome: Equatable, Sendable {
        /// Stay on the code screen; `errorMessage` says why.
        case retry
        /// The challenge is gone: back to the password form with this message.
        case restart(String)
    }

    static let expiredMessage = "That took too long. Sign in again."
    static let offlineMessage = "No connection. Try again."

    mutating func receive(_ error: APIError) -> Outcome {
        switch error {
        case .challengeExpired:
            // The server says why: too long, or too many wrong codes.
            return .restart(error.localizedDescription)
        default:
            errorMessage = Self.message(for: error)
            return .retry
        }
    }

    /// The code screen's wording for a failure.
    static func message(for error: APIError) -> String {
        switch error {
        case .wrongCode(_, let triesLeft):
            // The server's message, then how many tries are left.
            let text = error.localizedDescription
            guard let triesLeft else { return text }
            switch triesLeft {
            case ..<1: return text
            case 1: return text + " 1 try left."
            default: return text + " \(triesLeft) tries left."
            }
        case .challengeExpired:
            return error.localizedDescription
        case .unreachable, .timedOut:
            return offlineMessage
        default:
            // 429 and the rest: the password form's wording.
            return SignInMessages.message(for: error)
        }
    }
}
