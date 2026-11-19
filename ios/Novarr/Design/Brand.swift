import SwiftUI
import UIKit

// The brand hand-off (design_handoff_novarr_brand), as Android's ui/theme:
// Geist for UI text, Literata for reading and display titles, indigo for
// actions, amber only for reading signals (progress, the mark's last bar).
// Both families are bundled (Resources/Fonts, SIL OFL 1.1) and registered
// through UIAppFonts.

/// Bundled faces by PostScript name.
enum BrandFont {
    static let geistRegular = "Geist-Regular"
    static let geistMedium = "Geist-Medium"
    static let geistSemiBold = "Geist-SemiBold"
    static let geistMono = "GeistMono-Regular"
    static let literataRegular = "Literata-Regular"
    static let literataSemiBold = "Literata-SemiBold"
    static let literataBold = "Literata-Bold"

    /// The licence texts shipped with the fonts (Settings › Font licences).
    static let licenceFiles = ["OFL-Geist", "OFL-Literata"]

    /// Geist face for a weight: the bundle has Regular, Medium and SemiBold
    /// (bold and heavier use SemiBold, as on Android).
    static func geist(_ weight: Font.Weight) -> String {
        switch weight {
        case .regular, .light, .thin, .ultraLight: geistRegular
        case .medium: geistMedium
        default: geistSemiBold
        }
    }

    static func literata(_ weight: Font.Weight) -> String {
        switch weight {
        case .regular, .light, .thin, .ultraLight: literataRegular
        case .medium, .semibold: literataSemiBold
        default: literataBold
        }
    }

    /// The system's default point size for a text style (before Dynamic Type).
    static func baseSize(_ style: Font.TextStyle) -> CGFloat {
        switch style {
        case .largeTitle: 34
        case .title: 28
        case .title2: 22
        case .title3: 20
        case .headline, .body: 17
        case .callout: 16
        case .subheadline: 15
        case .footnote: 13
        case .caption: 12
        case .caption2: 11
        @unknown default: 17
        }
    }
}

extension Font {
    /// Geist at a text style's size, scaling with Dynamic Type. Headlines
    /// default to SemiBold as the system's do.
    static func ui(_ style: Font.TextStyle, _ weight: Font.Weight? = nil) -> Font {
        let weight = weight ?? (style == .headline ? .semibold : .regular)
        return .custom(BrandFont.geist(weight), size: BrandFont.baseSize(style), relativeTo: style)
    }

    /// Geist Mono (counters, addresses), scaling with Dynamic Type.
    static func uiMono(_ style: Font.TextStyle) -> Font {
        .custom(BrandFont.geistMono, size: BrandFont.baseSize(style), relativeTo: style)
    }

    /// Literata: display titles (SemiBold) and quoted or reading text, scaling with Dynamic Type.
    static func literata(_ style: Font.TextStyle, _ weight: Font.Weight = .regular) -> Font {
        .custom(BrandFont.literata(weight), size: BrandFont.baseSize(style), relativeTo: style)
    }
}

extension Color {
    /// The brand indigo pair (the AccentColor asset): IndigoFill #4A58E8 on
    /// light grounds, IndigoInk #8EA2FF on dark. Named explicitly: the
    /// environment's `accentColor` resolved to the system blue here.
    static let brandAccent = Color("AccentColor")
    /// Screen ground: #F7F8FA light, the canonical #0F1216 dark.
    static let appBackground = Color("AppBackground")
    /// Raised surfaces (cards): white light, #161A20 dark.
    static let surface = Color("Surface")
    /// Reading signals: progress fills and the mark's last bar.
    static let brandAmber = Color("BrandAmber")
    /// Text and icons on an accent fill (white on light, ink on dark, where
    /// the accent is the lighter indigo).
    static let onAccent = Color("OnAccent")
    /// The canonical dark ground (#0F1216).
    static let brandDark = Color(red: 15 / 255, green: 18 / 255, blue: 22 / 255)
}

extension View {
    /// The brand ground behind a screen's scrolling content (lists and
    /// forms drop their own system background for it).
    func brandBackground() -> some View {
        scrollContentBackground(.hidden)
            .background(Color.appBackground.ignoresSafeArea())
    }
}

/// The app's own light/dark choice (Settings › Appearance). Dark is the
/// brand's canonical look and the default.
enum AppAppearance: String, CaseIterable, Identifiable {
    case system, light, dark
    var id: String { rawValue }
    static let defaultsKey = "app.appearance"

    var title: String { rawValue.capitalized }

    var colorScheme: ColorScheme? {
        switch self {
        case .system: nil
        case .light: .light
        case .dark: .dark
        }
    }

    var interfaceStyle: UIUserInterfaceStyle {
        switch self {
        case .system: .unspecified
        case .light: .light
        case .dark: .dark
        }
    }

    /// Sets every window's style: the app's choice, or a themed reader's
    /// while it is on screen. Window-level, so sheets and alerts follow too.
    @MainActor static func applyToWindows(_ style: UIUserInterfaceStyle) {
        for scene in UIApplication.shared.connectedScenes {
            for window in (scene as? UIWindowScene)?.windows ?? [] {
                window.overrideUserInterfaceStyle = style
            }
        }
    }
}
