import SwiftUI

/// "Link with a code instead": shows the code to enter on the website and
/// waits. The poll loop belongs to `AppModel` (one per code), so this view
/// can be re-created freely; the rules are in `PairingFlow`.
struct PairingView: View {
    @Environment(AppModel.self) private var app
    let flow: PairingFlow

    var body: some View {
        Form {
            Section {
                VStack(spacing: 14) {
                    BrandMark().frame(width: 44)
                    Text("Link with a code")
                        .font(.literata(.title, .semibold))
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
            }
            .listRowBackground(Color.clear)

            switch flow.phase {
            case .starting:
                Section {
                    HStack(spacing: 10) {
                        ProgressView()
                        Text("Getting a code…")
                    }
                }
            case .waiting, .linked:
                Section {
                    Text(verbatim: flow.code)
                        .font(.system(size: 44, weight: .semibold, design: .monospaced))
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 8)
                        .textSelection(.enabled)
                        .accessibilityLabel(flow.code.map(String.init).joined(separator: " "))
                    Text(flow.instructions)
                        .font(.ui(.callout))
                    if let until = flow.validUntilText() {
                        Text(until)
                            .font(.ui(.callout))
                            .foregroundStyle(.secondary)
                    }
                    HStack(spacing: 10) {
                        ProgressView()
                        Text("Waiting for the code…")
                            .foregroundStyle(.secondary)
                    }
                    .font(.ui(.callout))
                }
            case .expired:
                Section {
                    Text("This code has run out.")
                        .font(.ui(.callout))
                    Button("Get a new code") { app.newPairingCode() }
                }
            case .failed(let message):
                Section {
                    Label(message, systemImage: "exclamationmark.triangle.fill")
                        .foregroundStyle(.red)
                        .font(.ui(.callout))
                    Button("Get a new code") { app.newPairingCode() }
                }
            }

            Section {
                Button("Cancel", role: .cancel) { app.cancelPairing() }
                    .frame(maxWidth: .infinity)
            }
        }
    }
}
