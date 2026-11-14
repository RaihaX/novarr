import CryptoKit
import Foundation
import ImageIO
import UIKit

/// Cover images: memory cache in front of a disk cache in Caches/covers.
/// Disk entries are the original bytes; images are downsampled on decode.
actor CoverCache {
    static let shared = CoverCache()

    private let memory = NSCache<NSString, UIImage>()
    private var inFlight: [URL: Task<UIImage?, Never>] = [:]
    private let directory: URL
    private let session: URLSession

    init(directory: URL? = nil, session: URLSession = .novarr) {
        let caches = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first
            ?? FileManager.default.temporaryDirectory
        self.directory = directory ?? caches.appending(path: "covers", directoryHint: .isDirectory)
        self.session = session
        memory.countLimit = 300
        try? FileManager.default.createDirectory(at: self.directory, withIntermediateDirectories: true)
    }

    func image(for url: URL, maxPixel: CGFloat) async -> UIImage? {
        let key = "\(url.absoluteString)#\(Int(maxPixel))" as NSString
        if let hit = memory.object(forKey: key) { return hit }
        if let task = inFlight[url] {
            return await task.value
        }

        let file = directory.appending(path: Self.fileName(for: url))
        let session = self.session
        let task = Task<UIImage?, Never>.detached(priority: .utility) {
            if let data = try? Data(contentsOf: file), let image = Self.downsample(data, maxPixel: maxPixel) {
                return image
            }
            guard let (data, response) = try? await session.data(from: url),
                  let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode),
                  let image = Self.downsample(data, maxPixel: maxPixel)
            else { return nil }
            try? data.write(to: file, options: .atomic)
            return image
        }
        inFlight[url] = task
        let image = await task.value
        inFlight[url] = nil
        if let image { memory.setObject(image, forKey: key) }
        return image
    }

    func diskSize() -> Int64 { OfflineStore.size(of: directory) }

    func clear() {
        memory.removeAllObjects()
        try? FileManager.default.removeItem(at: directory)
        try? FileManager.default.createDirectory(at: directory, withIntermediateDirectories: true)
    }

    private static func fileName(for url: URL) -> String {
        SHA256.hash(data: Data(url.absoluteString.utf8)).map { String(format: "%02x", $0) }.joined()
    }

    private static func downsample(_ data: Data, maxPixel: CGFloat) -> UIImage? {
        let options = [kCGImageSourceShouldCache: false] as CFDictionary
        guard let source = CGImageSourceCreateWithData(data as CFData, options) else { return nil }
        let thumbOptions = [
            kCGImageSourceCreateThumbnailFromImageAlways: true,
            kCGImageSourceShouldCacheImmediately: true,
            kCGImageSourceCreateThumbnailWithTransform: true,
            kCGImageSourceThumbnailMaxPixelSize: max(maxPixel, 64),
        ] as CFDictionary
        guard let cgImage = CGImageSourceCreateThumbnailAtIndex(source, 0, thumbOptions) else { return nil }
        return UIImage(cgImage: cgImage)
    }
}
