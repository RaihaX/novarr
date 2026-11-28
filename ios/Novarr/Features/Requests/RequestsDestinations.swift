import SwiftUI

// OWNER: requests / books area. Replace the placeholder views.

extension View {
    /// Requests and the ePub upload.
    func requestsDestinations() -> some View {
        navigationDestination(for: RequestsRoute.self) { route in
            Text("Requests: \(route.section.rawValue)")
        }
        .navigationDestination(for: BookUploadRoute.self) { route in
            BookUploadView(route: route)
        }
    }
}

/// The Requests screen as the iPad sidebar's own section (the same screen `RequestsRoute` pushes).
struct RequestsRootView: View {
    var body: some View { Text("Requests") }
}

/// "Send ePubs to Novarr" (pushed from Settings / Requests, or in a sheet for
/// files opened from Files or the Share sheet).
struct BookUploadView: View {
    let route: BookUploadRoute

    var body: some View { Text("Send ePubs") }
}

/// The series screen's book controls: Send to my Kindle / email, Ask for the
/// audiobook. Shown under the primary button.
struct NovelBookActions: View {
    let summary: NovelSummary
    let detail: NovelDetail?

    var body: some View { EmptyView() }
}

/// Settings rows for this area (Requests, Send ePubs).
struct SettingsRequestsSection: View {
    var body: some View { EmptyView() }
}

/// Home's top-bar Requests button (with the unseen count).
struct RequestsToolbarButton: View {
    var body: some View { EmptyView() }
}
