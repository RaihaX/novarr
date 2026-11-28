import Foundation
import Observation

/// The reader's categories (reading lists) for the current session, shared
/// by Home, Library, the series screen and Requests, so a change in one shows
/// in the others at once. Fetched with the library refresh and saved for
/// offline viewing. Edits go straight to the server (never queued): offline
/// they fail, are rolled back, and the screens say categories can't be
/// changed offline. Only while the server offers lists (meta `lists`).
/// Mirrors android app/ListsModel.kt.
@MainActor
@Observable
final class ListsModel {
    private(set) var state = ReadingLists.State()
    /// Fetched (or read from the offline copy) at least once this session.
    private(set) var loaded = false
    /// Showing the saved copy: the server didn't answer the last refresh.
    private(set) var fromCache = false

    @ObservationIgnored private weak var app: AppModel?
    /// The session the state belongs to (`AppModel.sessionID`).
    @ObservationIgnored private var owner: String?

    init(app: AppModel? = nil) {
        self.app = app
    }

    func attach(_ app: AppModel) { self.app = app }

    var lists: [ReadingList] { state.lists }
    /// In the reader's order.
    var sorted: [ReadingList] { ReadingLists.sorted(state.lists) }
    var wantToRead: ReadingList? { ReadingLists.wantToRead(state.lists) }
    var favourites: ReadingList? { ReadingLists.favourites(state.lists) }

    /// Whether the server offers categories.
    var offered: Bool { app?.serverMeta?.lists == true }

    func isFavourite(_ novelId: Int) -> Bool { ReadingLists.isFavourite(state.lists, novelId: novelId) }
    func isWanted(_ novelId: Int) -> Bool { ReadingLists.isWanted(state.lists, novelId: novelId) }
    func containing(_ novelId: Int) -> [ReadingList] { ReadingLists.containing(sorted, novelId: novelId) }

    /// A new session (sign-in, sign-out, another server): another reader's lists must never show.
    func reset() {
        owner = nil
        state = ReadingLists.State()
        loaded = false
        fromCache = false
    }

    /// With the library refresh: from the server, else the saved copy.
    /// Runs when the server offers lists, or before meta is known (an older
    /// server answers 404 and nothing shows).
    func refresh() async {
        guard let app, app.client != nil else { return }
        if let meta = app.serverMeta, !meta.lists { return }
        let session = app.sessionID
        if owner != session {
            reset()
            owner = session
            if let cached = await app.cached([ReadingList].self, .lists), app.sessionID == session {
                state = ReadingLists.State(lists: cached)
                loaded = true
                fromCache = true
            }
        }
        let result = await app.load(.lists) { try await $0.lists() }
        guard app.sessionID == session else { return }
        // Changes still on their way stay shown on top of the fresh copy.
        if let lists = result.value, !result.fromCache {
            state.lists = ReadingLists.applyingInFlight(lists, state.inFlight)
            loaded = true
        } else if let lists = result.value, !loaded {
            state.lists = lists
            loaded = true
        }
        fromCache = result.fromCache || result.error != nil
    }

    /// Adds or removes `novelId` in `listId`, shown at once; on failure it is
    /// undone and the message returned (for a toast). Nil: saved, or nothing
    /// to do (already so, or a change for it is still on its way).
    @discardableResult
    func toggle(listId: Int, novelId: Int, add: Bool) async -> String? {
        guard let app, let client = app.client else { return nil }
        let session = app.sessionID
        guard let started = ReadingLists.begin(state, listId: listId, novelId: novelId, add: add) else { return nil }
        state = started
        let toggle = ReadingLists.Toggle(listId: listId, novelId: novelId, add: add)
        do {
            let answer = try await client.setListMembership(listId: listId, novelId: novelId, add: add)
            app.noteSuccess()
            guard app.sessionID == session else { return nil }
            state = ReadingLists.succeed(state, toggle: toggle, answer: answer)
            await save()
            app.libraryChanged()
            return nil
        } catch {
            let error = APIError.from(error)
            app.noteFailure(error)
            if app.sessionID == session { state = ReadingLists.fail(state, toggle: toggle) }
            // A list deleted elsewhere: drop it here too.
            if error.status == 404 { await refresh() }
            return failure(error, fallback: add ? "Couldn't add it to the category." : "Couldn't remove it from the category.")
        }
    }

    /// Puts `novelId` on "Want to read" or takes it off (the built-in list,
    /// fetched first if this session hasn't yet).
    @discardableResult
    func toggleWantToRead(novelId: Int, add: Bool) async -> String? {
        if wantToRead == nil { await refresh() }
        guard let list = wantToRead else { return "Want to read isn't available on this server yet." }
        return await toggle(listId: list.id, novelId: novelId, add: add)
    }

    /// The heart: a toggle on the built-in "Favourites".
    @discardableResult
    func toggleFavourite(novelId: Int, add: Bool) async -> String? {
        if favourites == nil { await refresh() }
        guard let list = favourites else { return "Favourites isn't available on this server yet." }
        return await toggle(listId: list.id, novelId: novelId, add: add)
    }

    /// Creates a category (and puts `addNovel` in it): the new list, or the message why not.
    func create(name: String, addNovel: Int? = nil) async -> Result<ReadingList, ListProblem> {
        guard let app, let client = app.client else { return .failure(ListProblem("You're not connected to a server.")) }
        let session = app.sessionID
        if let problem = ReadingLists.nameProblem(name, lists: state.lists) { return .failure(ListProblem(problem)) }
        do {
            var list = try await client.createList(name: ReadingLists.clean(name))
            app.noteSuccess()
            if let addNovel {
                do {
                    list = try await client.setListMembership(listId: list.id, novelId: addNovel, add: true)
                } catch {
                    // The list exists either way; a failed add is reported but keeps it.
                    if app.sessionID == session {
                        state.lists = ReadingLists.replace(state.lists, list)
                        await save()
                    }
                    return .failure(ListProblem(failure(APIError.from(error),
                                                        fallback: "The category was made, but the series couldn't be added.")))
                }
            }
            if app.sessionID == session {
                state.lists = ReadingLists.replace(state.lists, list)
                await save()
            }
            return .success(list)
        } catch {
            let error = APIError.from(error)
            app.noteFailure(error)
            return .failure(ListProblem(failure(error, fallback: "Couldn't make the category.")))
        }
    }

    /// Nil when renamed, else the message why not.
    func rename(listId: Int, name: String) async -> String? {
        guard let app, let client = app.client else { return nil }
        let session = app.sessionID
        if let problem = ReadingLists.nameProblem(name, lists: state.lists, renaming: listId) { return problem }
        do {
            let list = try await client.renameList(id: listId, name: ReadingLists.clean(name))
            app.noteSuccess()
            if app.sessionID == session {
                state.lists = ReadingLists.replace(state.lists, list)
                await save()
            }
            return nil
        } catch {
            let error = APIError.from(error)
            app.noteFailure(error)
            return failure(error, fallback: "Couldn't rename the category.")
        }
    }

    /// Nil when deleted, else the message why not. The series stay in the library.
    func delete(listId: Int) async -> String? {
        guard let app, let client = app.client else { return nil }
        let session = app.sessionID
        do {
            try await client.deleteList(id: listId)
            app.noteSuccess()
            if app.sessionID == session {
                state.lists.removeAll { $0.id == listId }
                state.inFlight = state.inFlight.filter { $0.listId != listId }
                await save()
                app.libraryChanged()
            }
            return nil
        } catch {
            let error = APIError.from(error)
            app.noteFailure(error)
            return failure(error, fallback: "Couldn't delete the category.")
        }
    }

    /// "Set categories" for a multi-select: every series of `novelIds` into
    /// `add` and out of `remove`, in one call (500 a call at most), shown at
    /// once; the server's index replaces ours, a failure puts the old copy back.
    func setMemberships(novelIds: [Int], add: [Int], remove: [Int]) async -> String? {
        guard let app, let client = app.client else { return nil }
        guard !novelIds.isEmpty, !(add.isEmpty && remove.isEmpty) else { return nil }
        let session = app.sessionID
        let before = state.lists
        state.lists = Categories.applyMemberships(state.lists, novelIds: novelIds, add: add, remove: remove)
        do {
            var answer: [ReadingList]?
            var unique: [Int] = []
            var seen = Set<Int>()
            for id in novelIds where seen.insert(id).inserted { unique.append(id) }
            for start in stride(from: 0, to: unique.count, by: Categories.bulkMax) {
                let part = Array(unique[start..<min(start + Categories.bulkMax, unique.count)])
                answer = try await client.setMemberships(novelIds: part, add: add, remove: remove)
            }
            app.noteSuccess()
            if app.sessionID == session {
                if let answer { state.lists = ReadingLists.applyingInFlight(answer, state.inFlight) }
                await save()
                app.libraryChanged()
            }
            return nil
        } catch {
            let error = APIError.from(error)
            app.noteFailure(error)
            if app.sessionID == session { state.lists = before }
            if error.status == 404 { await refresh() }
            return failure(error, fallback: "Couldn't change the categories.")
        }
    }

    /// Manage categories' drag: the new order shown at once (positions
    /// renumbered here), saved with PATCH /lists/order; a failure puts the old order back.
    func reorder(ids: [Int]) async -> String? {
        guard let app, let client = app.client else { return nil }
        let session = app.sessionID
        let before = state.lists
        let order = Dictionary(uniqueKeysWithValues: ids.enumerated().map { ($1, $0) })
        state.lists = state.lists.map { list in
            var list = list
            list.position = order[list.id] ?? (ids.count + list.position)
            return list
        }
        do {
            let lists = try await client.reorderLists(ids: ids)
            app.noteSuccess()
            if app.sessionID == session {
                state.lists = ReadingLists.applyingInFlight(lists, state.inFlight)
                await save()
                app.libraryChanged()
            }
            return nil
        } catch {
            let error = APIError.from(error)
            app.noteFailure(error)
            if app.sessionID == session { state.lists = before }
            if error.status == 404 { await refresh() }
            return failure(error, fallback: "Couldn't save the order.")
        }
    }

    /// Hides `listId` from Home's sections (or shows it again), shown at once and undone on failure.
    func setHiddenOnHome(listId: Int, hidden: Bool) async -> String? {
        guard let app, let client = app.client else { return nil }
        let session = app.sessionID
        let before = state.lists
        state.lists = state.lists.map { list in
            var list = list
            if list.id == listId { list.hiddenOnHome = hidden }
            return list
        }
        do {
            let list = try await client.setListHiddenOnHome(id: listId, hidden: hidden)
            app.noteSuccess()
            if app.sessionID == session {
                state.lists = ReadingLists.replace(state.lists, list)
                await save()
                app.libraryChanged()
            }
            return nil
        } catch {
            let error = APIError.from(error)
            app.noteFailure(error)
            if app.sessionID == session { state.lists = before }
            return failure(error, fallback: hidden ? "Couldn't hide it on Home." : "Couldn't show it on Home.")
        }
    }

    /// Replaces the local copy with an index the server just sent (e.g. after a Browse request with categories).
    func replaceAll(_ lists: [ReadingList]) async {
        state.lists = ReadingLists.applyingInFlight(lists, state.inFlight)
        await save()
    }

    private func save() async {
        await app?.store?.save(state.lists, as: .lists)
    }

    /// The server's own words for a refused change (422), else what went wrong.
    func failure(_ error: APIError, fallback: String) -> String {
        if error.isConnectivity { return "You're offline, so categories can't be changed right now." }
        if error.status == 422 { return error.serverMessage ?? fallback }
        if error.status == 404 { return "That category no longer exists." }
        return "\(fallback) \(error.localizedDescription)"
    }
}

/// A message for the reader, from a name check or the server.
struct ListProblem: Error, Equatable {
    var message: String
    init(_ message: String) { self.message = message }
}
