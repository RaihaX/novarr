import SwiftUI

enum ReaderTheme: String, CaseIterable, Identifiable {
    case system, light, sepia, dark, black
    var id: String { rawValue }

    var title: String { rawValue.capitalized }

    var colorScheme: ColorScheme? {
        switch self {
        case .system: nil
        case .light, .sepia: .light
        case .dark, .black: .dark
        }
    }

    var background: Color {
        switch self {
        case .system: Color(.systemBackground)
        case .light: .white
        case .sepia: Color(red: 0.976, green: 0.945, blue: 0.878)
        case .dark: .brandDark
        case .black: .black
        }
    }

    var text: Color {
        switch self {
        case .system: Color(.label)
        case .light: Color(white: 0.1)
        case .sepia: Color(red: 0.31, green: 0.24, blue: 0.16)
        case .dark: Color(white: 0.86)
        case .black: Color(white: 0.78)
        }
    }

    var secondaryText: Color { text.opacity(0.6) }
}

enum ReaderFont: String, CaseIterable, Identifiable {
    /// Literata (bundled) is the brand's reading face and the default.
    case literata, newYork, sanFrancisco, georgia
    var id: String { rawValue }

    static let standard = ReaderFont.literata

    var title: String {
        switch self {
        case .literata: "Literata"
        case .newYork: "New York"
        case .sanFrancisco: "San Francisco"
        case .georgia: "Georgia"
        }
    }

    /// `size` is already scaled for Dynamic Type by the reader. Must name
    /// the same face as `uiFont(size:weight:)` (ReaderTypography.swift).
    func font(size: CGFloat, weight: Font.Weight = .regular) -> Font {
        switch self {
        case .literata: .custom(BrandFont.literata(weight), fixedSize: size)
        case .newYork: .system(size: size, weight: weight, design: .serif)
        case .sanFrancisco: .system(size: size, weight: weight)
        case .georgia: .custom(weight == .regular ? "Georgia" : "Georgia-Bold", fixedSize: size)
        }
    }
}

enum ReaderSpacing: String, CaseIterable, Identifiable {
    case compact, normal, relaxed
    var id: String { rawValue }
    var title: String { rawValue.capitalized }
    /// Extra line spacing as a fraction of the font size.
    var factor: CGFloat {
        switch self {
        case .compact: 0.2
        case .normal: 0.45
        case .relaxed: 0.75
        }
    }
}

enum ReaderMargins: String, CaseIterable, Identifiable {
    case narrow, medium, wide
    var id: String { rawValue }
    var title: String { rawValue.capitalized }
    var padding: CGFloat {
        switch self {
        case .narrow: 14
        case .medium: 24
        case .wide: 40
        }
    }
}

enum ReaderWidth: String, CaseIterable, Identifiable {
    case narrow, standard, wide
    var id: String { rawValue }
    var title: String { rawValue.capitalized }
    /// Maximum text column width; matters on iPad and in landscape.
    var maxWidth: CGFloat {
        switch self {
        case .narrow: 560
        case .standard: 680
        case .wide: 860
        }
    }
}

/// How the text reader lays a chapter out (Android's `TextReadingMode`).
enum ReaderMode: String, CaseIterable, Identifiable {
    case scroll, pages
    var id: String { rawValue }
    var title: String { rawValue.capitalized }
}

/// UserDefaults keys shared by the reader and the settings screens.
enum ReaderKeys {
    static let fontSize = "reader.fontSize"
    static let font = "reader.font"
    static let spacing = "reader.spacing"
    static let margins = "reader.margins"
    static let width = "reader.width"
    static let theme = "reader.theme"
    static let mode = "reader.mode"
    static let justify = "reader.justify"
    static let keepScreenOn = "reader.keepScreenOn"
}

/// Reader appearance controls, used in the reader's sheet and in Settings.
struct ReaderSettingsForm: View {
    @AppStorage(ReaderKeys.fontSize) private var fontSize = 19.0
    @AppStorage(ReaderKeys.font) private var font = ReaderFont.standard
    @AppStorage(ReaderKeys.spacing) private var spacing = ReaderSpacing.normal
    @AppStorage(ReaderKeys.margins) private var margins = ReaderMargins.medium
    @AppStorage(ReaderKeys.width) private var width = ReaderWidth.standard
    @AppStorage(ReaderKeys.theme) private var theme = ReaderTheme.system
    @AppStorage(ReaderKeys.mode) private var mode = ReaderMode.scroll
    @AppStorage(ReaderKeys.justify) private var justify = false
    @AppStorage(ReaderKeys.keepScreenOn) private var keepScreenOn = true

    var body: some View {
        Section {
            Picker("Reading mode", selection: $mode) {
                ForEach(ReaderMode.allCases) { Text($0.title).tag($0) }
            }
            .pickerStyle(.segmented)
            Toggle("Keep screen on", isOn: $keepScreenOn)
        } header: {
            Text("Reading")
        } footer: {
            Text(mode == .pages
                 ? "Tap the right side or swipe left for the next page, the left side for the previous one, the middle for the controls."
                 : "Tap the middle of the page to show or hide the controls.")
        }

        Section("Theme") {
            HStack(spacing: 10) {
                ForEach(ReaderTheme.allCases) { option in
                    Button {
                        theme = option
                    } label: {
                        VStack(spacing: 6) {
                            Circle()
                                .fill(option == .system
                                      ? AnyShapeStyle(LinearGradient(colors: [.white, .black], startPoint: .topLeading, endPoint: .bottomTrailing))
                                      : AnyShapeStyle(option.background))
                                .overlay(Text("Aa").font(.ui(.caption, .semibold))
                                    .foregroundStyle(option == .system ? Color.gray : option.text))
                                .overlay(Circle().strokeBorder(theme == option ? Color.brandAccent : Color.primary.opacity(0.2),
                                                               lineWidth: theme == option ? 3 : 1))
                                .frame(width: 44, height: 44)
                            Text(option.title).font(.ui(.caption2))
                        }
                        .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.plain)
                    .accessibilityLabel(option.title)
                    .accessibilityAddTraits(theme == option ? .isSelected : [])
                }
            }
            .padding(.vertical, 4)
        }

        Section("Text") {
            HStack {
                Button { fontSize = max(13, fontSize - 1) } label: {
                    Image(systemName: "textformat.size.smaller").frame(width: 36, height: 30)
                }
                .accessibilityLabel("Smaller text")
                Spacer()
                Text("\(Int(fontSize)) pt").font(.ui(.body).monospacedDigit())
                Spacer()
                Button { fontSize = min(34, fontSize + 1) } label: {
                    Image(systemName: "textformat.size.larger").frame(width: 36, height: 30)
                }
                .accessibilityLabel("Larger text")
            }
            .buttonStyle(.bordered)
            Picker("Font", selection: $font) {
                ForEach(ReaderFont.allCases) { Text($0.title).font($0.font(size: 17)).tag($0) }
            }
            Picker("Line spacing", selection: $spacing) {
                ForEach(ReaderSpacing.allCases) { Text($0.title).tag($0) }
            }
            Toggle("Justify text", isOn: $justify)
        }

        Section {
            Picker("Margins", selection: $margins) {
                ForEach(ReaderMargins.allCases) { Text($0.title).tag($0) }
            }
            .pickerStyle(.segmented)
            Picker("Column width", selection: $width) {
                ForEach(ReaderWidth.allCases) { Text($0.title).tag($0) }
            }
        } header: {
            Text("Layout")
        } footer: {
            Text("Column width caps how wide lines get on iPad and in landscape.")
        }
    }
}

struct ReaderSettingsSheet: View {
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            Form { ReaderSettingsForm() }
                .navigationTitle("Reading")
                .navigationBarTitleDisplayMode(.inline)
                .toolbar {
                    ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } }
                }
        }
        .presentationDetents([.medium, .large])
    }
}
