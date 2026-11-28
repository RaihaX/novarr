import SwiftUI
import UniformTypeIdentifiers

/// "Upload an audiobook": the files (picked in Files, or handed in from
/// another app), an optional book (from its page), a note, then the upload
/// (`AudiobookUploadManager`); below it, this account's uploads. Pushed, or in
/// a sheet for audio files opened in Novarr. Mirrors android AudiobookUploadScreen.
struct AudiobookUploadView: View {
    @Environment(AppModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    let route: AudiobookUploadRoute

    @State private var files: [PickedUpload] = []
    @State private var book: (id: Int, title: String)?
    @State private var note = ""
    @State private var starting = false
    @State private var started = false
    @State private var failure: String?
    @State private var picking = false
    @State private var prepared = false

    /// Every audio type the server takes (the extension decides; these are what Files offers).
    static let types: [UTType] = {
        var types: [UTType] = [.audio, .mp3, .mpeg4Audio]
        for ext in AudiobookUploads.extensions {
            if let type = UTType(filenameExtension: ext) { types.append(type) }
        }
        return types
    }()

    var body: some View {
        let listening = app.listening
        Group {
            if app.needsSignIn || app.client == nil {
                ContentUnavailableView("Sign in to Novarr first", systemImage: "person.crop.circle.badge.exclamationmark",
                                       description: Text("Sign in, then share the audiobook files again."))
            } else if app.serverMeta?.audiobooks == false {
                ContentUnavailableView("No audiobooks on this server", systemImage: "headphones",
                                       description: Text("This Novarr server doesn't have audiobooks switched on."))
            } else {
                form(listening)
            }
        }
        .navigationTitle("Upload an audiobook")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            if !route.files.isEmpty {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Close") { dismiss() }
                }
            }
        }
        .fileImporter(isPresented: $picking, allowedContentTypes: Self.types, allowsMultipleSelection: true) { result in
            if case .success(let urls) = result { add(urls) }
        }
        .task {
            guard !prepared else { return }
            prepared = true
            if let id = route.novelId, id > 0 { book = (id, route.title) }
            add(route.files)
        }
    }

    private var sendable: [PickedUpload] { files.filter { $0.problem == nil } }

    private var tooMuch: String? {
        AudiobookUploads.tooMuch(count: sendable.count, totalBytes: sendable.reduce(0) { $0 + ($1.picked.size ?? 0) })
    }

    private func form(_ listening: ListeningModel) -> some View {
        @Bindable var listening = listening
        return Form {
            Section {
                Text("One .m4b, or every part of one book (.mp3, .m4a, .aac, .ogg, .opus, .flac). It uploads while Novarr is open (and carries on where it stopped next time); the owner approves it before it joins the library.")
                    .font(.ui(.subheadline))
                    .foregroundStyle(.secondary)
                if let book {
                    HStack {
                        Text("For: \(book.title.isEmpty ? "this book" : book.title)").font(.ui(.subheadline, .semibold))
                        Spacer()
                        Button("Not this book") { self.book = nil }.font(.ui(.subheadline))
                    }
                }
                if started {
                    Text("Uploading. You'll see its progress below.")
                        .font(.ui(.subheadline))
                        .foregroundStyle(.green)
                }
            }

            if !files.isEmpty {
                Section {
                    ForEach(files) { file in fileRow(file) }
                        .onDelete { files.remove(atOffsets: $0) }
                } footer: {
                    if !sendable.isEmpty {
                        let bytes = sendable.reduce(Int64(0)) { $0 + ($1.picked.size ?? 0) }
                        Text("\(sendable.count) file\(sendable.count == 1 ? "" : "s") · \(bytes.byteString)")
                    }
                }
            }

            Section {
                Button(files.isEmpty ? "Choose files" : "Add more files") { picking = true }
                if let tooMuch {
                    Text(tooMuch).foregroundStyle(.red).font(.ui(.footnote))
                }
                if !files.isEmpty {
                    VStack(alignment: .trailing, spacing: 4) {
                        TextField("Note for the owner (optional)", text: $note, axis: .vertical)
                            .lineLimit(1...4)
                            .onChange(of: note) { _, value in
                                if value.count > AudiobookUploads.noteMax { note = AudiobookUploads.clampNote(value) }
                            }
                        Text("\(note.count) / \(AudiobookUploads.noteMax)").font(.ui(.caption2)).foregroundStyle(.secondary)
                    }
                }
                Toggle("Upload on Wi-Fi only", isOn: $listening.prefs.wifiOnlyUploads)
                if let failure {
                    Text(failure).foregroundStyle(.red).font(.ui(.footnote))
                }
                Button {
                    Task { await upload(listening) }
                } label: {
                    Text(starting ? "Preparing…" : "Upload").frame(maxWidth: .infinity)
                }
                .buttonStyle(.borderedProminent)
                .disabled(sendable.isEmpty || tooMuch != nil || starting)
            }

            Section {
                AudiobookUploadsList(inset: 0, title: nil)
            } header: {
                if !listening.uploads.uploads.isEmpty { Text("Your audiobook uploads") }
            }
        }
        .scrollContentBackground(.hidden)
        .brandBackground()
    }

    private func fileRow(_ file: PickedUpload) -> some View {
        let problem = file.problem
        let detail = [file.picked.size.map(\.byteString), problem.map(AudiobookUploads.message)].compactMap { $0 }
            .joined(separator: " · ")
        return HStack(spacing: 12) {
            Image(systemName: problem == nil ? "waveform" : "exclamationmark.circle")
                .foregroundStyle(problem == nil ? Color.secondary : Color.red)
            VStack(alignment: .leading, spacing: 2) {
                Text(file.picked.name ?? file.uploadName).lineLimit(2)
                if !detail.isEmpty {
                    Text(detail).font(.ui(.caption)).foregroundStyle(problem == nil ? Color.secondary : Color.red)
                }
            }
            Spacer(minLength: 0)
            Button {
                files.removeAll { $0.url == file.url }
            } label: {
                Image(systemName: "xmark").foregroundStyle(.secondary)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("Remove \(file.picked.name ?? "file")")
        }
    }

    private func add(_ urls: [URL]) {
        guard !urls.isEmpty else { return }
        let known = Set(files.map(\.url))
        let fresh = urls.filter { !known.contains($0) }.enumerated().map { i, url in
            PickedUpload.describe(url, index: files.count + i)
        }
        files = (files + fresh).sorted { AudiobookUploads.naturalOrder($0.uploadName, $1.uploadName) }
        started = false
    }

    private func upload(_ listening: ListeningModel) async {
        guard !starting, !sendable.isEmpty, tooMuch == nil else { return }
        starting = true
        failure = nil
        defer { starting = false }
        do {
            let id = try await listening.uploads.start(files: sendable, novelId: book?.id, bookTitle: book?.title, note: note)
            if id == nil {
                failure = "Sign in to Novarr first."
            } else {
                started = true
                files = []
                note = ""
            }
        } catch {
            failure = error.localizedDescription
        }
    }
}

/// "Your audiobook uploads": the ones started on this device for this
/// account, with progress, status and Cancel / Try again / Remove. Nothing
/// when there are none. Also used by Listen and (optionally) My requests.
struct AudiobookUploadsList: View {
    @Environment(AppModel.self) private var app
    var inset: CGFloat = 16
    /// The heading; nil leaves it to the container (a Form section header).
    var title: String? = "Your audiobook uploads"

    var body: some View {
        let manager = app.listening.uploads
        let uploads = manager.uploads
        VStack(alignment: .leading, spacing: 8) {
            if !uploads.isEmpty, let title {
                Text(title)
                    .font(.ui(.headline))
                    .accessibilityAddTraits(.isHeader)
            }
            ForEach(uploads) { upload in
                UploadRow(upload: upload, manager: manager)
                if upload.id != uploads.last?.id { Divider() }
            }
        }
        .padding(.horizontal, inset)
        .task(id: app.sessionID) { await manager.refresh() }
    }
}

private struct UploadRow: View {
    let upload: AudiobookUploadItem
    let manager: AudiobookUploadManager

    var body: some View {
        let entry = upload.entry
        let state = entry.state
        VStack(alignment: .leading, spacing: 4) {
            HStack(alignment: .firstTextBaseline) {
                Text(entry.title).font(.ui(.body)).lineLimit(2)
                Spacer()
                Text(state.label).font(.ui(.caption, .semibold)).foregroundStyle(color(state))
            }
            Text(line).font(.ui(.caption)).foregroundStyle(.secondary)
            if state == .uploading || state == .queued {
                ProgressView(value: Double(upload.percent), total: 100)
            } else if state == .completing {
                ProgressView().progressViewStyle(.linear)
            }
            if let message = entry.message, state == .failed || state == .declined {
                Text(message).font(.ui(.caption)).foregroundStyle(.red)
            }
            HStack(spacing: 16) {
                if !state.finished {
                    Button("Cancel upload") { Task { await manager.cancel(entry.localId) } }
                }
                if state == .failed {
                    Button("Try again") { Task { await manager.retry(entry.localId) } }
                }
                if state.finished {
                    Button("Remove from list") { Task { await manager.remove(entry.localId) } }
                }
            }
            .font(.ui(.footnote, .medium))
            .buttonStyle(.borderless)
        }
        .padding(.vertical, 6)
    }

    private var line: String {
        let entry = upload.entry
        switch entry.state {
        case .uploading, .queued:
            var text = "\(upload.doneBytes.byteString) of \(entry.totalBytes.byteString) · \(upload.percent)%"
            if upload.waitingForNetwork { text += entry.wifiOnly ? " · waiting for Wi-Fi" : " · waiting for a connection" }
            return text
        default:
            return "\(entry.files.count) file\(entry.files.count == 1 ? "" : "s") · \(entry.totalBytes.byteString)"
        }
    }

    private func color(_ state: AudiobookUploads.Status) -> Color {
        switch state {
        case .approved: .green
        case .declined, .failed: .red
        case .pending: .brandAmber
        default: .brandAccent
        }
    }
}
