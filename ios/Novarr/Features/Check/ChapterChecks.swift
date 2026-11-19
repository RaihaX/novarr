import Foundation
import Observation

/// Runs "check for new chapters" for any number of novels, app-wide, so a
/// check started from the novel screen keeps being watched while the user
/// moves around. Polls only while the app is in the foreground and
/// looks again straight away when it comes back.
@MainActor
@Observable
final class ChapterChecks {
    private(set) var displays: [Int: CheckDisplay] = [:]
    /// Bumped per novel whenever its chapter list may have changed (new
    /// chapters listed while running, and when a check ends).
    private(set) var revisions: [Int: Int] = [:]

    /// Called with the novel id when its chapters may have changed.
    @ObservationIgnored var onChaptersChanged: (@MainActor (Int) -> Void)?
    @ObservationIgnored private var tasks: [Int: Task<Void, Never>] = [:]
    @ObservationIgnored private var isForeground = true
    @ObservationIgnored private var wakeRequested: Set<Int> = []

    /// Granularity of the wait between polls (cheap: a sleeping task).
    private static let tick: Duration = .seconds(1)

    func display(for novelId: Int) -> CheckDisplay? { displays[novelId] }
    func revision(for novelId: Int) -> Int { revisions[novelId] ?? 0 }
    func isRunning(_ novelId: Int) -> Bool { tasks[novelId] != nil }

    /// Starts (or joins) a check; does nothing if this novel is already being watched.
    func start(novelId: Int, client: APIClient) {
        guard tasks[novelId] == nil else { return }
        displays[novelId] = .checking(found: 0)
        tasks[novelId] = Task { [weak self] in
            await self?.run(novelId: novelId, client: client)
        }
    }

    func setForeground(_ foreground: Bool) {
        if foreground && !isForeground {
            // Look again straight away instead of waiting out the interval.
            wakeRequested.formUnion(tasks.keys)
        }
        isForeground = foreground
    }

    func cancelAll() {
        tasks.values.forEach { $0.cancel() }
        tasks.removeAll()
        displays.removeAll()
        wakeRequested.removeAll()
    }

    // MARK: Runner

    private func run(novelId: Int, client: APIClient) async {
        defer { tasks[novelId] = nil }
        var polling = CheckPolling(startedAt: Date())
        var step: CheckPolling.Step
        do {
            // GET first: join a check that is already running rather than ask for another.
            let current = try? await client.check(novelId: novelId)
            let check = if let current, !CheckPolling.shouldStart(after: current) {
                current
            } else {
                try await client.startCheck(novelId: novelId)
            }
            step = polling.receive(check, at: Date())
        } catch is CancellationError {
            return
        } catch {
            displays[novelId] = .failed(APIError.from(error).localizedDescription)
            return
        }
        apply(polling, novelId: novelId)

        while case .poll(let interval) = step {
            guard await wait(interval, novelId: novelId) else { return }
            do {
                let check = try await client.check(novelId: novelId)
                step = polling.receive(check, at: Date())
            } catch is CancellationError {
                return
            } catch {
                step = polling.receive(error: APIError.from(error).localizedDescription, at: Date())
            }
            apply(polling, novelId: novelId)
        }
        // Finished either way: refresh the novel and its chapter list.
        if !polling.chaptersChanged { chaptersChanged(novelId) }
    }

    private func apply(_ polling: CheckPolling, novelId: Int) {
        displays[novelId] = polling.display
        if polling.chaptersChanged { chaptersChanged(novelId) }
    }

    private func chaptersChanged(_ novelId: Int) {
        revisions[novelId, default: 0] += 1
        onChaptersChanged?(novelId)
    }

    /// Sleeps `seconds` of foreground time, or until woken on return to the
    /// foreground. False when the task was cancelled.
    private func wait(_ seconds: TimeInterval, novelId: Int) async -> Bool {
        wakeRequested.remove(novelId)
        let start = Date()
        while true {
            do { try await Task.sleep(for: Self.tick) } catch { return false }
            guard isForeground else { continue }
            if wakeRequested.remove(novelId) != nil { return true }
            if Date().timeIntervalSince(start) >= seconds { return true }
        }
    }
}
