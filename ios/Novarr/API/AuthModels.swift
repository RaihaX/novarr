import Foundation

/// `meta.auth`: whether the server wants a signed-in user. Missing on older
/// servers, which (like `required: false`) means no sign-in at all.
/// `second_step` (this address asks for a sign-in code after the password)
/// and `available` (this address accepts sign-ins at all) are newer: absent,
/// they mean no second step and sign-ins accepted, as before.
struct ServerAuth: Codable, Sendable, Equatable {
    var required: Bool
    var methods: [String]
    var secondStep: Bool
    var available: Bool

    init(required: Bool, methods: [String] = [], secondStep: Bool = false, available: Bool = true) {
        self.required = required
        self.methods = methods
        self.secondStep = secondStep
        self.available = available
    }

    private enum CodingKeys: String, CodingKey { case required, methods, secondStep, available }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        required = c.lenientBool(.required) ?? false
        methods = (try? c.decodeIfPresent([String].self, forKey: .methods)) ?? []
        secondStep = c.lenientBool(.secondStep) ?? false
        available = c.lenientBool(.available) ?? true
    }

    /// Whether a device can be linked with a code (`POST auth/pairings`).
    var offersPairing: Bool { methods.contains("pairing") }
}

/// What a signed-in user may do. Anything the app doesn't recognise is
/// treated as a reader (least privilege).
enum UserRole: Hashable, Sendable, Codable {
    case admin, reader
    case other(String)

    init(raw: String) {
        switch raw.lowercased() {
        case "admin": self = .admin
        case "reader": self = .reader
        default: self = .other(raw)
        }
    }

    var raw: String {
        switch self {
        case .admin: "admin"
        case .reader: "reader"
        case .other(let raw): raw
        }
    }

    var label: String {
        switch self {
        case .admin: "Owner"
        case .reader: "Reader"
        case .other(let raw): raw.capitalized
        }
    }

    init(from decoder: Decoder) throws {
        self.init(raw: try decoder.singleValueContainer().decode(String.self))
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        try c.encode(raw)
    }
}

struct AuthUser: Codable, Sendable, Equatable {
    var id: Int
    var name: String
    var username: String
    var role: UserRole

    init(id: Int, name: String, username: String, role: UserRole) {
        self.id = id
        self.name = name
        self.username = username
        self.role = role
    }

    private enum CodingKeys: String, CodingKey { case id, name, username, role }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let id = c.lenientInt(.id) else {
            throw DecodingError.keyNotFound(CodingKeys.id, .init(codingPath: c.codingPath, debugDescription: "user.id"))
        }
        self.id = id
        username = c.lenientString(.username) ?? ""
        name = c.lenientString(.name) ?? username
        role = UserRole(raw: c.lenientString(.role) ?? "")
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.container(keyedBy: CodingKeys.self)
        try c.encode(id, forKey: .id)
        try c.encode(name, forKey: .name)
        try c.encode(username, forKey: .username)
        try c.encode(role, forKey: .role)
    }
}

/// `POST /api/v1/auth/token` → 200.
struct AuthTokenResponse: Decodable, Sendable, Equatable {
    var token: String
    var user: AuthUser
}

/// `GET /api/v1/auth/me` → 200.
struct AuthUserEnvelope: Decodable, Sendable {
    var user: AuthUser
}

// MARK: - Second step

/// A way to pass the second sign-in step, as named in `methods`. Passkeys are
/// web only and never offered to the app; unknown names are skipped.
enum SecondStepMethod: String, Sendable, Equatable, CaseIterable {
    case totp, email, recovery

    /// The chooser's wording.
    var label: String {
        switch self {
        case .totp: "Authenticator app"
        case .email: "Email me a code"
        case .recovery: "Use a recovery code"
        }
    }
}

/// `POST auth/token` → 401 `second_step_required`: the password was right and
/// a sign-in code must follow. Kept in memory only, never on disk.
struct SecondStepChallenge: Sendable, Equatable {
    var challenge: String
    var message: String?
    var expiresIn: Int
    var methods: [SecondStepMethod]
    var emailHint: String?
}

/// `POST auth/token`: either a token, or a second step to pass first.
enum SignInOutcome: Sendable, Equatable {
    case signedIn(AuthTokenResponse)
    case secondStepRequired(SecondStepChallenge)
}

/// Every auth error body the server sends: `{message, code}` plus, by code,
/// the challenge fields or `tries_left`.
struct AuthErrorBody: Decodable, Sendable, Equatable {
    var message: String?
    var code: String?
    var challenge: String?
    var expiresIn: Int?
    var methods: [String]?
    var emailHint: String?
    var triesLeft: Int?

    private enum CodingKeys: String, CodingKey {
        case message, code, challenge, expiresIn, methods, emailHint, triesLeft
    }

    init(message: String? = nil, code: String? = nil) {
        self.message = message
        self.code = code
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        message = c.lenientString(.message)
        code = c.lenientString(.code)
        challenge = c.lenientString(.challenge)
        expiresIn = c.lenientInt(.expiresIn)
        methods = try? c.decodeIfPresent([String].self, forKey: .methods)
        emailHint = c.lenientString(.emailHint)
        triesLeft = c.lenientInt(.triesLeft)
    }

    static let secondStepRequired = "second_step_required"
    static let secondStepUnavailable = "second_step_unavailable"
    static let wrongCode = "wrong_code"
    static let challengeExpired = "challenge_expired"

    /// The challenge of a `second_step_required` answer; nil when the body
    /// isn't one (no challenge, or no method the app can use).
    var secondStepChallenge: SecondStepChallenge? {
        guard code == Self.secondStepRequired, let challenge, !challenge.isEmpty else { return nil }
        let known = (methods ?? []).compactMap(SecondStepMethod.init(rawValue:))
        var unique: [SecondStepMethod] = []
        for method in known where !unique.contains(method) { unique.append(method) }
        guard !unique.isEmpty else { return nil }
        let hint = emailHint.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 }
        return SecondStepChallenge(challenge: challenge, message: message,
                                   expiresIn: expiresIn.flatMap { $0 > 0 ? $0 : nil } ?? 600,
                                   methods: unique, emailHint: unique.contains(.email) ? hint : nil)
    }
}

// MARK: - Pairing (link with a code)

/// `POST auth/pairings` → 201.
struct PairingStart: Decodable, Sendable, Equatable {
    var code: String
    var secret: String
    var expiresIn: Int?
    var interval: Int?

    init(code: String, secret: String, expiresIn: Int? = nil, interval: Int? = nil) {
        self.code = code
        self.secret = secret
        self.expiresIn = expiresIn
        self.interval = interval
    }

    private enum CodingKeys: String, CodingKey { case code, secret, expiresIn, interval }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        guard let code = c.lenientString(.code), !code.isEmpty,
              let secret = c.lenientString(.secret), !secret.isEmpty else {
            throw DecodingError.dataCorrupted(.init(codingPath: c.codingPath, debugDescription: "pairing code/secret"))
        }
        self.code = code
        self.secret = secret
        expiresIn = c.lenientInt(.expiresIn)
        interval = c.lenientInt(.interval)
    }
}

/// `GET auth/pairings/{secret}`: still waiting, linked (the token is handed
/// over once), or gone (404: expired, unknown or already collected).
enum PairingPoll: Sendable, Equatable {
    case pending
    case linked(AuthTokenResponse)
    case expired
}

/// The 200 body of a poll: `{"status":"pending"}` or `{"status":"linked","token","user"}`.
struct PairingStatusBody: Decodable, Sendable {
    var status: String
    var token: String?
    var user: AuthUser?

    private enum CodingKeys: String, CodingKey { case status, token, user }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        status = c.lenientString(.status) ?? ""
        token = c.lenientString(.token)
        user = try? c.decodeIfPresent(AuthUser.self, forKey: .user)
    }
}
