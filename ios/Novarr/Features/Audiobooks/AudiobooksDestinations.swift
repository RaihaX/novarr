import SwiftUI

// OWNER: audiobooks area. Replace the placeholder views.

extension View {
    /// Every audiobook, the player and the upload screen.
    func audiobooksDestinations() -> some View {
        navigationDestination(for: AudiobooksRoute.self) { _ in
            AudiobooksView()
        }
        .navigationDestination(for: PlayerRoute.self) { route in
            Text("Player \(route.id)")
        }
        .navigationDestination(for: AudiobookUploadRoute.self) { route in
            AudiobookUploadView(route: route)
        }
    }
}

/// The Listen tab's root.
struct AudiobooksView: View {
    var body: some View { Text("Listen") }
}

/// "Upload an audiobook" (pushed, or in a sheet for audio files opened from
/// Files or the Share sheet).
struct AudiobookUploadView: View {
    let route: AudiobookUploadRoute

    var body: some View { Text("Upload an audiobook") }
}

/// The series screen's audiobook controls: Listen (when the book has one)
/// and Upload an audiobook. Shown under the primary button.
struct NovelAudiobookActions: View {
    let summary: NovelSummary

    var body: some View { EmptyView() }
}

/// Settings rows for this area (playback options, downloaded audiobooks).
struct SettingsListenSection: View {
    var body: some View { EmptyView() }
}

/// The mini-player shown at the bottom of every tab while an audiobook is
/// loaded (tapping it opens the full player). EmptyView when nothing is loaded.
struct MiniPlayerBar: View {
    var body: some View { EmptyView() }
}
