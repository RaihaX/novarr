import Foundation

/// Shared coders for the API and the on-disk cache, so cached files read back
/// through exactly the same path as live responses.
enum JSONCoding {
    static func decoder() -> JSONDecoder {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        decoder.dateDecodingStrategy = .custom { decoder in
            let container = try decoder.singleValueContainer()
            let raw = try container.decode(String.self)
            guard let date = parseDate(raw) else {
                throw DecodingError.dataCorruptedError(
                    in: container, debugDescription: "Unrecognised date \(raw)")
            }
            return date
        }
        return decoder
    }

    static func encoder() -> JSONEncoder {
        let encoder = JSONEncoder()
        encoder.keyEncodingStrategy = .convertToSnakeCase
        encoder.dateEncodingStrategy = .iso8601
        return encoder
    }

    /// ISO-8601 as the contract specifies (no fractional seconds), tolerating
    /// fractional seconds in case the server ever sends them.
    static func parseDate(_ raw: String) -> Date? {
        if let date = try? Date.ISO8601FormatStyle().parse(raw) { return date }
        return try? Date.ISO8601FormatStyle(includingFractionalSeconds: true).parse(raw)
    }
}

/// Decodes an array element by element, skipping elements that fail, so one
/// malformed row never empties a whole list.
struct LossyArray<Element: Decodable>: Decodable {
    var items: [Element]

    init(from decoder: Decoder) throws {
        var container = try decoder.unkeyedContainer()
        var items: [Element] = []
        if let count = container.count { items.reserveCapacity(count) }
        while !container.isAtEnd {
            if let item = try? container.decode(Element.self) {
                items.append(item)
            } else {
                // Decoding anything advances the cursor past the bad element.
                _ = try? container.decode(Skip.self)
            }
        }
        self.items = items
    }

    private struct Skip: Decodable {
        init(from decoder: Decoder) throws {}
    }
}

extension KeyedDecodingContainer {
    func lenientInt(_ key: Key) -> Int? {
        if let value = try? decodeIfPresent(Int.self, forKey: key) { return value }
        if let value = try? decodeIfPresent(Double.self, forKey: key), value.isFinite { return Int(value) }
        if let value = try? decodeIfPresent(String.self, forKey: key) {
            return Int(value) ?? Double(value).flatMap { $0.isFinite ? Int($0) : nil }
        }
        return nil
    }

    func lenientInt64(_ key: Key) -> Int64? {
        if let value = try? decodeIfPresent(Int64.self, forKey: key) { return value }
        if let value = try? decodeIfPresent(Double.self, forKey: key), value.isFinite { return Int64(value) }
        if let value = try? decodeIfPresent(String.self, forKey: key) {
            let trimmed = value.trimmingCharacters(in: .whitespaces)
            return Int64(trimmed) ?? Double(trimmed).flatMap { $0.isFinite ? Int64($0) : nil }
        }
        return nil
    }

    func lenientDouble(_ key: Key) -> Double? {
        if let value = try? decodeIfPresent(Double.self, forKey: key) { return value }
        if let value = try? decodeIfPresent(String.self, forKey: key) { return Double(value) }
        return nil
    }

    func lenientBool(_ key: Key) -> Bool? {
        if let value = try? decodeIfPresent(Bool.self, forKey: key) { return value }
        if let value = try? decodeIfPresent(Int.self, forKey: key) { return value != 0 }
        if let value = try? decodeIfPresent(String.self, forKey: key) {
            return ["1", "true", "yes"].contains(value.lowercased())
        }
        return nil
    }

    func lenientString(_ key: Key) -> String? {
        if let value = try? decodeIfPresent(String.self, forKey: key) { return value }
        if let value = try? decodeIfPresent(Int.self, forKey: key) { return String(value) }
        if let value = try? decodeIfPresent(Double.self, forKey: key) { return String(value) }
        return nil
    }

    func lenientURL(_ key: Key) -> URL? {
        guard let raw = lenientString(key), !raw.isEmpty else { return nil }
        return URL(string: raw)
    }

    func lenientDate(_ key: Key) -> Date? {
        if let raw = try? decodeIfPresent(String.self, forKey: key) { return JSONCoding.parseDate(raw) }
        // Our own cache encodes dates as ISO strings too, but accept epoch seconds.
        if let seconds = try? decodeIfPresent(Double.self, forKey: key) {
            return Date(timeIntervalSince1970: seconds)
        }
        return nil
    }

    func lossyArray<T: Decodable>(_ type: T.Type, _ key: Key) -> [T] {
        (try? decodeIfPresent(LossyArray<T>.self, forKey: key))?.items ?? []
    }
}
