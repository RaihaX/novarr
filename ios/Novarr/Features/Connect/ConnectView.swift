import SwiftUI

/// Enter and verify the server address. Shown on first launch and from Settings.
struct ConnectView: View {
    var isInitialSetup: Bool

    @Environment(AppModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @State private var address = ""
    @State private var isChecking = false
    @State private var errorMessage: String?
    @FocusState private var fieldFocused: Bool

    var body: some View {
        NavigationStack {
            Form {
                if isInitialSetup {
                    Section {
                        VStack(spacing: 14) {
                            BrandMark().frame(width: 44)
                            Text("Novarr")
                                .font(.literata(.largeTitle, .semibold))
                            Text("Connect to the Novarr server you run at home to read your library here.")
                                .font(.ui(.callout))
                                .foregroundStyle(.secondary)
                                .multilineTextAlignment(.center)
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 12)
                    }
                    .listRowBackground(Color.clear)
                }

                Section {
                    // Verbatim so the example isn't auto-linked as Markdown.
                    TextField("Server address", text: $address,
                              prompt: Text(verbatim: "http://192.168.0.10:8080"))
                        .keyboardType(.URL)
                        .textContentType(.URL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .submitLabel(.go)
                        .focused($fieldFocused)
                        .onSubmit { Task { await verify() } }
                        .disabled(isChecking)
                } header: {
                    Text("Server address")
                } footer: {
                    Text("Your server's LAN address, or its HTTPS address on Tailscale. “http://” is added if you leave it out.")
                }

                if let errorMessage {
                    Section {
                        Label(errorMessage, systemImage: "exclamationmark.triangle.fill")
                            .foregroundStyle(.red)
                            .font(.ui(.callout))
                    }
                }

                Section {
                    Button {
                        Task { await verify() }
                    } label: {
                        HStack {
                            Spacer()
                            if isChecking {
                                ProgressView()
                                Text("Checking…").padding(.leading, 6)
                            } else {
                                Text("Connect").bold()
                            }
                            Spacer()
                        }
                    }
                    .disabled(isChecking || address.trimmingCharacters(in: .whitespaces).isEmpty)
                }
            }
            .navigationTitle(isInitialSetup ? "" : "Server")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                if !isInitialSetup {
                    ToolbarItem(placement: .cancellationAction) {
                        Button("Cancel") { dismiss() }
                    }
                }
            }
            .onAppear {
                if address.isEmpty, let current = app.serverURL { address = current.absoluteString }
                if isInitialSetup { fieldFocused = true }
            }
        }
    }

    private func verify() async {
        guard !isChecking else { return }
        errorMessage = nil
        guard let url = ServerURL.normalize(address) else {
            errorMessage = APIError.invalidServerURL.localizedDescription
            return
        }
        address = url.absoluteString
        isChecking = true
        defer { isChecking = false }
        do {
            let meta = try await APIClient(baseURL: url).meta()
            app.connect(to: url, meta: meta)
            if !isInitialSetup { dismiss() }
        } catch {
            errorMessage = APIError.from(error).localizedDescription
        }
    }
}
