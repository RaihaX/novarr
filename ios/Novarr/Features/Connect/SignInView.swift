import SwiftUI

/// Name and password for a server with sign-in on. Shown after the server
/// address step, and again whenever the session ends (401 or sign-out).
struct SignInView: View {
    @Environment(AppModel.self) private var app
    @State private var username = ""
    @State private var password = ""
    @State private var isSigningIn = false
    @State private var errorMessage: String?
    @State private var showConnect = false
    @FocusState private var focus: Field?

    private enum Field { case username, password }

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    VStack(spacing: 14) {
                        BrandMark().frame(width: 44)
                        Text("Sign in")
                            .font(.system(.largeTitle, design: .serif).weight(.semibold))
                        Text("This Novarr server has accounts. Sign in with the name and password you were given.")
                            .font(.callout)
                            .foregroundStyle(.secondary)
                            .multilineTextAlignment(.center)
                    }
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 12)
                }
                .listRowBackground(Color.clear)

                if let notice = app.signInNotice {
                    Section {
                        Label(notice, systemImage: "person.crop.circle.badge.exclamationmark")
                            .font(.callout)
                    }
                }

                Section("Server") {
                    LabeledContent("Address") {
                        Text(app.serverURL?.absoluteString ?? "Not set")
                            .lineLimit(1)
                            .truncationMode(.middle)
                    }
                    Button("Change Server…") { showConnect = true }
                        .disabled(isSigningIn)
                }

                Section("Account") {
                    TextField("Name", text: $username, prompt: Text("Name"))
                        .textContentType(.username)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .submitLabel(.next)
                        .focused($focus, equals: .username)
                        .onSubmit { focus = .password }
                    SecureField("Password", text: $password, prompt: Text("Password"))
                        .textContentType(.password)
                        .submitLabel(.go)
                        .focused($focus, equals: .password)
                        .onSubmit { Task { await signIn() } }
                }
                .disabled(isSigningIn)

                if let errorMessage {
                    Section {
                        Label(errorMessage, systemImage: "exclamationmark.triangle.fill")
                            .foregroundStyle(.red)
                            .font(.callout)
                    }
                }

                Section {
                    Button {
                        Task { await signIn() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSigningIn {
                                ProgressView()
                                Text("Signing in…").padding(.leading, 6)
                            } else {
                                Text("Sign In").bold()
                            }
                            Spacer()
                        }
                    }
                    .disabled(!canSubmit)
                }
            }
            .navigationTitle("")
            .navigationBarTitleDisplayMode(.inline)
            .sheet(isPresented: $showConnect) { ConnectView(isInitialSetup: false) }
            .onAppear {
                if username.isEmpty, let last = app.lastUsername { username = last }
                focus = username.isEmpty ? .username : .password
            }
        }
    }

    private var canSubmit: Bool {
        !isSigningIn && !username.trimmingCharacters(in: .whitespaces).isEmpty && !password.isEmpty
    }

    private func signIn() async {
        guard canSubmit else { return }
        errorMessage = nil
        isSigningIn = true
        defer { isSigningIn = false }
        do {
            try await app.signIn(username: username.trimmingCharacters(in: .whitespaces), password: password)
            password = ""
        } catch {
            errorMessage = SignInMessages.message(for: APIError.from(error))
            focus = .password
        }
    }
}

/// What the sign-in form says when a sign-in fails.
enum SignInMessages {
    static func message(for error: APIError) -> String {
        switch error {
        case .http(422, let message):
            nonEmpty(message) ?? "Wrong name or password."
        case .http(429, let message):
            nonEmpty(message) ?? "Too many sign-in attempts. Wait a minute and try again."
        case .http(404, _):
            "This server doesn't support signing in from the app yet. Update the server."
        case .unreachable, .timedOut:
            "Couldn't reach the server. Check your connection and try again."
        default:
            error.localizedDescription
        }
    }

    private static func nonEmpty(_ text: String?) -> String? {
        guard let text, !text.trimmingCharacters(in: .whitespaces).isEmpty else { return nil }
        return text
    }
}
