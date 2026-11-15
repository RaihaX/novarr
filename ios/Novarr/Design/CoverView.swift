import SwiftUI

/// A 2:3 book cover that loads asynchronously through `CoverCache`, with a
/// typographic placeholder when there is no cover (or it can't load).
struct CoverView: View {
    let url: URL?
    let title: String
    var cornerRadius: CGFloat = 6
    /// Approximate on-screen width in points, used to pick a decode size.
    var targetWidth: CGFloat = 160

    @Environment(\.displayScale) private var displayScale
    @State private var image: UIImage?

    var body: some View {
        Color.clear
            .aspectRatio(2 / 3, contentMode: .fit)
            .overlay {
                if let image {
                    Image(uiImage: image)
                        .resizable()
                        .scaledToFill()
                        .transition(.opacity)
                } else {
                    CoverPlaceholder(title: title)
                }
            }
            .clipShape(RoundedRectangle(cornerRadius: cornerRadius, style: .continuous))
            .overlay {
                RoundedRectangle(cornerRadius: cornerRadius, style: .continuous)
                    .strokeBorder(Color.primary.opacity(0.08), lineWidth: 0.5)
            }
            .task(id: url) {
                guard let url else { image = nil; return }
                let loaded = await CoverCache.shared.image(for: url, maxPixel: targetWidth * 1.5 * displayScale)
                withAnimation(.easeOut(duration: 0.2)) { image = loaded }
            }
            .accessibilityHidden(true)
    }
}

struct CoverPlaceholder: View {
    let title: String

    var body: some View {
        ZStack(alignment: .bottomLeading) {
            LinearGradient(
                colors: [Color(hue: hue, saturation: 0.18, brightness: 0.42),
                         Color(hue: hue, saturation: 0.22, brightness: 0.28)],
                startPoint: .top, endPoint: .bottom)
            VStack(alignment: .leading, spacing: 8) {
                Text(title)
                    .font(.system(.footnote, design: .serif).weight(.semibold))
                    .foregroundStyle(.white.opacity(0.92))
                    .lineLimit(5)
                    .minimumScaleFactor(0.7)
                Spacer(minLength: 0)
                BrandMark(color: .white.opacity(0.35))
                    .frame(width: 18)
            }
            .padding(10)
        }
    }

    /// A stable muted hue per title so placeholders don't all look identical.
    private var hue: Double {
        var hash: UInt64 = 5381
        for byte in title.utf8 { hash = (hash &* 33) &+ UInt64(byte) }
        return Double(hash % 360) / 360
    }
}
