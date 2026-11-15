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
        case .dark: Color(red: 0.11, green: 0.11, blue: 0.12)
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
    case newYork, sanFrancisco, georgia
    var id: String { rawValue }

    var title: String {
        switch self {
        case .newYork: "New York"
        case .sanFrancisco: "San Francisco"
        case .georgia: "Georgia"
        }
    }

    func font(size: CGFloat) -> Font {
        switch self {
        case .newYork: .system(size: size, design: .serif)
        case .sanFrancisco: .system(size: size)
        case .georgia: .custom("Georgia", fixedSize: size)
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

/// UserDefaults keys shared by the reader and the settings screens.
enum ReaderKeys {
    static let fontSize = "reader.fontSize"
    static let font = "reader.font"
    static let spacing = "reader.spacing"
    static let margins = "reader.margins"
    static let width = "reader.width"
    static let theme = "reader.theme"
}

/// Reader appearance controls, used in the reader's sheet and in Settings.
struct ReaderSettingsForm: View {
    @AppStorage(ReaderKeys.fontSize) private var fontSize = 19.0
    @AppStorage(ReaderKeys.font) private var font = ReaderFont.newYork
    @AppStorage(ReaderKeys.spacing) private var spacing = ReaderSpacing.normal
    @AppStorage(ReaderKeys.margins) private var margins = ReaderMargins.medium
    @AppStorage(ReaderKeys.width) private var width = ReaderWidth.standard
    @AppStorage(ReaderKeys.theme) private var theme = ReaderTheme.system

    var body: some View {
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
                                .overlay(Text("Aa").font(.caption.weight(.semibold))
                                    .foregroundStyle(option == .system ? Color.gray : option.text))
                                .overlay(Circle().strokeBorder(theme == option ? Color.accentColor : Color.primary.opacity(0.2),
                                                               lineWidth: theme == option ? 3 : 1))
                                .frame(width: 44, height: 44)
                            Text(option.title).font(.caption2)
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
                Text("\(Int(fontSize)) pt").font(.body.monospacedDigit())
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
