import SwiftUI

/// One line describing a check: spinner while running, then the outcome.
struct CheckStatusLabel: View {
    let display: CheckDisplay

    var body: some View {
        HStack(spacing: 6) {
            if display.isChecking {
                ProgressView().controlSize(.small)
            } else {
                Image(systemName: icon).foregroundStyle(tint)
            }
            Text(display.text)
                .foregroundStyle(display.isChecking ? Color.secondary : tint)
                .fixedSize(horizontal: false, vertical: true)
        }
        .accessibilityElement(children: .combine)
    }

    private var icon: String {
        switch display {
        case .checking: "arrow.clockwise"
        case .found: "sparkles"
        case .noneYet: "checkmark.circle"
        case .failed: "exclamationmark.triangle"
        case .stillRunning: "clock"
        }
    }

    private var tint: Color {
        switch display {
        case .found: .accentColor
        case .failed: .orange
        default: .secondary
        }
    }
}

/// "Check for new chapters" button plus its status, for the novel screen and
/// the reader's "caught up" footer.
struct CheckForChaptersControl: View {
    let novelId: Int
    var compact = false

    @Environment(AppModel.self) private var app

    var body: some View {
        let display = app.checks.display(for: novelId)
        let running = app.checks.isRunning(novelId)
        Group {
            if compact {
                VStack(spacing: 6) {
                    if let display { CheckStatusLabel(display: display) }
                    if !running { checkButton(display) }
                }
            } else {
                HStack(spacing: 8) {
                    if let display {
                        CheckStatusLabel(display: display)
                        Spacer(minLength: 0)
                    }
                    if !running { checkButton(display) }
                }
            }
        }
        .font(.footnote)
        .frame(maxWidth: .infinity, alignment: compact ? .center : .leading)
    }

    private func checkButton(_ display: CheckDisplay?) -> some View {
        Button {
            app.startCheck(novelId: novelId)
        } label: {
            Label(display == nil ? "Check for new chapters" : "Check again", systemImage: "arrow.clockwise")
                .font(.footnote.weight(.semibold))
        }
        .buttonStyle(.borderless)
        .disabled(app.client == nil)
    }
}
