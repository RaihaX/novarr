import Foundation
#if canImport(UIKit)
import UIKit
#endif

/// Who is writing read state, so the server can tell this device apart from
/// the Kindle or the web reader ("latest position" prompt). Sent as the
/// `X-Novarr-Device` / `X-Novarr-Device-Name` headers on read-state writes.
struct DeviceIdentity: Sendable, Equatable {
    static let idDefaultsKey = "deviceID"
    static let idHeader = "X-Novarr-Device"
    static let nameHeader = "X-Novarr-Device-Name"
    /// Every client prefixes its ids (`k_` Kindle, `i_` iOS…) to ease debugging.
    static let idPrefix = "i_"

    let id: String
    let name: String

    /// The persisted id, or a new one saved for next time. A stored value that
    /// doesn't fit the contract (8–64 of `[A-Za-z0-9_-]`) is replaced.
    static func loadOrCreate(defaults: UserDefaults, name: String) -> DeviceIdentity {
        if let stored = defaults.string(forKey: idDefaultsKey), isValidID(stored) {
            return DeviceIdentity(id: stored, name: name)
        }
        let id = makeID()
        defaults.set(id, forKey: idDefaultsKey)
        return DeviceIdentity(id: id, name: name)
    }

    static func makeID() -> String {
        let alphabet = Array("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789")
        var generator = SystemRandomNumberGenerator()
        let suffix = (0..<22).map { _ in alphabet[Int(generator.next(upperBound: UInt(alphabet.count)))] }
        return idPrefix + String(suffix)
    }

    static func isValidID(_ id: String) -> Bool {
        (8...64).contains(id.count) && id.allSatisfy { char in
            char.isASCII && (char.isLetter || char.isNumber || char == "_" || char == "-")
        }
    }

    /// "Mac" for iOS-on-Mac and Catalyst, else "iPad" or "iPhone".
    static func name(isPad: Bool, onMac: Bool) -> String {
        if onMac { return "Mac" }
        return isPad ? "iPad" : "iPhone"
    }

    @MainActor
    static func currentName() -> String {
        let info = ProcessInfo.processInfo
        let onMac = info.isiOSAppOnMac || info.isMacCatalystApp
        #if canImport(UIKit)
        let idiom = UIDevice.current.userInterfaceIdiom
        return name(isPad: idiom == .pad, onMac: onMac || idiom == .mac)
        #else
        return name(isPad: false, onMac: true)
        #endif
    }

    var headers: [String: String] {
        [Self.idHeader: id, Self.nameHeader: name]
    }
}
