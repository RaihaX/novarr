import SwiftUI

/// Settings › Listening (android SettingsScreen's "Listening"), only on
/// servers with audiobooks: skip intervals, Wi-Fi only transfers and the
/// downloaded audiobooks.
struct SettingsListenSection: View {
    @Environment(AppModel.self) private var app

    var body: some View {
        if app.serverMeta?.audiobooks == true {
            @Bindable var listening = app.listening
            Section {
                Picker("Skip back", selection: $listening.prefs.skipBackSeconds) {
                    ForEach(ListeningPrefs.skipChoices, id: \.self) { Text("\($0) seconds").tag($0) }
                }
                Picker("Skip forward", selection: $listening.prefs.skipForwardSeconds) {
                    ForEach(ListeningPrefs.skipChoices, id: \.self) { Text("\($0) seconds").tag($0) }
                }
                Toggle("Download audiobooks on Wi-Fi only", isOn: $listening.prefs.wifiOnlyDownloads)
                Toggle("Upload audiobooks on Wi-Fi only", isOn: $listening.prefs.wifiOnlyUploads)
                NavigationLink {
                    DownloadedAudiobooksView()
                } label: {
                    LabeledContent("Downloaded audiobooks") {
                        let bytes = listening.downloads.totalBytes()
                        Text(bytes > 0 ? bytes.byteString : "None")
                    }
                }
            } header: {
                Text("Listening")
            } footer: {
                Text("Audiobooks are large: on Wi-Fi only, a download or an upload waits for Wi-Fi.")
            }
        }
    }
}

/// The audiobooks on this device, with their size; swipe to remove one.
struct DownloadedAudiobooksView: View {
    @Environment(AppModel.self) private var app
    @State private var confirm: AudiobookDownloadManifest?

    var body: some View {
        let downloads = app.listening.downloads
        // Re-read whenever a download finishes or is removed.
        let all = withRevision(downloads.revision) { downloads.all() }
        List {
            if all.isEmpty {
                ContentUnavailableView("No downloaded audiobooks", systemImage: "arrow.down.circle",
                                       description: Text("Download one from the player to listen without a connection."))
            } else {
                Section {
                    ForEach(all) { item in
                        NavigationLink(value: PlayerRoute(id: item.id)) {
                            LabeledContent(item.title, value: item.bytes.byteString)
                        }
                        .swipeActions {
                            Button("Remove", role: .destructive) { confirm = item }
                        }
                    }
                } footer: {
                    Text("Total \(all.reduce(Int64(0)) { $0 + $1.bytes }.byteString). They stay on your server; removing one only frees this device.")
                }
            }
        }
        .navigationTitle("Downloaded audiobooks")
        .confirmationDialog("Remove the download?", isPresented: Binding(get: { confirm != nil }, set: { if !$0 { confirm = nil } }),
                            titleVisibility: .visible, presenting: confirm) { item in
            Button("Remove \(item.title)", role: .destructive) { downloads.remove(item.id) }
        } message: { _ in
            Text("The audiobook stays on your server; only this device's copy is removed.")
        }
    }

    private func withRevision<T>(_ revision: Int, _ read: () -> T) -> T { read() }
}
