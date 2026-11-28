import Foundation
import Testing
@testable import Novarr

/// Listening progress: last write wins by recorded time, the offline queue and
/// where playback resumes (android ListeningSyncTest).
@Suite("Listening sync")
struct ListeningSyncTests {
    func rec(_ id: Int, _ position: Int64, at: Int64, finished: Bool = false) -> ListeningRecord {
        ListeningRecord(audiobookId: id, positionMs: position, speed: 1.25, finished: finished, recordedAt: at)
    }

    func store(_ file: URL = temporaryFile(ListeningStore.fileName)) -> ListeningStore { ListeningStore(fileURL: file) }

    let t0 = ListeningSync.parseInstant("2026-12-10T10:00:00Z")!

    // The resume rule

    @Test func nothingAnywhereStartsAtTheBeginning() {
        #expect(ListeningSync.resolve(nil, nil) == .init(positionMs: 0, finished: false, fromLocal: false))
    }

    @Test func theNewerPositionWinsWhicheverSideItIsOn() {
        let server = AudiobookProgress(positionMs: 500_000, updatedAt: "2026-12-10T10:00:00Z")
        // This device listened later (offline, say): its position.
        #expect(ListeningSync.resolve(rec(1, 700_000, at: t0 + 60_000), server) == .init(positionMs: 700_000, finished: false, fromLocal: true))
        // Another device listened later: the server's, even though it is EARLIER in the book.
        #expect(ListeningSync.resolve(rec(1, 900_000, at: t0 - 60_000), server) == .init(positionMs: 500_000, finished: false, fromLocal: false))
        // A tie goes to this device.
        #expect(ListeningSync.resolve(rec(1, 1, at: t0), server).fromLocal)
    }

    @Test func anUnreadableServerTimeLosesToThisDevice() {
        let server = AudiobookProgress(positionMs: 500_000, updatedAt: "yesterday")
        #expect(ListeningSync.resolve(rec(1, 10, at: 0), server).fromLocal)
        // ...but is used when it is all there is.
        #expect(ListeningSync.resolve(nil, server).positionMs == 500_000)
    }

    @Test func serverTimesWithOffsetsParse() {
        #expect(ListeningSync.parseInstant("2026-12-10T11:00:00+01:00") == t0)
        #expect(ListeningSync.parseInstant("2026-12-10T10:00:00.123Z") == t0 + 123)
        #expect(ListeningSync.parseInstant("not a date") == nil)
    }

    @Test func aFinishedBookStartsOver() {
        let total: Int64 = 1_000_000
        #expect(ListeningSync.startPosition(.init(positionMs: total, finished: true, fromLocal: false), totalMs: total) == 0)
        // Within the last seconds counts as finished too.
        #expect(ListeningSync.startPosition(.init(positionMs: total - 2_000, finished: false, fromLocal: false), totalMs: total) == 0)
        #expect(ListeningSync.startPosition(.init(positionMs: 400_000, finished: false, fromLocal: false), totalMs: total) == 400_000)
        // Never past the end, never negative.
        #expect(ListeningSync.startPosition(.init(positionMs: -5, finished: false, fromLocal: false), totalMs: total) == 0)
    }

    // The store

    @Test func anOlderPositionNeverReplacesANewerOne() async {
        let store = store()
        #expect(await store.record(rec(1, 100, at: 2_000)))
        #expect(await !store.record(rec(1, 999, at: 1_000)))
        #expect(await store.latest(1)?.positionMs == 100)
        #expect(await store.record(rec(1, 200, at: 3_000)))
        #expect(await store.latest(1)?.positionMs == 200)
        // Coalesced: one pending position per audiobook.
        #expect(await store.pendingCount() == 1)
    }

    @Test func audiobooksAreKeptApartAndSentOldestFirst() async {
        let store = store()
        await store.record(rec(2, 50, at: 5_000))
        await store.record(rec(1, 10, at: 1_000))
        let sent = ListeningRecorder()
        let outcome = await store.flush { await sent.add($0.audiobookId) }
        #expect(outcome == .drained(sent: 2, dropped: 0))
        #expect(await sent.values == [1, 2])
        #expect(await store.pendingCount() == 0)
        // Sent positions are still known (resume).
        #expect(await store.latest(2)?.positionMs == 50)
    }

    @Test func offlineKeepsEverythingAndResumesLater() async {
        let store = store()
        await store.record(rec(1, 10, at: 1_000))
        await store.record(rec(2, 20, at: 2_000))
        #expect(await store.flush { _ in throw APIError.unreachable("offline") } == .stalled(sent: 0, remaining: 2))
        #expect(await store.pendingCount() == 2)
        #expect(await store.flush { _ in } == .drained(sent: 2, dropped: 0))
    }

    @Test func aSignOutKeepsThePositionsForTheNextSignIn() async {
        let store = store()
        await store.record(rec(1, 10, at: 1_000))
        #expect(await store.flush { _ in throw APIError.http(status: 401, message: nil) } == .unauthenticated(sent: 0, remaining: 1))
        #expect(await store.pendingCount() == 1)
    }

    @Test func aPositionTheServerRefusesIsDropped() async {
        let store = store()
        await store.record(rec(1, 10, at: 1_000))
        await store.record(rec(2, 10, at: 2_000))
        let outcome = await store.flush { if $0.audiobookId == 1 { throw APIError.rejected(HTTPFailure(status: 404, message: "gone")) } }
        #expect(outcome == .drained(sent: 1, dropped: 1))
        #expect(await store.pendingCount() == 0)
    }

    @Test func aPositionRecordedWhileSendingStaysPending() async {
        let store = store()
        await store.record(rec(1, 10, at: 1_000))
        let gate = Gate()
        let flushing = Task { await store.flush { _ in await gate.wait() } }
        await gate.entered()
        // Newer than the one on the wire.
        await store.record(rec(1, 20, at: 2_000))
        // A second flush while one runs does nothing.
        #expect(await store.flush { _ in } == .busy)
        await gate.open()
        _ = await flushing.value
        #expect(await store.pending().map(\.positionMs) == [20])
        #expect(await store.flush { _ in } == .drained(sent: 1, dropped: 0))
        #expect(await store.pendingCount() == 0)
    }

    @Test func everythingSurvivesARestart() async {
        let file = temporaryFile(ListeningStore.fileName)
        let first = store(file)
        await first.record(rec(1, 10, at: 1_000))
        await first.record(rec(2, 20, at: 2_000, finished: true))
        _ = await first.flush { if $0.audiobookId == 2 { throw APIError.timedOut } }
        await first.setLastAudiobookId(2)
        let reopened = store(file)
        #expect(await reopened.lastAudiobookId() == 2)
        #expect(await reopened.pending().map(\.audiobookId) == [2])
        #expect(await reopened.latest(2)?.finished == true)
        #expect(await reopened.latest(1)?.positionMs == 10)
        await reopened.setLastAudiobookId(nil)
        #expect(await store(file).lastAudiobookId() == nil)
    }

    @Test func aDamagedFileStartsEmpty() async throws {
        let file = temporaryFile(ListeningStore.fileName)
        try FileManager.default.createDirectory(at: file.deletingLastPathComponent(), withIntermediateDirectories: true)
        try Data("{not json".utf8).write(to: file)
        let store = store(file)
        #expect(await store.pendingCount() == 0)
        #expect(await store.record(rec(1, 1, at: 1)))
    }

    // Formatting and preferences

    @Test func clocksAndLengths() {
        #expect(ListeningSync.clock(0) == "0:00")
        #expect(ListeningSync.clock(123_400) == "2:03")
        #expect(ListeningSync.clock(3_723_000) == "1:02:03")
        #expect(ListeningSync.length(45 * 60_000) == "45 min")
        #expect(ListeningSync.length((11 * 60 + 32) * 60_000) == "11 h 32 min")
        #expect(ListeningSync.length(2 * 3_600_000) == "2 h")
        #expect(ListeningSync.isoOf(t0) == "2026-12-10T10:00:00Z")
        #expect(ListeningSync.speedLabel(1) == "1×")
        #expect(ListeningSync.speedLabel(1.25) == "1.25×")
        #expect(ListeningSync.speedLabel(1.5) == "1.5×")
    }

    @Test func speedsSnapToTwentiethsWithinTheRange() {
        #expect(ListeningPrefs.validSpeed(1.26) == 1.25)
        #expect(ListeningPrefs.validSpeed(1.28) == 1.3)
        #expect(ListeningPrefs.validSpeed(0.1) == 0.5)
        #expect(ListeningPrefs.validSpeed(7) == 3.0)
        #expect(ListeningPrefs.validSpeed(.nan) == 1)
        #expect(ListeningPrefs.validSkip(17, default: 15) == 15)
        #expect(ListeningPrefs.validSkip(45, default: 15) == 45)
    }

    @Test func prefsAreSavedAndReadBack() throws {
        let defaults = try #require(UserDefaults(suiteName: "listening-\(UUID().uuidString)"))
        #expect(ListeningPrefs.load(defaults) == ListeningPrefs())
        var prefs = ListeningPrefs()
        prefs.skipBackSeconds = 30
        prefs.speed = 1.75
        prefs.wifiOnlyUploads = false
        prefs.save(defaults)
        #expect(ListeningPrefs.load(defaults) == prefs)
    }
}

actor ListeningRecorder {
    private(set) var values: [Int] = []
    func add(_ value: Int) { values.append(value) }
}

/// Holds a send until opened; `entered` returns once a send is waiting.
actor Gate {
    private var waiting: CheckedContinuation<Void, Never>?
    private var enteredWaiters: [CheckedContinuation<Void, Never>] = []
    private var isOpen = false
    private var hasEntered = false

    func wait() async {
        hasEntered = true
        enteredWaiters.forEach { $0.resume() }
        enteredWaiters = []
        if isOpen { return }
        await withCheckedContinuation { waiting = $0 }
    }

    func entered() async {
        if hasEntered { return }
        await withCheckedContinuation { enteredWaiters.append($0) }
    }

    func open() {
        isOpen = true
        waiting?.resume()
        waiting = nil
    }
}
