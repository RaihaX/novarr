import SwiftUI

/// "Enter your sign-in code": the second step after a right password. The
/// rules (default method, resend delay, code format, messages) are in
/// `SecondStepFlow`; the requests in `AppModel`.
struct SecondStepView: View {
    @Environment(AppModel.self) private var app
    let flow: SecondStepFlow
    @State private var code = ""
    @State private var isSubmitting = false
    @State private var isSending = false
    @FocusState private var codeFocused: Bool

    var body: some View {
        Form {
            Section {
                VStack(spacing: 14) {
                    BrandMark().frame(width: 44)
                    Text(flow.title)
                        .font(.literata(.title, .semibold))
                        .multilineTextAlignment(.center)
                    Text(flow.prompt)
                        .font(.ui(.callout))
                        .foregroundStyle(.secondary)
                        .multilineTextAlignment(.center)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 12)
            }
            .listRowBackground(Color.clear)

            methodSection

            if let error = flow.errorMessage {
                Section {
                    Label(error, systemImage: "exclamationmark.triangle.fill")
                        .foregroundStyle(.red)
                        .font(.ui(.callout))
                }
            }

            if showsCodeField {
                Section {
                    Button {
                        Task { await submit() }
                    } label: {
                        HStack {
                            Spacer()
                            if isSubmitting {
                                ProgressView()
                                Text("Checking…").padding(.leading, 6)
                            } else {
                                Text("Continue").bold()
                            }
                            Spacer()
                        }
                    }
                    .disabled(!canSubmit)
                }
            }

            if !flow.otherMethods.isEmpty {
                Section("Other ways") {
                    ForEach(flow.otherMethods, id: \.self) { method in
                        Button(method.label) { app.selectSecondStepMethod(method) }
                            .disabled(isSubmitting)
                    }
                }
            }

            Section {
                Button("Cancel", role: .cancel) { app.cancelSecondStep() }
                    .frame(maxWidth: .infinity)
                    .disabled(isSubmitting)
            }
        }
        .onChange(of: flow.method) {
            code = ""
            codeFocused = showsCodeField
        }
        .onAppear { codeFocused = showsCodeField }
    }

    /// The emailed code's field only shows once a code was sent.
    private var showsCodeField: Bool { flow.method != .email || flow.emailSent }

    @ViewBuilder private var methodSection: some View {
        switch flow.method {
        case .totp:
            Section {
                digitsField
            } header: {
                Text("Authenticator app")
            } footer: {
                Text("Enter the 6-digit code your authenticator app shows for Novarr.")
            }
        case .email:
            Section {
                if flow.emailSent {
                    Text(flow.emailSentText)
                        .font(.ui(.callout))
                    digitsField
                    TimelineView(.periodic(from: .now, by: 1)) { context in
                        Button("Send again") { Task { await sendEmail() } }
                            .disabled(isSending || isSubmitting || !flow.canSendEmail(at: context.date))
                    }
                } else {
                    Button {
                        Task { await sendEmail() }
                    } label: {
                        HStack {
                            Text("Email me a code")
                            if isSending { Spacer(); ProgressView() }
                        }
                    }
                    .disabled(isSending)
                }
            } header: {
                Text("Emailed code")
            }
        case .recovery:
            Section {
                TextField("Recovery code", text: codeBinding, prompt: Text(verbatim: "xxxxx-xxxxx"))
                    .font(.uiMono(.body))
                    .textContentType(.oneTimeCode)
                    .keyboardType(.asciiCapable)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .submitLabel(.go)
                    .focused($codeFocused)
                    .onSubmit { Task { await submit() } }
                    .disabled(isSubmitting)
            } header: {
                Text("Recovery code")
            } footer: {
                Text("Each recovery code works once.")
            }
        }
    }

    private var digitsField: some View {
        TextField("Code", text: codeBinding, prompt: Text("6-digit code"))
            .font(.uiMono(.body))
            .textContentType(.oneTimeCode)
            .keyboardType(.numberPad)
            .focused($codeFocused)
            .disabled(isSubmitting)
    }

    /// Keeps the field to what the method takes (digits, or `xxxxx-xxxxx`).
    private var codeBinding: Binding<String> {
        Binding(get: { code }, set: { code = SecondStepFlow.normalize($0, for: flow.method) })
    }

    private var canSubmit: Bool {
        !isSubmitting && showsCodeField && SecondStepFlow.isComplete(code, for: flow.method)
    }

    private func submit() async {
        guard canSubmit else { return }
        isSubmitting = true
        defer { isSubmitting = false }
        await app.submitSecondStep(code: code)
        // Still here: the code was refused. Start the field over.
        if app.secondStep != nil {
            code = ""
            codeFocused = true
        }
    }

    private func sendEmail() async {
        guard !isSending else { return }
        isSending = true
        defer { isSending = false }
        await app.requestEmailCode()
        if app.secondStep?.emailSent == true { codeFocused = true }
    }
}
