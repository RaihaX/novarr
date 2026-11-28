import Foundation
import Security
import os

/// Secret storage by key. The app uses the Keychain; tests use an in-memory fake.
protocol SecureStore: Sendable {
    func data(for key: String) throws -> Data?
    func set(_ data: Data, for key: String) throws
    func remove(for key: String) throws
}

struct KeychainError: Error, Equatable {
    var status: OSStatus
}

/// Generic-password items under one service, one account per key.
/// Readable after the first unlock so queued writes can sync in the background;
/// never synced to iCloud or restored onto another device.
struct KeychainStore: SecureStore {
    var service = "au.com.atomicinsights.novarr.auth"

    private func baseQuery(_ key: String) -> [String: Any] {
        [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
        ]
    }

    func data(for key: String) throws -> Data? {
        var query = baseQuery(key)
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)
        switch status {
        case errSecSuccess: return result as? Data
        case errSecItemNotFound: return nil
        default: throw KeychainError(status: status)
        }
    }

    func set(_ data: Data, for key: String) throws {
        let attributes: [String: Any] = [
            kSecValueData as String: data,
            kSecAttrAccessible as String: kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly,
        ]
        let status = SecItemUpdate(baseQuery(key) as CFDictionary, attributes as CFDictionary)
        if status == errSecItemNotFound {
            let addStatus = SecItemAdd(baseQuery(key).merging(attributes) { $1 } as CFDictionary, nil)
            guard addStatus == errSecSuccess else { throw KeychainError(status: addStatus) }
        } else if status != errSecSuccess {
            throw KeychainError(status: status)
        }
    }

    func remove(for key: String) throws {
        let status = SecItemDelete(baseQuery(key) as CFDictionary)
        guard status == errSecSuccess || status == errSecItemNotFound else { throw KeychainError(status: status) }
    }
}

/// A token plus who it belongs to, as stored for one server.
struct Credential: Codable, Sendable, Equatable {
    var token: String
    var user: AuthUser
}

/// Sign-in credentials, one per server, in a `SecureStore` (the Keychain).
/// Keyed by the server's storage key, so two servers never share a token.
struct CredentialStore: Sendable {
    let secure: SecureStore
    private let log = Logger(subsystem: "au.com.atomicinsights.novarr", category: "Credentials")

    init(secure: SecureStore = KeychainStore()) {
        self.secure = secure
    }

    static func key(for server: URL) -> String {
        "server:" + ServerURL.storageKey(for: server)
    }

    func credential(for server: URL) -> Credential? {
        do {
            guard let data = try secure.data(for: Self.key(for: server)) else { return nil }
            return try JSONDecoder().decode(Credential.self, from: data)
        } catch {
            log.error("Could not read the saved sign-in: \(String(describing: error), privacy: .public)")
            return nil
        }
    }

    @discardableResult
    func save(_ credential: Credential, for server: URL) -> Bool {
        do {
            try secure.set(try JSONEncoder().encode(credential), for: Self.key(for: server))
            return true
        } catch {
            log.error("Could not save the sign-in: \(String(describing: error), privacy: .public)")
            return false
        }
    }

    func remove(for server: URL) {
        do {
            try secure.remove(for: Self.key(for: server))
        } catch {
            log.error("Could not remove the saved sign-in: \(String(describing: error), privacy: .public)")
        }
    }
}
