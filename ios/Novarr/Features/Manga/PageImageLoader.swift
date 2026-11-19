import ImageIO
import SwiftUI
import UIKit

/// Manga page images: a downloaded file, or the pre-signed URL fetched as a
/// plain GET (no token, no other header). Decoded off the main thread and
/// downsampled to the width they are shown at; a small memory cache keeps
/// the pages around the current one.
actor PageImageLoader {
    static let shared = PageImageLoader()

    private let memory = NSCache<NSString, UIImage>()
    private var inFlight: [String: Task<UIImage?, Never>] = [:]
    private let session: URLSession

    init(session: URLSession = .novarr) {
        self.session = session
        memory.countLimit = 24
    }

    /// `width`: the widest the image is drawn, in pixels.
    func image(for url: URL, width: CGFloat) async -> UIImage? {
        let key = "\(url.absoluteString)#\(Int(width))"
        if let hit = memory.object(forKey: key as NSString) { return hit }
        if let task = inFlight[key] { return await task.value }
        let session = self.session
        let task = Task<UIImage?, Never>.detached(priority: .userInitiated) {
            let data: Data?
            if url.isFileURL {
                data = try? Data(contentsOf: url)
            } else {
                var request = URLRequest(url: url, timeoutInterval: 60)
                request.setValue("image/*", forHTTPHeaderField: "Accept")
                if let (body, response) = try? await session.data(for: request),
                   let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) {
                    data = body
                } else {
                    data = nil
                }
            }
            return data.flatMap { Self.decode($0, width: width) }
        }
        inFlight[key] = task
        let image = await task.value
        inFlight[key] = nil
        if let image { memory.setObject(image, forKey: key as NSString) }
        return image
    }

    /// Downsamples so the image is at most `width` pixels wide (tall strips
    /// keep their full width, unlike a longest-side limit).
    nonisolated static func decode(_ data: Data, width: CGFloat) -> UIImage? {
        let options = [kCGImageSourceShouldCache: false] as CFDictionary
        guard let source = CGImageSourceCreateWithData(data as CFData, options) else { return nil }
        let properties = CGImageSourceCopyPropertiesAtIndex(source, 0, nil) as? [CFString: Any]
        let pixelWidth = (properties?[kCGImagePropertyPixelWidth] as? CGFloat) ?? 0
        let pixelHeight = (properties?[kCGImagePropertyPixelHeight] as? CGFloat) ?? 0
        let longest = max(pixelWidth, pixelHeight)
        let scale = pixelWidth > width && pixelWidth > 0 ? width / pixelWidth : 1
        let thumbOptions = [
            kCGImageSourceCreateThumbnailFromImageAlways: true,
            kCGImageSourceShouldCacheImmediately: true,
            kCGImageSourceCreateThumbnailWithTransform: true,
            kCGImageSourceThumbnailMaxPixelSize: max(longest * scale, 64),
        ] as CFDictionary
        guard let cgImage = CGImageSourceCreateThumbnailAtIndex(source, 0, thumbOptions) else { return nil }
        return UIImage(cgImage: cgImage)
    }
}

/// One page image with loading and failure states.
struct PageImage: View {
    let url: URL?
    let index: Int
    let count: Int
    /// Fill the width (vertical strip) rather than fit the screen (pages).
    var fillWidth = false
    var aspect: Double?

    @Environment(\.displayScale) private var displayScale
    @State private var image: UIImage?
    @State private var failed = false

    var body: some View {
        GeometryReader { geo in
            content
                .frame(width: geo.size.width, height: geo.size.height)
                .task(id: url) { await load(width: geo.size.width) }
        }
        .modifier(StripSize(fillWidth: fillWidth, aspect: image.map { $0.size.height / max($0.size.width, 1) } ?? aspect))
        .accessibilityElement()
        .accessibilityLabel("Page \(index + 1) of \(count)")
        .accessibilityAddTraits(.isImage)
    }

    @ViewBuilder
    private var content: some View {
        if let image {
            Image(uiImage: image)
                .resizable()
                .interpolation(.high)
                .aspectRatio(contentMode: .fit)
        } else if failed {
            VStack(spacing: 10) {
                Text("Couldn't load page \(index + 1)")
                    .font(.ui(.callout))
                    .foregroundStyle(Color(white: 0.74))
                Button("Try again") { Task { failed = false; await load(width: 0) } }
                    .font(.ui(.callout).weight(.semibold))
            }
        } else {
            ProgressView().tint(Color(white: 0.6))
        }
    }

    private func load(width: CGFloat) async {
        guard let url else {
            failed = true
            return
        }
        let pixels = max(width, 320) * displayScale
        let loaded = await PageImageLoader.shared.image(for: url, width: min(pixels, 2400))
        guard !Task.isCancelled else { return }
        image = loaded
        failed = loaded == nil
    }

    /// In a vertical strip a page is as tall as its aspect ratio says (a
    /// placeholder height until it is known); on a page it fills the screen.
    private struct StripSize: ViewModifier {
        let fillWidth: Bool
        let aspect: Double?

        func body(content: Content) -> some View {
            if fillWidth {
                if let aspect, aspect > 0 {
                    content.aspectRatio(1 / aspect, contentMode: .fit)
                } else {
                    content.frame(height: 420)
                }
            } else {
                content
            }
        }
    }
}
