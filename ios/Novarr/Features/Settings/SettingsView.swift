import SwiftUI

struct SettingsView: View {
    @Environment(AppModel.self) private var app
    @State private var showConnect = false
    @State private var offlineBytes: Int64?
    @State private var imageBytes: Int64?
    @State private var totalBytes: Int64?
    @State private var confirmClear = false
    @State private var isSyncing = false

    var body: some View {
        Form {
            Section("Server") {
                LabeledContent("Address", value: app.serverURL?.absoluteString ?? "Not set")
                    .textSelection(.enabled)
                LabeledContent("Status") {
                    Label(app.isReachable ? "Connected" : "Unreachable",
                          systemImage: app.isReachable ? "checkmark.circle.fill" : "icloud.slash")
                        .foregroundStyle(app.isReachable ? Color.green : Color.orange)
                }
                Button("Change Server…") { showConnect = true }
            }

            Section {
                NavigationLink {
                    Form { ReaderSettingsForm() }
                        .navigationTitle("Reader")
                        .navigationBarTitleDisplayMode(.inline)
                } label: {
                    Label("Reader appearance", systemImage: "textformat.size")
                }
            } header: {
                Text("Reading")
            }

            Section {
                NavigationLink {
                    DownloadsView()
                } label: {
                    LabeledContent {
                        Text(offlineBytes.map(\.byteString) ?? "…")
                    } label: {
                        Label("Downloads", systemImage: "arrow.down.circle")
                    }
                }
                LabeledContent("Cover images", value: imageBytes.map(\.byteString) ?? "…")
                LabeledContent("All saved data", value: totalBytes.map(\.byteString) ?? "…")
                Button("Clear Cache…", role: .destructive) { confirmClear = true }
                if app.pendingWrites > 0 {
                    HStack {
                        Text("\(app.pendingWrites) reading updates waiting to sync")
                            .font(.callout)
                        Spacer()
                        if isSyncing {
                            ProgressView()
                        } else {
                            Button("Sync Now") {
                                Task {
                                    isSyncing = true
                                    await app.flushWrites()
                                    isSyncing = false
                                }
                            }
                        }
                    }
                }
            } header: {
                Text("Offline")
            } footer: {
                Text("Clearing removes saved lists, offline chapters and cover images from this device. Nothing on your server changes, and unsynced reading progress is kept.")
            }

            Section("About") {
                LabeledContent("App version", value: Self.appVersion)
                LabeledContent("Server version", value: serverVersion)
                if let meta = app.serverMeta {
                    LabeledContent("API version", value: "v\(meta.apiVersion)")
                }
            }
        }
        .navigationTitle("Settings")
        .sheet(isPresented: $showConnect) { ConnectView(isInitialSetup: false) }
        .confirmationDialog("Clear all cached data?", isPresented: $confirmClear, titleVisibility: .visible) {
            Button("Clear Cache", role: .destructive) {
                Task {
                    await app.clearCaches()
                    await measure()
                }
            }
        } message: {
            Text("Offline chapters will need to be downloaded again.")
        }
        .task {
            if app.serverMeta == nil { await app.refreshMeta() }
        }
        .task(id: app.downloads.revision) { await measure() }
        .refreshable {
            await app.refreshMeta()
            await measure()
        }
    }

    private var serverVersion: String {
        guard let meta = app.serverMeta else { return app.isReachable ? "…" : "Unavailable offline" }
        return meta.serverVersion ?? "Unknown"
    }

    private func measure() async {
        offlineBytes = await app.store?.offlineChaptersSize() ?? 0
        totalBytes = await app.store?.totalSize() ?? 0
        imageBytes = await CoverCache.shared.diskSize()
    }

    static var appVersion: String {
        let info = Bundle.main.infoDictionary
        let version = info?["CFBundleShortVersionString"] as? String ?? "?"
        let build = info?["CFBundleVersion"] as? String ?? "?"
        return "\(version) (\(build))"
    }
}
