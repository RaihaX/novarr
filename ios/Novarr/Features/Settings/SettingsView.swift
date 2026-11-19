import SwiftUI

struct SettingsView: View {
    @Environment(AppModel.self) private var app
    @State private var showConnect = false
    @State private var offlineBytes: Int64?
    @State private var imageBytes: Int64?
    @State private var totalBytes: Int64?
    @State private var confirmClear = false
    @State private var isSyncing = false
    @State private var confirmSignOut = false
    @State private var isSigningOut = false
    @AppStorage(AppAppearance.defaultsKey) private var appearance = AppAppearance.dark

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

            // Only on servers with sign-in on; with auth off there is no account.
            if let user = app.account?.user {
                Section("Account") {
                    LabeledContent("Signed in as") {
                        VStack(alignment: .trailing, spacing: 2) {
                            Text(user.name)
                            Text("@\(user.username)").font(.ui(.caption)).foregroundStyle(.secondary)
                        }
                    }
                    LabeledContent("Role", value: user.role.label)
                    if isSigningOut {
                        HStack {
                            ProgressView()
                            Text("Signing out…").padding(.leading, 6)
                        }
                    } else {
                        Button("Sign Out", role: .destructive) { confirmSignOut = true }
                    }
                }
            }

            Section {
                Picker("Appearance", selection: $appearance) {
                    ForEach(AppAppearance.allCases) { Text($0.title).tag($0) }
                }
            } header: {
                Text("App")
            } footer: {
                Text("Dark is Novarr's own look. The reader keeps its own theme.")
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
                            .font(.ui(.callout))
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
                NavigationLink {
                    FontLicencesView()
                } label: {
                    Text("Font licences")
                }
            }
        }
        .brandBackground()
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
        .confirmationDialog("Sign out of this server?", isPresented: $confirmSignOut, titleVisibility: .visible) {
            Button("Sign Out", role: .destructive) {
                Task {
                    isSigningOut = true
                    await app.signOut()
                    isSigningOut = false
                }
            }
        } message: {
            Text(app.pendingWrites > 0
                 ? "\(app.pendingWrites) reading updates haven't synced yet. They stay on this device and are sent the next time you sign in to this account."
                 : "Your offline chapters for this account stay on this device.")
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

/// The bundled fonts' licences (SIL Open Font License 1.1), as Android's
/// font-licence screen.
struct FontLicencesView: View {
    var body: some View {
        List {
            Section {
                Text("Geist and Geist Mono (Vercel) and Literata (TypeTogether for Google) are used under the SIL Open Font License 1.1.")
            }
            ForEach(BrandFont.licenceFiles, id: \.self) { name in
                Section(name.replacingOccurrences(of: "OFL-", with: "")) {
                    Text(Self.text(of: name))
                        .font(.uiMono(.caption))
                        .textSelection(.enabled)
                }
            }
        }
        .navigationTitle("Font licences")
        .navigationBarTitleDisplayMode(.inline)
    }

    private static func text(of name: String) -> String {
        guard let url = Bundle.main.url(forResource: name, withExtension: "txt"),
              let text = try? String(contentsOf: url, encoding: .utf8) else { return "Licence text missing." }
        return text
    }
}
