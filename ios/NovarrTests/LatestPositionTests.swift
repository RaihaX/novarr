import Foundation
import Testing
@testable import Novarr

@Suite("Latest position prompt")
struct LatestPositionTests {
    static let me = "i_thisDevice123"
    static let at = Date(timeIntervalSince1970: 1_800_000_000)

    /// A position on another device in chapter 20 at 40%, 2 hours ago.
    static func position(chapter: Int = 20, progress: Int? = 40, device: String? = "k_kindle123",
                         at: Date? = at, age: Int? = 7200) -> ReadingPosition {
        ReadingPosition(chapterId: chapter, chapterLabel: "Chapter 21: The Gate", chapterNumber: "21",
                        progress: progress, downloaded: true, deviceId: device, deviceName: "Kindle",
                        at: at, ageSeconds: age)
    }

    private func offer(_ position: ReadingPosition?, chapter: Int = 10, local: Int? = nil, localNewer: Bool = false,
                       declinedAt: Date? = nil, canOpen: Bool = true) -> Bool {
        LatestPosition.shouldOffer(position: position, deviceID: Self.me, chapterID: chapter,
                                   localProgress: local, localNewer: localNewer,
                                   declinedAt: declinedAt, canOpen: canOpen)
    }

    // MARK: Decision truth table

    @Test func offersWhenEveryRuleHolds() {
        #expect(offer(Self.position()))
    }

    @Test func rule1NeedsAPositionFromAnotherKnownDevice() {
        #expect(!offer(nil))
        #expect(!offer(Self.position(device: nil)))
        #expect(!offer(Self.position(device: Self.me)))
    }

    @Test func rule2LocalNewerWins() {
        #expect(!offer(Self.position(), localNewer: true))
    }

    @Test(arguments: [
        // (their progress, my progress, offer?)
        (40 as Int?, 40 as Int?, false),
        (40, 44, false),
        (40, 36, false),
        (40, 45, true),
        (40, 35, true),
        (40, nil, true),   // I'm at the top
        (3, nil, false),   // both near the top
        (nil, 70, false),  // same chapter, no percentage: nothing to go to
        (100, 50, true),
    ])
    func rule3SameChapterNeedsFivePoints(theirs: Int?, mine: Int?, expected: Bool) {
        #expect(offer(Self.position(chapter: 10, progress: theirs), chapter: 10, local: mine) == expected)
    }

    @Test func rule3OtherChapterAlwaysCounts() {
        #expect(offer(Self.position(chapter: 20, progress: nil), chapter: 10, local: 40))
        #expect(offer(Self.position(chapter: 20, progress: 0), chapter: 10))
    }

    @Test func rule4DeclinedPositionIsNotOfferedAgain() {
        #expect(!offer(Self.position(), declinedAt: Self.at))
        #expect(!offer(Self.position(), declinedAt: Self.at.addingTimeInterval(60)))
        // A newer position than the one declined is offered.
        #expect(offer(Self.position(at: Self.at.addingTimeInterval(1)), declinedAt: Self.at))
        // Without a time we can't tell it apart from the declined one.
        #expect(!offer(Self.position(at: nil), declinedAt: Self.at))
        #expect(offer(Self.position(at: nil)))
    }

    @Test func rule5MustBeOpenable() {
        #expect(!offer(Self.position(), canOpen: false))
    }

    @Test func localIsNewerComparesAgainstAge() {
        let now = Date(timeIntervalSince1970: 1_000_000)
        let tenMinutesAgo = now.addingTimeInterval(-600)
        #expect(LatestPosition.localIsNewer(localWriteTimes: [tenMinutesAgo], ageSeconds: 7200, now: now))
        #expect(!LatestPosition.localIsNewer(localWriteTimes: [tenMinutesAgo], ageSeconds: 60, now: now))
        #expect(!LatestPosition.localIsNewer(localWriteTimes: [], ageSeconds: 7200, now: now))
        #expect(!LatestPosition.localIsNewer(localWriteTimes: [tenMinutesAgo], ageSeconds: nil, now: now))
    }

    @Test func onlyPositionMovingWritesCount() {
        #expect(PendingWrite.Kind.progress(chapterId: 1, progress: 30, read: false).movesPosition)
        #expect(PendingWrite.Kind.progress(chapterId: 1, progress: 100, read: true).movesPosition)
        #expect(PendingWrite.Kind.setRead(chapterId: 1, read: true).movesPosition)
        #expect(!PendingWrite.Kind.setRead(chapterId: 1, read: false).movesPosition)
        #expect(!PendingWrite.Kind.bulkRead(.upTo(9, novelId: 4, read: true)).movesPosition)
    }

    @Test func queueRemembersTheSeries() async {
        let file = temporaryFile()
        let queue = WriteQueue(fileURL: file)
        await queue.enqueue(.progress(chapterId: 1, progress: 30, read: false), novelId: 7)
        await queue.enqueue(.setRead(chapterId: 2, read: true))
        #expect(await WriteQueue(fileURL: file).items.map(\.novelId) == [7, nil])
    }

    // MARK: Text

    @Test(arguments: [
        (0, "just now"), (59, "just now"), (60, "1 minute ago"), (125, "2 minutes ago"),
        (3_600, "1 hour ago"), (7_200, "2 hours ago"), (86_399, "23 hours ago"),
        (86_400, "yesterday"), (172_800, "2 days ago"), (604_800, "1 week ago"),
        (1_814_400, "3 weeks ago"), (2_592_000, "1 month ago"), (31_536_000, "1 year ago"),
        (-5, "just now"),
    ])
    func relativeTime(age: Int, expected: String) {
        #expect(LatestPosition.relativeTime(ageSeconds: age) == expected)
    }

    @Test func messageFollowsTheContract() {
        #expect(LatestPosition.message(for: Self.position())
                == "Chapter 21: The Gate · 40% was read on Kindle 2 hours ago. Go there?")
        let sparse = ReadingPosition(chapterId: 5, chapterNumber: "124", progress: 100, ageSeconds: 30)
        #expect(LatestPosition.message(for: sparse) == "Chapter 124 was read on another device just now. Go there?")
        let zero = ReadingPosition(chapterId: 5, chapterLabel: "Prologue", progress: 0, deviceName: "iPad")
        #expect(LatestPosition.message(for: zero) == "Prologue was read on iPad recently. Go there?")
    }

    @Test func paragraphIndexForProgress() {
        #expect(LatestPosition.paragraphIndex(progress: 0, paragraphCount: 101) == nil)
        #expect(LatestPosition.paragraphIndex(progress: 40, paragraphCount: 101) == 40)
        #expect(LatestPosition.paragraphIndex(progress: 100, paragraphCount: 11) == 10)
        #expect(LatestPosition.paragraphIndex(progress: 50, paragraphCount: 1) == nil)
    }

    // MARK: Declines

    @Test func declinesArePerServerAndSeries() throws {
        let suite = "novarr-tests-\(UUID().uuidString)"
        let defaults = try #require(UserDefaults(suiteName: suite))
        defer { defaults.removePersistentDomain(forName: suite) }
        let server = URL(string: "http://nas.local:8080")
        let declines = LatestPositionDeclines(defaults: defaults, server: server)
        #expect(declines.declinedAt(novelId: 3) == nil)
        declines.decline(Self.at, novelId: 3)
        #expect(declines.declinedAt(novelId: 3) == Self.at)
        #expect(declines.declinedAt(novelId: 4) == nil)
        #expect(LatestPositionDeclines(defaults: defaults, server: URL(string: "http://other")).declinedAt(novelId: 3) == nil)
        #expect(LatestPositionDeclines(defaults: defaults, server: server).declinedAt(novelId: 3) == Self.at)
    }

    // MARK: Decoding

    @Test func decodesNullPosition() throws {
        #expect(try Fixture.decode(PositionEnvelope.self, json: #"{"position":null}"#).position == nil)
    }

    @Test func decodesFullPosition() throws {
        let json = #"""
        {"position":{"chapter_id":123,"chapter_label":"Chapter 124: The Gate","chapter_number":"124",
          "progress":40,"downloaded":true,"device_id":"k_8f3a","device_name":"Kindle",
          "at":"2026-11-18T02:10:00+00:00","age_seconds":7200}}
        """#
        let position = try #require(try Fixture.decode(PositionEnvelope.self, json: json).position)
        #expect(position.chapterId == 123)
        #expect(position.chapterLabel == "Chapter 124: The Gate")
        #expect(position.chapterNumber == "124")
        #expect(position.progress == 40)
        #expect(position.downloaded == true)
        #expect(position.deviceId == "k_8f3a")
        #expect(position.deviceName == "Kindle")
        #expect(position.at == JSONCoding.parseDate("2026-11-18T02:10:00Z"))
        #expect(position.ageSeconds == 7200)
    }

    @Test func decodesSparsePosition() throws {
        let json = #"""
        {"position":{"chapter_id":9,"chapter_label":null,"chapter_number":7,"progress":null,
          "downloaded":false,"device_id":null,"device_name":null,
          "at":"2026-11-18T02:10:00+00:00","age_seconds":5}}
        """#
        let position = try #require(try Fixture.decode(PositionEnvelope.self, json: json).position)
        #expect(position.chapterId == 9)
        #expect(position.chapterLabel == nil)
        #expect(position.chapterNumber == "7")
        #expect(position.progress == nil)
        #expect(position.deviceId == nil)
        #expect(position.deviceName == nil)
        #expect(position.downloaded == false)
    }

    // MARK: Network

    @Test func fetchesPositionWithShortTimeout() async throws {
        let client = StubURLProtocol.client { request, _ in
            #expect(request.url?.path == "/api/v1/novels/4/position")
            #expect(request.httpMethod == "GET")
            #expect(request.timeoutInterval == 3)
            return (200, Data(#"{"position":{"chapter_id":1,"device_id":"w_x","age_seconds":3}}"#.utf8))
        }
        let position = try await client.latestPosition(novelId: 4)
        #expect(position?.chapterId == 1)
        #expect(position?.deviceId == "w_x")
    }

    @Test func olderServerWithout404MeansNoPosition() async throws {
        let client = StubURLProtocol.client { _, _ in (404, Data(#"{"message":"Not Found"}"#.utf8)) }
        #expect(try await client.latestPosition(novelId: 4) == nil)
    }

    @Test func serverErrorThrowsSoThePromptIsSkipped() async {
        let client = StubURLProtocol.client { _, _ in (500, Data()) }
        await #expect(throws: APIError.self) { try await client.latestPosition(novelId: 4) }
    }
}

@Suite("Latest position hold")
struct PositionHoldTests {
    @Test func checkWithoutOfferSendsTheHeldPosition() {
        var hold = PositionHold()
        #expect(!hold.isHolding)
        let round = hold.startCheck(chapterId: 10)
        #expect(hold.isHolding)
        #expect(hold.check?.chapterId == 10)
        #expect(hold.leaving() == .keep) // a waiting check decides when it ends
        #expect(hold.resolveCheck(round, offered: false) == .send)
        #expect(!hold.isHolding)
    }

    @Test func offerKeepsHoldingUntilAnswered() {
        var stay = PositionHold()
        _ = stay.resolveCheck(stay.startCheck(chapterId: 10), offered: true)
        #expect(stay.isHolding)
        #expect(stay.offerShown)
        #expect(stay.stay() == .send)
        #expect(!stay.isHolding)

        var go = PositionHold()
        _ = go.resolveCheck(go.startCheck(chapterId: 10), offered: true)
        #expect(go.goToLatest() == .discard)
        #expect(!go.isHolding)
    }

    @Test func leavingWithAnUnansweredOfferDiscards() {
        var hold = PositionHold()
        _ = hold.resolveCheck(hold.startCheck(chapterId: 10), offered: true)
        #expect(hold.leaving() == .discard)
        #expect(hold.isHolding) // the prompt is still up when the reader comes back
        #expect(PositionHold().leaving() == .send)
    }

    @Test func movingOnIsAnImplicitStayAndEndsTheCheck() {
        var offered = PositionHold()
        _ = offered.resolveCheck(offered.startCheck(chapterId: 10), offered: true)
        #expect(offered.moveOn() == .send)
        #expect(!offered.isHolding)

        var waiting = PositionHold()
        let round = waiting.startCheck(chapterId: 10)
        #expect(waiting.moveOn() == .send)
        #expect(!waiting.isCurrent(round))
        // The late answer for the chapter left behind changes nothing.
        #expect(waiting.resolveCheck(round, offered: true) == .keep)
        #expect(!waiting.offerShown)
    }

    @Test func reachingTheEndDismissesTheOffer() {
        var hold = PositionHold()
        _ = hold.resolveCheck(hold.startCheck(chapterId: 10), offered: true)
        #expect(hold.dismissOffer() == .send)
        #expect(!hold.isHolding)
    }

    @Test func newerCheckSupersedesAnOlderOne() {
        var hold = PositionHold()
        let first = hold.startCheck(chapterId: 10)
        let second = hold.startCheck(chapterId: 11)
        #expect(hold.resolveCheck(first, offered: true) == .keep) // stale: ignored
        #expect(!hold.offerShown)
        #expect(hold.isHolding)
        #expect(hold.resolveCheck(second, offered: false) == .send)
        #expect(hold.resolveCheck(second, offered: false) == .keep) // already ended
    }

    @Test func answeringWhileANewCheckWaitsKeepsHolding() {
        var hold = PositionHold()
        _ = hold.resolveCheck(hold.startCheck(chapterId: 10), offered: true)
        let round = hold.startCheck(chapterId: 10)
        #expect(hold.stay() == .keep)
        #expect(hold.isHolding)
        #expect(hold.resolveCheck(round, offered: false) == .send)

        var discard = PositionHold()
        _ = discard.resolveCheck(discard.startCheck(chapterId: 10), offered: true)
        _ = discard.startCheck(chapterId: 10)
        #expect(discard.goToLatest() == .discard)
        #expect(discard.isHolding)
    }

    @Test func checkFailingAfterAnOfferStillShownKeepsHolding() {
        var hold = PositionHold()
        _ = hold.resolveCheck(hold.startCheck(chapterId: 10), offered: true)
        #expect(hold.resolveCheck(hold.startCheck(chapterId: 10), offered: false) == .keep)
        #expect(hold.offerShown)
    }

    @Test func recheckAfterAMinuteInTheBackground() {
        let left = Date(timeIntervalSince1970: 1_800_000_000)
        #expect(!LatestPosition.shouldRecheck(backgroundedAt: nil, now: left))
        #expect(!LatestPosition.shouldRecheck(backgroundedAt: left, now: left.addingTimeInterval(59.9)))
        #expect(LatestPosition.shouldRecheck(backgroundedAt: left, now: left.addingTimeInterval(60)))
        #expect(LatestPosition.shouldRecheck(backgroundedAt: left, now: left.addingTimeInterval(3_600)))
    }

    @Test func unsentWriteCountsOnlyForItsOwnChapter() {
        func write(_ kind: PendingWrite.Kind) -> PendingWrite {
            PendingWrite(id: UUID(), kind: kind, recordedAt: Date(), novelId: 4)
        }
        let writes = [
            write(.progress(chapterId: 1, progress: 30, read: false)),
            write(.setRead(chapterId: 2, read: false)),
            write(.bulkRead(.upTo(3, novelId: 4, read: true))),
        ]
        #expect(PendingWrite.containsChapterWrite(writes, chapterId: 1))
        #expect(PendingWrite.containsChapterWrite(writes, chapterId: 2))
        #expect(!PendingWrite.containsChapterWrite(writes, chapterId: 3)) // bulk marking doesn't count
        #expect(!PendingWrite.containsChapterWrite(writes, chapterId: 9))
        #expect(!PendingWrite.containsChapterWrite([], chapterId: 1))
    }
}

@Suite("Device identity")
struct DeviceIdentityTests {
    private func freshDefaults() throws -> (UserDefaults, String) {
        let suite = "novarr-tests-\(UUID().uuidString)"
        return (try #require(UserDefaults(suiteName: suite)), suite)
    }

    @Test func idIsStableAcrossLaunches() throws {
        let (defaults, suite) = try freshDefaults()
        defer { defaults.removePersistentDomain(forName: suite) }
        let first = DeviceIdentity.loadOrCreate(defaults: defaults, name: "iPhone")
        // A relaunch reads the same defaults domain from a new instance.
        let relaunched = try #require(UserDefaults(suiteName: suite))
        let second = DeviceIdentity.loadOrCreate(defaults: relaunched, name: "iPhone")
        #expect(first.id == second.id)
        #expect(first.id.hasPrefix("i_"))
        #expect(DeviceIdentity.isValidID(first.id))
    }

    @Test func invalidStoredIdIsReplaced() throws {
        let (defaults, suite) = try freshDefaults()
        defer { defaults.removePersistentDomain(forName: suite) }
        defaults.set("bad id!", forKey: DeviceIdentity.idDefaultsKey)
        let identity = DeviceIdentity.loadOrCreate(defaults: defaults, name: "iPad")
        #expect(identity.id != "bad id!")
        #expect(DeviceIdentity.isValidID(identity.id))
        #expect(defaults.string(forKey: DeviceIdentity.idDefaultsKey) == identity.id)
    }

    @Test func generatedIdsAreUniqueAndValid() {
        let ids = (0..<50).map { _ in DeviceIdentity.makeID() }
        #expect(Set(ids).count == 50)
        #expect(ids.allSatisfy { DeviceIdentity.isValidID($0) && $0.count <= 64 && $0.count >= 8 })
        #expect(!DeviceIdentity.isValidID("short"))
        #expect(!DeviceIdentity.isValidID(String(repeating: "a", count: 65)))
        #expect(DeviceIdentity.isValidID("k_8f3a-B_9"))
    }

    @Test func nameFromIdiom() {
        #expect(DeviceIdentity.name(isPad: false, onMac: false) == "iPhone")
        #expect(DeviceIdentity.name(isPad: true, onMac: false) == "iPad")
        #expect(DeviceIdentity.name(isPad: true, onMac: true) == "Mac")
        #expect(DeviceIdentity.name(isPad: false, onMac: true) == "Mac")
    }

    @Test func everyWriteCarriesDeviceHeadersIncludingReplays() async {
        let seen = HeaderLog()
        let device = DeviceIdentity(id: "i_testDevice01", name: "iPad")
        let client = StubURLProtocol.client(device: device) { request, _ in
            seen.append(path: request.url?.path ?? "",
                        id: request.value(forHTTPHeaderField: "X-Novarr-Device"),
                        name: request.value(forHTTPHeaderField: "X-Novarr-Device-Name"))
            return (200, Data(#"{"success":true}"#.utf8))
        }
        let file = temporaryFile()
        let offline = WriteQueue(fileURL: file)
        await offline.enqueue(.progress(chapterId: 7, progress: 42, read: false), novelId: 1)
        await offline.enqueue(.setRead(chapterId: 8, read: true), novelId: 1)
        await offline.enqueue(.bulkRead(.upTo(9, novelId: 1, read: true)), novelId: 1)

        // Replayed after a relaunch, from the persisted queue.
        let outcome = await WriteQueue(fileURL: file).flush(using: client)
        #expect(outcome == .drained(sent: 3, dropped: 0))
        let entries = seen.entries
        #expect(entries.count == 3)
        #expect(entries.allSatisfy { $0.id == "i_testDevice01" && $0.name == "iPad" })
    }

    @Test func clientWithoutDeviceSendsNoHeaders() async throws {
        let seen = HeaderLog()
        let client = StubURLProtocol.client { request, _ in
            seen.append(path: "", id: request.value(forHTTPHeaderField: "X-Novarr-Device"), name: nil)
            return (200, Data("{}".utf8))
        }
        try await client.send(.setRead(chapterId: 1, read: true), age: 0)
        #expect(seen.entries.first?.id == nil)
    }
}

private final class HeaderLog: @unchecked Sendable {
    struct Entry { var path: String; var id: String?; var name: String? }
    private let lock = NSLock()
    private var _entries: [Entry] = []
    var entries: [Entry] { lock.withLock { _entries } }
    func append(path: String, id: String?, name: String?) {
        lock.withLock { _entries.append(Entry(path: path, id: id, name: name)) }
    }
}
