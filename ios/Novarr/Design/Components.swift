import SwiftUI

/// The "Serial" brand mark: four flat bars, 22/22/15/8 units long on a 32 grid.
struct BrandMark: View {
    var color: Color = .accentColor

    var body: some View {
        GeometryReader { geo in
            let unit = min(geo.size.width / 22, geo.size.height / 23.5)
            VStack(alignment: .leading, spacing: 2.5 * unit) {
                ForEach([22.0, 22, 15, 8], id: \.self) { length in
                    Rectangle().fill(color).frame(width: length * unit, height: 4 * unit)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
        }
        .aspectRatio(22 / 23.5, contentMode: .fit)
        .accessibilityHidden(true)
    }
}

/// Thin, rounded progress bar.
struct ProgressLine: View {
    var fraction: Double
    var tint: Color = .accentColor
    var track: Color = Color.primary.opacity(0.12)
    var height: CGFloat = 4

    var body: some View {
        GeometryReader { geo in
            ZStack(alignment: .leading) {
                Capsule().fill(track)
                Capsule().fill(tint)
                    .frame(width: max(fraction > 0 ? height : 0, geo.size.width * min(max(fraction, 0), 1)))
            }
        }
        .frame(height: height)
        .accessibilityElement()
        .accessibilityValue(Text("\(Int((fraction * 100).rounded())) percent"))
    }
}

struct StateBadge: View {
    let state: NovelState

    var body: some View {
        Text(state.label.uppercased())
            .font(.caption2.weight(.semibold))
            .tracking(0.6)
            .padding(.horizontal, 8)
            .padding(.vertical, 3)
            .foregroundStyle(color)
            .background(color.opacity(0.14), in: Capsule())
    }

    private var color: Color {
        switch state {
        case .active: .accentColor
        case .completed: .green
        case .paused: .secondary
        case .attention: .orange
        case .failed: .red
        case .other: .secondary
        }
    }
}

/// Small pill shown when a screen is displaying its saved copy.
struct OfflinePill: View {
    var text = "Offline · showing saved copy"

    var body: some View {
        Label(text, systemImage: "icloud.slash")
            .font(.footnote)
            .foregroundStyle(.secondary)
            .padding(.horizontal, 12)
            .padding(.vertical, 6)
            .background(.thinMaterial, in: Capsule())
            .accessibilityLabel(text)
    }
}

/// Full-screen failure state with a retry action; used only when there is
/// nothing cached to show.
struct LoadFailedView: View {
    let title: String
    let message: String
    let retry: () async -> Void

    var body: some View {
        ContentUnavailableView {
            Label(title, systemImage: "wifi.exclamationmark")
        } description: {
            Text(message)
        } actions: {
            Button("Try again") { Task { await retry() } }
                .buttonStyle(.borderedProminent)
        }
    }
}

extension Int64 {
    var byteString: String {
        ByteCountFormatter.string(fromByteCount: self, countStyle: .file)
    }
}

enum LoadPhase: Equatable {
    case idle, loading, loaded, failed(String)
}
