import SwiftUI

/// Name and password for a server with sign-in on. Shown after the server
/// address step, and again whenever the session ends (401 or sign-out).
/// After a right password the server may ask for a sign-in code (the second
/// step), and a device can be linked with a code instead; both flows live in
/// `AppModel`, so this screen being re-created never loses or repeats them.
struct SignInView: View {
    @Environment(AppModel.self) private var app

    var body: some View {
        NavigationStack {
            Group {
                if let flow = app.secondStep {
                    SecondStepView(flow: flow)
                } else if let pairing = app.pairing {
                    PairingView(flow: pairing)
                } else {
                    PasswordSignInForm()
                }
            }
            .navigationTitle("")
            .navigationBarTitleDisplayMode(.inline)
        }
    }
}

/// The name-and-password form.
private struct PasswordSignInForm: View {
    @Environment(AppModel.self) private var app
    @State private var username = ""
    @State private var password = ""
    @State private var isSigningIn = false
    @State private var errorMessage: String?
    /// The server refused for want of a second step: point at linking with a code.
    @State private var offerLinkCode = false
    @State private var showConnect = false
    @FocusState private var focus: Field?

    private enum Field { case username, password }

    static let unavailableText = "This server is not accepting sign-ins at this address."

    private var signInUnavailable: Bool { app.serverMeta?.signInUnavailable == true }
    private var showsLinkCode: Bool { !signInUnavailable && (app.serverMeta?.offersPairing == true || offerLinkCode) }

    var body: some View {
        Form {
            Section {
                VStack(spacing: 14) {
                    BrandMark().frame(width: 44)
                    Text("Sign in")
                        .font(.literata(.largeTitle, .semibold))
                    Text("This Novarr server has accounts. Sign in with the name and password you were given.")
                        .font(.ui(.callout))
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
                        .font(.ui(.callout))
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

            if signInUnavailable {
                Section {
                    Label(Self.unavailableText, systemImage: "lock.slash")
                        .font(.ui(.callout))
                }
            } else {
                accountSections
            }
        }
        .sheet(isPresented: $showConnect) { ConnectView(isInitialSetup: false) }
        .onAppear {
            if username.isEmpty, let last = app.lastUsername { username = last }
            focus = username.isEmpty ? .username : .password
        }
    }

    @ViewBuilder private var accountSections: some View {
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

        if let errorMessage = errorMessage ?? app.signInError {
            Section {
                Label(errorMessage, systemImage: "exclamationmark.triangle.fill")
                    .foregroundStyle(.red)
                    .font(.ui(.callout))
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
            if showsLinkCode {
                Button("Link with a code instead") {
                    password = ""
                    app.startPairing()
                }
                .frame(maxWidth: .infinity)
                .disabled(isSigningIn)
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
        offerLinkCode = false
        do {
            // The password goes out once: signed in, or on to the sign-in code.
            try await app.signIn(username: username.trimmingCharacters(in: .whitespaces), password: password)
            password = ""
        } catch {
            let apiError = APIError.from(error)
            errorMessage = SignInMessages.message(for: apiError)
            if case .secondStepUnavailable = apiError {
                password = ""
                offerLinkCode = true
            }
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
        case .secondStepUnavailable, .challengeExpired:
            error.localizedDescription
        default:
            error.localizedDescription
        }
    }

    private static func nonEmpty(_ text: String?) -> String? {
        guard let text, !text.trimmingCharacters(in: .whitespaces).isEmpty else { return nil }
        return text
    }
}
