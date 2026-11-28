import SwiftUI
import UniformTypeIdentifiers

/// One file to send, with what stops it from being sent (re-checked against the latest limits).
struct PickedBook: Identifiable, Equatable {
    var id: URL { url }
    var url: URL
    var picked: BookUploads.Picked
    var uploadName: String
}

/// "Send ePubs to Novarr" (android ui/share/ShareEpubViewModel.kt): the
/// picked or opened ePubs, an optional note, one upload, then what the
/// server did with each one.
@MainActor
@Observable
final class BookUploadModel {
    enum Phase: Equatable {
        case ready, sending
        case answered(BookUploadResponse)
        case failed(message: String, signedOut: Bool)
    }

    private(set) var files: [PickedBook] = []
    var note = "" { didSet { if note.count > BookUploads.noteMax { note = BookUploads.clampNote(note) } } }
    private(set) var phase: Phase = .ready
    /// 0…1 of the bytes written.
    private(set) var progress: Double = 0
    var pickError: String?

    /// Copies made into the app (files picked here, or opened from Files), removed when done.
    @ObservationIgnored private var ownedCopies: [URL] = []

    init(files: [URL]) {
        ownedCopies = files
        add(files)
    }

    func limits(_ app: AppModel) -> BookUploads.Limits { .from(app.serverMeta?.bookUploads) }

    func problem(_ file: PickedBook, _ app: AppModel) -> BookUploads.Problem? {
        BookUploads.problem(file.picked, limits: limits(app))
    }

    /// Files already inside the app (copies): read their names and sizes.
    func add(_ urls: [URL]) {
        for url in urls where !files.contains(where: { $0.url == url }) {
            let values = try? url.resourceValues(forKeys: [.fileSizeKey, .isReadableKey, .contentTypeKey])
            let readable = values?.isReadable ?? FileManager.default.isReadableFile(atPath: url.path)
            let name = url.lastPathComponent
            let picked = BookUploads.Picked(name: name, mime: values?.contentType?.preferredMIMEType,
                                            size: values?.fileSize.map(Int64.init), readable: readable)
            files.append(PickedBook(url: url, picked: picked, uploadName: BookUploads.uploadName(name, index: files.count)))
        }
        if case .answered = phase { phase = .ready }
    }

    /// From the file picker: copies each (security-scoped) file into the app first.
    func importPicked(_ urls: [URL]) {
        var copies: [URL] = []
        for url in urls {
            if let incoming = IncomingFiles.receive(url) {
                copies += incoming.files
            } else {
                // Not an ePub (or unreadable): listed with its problem, never sent.
                let name = url.lastPathComponent
                files.append(PickedBook(url: url, picked: BookUploads.Picked(name: name, mime: nil, size: nil,
                                                                              readable: url.pathExtension.lowercased() != "epub"),
                                        uploadName: BookUploads.uploadName(name, index: files.count)))
            }
        }
        ownedCopies += copies
        add(copies)
    }

    func remove(_ file: PickedBook) {
        files.removeAll { $0.url == file.url }
    }

    func send(_ app: AppModel) {
        if phase == .sending { return }
        guard let client = app.client else {
            phase = .failed(message: "Sign in to Novarr first.", signedOut: true)
            return
        }
        let limits = limits(app)
        let chosen = files.filter { BookUploads.problem($0.picked, limits: limits) == nil }
        guard !chosen.isEmpty, BookUploads.tooMany(chosen.count, limits: limits) == nil else { return }
        phase = .sending
        progress = 0
        let parts = chosen.map { APIClient.UploadFile(name: $0.uploadName, fileURL: $0.url) }
        let trimmed = note.trimmingCharacters(in: .whitespacesAndNewlines)
        let delegate = UploadProgressDelegate { [weak self] fraction in
            Task { @MainActor in self?.progress = fraction }
        }
        Task {
            do {
                let answer = try await client.uploadBooks(parts, note: trimmed.isEmpty ? nil : trimmed, delegate: delegate)
                app.noteSuccess()
                phase = .answered(answer)
                if !answer.sent.isEmpty { app.libraryChanged() }
            } catch {
                let api = APIError.from(error)
                app.noteFailure(api)
                phase = .failed(message: BookUploads.failureMessage(api), signedOut: api.isUnauthenticated)
            }
        }
    }

    func retry() { phase = .ready }

    /// The copies made for this upload go (Caches/Incoming); picked originals are never touched.
    func cleanUp() {
        let fm = FileManager.default
        for url in ownedCopies where url.path.contains("/Incoming/") {
            try? fm.removeItem(at: url.deletingLastPathComponent())
        }
        ownedCopies = []
    }
}

/// Reports the bytes sent, in whole percents.
final class UploadProgressDelegate: NSObject, URLSessionTaskDelegate, @unchecked Sendable {
    private let report: @Sendable (Double) -> Void
    private let lock = NSLock()
    private var lastPercent = -1

    init(report: @escaping @Sendable (Double) -> Void) { self.report = report }

    func urlSession(_ session: URLSession, task: URLSessionTask, didSendBodyData bytesSent: Int64,
                    totalBytesSent: Int64, totalBytesExpectedToSend: Int64) {
        guard totalBytesExpectedToSend > 0 else { return }
        let percent = Int(min(max(totalBytesSent * 100 / totalBytesExpectedToSend, 0), 100))
        let changed = lock.withLock {
            guard percent != lastPercent else { return false }
            lastPercent = percent
            return true
        }
        if changed { report(Double(percent) / 100) }
    }
}

/// "Send ePubs to Novarr": pushed from Settings, or in a sheet for ePubs
/// opened into the app from Files or the Share sheet ("Open in Novarr").
struct BookUploadView: View {
    let route: BookUploadRoute
    @State private var model: BookUploadModel

    init(route: BookUploadRoute) {
        self.route = route
        _model = State(initialValue: BookUploadModel(files: route.files))
    }

    var body: some View {
        // Files handed in come from a sheet (Open in Novarr): it gets a Close button.
        BookUploadContent(model: model, showsClose: !route.files.isEmpty)
            .navigationTitle("Send ePubs")
            .navigationBarTitleDisplayMode(.inline)
            .onDisappear { model.cleanUp() }
    }
}

private struct BookUploadContent: View {
    @Environment(AppModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @Bindable var model: BookUploadModel
    let showsClose: Bool
    @State private var picking = false

    private static let epubType = UTType(filenameExtension: "epub") ?? UTType(importedAs: "org.idpf.epub-container")

    var body: some View {
        let limits = model.limits(app)
        List {
            switch model.phase {
            case .answered(let response):
                answered(response, limits: limits)
            default:
                picking(limits: limits)
            }
        }
        .brandBackground()
        .toolbar {
            if showsClose {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }.disabled(model.phase == .sending)
                }
            }
            if model.phase != .sending, !isAnswered {
                ToolbarItem(placement: .primaryAction) {
                    Button { picking = true } label: { Label("Add ePubs", systemImage: "plus") }
                }
            }
        }
        .fileImporter(isPresented: $picking, allowedContentTypes: [Self.epubType], allowsMultipleSelection: true) { result in
            switch result {
            case .success(let urls): model.importPicked(urls)
            case .failure(let error): model.pickError = error.localizedDescription
            }
        }
        .interactiveDismissDisabled(model.phase == .sending)
    }

    private var isAnswered: Bool { if case .answered = model.phase { true } else { false } }

    @ViewBuilder
    private func picking(limits: BookUploads.Limits) -> some View {
        let files = model.files
        let sendable = files.filter { model.problem($0, app) == nil }
        let tooMany = BookUploads.tooMany(sendable.count, limits: limits)
        Section {
            if files.isEmpty {
                VStack(alignment: .leading, spacing: 10) {
                    Text("Pick ePub files (.epub) to send to the owner, who adds them to the library.")
                        .foregroundStyle(.secondary)
                    Button { picking = true } label: { Label("Choose ePubs", systemImage: "doc.badge.plus") }
                        .buttonStyle(.borderedProminent)
                }
                .padding(.vertical, 6)
            }
            ForEach(files) { file in
                let problem = model.problem(file, app)
                let detail = [file.picked.size.map { $0.byteString }, problem.map { BookUploads.message($0, limits: limits) }]
                    .compactMap { $0 }.joined(separator: " · ")
                ResultRow(icon: problem == nil ? "book.closed" : "exclamationmark.circle",
                          tint: problem == nil ? .secondary : .red,
                          title: file.picked.name ?? file.uploadName, detail: detail.isEmpty ? nil : detail)
                    .swipeActions {
                        if model.phase != .sending {
                            Button("Remove", role: .destructive) { model.remove(file) }
                        }
                    }
            }
            if !files.isEmpty, sendable.isEmpty { Text("None of these can be sent.").foregroundStyle(.secondary) }
            if let tooMany { Text(tooMany).foregroundStyle(.red) }
            if let pickError = model.pickError { Text(pickError).foregroundStyle(.red) }
        }
        Section {
            RequestNoteField(text: $model.note, label: "Note for the owner (optional)", limit: BookUploads.noteMax)
                .disabled(model.phase == .sending)
        } footer: {
            if let email = app.serverMeta?.bookUploads?.email, !email.isEmpty {
                let from = app.account?.user.email.flatMap { $0.isEmpty ? nil : " from \($0)" } ?? ""
                Text("You can also email ePubs to \(email)\(from).")
            }
        }
        Section {
            switch model.phase {
            case .sending:
                VStack(alignment: .leading, spacing: 8) {
                    Text(model.progress >= 1 ? "Waiting for the server…"
                         : "Sending \(sendable.count) ePub\(sendable.count == 1 ? "" : "s")…")
                    if model.progress > 0, model.progress < 1 {
                        ProgressView(value: model.progress)
                    } else {
                        ProgressView().progressViewStyle(.linear)
                    }
                }
                .accessibilityElement(children: .combine)
            case .failed(let message, let signedOut):
                Text(message).foregroundStyle(.red)
                if signedOut {
                    Button("Close") { dismiss() }
                } else {
                    Button("Try again") { model.retry(); model.send(app) }
                }
            default:
                Button {
                    model.send(app)
                } label: {
                    Text("Send to Novarr").frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .disabled(sendable.isEmpty || tooMany != nil || !app.isReachable)
                .listRowBackground(Color.clear)
            }
        }
    }

    @ViewBuilder
    private func answered(_ response: BookUploadResponse, limits: BookUploads.Limits) -> some View {
        Section {
            Text(response.message ?? (response.sent.isEmpty ? "No books were sent." : "Sent to the owner."))
                .font(.ui(.headline, .semibold))
            ForEach(Array(response.sent.enumerated()), id: \.offset) { _, sent in
                ResultRow(icon: "checkmark.circle", tint: .green, title: sent.title.isEmpty ? "Book" : sent.title,
                          detail: "Waiting for the owner's approval")
            }
            ForEach(Array(response.refused.enumerated()), id: \.offset) { _, refused in
                ResultRow(icon: "exclamationmark.circle", tint: .red, title: refused.file,
                          detail: refused.message.isEmpty ? "Not sent." : refused.message)
            }
            // Never uploaded: refused here before sending.
            ForEach(model.files.filter { model.problem($0, app) != nil }) { file in
                ResultRow(icon: "exclamationmark.circle", tint: .red, title: file.picked.name ?? file.uploadName,
                          detail: model.problem(file, app).map { BookUploads.message($0, limits: limits) })
            }
        }
        Section {
            Button("Done") { dismiss() }
            if !response.sent.isEmpty {
                NavigationLink("My requests", value: RequestsRoute(section: .mine))
            }
        }
    }
}

private struct ResultRow: View {
    let icon: String
    let tint: Color
    let title: String
    let detail: String?

    var body: some View {
        HStack(spacing: 14) {
            Image(systemName: icon).foregroundStyle(tint).font(.system(size: 20)).frame(width: 24)
            VStack(alignment: .leading, spacing: 2) {
                Text(title).lineLimit(2)
                if let detail { Text(detail).font(.ui(.footnote)).foregroundStyle(.secondary) }
            }
        }
        .accessibilityElement(children: .combine)
    }
}
