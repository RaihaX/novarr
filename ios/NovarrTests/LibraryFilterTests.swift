import Foundation
import Testing
@testable import Novarr

@Suite("Library filter, sort and search")
struct LibraryFilterTests {
    let day: TimeInterval = 86_400
    var base: Date { Date(timeIntervalSince1970: 1_800_000_000) }

    var library: [NovelSummary] {
        [
            makeNovel(id: 1, name: "Reverend Insanity", author: "Gu Zhen Ren", read: 40, total: 100,
                      lastReadAt: base.addingTimeInterval(-day), updatedAt: base.addingTimeInterval(-5 * day)),
            makeNovel(id: 2, name: "Lord of the Mysteries", author: "Cuttlefish", read: 100, total: 100, finished: true,
                      lastReadAt: base.addingTimeInterval(-3 * day), updatedAt: base.addingTimeInterval(-30 * day)),
            makeNovel(id: 3, name: "Shadow Slave", author: "Guiltythree", read: 10, total: 120, new: 12,
                      lastReadAt: base, updatedAt: base),
            makeNovel(id: 4, name: "a Will Eternal", author: "Er Gen", read: 0, total: 50,
                      updatedAt: base.addingTimeInterval(-2 * day)),
            makeNovel(id: 5, name: "Outside of Time", author: "Ěr Gēn", read: 0, total: 0),
        ]
    }

    private func ids(_ filter: LibraryFilter.Filter, _ sort: LibraryFilter.Sort = .name, _ search: String = "") -> [Int] {
        LibraryFilter.apply(library, filter: filter, sort: sort, search: search).map(\.id)
    }

    @Test func filters() {
        #expect(ids(.all) == [4, 2, 5, 1, 3])
        #expect(ids(.reading) == [1, 3])
        #expect(ids(.newChapters) == [3])
        #expect(ids(.notStarted) == [4, 5])
        #expect(ids(.finished) == [2])
    }

    @Test func sortByName_isCaseInsensitiveAndNumericAware() {
        let names = LibraryFilter.sorted(
            [makeNovel(id: 1, name: "Volume 10"), makeNovel(id: 2, name: "volume 2"), makeNovel(id: 3, name: "Apple")],
            by: .name).map(\.name)
        #expect(names == ["Apple", "volume 2", "Volume 10"])
    }

    @Test func sortByLastRead_putsNeverReadLastByName() {
        #expect(ids(.all, .lastRead) == [3, 1, 2, 4, 5])
    }

    @Test func sortByRecentlyUpdated() {
        #expect(ids(.all, .recentlyUpdated) == [3, 4, 1, 2, 5])
    }

    @Test func searchMatchesNameAndAuthorIgnoringCaseAndAccents() {
        #expect(ids(.all, .name, "shadow") == [3])
        #expect(ids(.all, .name, "er gen") == [4, 5])
        #expect(ids(.all, .name, "  MYSTERIES ") == [2])
        #expect(ids(.all, .name, "zzz").isEmpty)
    }

    @Test func searchCombinesWithFilter() {
        #expect(ids(.notStarted, .name, "gen") == [4, 5])
        #expect(ids(.finished, .name, "gen").isEmpty)
    }

    @Test func captions() {
        #expect(makeNovel(id: 1, name: "a", read: 3, total: 10, new: 12).caption == "12 NEW")
        #expect(makeNovel(id: 1, name: "a", read: 10, total: 10, finished: true).caption == "FINISHED")
        #expect(makeNovel(id: 1, name: "a", read: 0, total: 10).caption == "NOT STARTED")
        #expect(makeNovel(id: 1, name: "a", read: 3, total: 10).caption == "3 / 10")
    }
}
