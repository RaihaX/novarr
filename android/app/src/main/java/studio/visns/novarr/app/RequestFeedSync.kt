package studio.visns.novarr.app

import android.content.Context
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.FeedItem
import studio.visns.novarr.api.ServerUrl
import studio.visns.novarr.domain.RequestFeed
import studio.visns.novarr.store.AppPrefs

/**
 * Reads the request notification feed (GET /notifications?since=) for one
 * server and account, from the background worker ([RequestsCheck]), when the
 * app comes to the foreground and when the Requests screen opens.
 *
 * De-duplication: one poll at a time (a lock shared by all callers in the
 * process); the stored position (the server's opaque cursor plus the newest
 * item id handled, per server and account) moves only after the items were
 * shown, so a failed or refused run gets the same items next time and an
 * item is never shown twice. Shown means: in the foreground, counted for the
 * dot on the Requests entry (or nothing, with the Requests screen open);
 * in the background, posted as notifications. When the switch is off or
 * Android blocks notifications, the position stays put and the next
 * foreground poll counts them for the dot instead.
 */
class RequestFeedSync(private val context: Context, private val prefs: AppPrefs) {
    private val lock = Mutex()

    private val _revision = MutableStateFlow(0)

    /** Bumped when new items arrived: the Requests screen reloads its lists. */
    val revision: StateFlow<Int> = _revision.asStateFlow()

    /** The Requests screen is showing: new items need no dot. */
    @Volatile var screenVisible = false

    /** Polls the feed; the number of new items shown. Throws ApiError (offline, 401...). */
    suspend fun poll(session: Session.Active, client: ApiClient, reportUnauthorized: Boolean): Int = lock.withLock {
        val scope = scope(session)
        var (cursor, last) = prefs.feedPosition(scope)
        var shown = 0
        for (page in 1..RequestFeed.MAX_PAGES) {
            val feed = client.notifications(cursor, reportUnauthorized)
            val fresh = RequestFeed.fresh(feed.items, last)
            val known = fresh.filter(RequestFeed::known)
            // Not shown (notifications off or blocked in the background): keep the position.
            if (known.isNotEmpty() && !show(scope, known)) break
            cursor = feed.cursor ?: cursor
            last = RequestFeed.lastId(last, fresh)
            prefs.setFeedPosition(scope, cursor, last)
            shown += known.size
            if (!feed.hasMore || feed.items.isEmpty()) break
        }
        if (shown > 0) _revision.update { it + 1 }
        shown
    }

    private suspend fun show(scope: String, items: List<FeedItem>): Boolean {
        if (inForeground()) {
            // In the app: a dot on the Requests entry instead of a system notification.
            if (!screenVisible) prefs.addRequestsUnseen(scope, items.size)
            return true
        }
        if (!prefs.requestNotifications.first() || !Notifications.canPost(context, Notifications.NotifyChannel.REQUESTS)) return false
        val before = Notifications.activeRequestCount(context)
        val posted = items.count { Notifications.postRequest(context, it) }
        if (posted == 0) return false
        Notifications.postRequestSummary(context, items, before + posted)
        // Also the dot, for when the app is opened without tapping the notification.
        prefs.addRequestsUnseen(scope, posted)
        return true
    }

    companion object {
        /** The feed position, the dot and the cursor are per server and account. */
        fun scope(session: Session.Active): String =
            ServerUrl.storageKey(session.server) + "|" + (session.account?.user?.id?.toString() ?: "-")
    }
}
