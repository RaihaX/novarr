package studio.visns.novarr.app

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import java.time.LocalTime
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import studio.visns.novarr.NovarrApplication
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.domain.ChapterUpdates
import studio.visns.novarr.store.AppPrefs

/**
 * The new-chapters check's side effects around [ChapterUpdates]: reads
 * `GET /updates?since=` (from [NewChaptersCheck]), keeps the state in
 * AppPrefs, and posts, updates or cancels the one notification.
 *
 * One change at a time (a lock shared by the worker, app start, a tap and
 * the swipe-away receiver), so a clear can't be undone by a run that read
 * the state before it. The state belongs to one server and account
 * ([RequestFeedSync.scope]); another one's is ignored and replaced.
 */
class ChapterUpdatesSync(private val context: Context, private val prefs: AppPrefs) {
    private val lock = Mutex()

    /** One background run against [client]: the new cursor stored, the notification posted when its content changed. */
    suspend fun poll(session: Session.Active, client: ApiClient) = lock.withLock {
        val scope = RequestFeedSync.scope(session)
        val state = load().forScope(scope)
        val answer = client.updates(state.cursor, reportUnauthorized = false)
        // The switch may have been turned off while the request ran.
        if (!prefs.newChapterNotifications.first()) return@withLock
        val outcome = ChapterUpdates.receive(
            state, answer, System.currentTimeMillis(), LocalTime.now(), inForeground(),
            showing = Notifications.isShowing(context, Notifications.NEW_CHAPTERS_ID),
        )
        val action = outcome.action
        if (action is ChapterUpdates.Action.Post) {
            val posted = Notifications.postNewChapters(context, outcome.state.pending, action.alert)
            // Blocked by Android: nothing alerted, so the 6-hour spacing isn't used up.
            val next = if (posted || !action.alert) outcome.state else outcome.state.copy(lastAlertAt = state.lastAlertAt)
            save(next)
        } else {
            save(outcome.state)
        }
    }

    /**
     * The reader is in the app, tapped or swiped the notification: what was
     * pending is seen. The cursor and the last alert time stay.
     */
    suspend fun clearPending() {
        // At once, even while a background run holds the lock (it won't post in the foreground).
        Notifications.cancel(context, Notifications.NEW_CHAPTERS_ID)
        lock.withLock { clearLocked() }
    }

    private suspend fun clearLocked() {
        Notifications.cancel(context, Notifications.NEW_CHAPTERS_ID)
        val state = load()
        if (state.pending.isNotEmpty()) save(state.cleared())
    }

    /**
     * Signed out, another server, or the switch turned off: everything goes,
     * cursor included, so the next run starts over (a first run only stores
     * a cursor) instead of reporting a backlog.
     */
    suspend fun reset() = lock.withLock {
        Notifications.cancel(context, Notifications.NEW_CHAPTERS_ID)
        prefs.setChapterUpdatesState(null)
    }

    private suspend fun load(): ChapterUpdates.State =
        prefs.chapterUpdatesState()?.let { json ->
            runCatching { NovarrJson.decodeFromString(ChapterUpdates.State.serializer(), json) }.getOrNull()
        } ?: ChapterUpdates.State()

    private suspend fun save(state: ChapterUpdates.State) =
        prefs.setChapterUpdatesState(NovarrJson.encodeToString(ChapterUpdates.State.serializer(), state))
}

/** The new-chapters notification was swiped away (its delete intent): what it listed is seen. */
class NewChaptersDismissedReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val app = context.applicationContext as? NovarrApplication ?: return
        val pending = goAsync()
        app.model.scope.launch {
            try {
                app.model.chapterUpdates.clearPending()
            } finally {
                pending.finish()
            }
        }
    }
}
