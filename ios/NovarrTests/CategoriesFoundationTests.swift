import Foundation
import Testing
@testable import Novarr

@Suite("Categories and reading lists: the shared logic")
struct CategoriesFoundationTests {
    private let action = ReadingList(id: 3, name: "Action", count: 2, novelIds: [1, 2], position: 2)
    private let want = ReadingList(id: 1, name: "Want to read", count: 1, novelIds: [9], kind: ReadingList.kindWantToRead)
    private let fav = ReadingList(id: 2, name: "Favourites", kind: ReadingList.kindFavourites, position: 1)

    @Test func optimisticToggleAndRollback() throws {
        let state = ReadingLists.State(lists: [want, action])
        let started = try #require(ReadingLists.begin(state, listId: 3, novelId: 5, add: true))
        #expect(started.lists[1].novelIds == [1, 2, 5])
        #expect(started.lists[1].count == 3)
        // One change at a time per list and series; and nothing to do when already so.
        #expect(ReadingLists.begin(started, listId: 3, novelId: 5, add: false) == nil)
        #expect(ReadingLists.begin(state, listId: 3, novelId: 1, add: true) == nil)
        let toggle = ReadingLists.Toggle(listId: 3, novelId: 5, add: true)
        let failed = ReadingLists.fail(started, toggle: toggle)
        #expect(failed.lists[1].novelIds == [1, 2])
        #expect(failed.inFlight.isEmpty)
        // The built-ins are newest first.
        let wanted = try #require(ReadingLists.begin(state, listId: 1, novelId: 4, add: true))
        #expect(wanted.lists[0].novelIds == [4, 9])
    }

    @Test func namesMirrorTheServer() {
        let lists = [want, fav, action]
        #expect(ReadingLists.nameProblem("  ", lists: lists) == "Enter a name.")
        #expect(ReadingLists.nameProblem("want  TO read", lists: lists) != nil)
        #expect(ReadingLists.nameProblem("action", lists: lists) == "You already have a category called “action”.")
        #expect(ReadingLists.nameProblem("Action", lists: lists, renaming: 3) == nil)
        #expect(ReadingLists.nameProblem(String(repeating: "a", count: 41), lists: lists) != nil)
        #expect(ReadingLists.nameProblem(String(repeating: "😀", count: 40), lists: lists) == nil)
    }

    @Test func orderFallsBackWithoutPositions() {
        let a = ReadingList(id: 7, name: "zeta")
        let b = ReadingList(id: 8, name: "Alpha")
        let f = ReadingList(id: 2, name: "Favourites", kind: ReadingList.kindFavourites)
        let w = ReadingList(id: 1, name: "Want to read", kind: ReadingList.kindWantToRead)
        #expect(ReadingLists.sorted([a, f, b, w]).map(\.id) == [1, 2, 8, 7])
        #expect(ReadingLists.sorted([action, fav, want]).map(\.id) == [1, 2, 3])
    }

    @Test func triStateSheet() {
        let boxes = Categories.initial([action, want], novelIds: [1, 5])
        #expect(boxes[3] == .mixed)
        #expect(boxes[1] == .off)
        #expect(Categories.next(.mixed, started: .mixed) == .on)
        #expect(Categories.next(.on, started: .mixed) == .off)
        #expect(Categories.next(.off, started: .mixed) == .mixed)
        #expect(Categories.next(.off, started: .off) == .on)
        let diff = Categories.diff(initial: boxes, current: [3: .mixed, 1: .on, 42: .on])
        #expect(diff == Categories.Diff(add: [1, 42], remove: []))
        let applied = Categories.applyMemberships([action, want], novelIds: [1, 5], add: [3, 1], remove: [])
        #expect(applied[0].novelIds == [1, 2, 5])
        #expect(applied[1].novelIds == [1, 5, 9])
    }

    @Test func libraryTabs() {
        let novels = [1, 2, 3, 9].map { makeNovel(id: $0, name: "N\($0)") }
        let tabs = Categories.tabs([action, want])
        #expect(tabs.map(\.key) == ["all", "1", "3", "none"])
        let none = Categories.asList(.uncategorised, lists: [action, want], novels: novels)
        #expect(none?.novelIds == [3])
        #expect(Categories.Tab.from("3", lists: [action]) == .category(action))
        #expect(Categories.Tab.from("99", lists: [action]) == nil)
        #expect(Categories.Tab.from("", lists: []) == .all)
    }

    @Test func selection() {
        var selection = Categories.Selection<Int>()
        #expect(selection.tap(1) == nil)
        selection = selection.longPress(1)
        #expect(selection.active)
        selection = selection.tap(2) ?? selection
        #expect(selection.count == 2)
        selection = selection.retain([2, 3])
        #expect(selection.keys == [2])
        selection = selection.toggle(2)
        #expect(!selection.active)
    }

    @Test func browseMarksAndActions() {
        var result = SearchResult(key: "k", title: "X", inCategories: [3])
        #expect(Categories.mark(result, lists: [action]) == Categories.Mark(badge: "In Action"))
        result.inCategories = []
        result.requestedCategories = [3, 1]
        #expect(Categories.mark(result, lists: [action, want]) == Categories.Mark(badge: "Requested · Want to read +1"))
        let fresh = SearchResult(key: "n", title: "New")
        #expect(Categories.mark(fresh, lists: []) == nil)
        #expect(Categories.browseAction(fresh, chosen: []) == .nothing)
        #expect(Categories.browseAction(fresh, chosen: [3]) == .request(categories: [3]))
        let owned = SearchResult(key: "o", inLibrary: true, novelId: 5, inCategories: [1])
        #expect(Categories.browseAction(owned, chosen: [3]) == .memberships(novelId: 5, add: [3], remove: [1]))
        let after = Categories.afterBrowse(fresh, action: .request(categories: [3]))
        #expect(after.requested?.byMe == true)
        #expect(after.requestedCategories == [3])
    }
}
