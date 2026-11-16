import Foundation
import Testing
@testable import Novarr

@Suite("Check polling state")
struct CheckPollingTests {
    private let start = Date(timeIntervalSince1970: 1_800_000_000)

    private func check(_ status: NovelCheck.Status, now: Int = 120, new: Int = 0, message: String? = nil) -> NovelCheck {
        NovelCheck(status: status, startedAt: start, finishedAt: nil, chaptersBefore: 120,
                   chaptersNow: now, newChapters: new, message: message)
    }

    @Test func joinsARunningCheckInsteadOfStartingAnother() {
        #expect(CheckPolling.shouldStart(after: nil))
        #expect(CheckPolling.shouldStart(after: check(.idle)))
        #expect(CheckPolling.shouldStart(after: check(.done, new: 2)))
        #expect(CheckPolling.shouldStart(after: check(.failed)))
        #expect(CheckPolling.shouldStart(after: check(.running)) == false)
    }

    @Test func runningThenDoneWithNewChapters() {
        var polling = CheckPolling(startedAt: start)
        let step1 = polling.receive(check(.running), at: start)
        #expect(step1 == .poll(after: 15))
        #expect(polling.display == .checking(found: 0))
        #expect(polling.chaptersChanged == false)

        // The TOC step listed chapters while still running: reload the list.
        let step2 = polling.receive(check(.running, now: 123, new: 3), at: start + 15)
        #expect(step2 == .poll(after: 15))
        #expect(polling.display == .checking(found: 3))
        #expect(polling.display.text == "Checking… 3 new chapters so far")
        #expect(polling.chaptersChanged)

        let step3 = polling.receive(check(.running, now: 123, new: 3), at: start + 30)
        #expect(step3 == .poll(after: 15))
        #expect(polling.chaptersChanged == false)

        let step4 = polling.receive(check(.done, now: 123, new: 3), at: start + 45)
        #expect(step4 == .finished)
        #expect(polling.display == .found(3))
        #expect(polling.display.text == "3 new chapters")
        #expect(polling.chaptersChanged)
    }

    @Test func doneWithNothingNew() {
        var polling = CheckPolling(startedAt: start)
        let step5 = polling.receive(check(.done), at: start)
        #expect(step5 == .finished) // a sync queue answers done at once
        #expect(polling.display == .noneYet)
        #expect(polling.display.text == "No new chapters yet")
    }

    @Test func oneNewChapterIsSingular() {
        var polling = CheckPolling(startedAt: start)
        _ = polling.receive(check(.done, now: 121, new: 1), at: start)
        #expect(polling.display.text == "1 new chapter")
    }

    @Test func failureShowsTheServersMessage() {
        var polling = CheckPolling(startedAt: start)
        _ = polling.receive(check(.running), at: start)
        let message = "Another scrape of this novel was already running, so this check was skipped."
        let step6 = polling.receive(check(.failed, message: message), at: start + 15)
        #expect(step6 == .finished)
        #expect(polling.display == .failed(message))

        var bare = CheckPolling(startedAt: start)
        _ = bare.receive(check(.failed), at: start)
        #expect(bare.display == .failed("The check failed on the server."))
    }

    @Test func stopsWatchingAfterTenMinutes() {
        var polling = CheckPolling(startedAt: start)
        let step7 = polling.receive(check(.running), at: start + 599)
        #expect(step7 == .poll(after: 15))
        let step8 = polling.receive(check(.running), at: start + 600)
        #expect(step8 == .finished)
        #expect(polling.display == .stillRunning)
        #expect(polling.display.isChecking == false)
    }

    @Test func requestErrorsAreToleratedUntilThreeInARow() {
        var polling = CheckPolling(startedAt: start)
        _ = polling.receive(check(.running), at: start)
        let step9 = polling.receive(error: "Couldn't reach the server.", at: start + 15)
        #expect(step9 == .poll(after: 15))
        let step10 = polling.receive(error: "Couldn't reach the server.", at: start + 30)
        #expect(step10 == .poll(after: 15))
        #expect(polling.display == .checking(found: 0)) // still shown as checking
        // A good answer resets the count.
        let step11 = polling.receive(check(.running), at: start + 45)
        #expect(step11 == .poll(after: 15))
        let step12 = polling.receive(error: "x", at: start + 60)
        #expect(step12 == .poll(after: 15))
        let step13 = polling.receive(error: "x", at: start + 75)
        #expect(step13 == .poll(after: 15))
        let step14 = polling.receive(error: "Couldn't reach the server.", at: start + 90)
        #expect(step14 == .finished)
        #expect(polling.display == .failed("Couldn't reach the server."))
    }

    @Test func idleMeansNothingToReport() {
        var polling = CheckPolling(startedAt: start)
        let step15 = polling.receive(check(.idle), at: start)
        #expect(step15 == .finished)
        #expect(polling.display == .noneYet)
    }

    @Test func unknownStatusKeepsPollingWithinTheLimit() {
        var polling = CheckPolling(startedAt: start)
        let step16 = polling.receive(check(.other("queued")), at: start)
        #expect(step16 == .poll(after: 15))
        #expect(polling.display.isChecking)
    }
}
