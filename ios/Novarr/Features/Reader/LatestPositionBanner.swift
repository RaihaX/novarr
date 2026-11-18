import SwiftUI

/// Compact card at the bottom of the reader offering to jump to where the
/// series was read more recently on another device. Non-blocking: the text
/// stays readable and scrollable behind it until the user picks an option.
struct LatestPositionBanner: View {
    let message: String
    let theme: ReaderTheme
    let onGo: () -> Void
    let onStay: () -> Void

    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Label {
                Text(message)
                    .font(.callout)
                    .foregroundStyle(theme.text)
                    .fixedSize(horizontal: false, vertical: true)
            } icon: {
                Image(systemName: "arrow.triangle.2.circlepath")
                    .foregroundStyle(Color.accentColor)
                    .accessibilityHidden(true)
            }

            let buttons = Group {
                button("Stay here", prominent: false, action: onStay)
                    .accessibilityHint("Keeps reading here and stops offering this position.")
                button("Go to latest", prominent: true, action: onGo)
                    .accessibilityHint("Opens the chapter at the position read on the other device.")
            }
            // Large accessibility sizes stack the buttons so labels never truncate.
            if dynamicTypeSize.isAccessibilitySize {
                VStack(spacing: 8) { buttons }
            } else {
                HStack(spacing: 10) { buttons }
            }
        }
        .padding(16)
        .background(background, in: RoundedRectangle(cornerRadius: 16, style: .continuous))
        .overlay(
            RoundedRectangle(cornerRadius: 16, style: .continuous)
                .strokeBorder(theme.text.opacity(0.08))
        )
        .shadow(color: .black.opacity(0.15), radius: 12, y: 4)
        .frame(maxWidth: 560)
        .padding(.horizontal, 16)
        .padding(.bottom, 12)
        .accessibilityElement(children: .contain)
        .accessibilityLabel("Latest reading position")
        .onAppear {
            AccessibilityNotification.Announcement(message).post()
        }
    }

    private var background: AnyShapeStyle {
        theme == .system ? AnyShapeStyle(.regularMaterial) : AnyShapeStyle(theme.background)
    }

    /// Same look as the reader's Previous / Next chapter buttons.
    private func button(_ title: String, prominent: Bool, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            Text(title)
                .font(.callout.weight(.semibold))
                .multilineTextAlignment(.center)
                .frame(maxWidth: .infinity)
                .padding(.vertical, 11)
                .foregroundStyle(prominent ? Color.white : theme.text)
                .background(prominent ? AnyShapeStyle(Color.accentColor) : AnyShapeStyle(theme.text.opacity(0.08)),
                            in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

#Preview {
    ZStack(alignment: .bottom) {
        Color.gray.opacity(0.2).ignoresSafeArea()
        LatestPositionBanner(
            message: "Chapter 124: The Gate · 40% was read on Kindle 2 hours ago. Go there?",
            theme: .sepia, onGo: {}, onStay: {})
    }
}
