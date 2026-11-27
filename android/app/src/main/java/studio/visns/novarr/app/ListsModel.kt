package studio.visns.novarr.app

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.domain.ReadingLists
import studio.visns.novarr.store.OfflineStore

/**
 * The reader's lists for the current session, shared by Library (filter,
 * manage) and the series screen (add/remove), so a change in one shows in
 * the other at once. Fetched with the library refresh and saved for offline
 * viewing. Edits go straight to the server (never queued): offline they fail,
 * are rolled back, and the screens say lists can't be changed offline.
 * Only while the server offers lists (meta `lists`).
 */
class ListsModel(private val app: AppModel) {
    private val _state = MutableStateFlow(ReadingLists.State())
    val state: StateFlow<ReadingLists.State> = _state.asStateFlow()

    /** Fetched (or read from the offline copy) at least once this session. */
    private val _loaded = MutableStateFlow(false)
    val loaded: StateFlow<Boolean> = _loaded.asStateFlow()

    /** Showing the saved copy: the server didn't answer the last refresh. */
    private val _fromCache = MutableStateFlow(false)
    val fromCache: StateFlow<Boolean> = _fromCache.asStateFlow()

    /** The session the state belongs to (Session.Active.id). */
    private var owner: String? = null

    val lists: List<ReadingList> get() = _state.value.lists

    /** A new session (sign-in, sign-out, another server): another reader's lists must never show. */
    fun reset() {
        owner = null
        _state.value = ReadingLists.State()
        _loaded.value = false
        _fromCache.value = false
    }

    /** With the library refresh: from the server, else the saved copy. */
    suspend fun refresh() {
        val session = app.active ?: return
        if (!app.listsOffered.value) return
        if (owner != session.id) {
            reset()
            owner = session.id
            app.cached(OfflineStore.Key.lists)?.let { cached ->
                _state.value = ReadingLists.State(cached)
                _loaded.value = true
                _fromCache.value = true
            }
        }
        val result = app.load(OfflineStore.Key.lists) { it.lists() }
        if (app.active?.id != session.id) return
        // Changes still on their way stay shown on top of the fresh copy.
        result.value?.let { lists -> _state.update { state -> state.copy(lists = state.inFlight.fold(lists, ReadingLists::apply)) } }
        if (result.value != null) _loaded.value = true
        _fromCache.value = result.fromCache || result.error != null
    }

    /**
     * Adds or removes [novelId] in [listId], shown at once; on failure it is
     * undone and the message returned (for a snackbar). Null: saved, or
     * nothing to do (already so, or a change for it is still on its way).
     */
    suspend fun toggle(listId: Int, novelId: Int, add: Boolean): String? {
        val session = app.active ?: return null
        val started = ReadingLists.begin(_state.value, listId, novelId, add) ?: return null
        _state.value = started
        val toggle = ReadingLists.Toggle(listId, novelId, add)
        return try {
            val answer = session.client.setListMembership(listId, novelId, add)
            app.noteSuccess()
            if (app.active?.id == session.id) {
                _state.update { ReadingLists.succeed(it, toggle, answer) }
                save()
            }
            null
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            val error = ApiError.from(e)
            app.noteFailure(error)
            if (app.active?.id == session.id) _state.update { ReadingLists.fail(it, toggle) }
            // A list deleted elsewhere: drop it from here too.
            if (error is ApiError.Http && error.status == 404) refresh()
            failure(error, if (add) "Couldn't add it to the list." else "Couldn't remove it from the list.")
        }
    }

    /** The built-in "Want to read", once the server sent it (null on an older server or before the first fetch). */
    val wantToRead: ReadingList? get() = ReadingLists.wantToRead(lists)

    /**
     * Puts [novelId] on "Want to read" or takes it off: shown at once, undone
     * on failure (the message returned, for a snackbar) — [toggle] on the
     * built-in list, fetched first if this session hasn't yet.
     */
    suspend fun toggleWantToRead(novelId: Int, add: Boolean): String? {
        if (wantToRead == null) refresh()
        val list = wantToRead ?: return "Want to read isn't available on this server yet."
        return toggle(list.id, novelId, add)
    }

    /** Creates a list (and puts [addNovel] in it); the new list, or the message why not. */
    suspend fun create(name: String, addNovel: Int? = null): Result<ReadingList> {
        val session = app.active ?: return Result.failure(IllegalStateException("No session"))
        ReadingLists.nameProblem(name, lists)?.let { return Result.failure(ListProblem(it)) }
        return try {
            var list = session.client.createList(ReadingLists.clean(name))
            app.noteSuccess()
            if (addNovel != null) {
                // The list exists either way; a failed add is reported but keeps it.
                list = runCatching { session.client.setListMembership(list.id, addNovel, true) }.getOrElse { e ->
                    e.rethrowIfCancellation()
                    if (app.active?.id == session.id) {
                        _state.update { it.copy(lists = ReadingLists.replace(it.lists, list)) }
                        save()
                    }
                    return Result.failure(ListProblem(failure(ApiError.from(e), "The list was made, but the series couldn't be added.")))
                }
            }
            if (app.active?.id == session.id) {
                _state.update { it.copy(lists = ReadingLists.replace(it.lists, list)) }
                save()
            }
            Result.success(list)
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            val error = ApiError.from(e)
            app.noteFailure(error)
            Result.failure(ListProblem(failure(error, "Couldn't make the list.")))
        }
    }

    /** Null when renamed, else the message why not. */
    suspend fun rename(listId: Int, name: String): String? {
        val session = app.active ?: return null
        ReadingLists.nameProblem(name, lists, renaming = listId)?.let { return it }
        return try {
            val list = session.client.renameList(listId, ReadingLists.clean(name))
            app.noteSuccess()
            if (app.active?.id == session.id) {
                _state.update { it.copy(lists = ReadingLists.replace(it.lists, list)) }
                save()
            }
            null
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            val error = ApiError.from(e)
            app.noteFailure(error)
            failure(error, "Couldn't rename the list.")
        }
    }

    /** Null when deleted, else the message why not. The series themselves stay in the library. */
    suspend fun delete(listId: Int): String? {
        val session = app.active ?: return null
        return try {
            session.client.deleteList(listId)
            app.noteSuccess()
            if (app.active?.id == session.id) {
                _state.update { state -> state.copy(lists = state.lists.filter { it.id != listId }, inFlight = state.inFlight.filter { it.listId != listId }.toSet()) }
                save()
            }
            null
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            val error = ApiError.from(e)
            app.noteFailure(error)
            failure(error, "Couldn't delete the list.")
        }
    }

    private suspend fun save() {
        app.active?.store?.save(_state.value.lists, OfflineStore.Key.lists)
    }

    /** The server's own words for a refused change (422), else what went wrong. */
    private fun failure(error: ApiError, fallback: String): String = when {
        error.isConnectivity -> "You're offline, so lists can't be changed right now."
        error is ApiError.Http && error.status == 422 -> error.serverMessage ?: fallback
        error is ApiError.Http && error.status == 404 -> "That list no longer exists."
        else -> "$fallback ${error.userMessage}"
    }

    /** A message for the reader, from a check or the server. */
    class ListProblem(message: String) : Exception(message)
}
