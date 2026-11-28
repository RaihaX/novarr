import SwiftUI
import UIKit

// OWNER: requests / books area.

extension View {
    /// Requests and the ePub upload.
    func requestsDestinations() -> some View {
        navigationDestination(for: RequestsRoute.self) { route in
            RequestsView(section: route.section)
        }
        .navigationDestination(for: BookUploadRoute.self) { route in
            BookUploadView(route: route)
        }
    }

    /// Calls `perform` once for each notification the reader taps (request
    /// news, new chapters), then forgets it. For the root view, which owns
    /// the tabs and their navigation paths.
    func onNotificationRoute(_ perform: @escaping (NotificationRoute) -> Void) -> some View {
        modifier(NotificationRouteModifier(perform: perform))
    }
}

private struct NotificationRouteModifier: ViewModifier {
    let perform: (NotificationRoute) -> Void
    private var hub: NotificationHub { .shared }

    func body(content: Content) -> some View {
        content
            .onAppear { deliver() }
            .onChange(of: hub.route) { _, _ in deliver() }
    }

    private func deliver() {
        guard let route = hub.route else { return }
        hub.consumeRoute()
        perform(route)
    }
}

/// The Requests screen as the iPad sidebar's own section (the same screen `RequestsRoute` pushes).
struct RequestsRootView: View {
    var section: RequestsRoute.Section = .search

    var body: some View { RequestsView(section: section) }
}

/// Settings rows for this area: the Notifications section (request news,
/// new chapters) and Requests / "Send ePubs to Novarr".
struct SettingsRequestsSection: View {
    @Environment(AppModel.self) private var app
    @Environment(\.openURL) private var openURL
    @Environment(\.scenePhase) private var scenePhase
    private var hub: NotificationHub { .shared }
    @State private var notifyRequests = NotificationHub.shared.prefs.requestNotifications
    @State private var notifyChapters = NotificationHub.shared.prefs.newChapterNotifications

    private var requestsOffered: Bool { app.serverMeta?.requests == true }
    private var updatesOffered: Bool { app.serverMeta?.updates == true }

    var body: some View {
        if requestsOffered {
            Section {
                NavigationLink(value: RequestsRoute()) {
                    Label("Requests", systemImage: "text.badge.plus")
                }
                NavigationLink(value: BookUploadRoute()) {
                    Label("Send ePubs to Novarr", systemImage: "square.and.arrow.up")
                }
            } header: {
                Text("Requests")
            } footer: {
                Text("Ask for a book, web novel or manga, or send ePubs you have; the owner adds them to the library. You can also share an ePub from Files or Books to Novarr.")
            }
        }
        if requestsOffered || updatesOffered {
            Section {
                // Shows off while iOS blocks them, whatever the saved choice.
                if requestsOffered {
                    Toggle("Notify me about requests", isOn: Binding(
                        get: { notifyRequests && hub.authorized },
                        set: { on in
                            notifyRequests = on
                            Task { await hub.setRequestNotifications(on) }
                        }))
                    if notifyRequests, !hub.authorized, hub.authorizationDetermined {
                        note("Notifications from Novarr are off in iOS Settings, so news about requests only shows as a dot on Requests.")
                        settingsLink
                    } else {
                        note("New requests to review, and the owner's decisions on yours. Checked from time to time while the app is closed (iOS decides when, about once an hour at most).")
                    }
                }
                if updatesOffered {
                    Toggle("New chapter notifications", isOn: Binding(
                        get: { notifyChapters && hub.authorized },
                        set: { on in
                            notifyChapters = on
                            Task { await hub.setNewChapterNotifications(on) }
                        }))
                    if notifyChapters, !hub.authorized, hub.authorizationDetermined {
                        note("Notifications from Novarr are off in iOS Settings, so new chapters only show on Home.")
                        if !(requestsOffered && notifyRequests) { settingsLink }
                    } else {
                        note("One notification when series you'd caught up on get new chapters. Checked about every 2 hours while the app is closed; never makes a sound more than once in 6 hours, or between 10 pm and 7 am.")
                    }
                }
            } header: {
                Text("Notifications")
            }
            .task(id: scenePhase) { await hub.refreshAuthorization() }
        }
    }

    private func note(_ text: String) -> some View {
        Text(text).font(.ui(.footnote)).foregroundStyle(.secondary)
    }

    private var settingsLink: some View {
        Button("Open notification settings") {
            if let url = URL(string: UIApplication.openNotificationSettingsURLString) { openURL(url) }
        }
    }
}

/// Home's top-bar Requests button, with a dot while the request feed has
/// news not seen in the app yet. Nothing on a server without requests.
struct RequestsToolbarButton: View {
    @Environment(AppModel.self) private var app
    private var hub: NotificationHub { .shared }

    var body: some View {
        if app.serverMeta?.requests == true {
            let unseen = hub.unseen(app)
            NavigationLink(value: RequestsRoute()) {
                Image(systemName: "text.badge.plus")
                    .overlay(alignment: .topTrailing) {
                        if unseen > 0 {
                            Circle().fill(Color.brandAmber).frame(width: 9, height: 9).offset(x: 4, y: -3)
                        }
                    }
            }
            .accessibilityLabel(unseen > 0 ? "Requests, new activity" : "Requests")
        }
    }
}
