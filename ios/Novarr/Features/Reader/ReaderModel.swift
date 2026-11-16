import Foundation
import Observation

@MainActor
@Observable
final class ReaderModel {
    let novelId: Int
    private(set) var chapterId: Int
    private(set) var content: ChapterContent?
    private(set) var phase: LoadPhase = .idle
    private(set) var isShowingCache = false
    /// Paragraph index to scroll to once the text is laid out.
    private(set) var restoreIndex: Int?
    /// Scroll events are ignored until the view has restored the position,
    /// so the initial jump never reports a bogus progress value.
    var isTracking = false

    @ObservationIgnored private var pendingProgress: Int?
    @ObservationIgnored private var lastSentProgress: Int?
    @ObservationIgnored private var lastSentAt = Date.distantPast
    @ObservationIgnored private var sendTask: Task<Void, Never>?
    @ObservationIgnored private var markedRead = false

    /// Minimum seconds between progress writes while scrolling.
    static let progressInterval: TimeInterval = 4

    init(route: ReaderRoute) {
        novelId = route.novelId
        chapterId = route.chapterId
    }

    var paragraphCount: Int { content?.paragraphs.count ?? 0 }

    // MARK: Loading

    func load(app: AppModel) async {
        phase = .loading
        isTracking = false
        markedRead = false
        pendingProgress = nil
        lastSentProgress = nil
        restoreIndex = nil

        let id = chapterId
        let novelId = self.novelId
        let cached = await app.store?.chapter(id: id, novelId: novelId)
        var loaded: ChapterContent?
        var failure: APIError?

        if let client = app.client {
            do {
                var fresh = try await client.chapter(id: id)
                app.noteSuccess()
                if fresh.novelId == 0 { fresh.novelId = novelId }
                // Unsent local progress is newer than what the server knows.
                if app.pendingWrites > 0, let cached {
                    fresh.readProgress = cached.readProgress
                    fresh.read = fresh.read || cached.read
                }
                await app.store?.saveChapter(fresh)
                loaded = fresh
            } catch {
                failure = APIError.from(error)
                app.noteFailure(failure ?? .timedOut)
            }
        }
        guard id == chapterId else { return } // the user moved on meanwhile

        if loaded == nil, let cached {
            loaded = cached
            isShowingCache = true
        } else {
            isShowingCache = false
        }

        guard let loaded else {
            phase = .failed(failure?.localizedDescription ?? "This chapter isn't available.")
            return
        }
        content = loaded
        markedRead = loaded.read
        lastSentProgress = loaded.readProgress
        if let progress = loaded.readProgress, progress > 0, progress < 100, loaded.paragraphs.count > 1 {
            restoreIndex = Int((Double(progress) / 100 * Double(loaded.paragraphs.count - 1)).rounded())
        }
        phase = .loaded
        if loaded.downloaded { app.downloads.contentChanged() }
        prefetch(loaded.next, app: app)
    }

    /// Fetches the next chapter in the background so it's ready (and offline).
    private func prefetch(_ ref: ChapterRef?, app: AppModel) {
        guard let ref, ref.downloaded != false, let client = app.client, let store = app.store else { return }
        let novelId = self.novelId
        Task(priority: .utility) {
            guard await !store.hasChapter(id: ref.id, novelId: novelId),
                  let chapter = try? await client.chapter(id: ref.id) else { return }
            await store.saveChapter(chapter)
        }
    }

    /// Re-reads only the chapter's `next` link (after a check found new
    /// chapters), leaving the text and scroll position alone.
    func refreshNext(app: AppModel) async {
        guard let client = app.client, let current = content,
              current.next == nil || current.next?.downloaded == false else { return }
        let id = chapterId
        guard let fresh = try? await client.chapter(id: id),
              var latest = content, latest.id == id, fresh.next != latest.next else { return }
        latest.next = fresh.next
        content = latest
        prefetch(fresh.next, app: app)
    }

    func go(to ref: ChapterRef, app: AppModel) async {
        await leave(app: app)
        chapterId = ref.id
        content = nil
        await load(app: app)
    }

    // MARK: Progress

    /// Called as the top visible paragraph changes.
    func scrolled(toParagraph index: Int, app: AppModel) {
        guard isTracking, paragraphCount > 0, content?.downloaded == true else { return }
        let fraction = Double(index) / Double(max(paragraphCount - 1, 1))
        // 100 is reserved for actually reaching the end of the chapter.
        pendingProgress = min(99, max(0, Int((fraction * 100).rounded())))
        scheduleSend(app: app)
    }

    func reachedEnd(app: AppModel) {
        guard isTracking, var content, content.downloaded else { return }
        sendTask?.cancel()
        sendTask = nil
        pendingProgress = nil
        guard !markedRead else { return }
        markedRead = true
        lastSentProgress = 100
        lastSentAt = Date()
        content.read = true
        content.readProgress = 100
        self.content = content
        let id = chapterId, novelId = self.novelId
        Task {
            await app.store?.updateChapterState(id: id, novelId: novelId, progress: 100, read: true)
            await app.record(.progress(chapterId: id, progress: 100, read: true))
        }
    }

    /// Sends any unsent position; call when leaving the chapter or the app.
    func leave(app: AppModel) async {
        sendTask?.cancel()
        sendTask = nil
        await sendPending(app: app)
    }

    private func scheduleSend(app: AppModel) {
        let elapsed = Date().timeIntervalSince(lastSentAt)
        if elapsed >= Self.progressInterval {
            sendTask?.cancel()
            sendTask = Task { await sendPending(app: app) }
        } else if sendTask == nil {
            let delay = Self.progressInterval - elapsed
            sendTask = Task {
                try? await Task.sleep(for: .seconds(delay))
                guard !Task.isCancelled else { return }
                await sendPending(app: app)
            }
        }
    }

    private func sendPending(app: AppModel) async {
        sendTask = nil
        guard let progress = pendingProgress, progress != lastSentProgress else { return }
        pendingProgress = nil
        lastSentProgress = progress
        lastSentAt = Date()
        let id = chapterId
        await app.store?.updateChapterState(id: id, novelId: novelId, progress: progress, read: nil)
        await app.record(.progress(chapterId: id, progress: progress, read: false))
    }
}
