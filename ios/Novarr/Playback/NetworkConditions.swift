import Foundation
import Network
import Observation

/// The network as audiobook transfers see it: connected at all, and whether
/// the path is expensive (cellular, a personal hotspot). "On Wi-Fi only"
/// downloads and uploads wait while it is.
@MainActor
@Observable
final class NetworkConditions {
    private(set) var connected = true
    private(set) var expensive = false

    @ObservationIgnored private let monitor = NWPathMonitor()
    @ObservationIgnored private var waiters: [CheckedContinuation<Void, Never>] = []

    init() {
        monitor.pathUpdateHandler = { [weak self] path in
            let connected = path.status == .satisfied
            let expensive = path.isExpensive
            Task { @MainActor in self?.update(connected: connected, expensive: expensive) }
        }
        monitor.start(queue: DispatchQueue(label: "studio.visns.novarr.audio-path"))
    }

    /// Whether a transfer may run now.
    func allows(wifiOnly: Bool) -> Bool { connected && !(wifiOnly && expensive) }

    /// Returns once a transfer may run (at once when it already may). Cancellation ends the wait.
    func waitUntilAllowed(wifiOnly: @MainActor () -> Bool) async {
        while !allows(wifiOnly: wifiOnly()), !Task.isCancelled {
            await withTaskCancellationHandler {
                await withCheckedContinuation { waiters.append($0) }
            } onCancel: {
                Task { @MainActor in self.wakeAll() }
            }
        }
    }

    /// Wakes the waiters to look again (a setting changed).
    func wakeAll() {
        let all = waiters
        waiters.removeAll()
        all.forEach { $0.resume() }
    }

    private func update(connected: Bool, expensive: Bool) {
        self.connected = connected
        self.expensive = expensive
        wakeAll()
    }
}
