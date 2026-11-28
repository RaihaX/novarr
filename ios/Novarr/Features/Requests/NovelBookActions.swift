import SwiftUI

/// "Send to my Kindle / email" for one series: POST, then poll GET until
/// sent / failed (or the give-up time). Never queued offline.
@MainActor
@Observable
final class SendModel {
    /// The dialog's state; nil when it is closed.
    private(set) var state: SendToKindle.UiState?
    @ObservationIgnored private var task: Task<Void, Never>?

    func start(novelId: Int, app: AppModel) {
        guard let client = app.client, state?.isRunning != true else { return }
        task?.cancel()
        state = .running(to: nil)
        task = Task { [weak self] in
            let started: SendState
            do {
                started = try await client.startSend(novelId: novelId)
                app.noteSuccess()
            } catch {
                app.noteFailure(APIError.from(error))
                self?.state = SendToKindle.refusal(error, baseURL: client.baseURL.absoluteString)
                return
            }
            self?.state = .running(to: started.to)
            let began = Date()
            var current = started
            while !Task.isCancelled {
                let elapsed = Date().timeIntervalSince(began)
                // The 202 answer itself may already be the end (a send that was running).
                if SendToKindle.phase(current) != .running || elapsed >= SendToKindle.giveUpAfter {
                    self?.state = SendToKindle.next(current, elapsed: elapsed)
                    return
                }
                try? await Task.sleep(for: .seconds(SendToKindle.pollInterval))
                if Task.isCancelled { return }
                do {
                    current = try await client.sendState(novelId: novelId)
                } catch {
                    let api = APIError.from(error)
                    // Offline for a moment: the send goes on on the server; keep asking.
                    if api.isConnectivity { continue }
                    self?.state = .failed(Requests.message(api))
                    return
                }
            }
        }
    }

    func dismiss() {
        task?.cancel()
        state = nil
    }
}

/// The series screen's book controls (android NovelScreen): "Send to my
/// Kindle / email" for series with an ePub (not manga or comics), and "Ask
/// for the audiobook" for a book without one on a server with audiobooks.
struct NovelBookActions: View {
    let summary: NovelSummary
    let detail: NovelDetail?

    @Environment(AppModel.self) private var app
    @State private var send = SendModel()
    @State private var asking = false
    @State private var notice: String?

    private var showsSend: Bool { !summary.isPaged }
    private var showsAsk: Bool { app.serverMeta?.audiobooks == true && summary.audiobook == nil && summary.isBook }

    var body: some View {
        if showsSend || showsAsk {
            VStack(alignment: .leading, spacing: 8) {
                if showsSend {
                    Button {
                        send.start(novelId: summary.id, app: app)
                    } label: {
                        Label(SendToKindle.menuLabel, systemImage: "paperplane")
                            .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.bordered)
                    .disabled(!app.isReachable || send.state?.isRunning == true)
                }
                if showsAsk {
                    Button {
                        ask()
                    } label: {
                        HStack {
                            Label("Ask for the audiobook", systemImage: "headphones")
                            if asking { ProgressView().controlSize(.small) }
                        }
                        .frame(maxWidth: .infinity)
                    }
                    .buttonStyle(.borderless)
                    .disabled(!app.isReachable || asking)
                }
                if let notice {
                    Text(notice)
                        .font(.ui(.footnote))
                        .foregroundStyle(.secondary)
                        .transition(.opacity)
                        .task(id: notice) {
                            UIAccessibility.post(notification: .announcement, argument: notice)
                            try? await Task.sleep(for: .seconds(6))
                            withAnimation { self.notice = nil }
                        }
                }
            }
            .sheet(isPresented: Binding(get: { send.state != nil }, set: { if !$0 { send.dismiss() } })) {
                if let state = send.state { SendSheet(state: state, onDismiss: send.dismiss) }
            }
        }
    }

    private func ask() {
        guard let client = app.client else {
            notice = "Sign in to ask for audiobooks."
            return
        }
        asking = true
        Task {
            let message: String
            do {
                message = try await client.askAudiobook(novelId: summary.id)
                app.noteSuccess()
            } catch {
                app.noteFailure(APIError.from(error))
                message = Requests.message(error)
            }
            asking = false
            withAnimation { notice = message }
        }
    }
}

/// "Send to my Kindle / email": progress, then the server's message (or where to add an address).
private struct SendSheet: View {
    let state: SendToKindle.UiState
    let onDismiss: () -> Void
    @Environment(\.openURL) private var openURL

    var body: some View {
        VStack(alignment: .leading, spacing: 18) {
            Text(SendToKindle.menuLabel).font(.ui(.headline, .semibold))
            Group {
                switch state {
                case .running(let to):
                    HStack(spacing: 12) {
                        ProgressView()
                        Text(to.flatMap { $0.isEmpty ? nil : "Sending to \($0)…" } ?? "Sending…")
                    }
                    .accessibilityElement(children: .combine)
                case .sent(let message), .stillSending(let message):
                    Text(message)
                case .failed(let message):
                    Text(message).foregroundStyle(.red)
                case .noAddress(let message, _):
                    Text(message)
                }
            }
            .font(.ui(.body))
            .fixedSize(horizontal: false, vertical: true)
            HStack {
                Spacer()
                if case .noAddress(_, let account?) = state, let url = URL(string: account) {
                    Button("Not now", action: onDismiss)
                    Button("Open Account page") {
                        openURL(url)
                        onDismiss()
                    }
                    .buttonStyle(.borderedProminent)
                } else {
                    Button(state.isRunning ? "Hide" : "OK", action: onDismiss)
                        .buttonStyle(.borderedProminent)
                }
            }
        }
        .padding(24)
        .presentationDetents([.height(220)])
        .onChange(of: state) { _, new in
            if case .running = new { return }
            UIAccessibility.post(notification: .announcement, argument: Self.spoken(new))
        }
    }

    private static func spoken(_ state: SendToKindle.UiState) -> String {
        switch state {
        case .running: "Sending"
        case .sent(let m), .failed(let m), .stillSending(let m), .noAddress(let m, _): m
        }
    }
}
