import Foundation

/// `meta.auth`: whether the server wants a signed-in user. Missing on older
/// servers, which (like `required: false`) means no sign-in at all.
struct ServerAuth: Codable, Sendable, Equatable {
    var required: Bool
    var methods: [String]

    init(required: Bool, methods: [String] = []) {
        self.required = required
        self.methods = methods
    }

    private enum CodingKeys: String, CodingKey { case required, methods }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        required = c.lenientBool(.required) ?? false
        methods = (try? c.decodeIfPresent([String].self, forKey: .methods)) ?? []
    }
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
