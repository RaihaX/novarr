import Foundation

enum ServerURL {
    /// Turns what the user typed into a base URL: trims whitespace, assumes
    /// `http://` when there is no scheme, drops trailing slashes and a pasted
    /// `/api` or `/api/v1` suffix. Returns nil if no usable http(s) host remains.
    static func normalize(_ raw: String) -> URL? {
        var text = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !text.isEmpty, !text.contains(" ") else { return nil }
        if text.range(of: "://") == nil { text = "http://" + text }

        guard var components = URLComponents(string: text),
              let scheme = components.scheme?.lowercased(), scheme == "http" || scheme == "https",
              let host = components.host, !host.isEmpty
        else { return nil }

        components.scheme = scheme
        components.query = nil
        components.fragment = nil

        var path = components.path
        while path.hasSuffix("/") { path.removeLast() }
        for suffix in ["/api/v1", "/api"] where path.lowercased().hasSuffix(suffix) {
            path.removeLast(suffix.count)
            break
        }
        while path.hasSuffix("/") { path.removeLast() }
        components.path = path

        return components.url
    }

    /// A filesystem-safe key per server so cached data from one server never
    /// shows up after switching to another.
    static func storageKey(for url: URL) -> String {
        let raw = url.absoluteString.lowercased()
        let allowed = CharacterSet.alphanumerics
        let mapped = raw.unicodeScalars.map { allowed.contains($0) ? Character($0) : "_" }
        return String(mapped).replacingOccurrences(of: "__+", with: "_", options: .regularExpression)
    }
}
