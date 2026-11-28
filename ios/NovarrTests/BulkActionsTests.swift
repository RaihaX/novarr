import Foundation
import Testing
@testable import Novarr

/// Records what was sent; answers with `answer`.
@MainActor
private final class Sent {
    var calls: [([Int], Bool)] = []
    var answer: String?
    var refuse = false
}

/// The multi-select's Mark read / unread with Undo (android app/BulkActions.kt
/// and ui/lists/Selection.kt), with a short wait in place of the 4.5 s.
@MainActor
@Suite("Bulk mark read / unread: Undo, flush and failures")
struct BulkActionsTests {
    private static let wait: Duration = .milliseconds(60)

    private func bulk(_ sent: Sent) -> BulkActions {
        BulkActions(wait: { try await Task.sleep(for: Self.wait) }) { ids, read, revert in
            sent.calls.append((ids, read))
            if sent.refuse { revert() }
            return sent.answer
        }
    }

    private func settle() async throws { try await Task.sleep(for: .milliseconds(250)) }

    @Test func sendsAfterTheUndoTime() async throws {
        let sent = Sent()
        let actions = bulk(sent)
        var done: [String?] = []
        let mark = actions.markRead(ids: [3, 1, 3], read: true, revert: {}) { done.append($0) }
        #expect(mark.ids == [3, 1])
        #expect(sent.calls.isEmpty)
        try await settle()
        #expect(sent.calls.map(\.0) == [[3, 1]])
        #expect(sent.calls.first?.1 == true)
        #expect(done.count == 1)
        // Too late now.
        #expect(!actions.undo(mark))
    }

    @Test func undoInTimeSendsNothing() async throws {
        let sent = Sent()
        let actions = bulk(sent)
        let mark = actions.markRead(ids: [1], read: false, revert: {}) { _ in }
        #expect(actions.undo(mark))
        try await settle()
        #expect(sent.calls.isEmpty)
        // A flush after Undo doesn't send it either.
        actions.flush(mark, revert: {}) { _ in }
        try await settle()
        #expect(sent.calls.isEmpty)
    }

    @Test func flushSendsAtOnceAndOnlyOnce() async throws {
        let sent = Sent()
        let actions = bulk(sent)
        let mark = actions.markRead(ids: [5], read: true, revert: {}) { _ in }
        actions.flush(mark, revert: {}) { _ in }
        try await Task.sleep(for: .milliseconds(20))
        #expect(sent.calls.count == 1)
        try await settle()
        #expect(sent.calls.count == 1)
        #expect(!actions.undo(mark))
    }

    @Test func failuresQueueOfflineAndRevertWhenRefused() {
        let offline = BulkActions.failure(.unreachable("offline"), read: true)
        #expect(offline == .queued("You're offline: saved on this device, sent once the server is reachable."))
        // A server without the bulk call: queued per series, silently.
        let older = BulkActions.failure(.rejected(HTTPFailure(status: 404)), read: true)
        #expect(older == .queued(nil))
        let refused = BulkActions.failure(.rejected(HTTPFailure(status: 422, message: "No")), read: false)
        if case .reverted(let message) = refused {
            #expect(message.hasPrefix("Couldn't mark them as unread."))
        } else {
            Issue.record("A refusal must revert")
        }
    }

    @Test func downloadMessages() {
        #expect(BulkActions.downloadMessage(started: 0, failed: 2) == "Couldn't fetch the chapter lists. Try again when the server is reachable.")
        #expect(BulkActions.downloadMessage(started: 0, failed: 0) == "Their unread chapters are already on this device.")
        #expect(BulkActions.downloadMessage(started: 2, failed: 1) == "Downloading 2 series; 1 series couldn't be checked.")
        #expect(BulkActions.downloadMessage(started: 1, failed: 0) == "Downloading the unread chapters of 1 series.")
    }

    // The selection's side (ui/lists/Selection.kt).

    @Test func markingEndsSelectingAndUndoPutsItBack() async throws {
        let sent = Sent()
        let selection = SeriesSelection(bulk: bulk(sent))
        selection.longPress(1)
        selection.tap(2)
        var shown: Set<Int> = []
        selection.markRead(true) { ids, _ in
            shown = ids
            return { shown = [] }
        }
        #expect(shown == [1, 2])
        #expect(!selection.active)
        let undoable = try #require(selection.undoable)
        #expect(undoable.message == "Marked 2 series as read")
        selection.undo(undoable)
        #expect(shown.isEmpty)
        #expect(selection.undoable == nil)
        try await settle()
        #expect(sent.calls.isEmpty)
    }

    @Test func aSecondMarkSendsTheFirstAtOnce() async throws {
        let sent = Sent()
        let selection = SeriesSelection(bulk: bulk(sent))
        selection.markRead(true, series: [1])
        selection.markRead(false, series: [2])
        try await Task.sleep(for: .milliseconds(20))
        #expect(sent.calls.map(\.0) == [[1]])
        #expect(selection.undoable?.message == "Marked 1 series as unread")
        try await settle()
        #expect(sent.calls.map(\.0) == [[1], [2]])
        // Undo after it was sent: too late, and said so.
        if let late = selection.undoable {
            selection.undo(late)
            #expect(selection.notice == "Too late to undo: it was already saved.")
        }
    }

    @Test func aRefusedMarkRevertsAndSaysWhy() async throws {
        let sent = Sent()
        sent.refuse = true
        sent.answer = "Couldn't mark them as read."
        let selection = SeriesSelection(bulk: bulk(sent))
        var marked = false
        selection.markRead(true, series: [4]) { _, _ in
            marked = true
            return { marked = false }
        }
        #expect(marked)
        try await settle()
        #expect(!marked)
        #expect(selection.notice == "Couldn't mark them as read.")
    }

    @Test func nothingChosenMarksNothing() {
        let sent = Sent()
        let selection = SeriesSelection(bulk: bulk(sent))
        selection.markRead(true)
        #expect(selection.undoable == nil)
    }

    @Test func categoriesSheetForTheSelectionOrOneSeries() {
        let selection = SeriesSelection(bulk: bulk(Sent()))
        selection.openCategories()
        #expect(selection.categoriesFor == nil)
        selection.longPress(3)
        selection.tap(1)
        selection.openCategories()
        #expect(selection.categoriesFor?.novelIds == [1, 3])
        selection.categoriesDone(nil)
        #expect(selection.categoriesFor == nil)
        #expect(!selection.active)
        #expect(selection.notice == "Categories saved.")
        selection.openCategories([9])
        #expect(selection.categoriesFor?.novelIds == [9])
    }
}
