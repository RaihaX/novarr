package studio.visns.novarr.app

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.provider.Settings
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import studio.visns.novarr.MainActivity
import studio.visns.novarr.R
import studio.visns.novarr.api.FeedItem
import studio.visns.novarr.domain.ChapterUpdates
import studio.visns.novarr.domain.RequestFeed
import studio.visns.novarr.domain.Requests

/**
 * The app's notifications in one place: the channels (one per kind, so a
 * kind can be muted in Android's settings without the others), the
 * notification ids, and posting and cancelling. Nothing here decides
 * whether to notify; the background checks ([BackgroundChecks]) do.
 *
 * A new kind of notification adds a [NotifyChannel] entry and an id below.
 */
object Notifications {
    /** Every channel the app posts to, created at launch (Android keeps the user's changes to them). */
    enum class NotifyChannel(val id: String, val title: String, val description: String, val importance: Int) {
        /** "Novarr 1.4 is available": quiet (no sound), it can wait. */
        APP_UPDATES("app-updates", "App updates", "A newer version of Novarr is on your server.", NotificationManager.IMPORTANCE_LOW),

        /** Reader requests: a new one to review (the owner), or a decision on yours. */
        REQUESTS("requests", "Requests", "New requests to review, and the owner's decisions on yours.", NotificationManager.IMPORTANCE_DEFAULT),

        /** The one combined "new chapters" notification (every series in it). */
        NEW_CHAPTERS("new-chapters", "New chapters", "Series you were caught up on that have something new to read.", NotificationManager.IMPORTANCE_DEFAULT),
    }

    /** The one "newer version" notification; a newer build replaces it. */
    const val APP_UPDATE_ID = 1

    /** The summary of several request notifications (one per item: [RequestFeed.notificationId]). */
    const val REQUESTS_SUMMARY_ID = 2

    /** The one "new chapters" notification; every post replaces it. */
    const val NEW_CHAPTERS_ID = 3

    /** Request notifications are bundled under this group. */
    const val REQUESTS_GROUP = "studio.visns.novarr.requests"

    /** MainActivity's action for a tapped notification: show Home. */
    const val ACTION_OPEN_HOME = "studio.visns.novarr.action.OPEN_HOME"

    /** MainActivity's action for a tapped request notification: the Requests screen ([EXTRA_SECTION], maybe [EXTRA_NOVEL_ID]). */
    const val ACTION_OPEN_REQUESTS = "studio.visns.novarr.action.OPEN_REQUESTS"
    /** MainActivity's action for the tapped new-chapters notification with one series: that series ([EXTRA_NOVEL_ID]). */
    const val ACTION_OPEN_SERIES = "studio.visns.novarr.action.OPEN_SERIES"

    /** Marks an intent as coming from the new-chapters notification: tapping it clears what was pending. */
    const val EXTRA_FROM_NEW_CHAPTERS = "fromNewChapters"
    const val EXTRA_SECTION = "section"
    const val EXTRA_NOVEL_ID = "novelId"

    fun ensureChannels(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        manager.createNotificationChannels(
            NotifyChannel.entries.map { channel ->
                NotificationChannel(channel.id, channel.title, channel.importance).apply { description = channel.description }
            },
        )
    }

    /** Whether Android 13+ still has to grant POST_NOTIFICATIONS (always granted before 13). */
    fun needsPermission(context: Context): Boolean =
        Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED

    /** Whether a notification on [channel] would show: permission granted, app notifications and the channel not turned off. */
    fun canPost(context: Context, channel: NotifyChannel): Boolean {
        if (needsPermission(context)) return false
        val compat = NotificationManagerCompat.from(context)
        if (!compat.areNotificationsEnabled()) return false
        val importance = context.getSystemService(NotificationManager::class.java)?.getNotificationChannel(channel.id)?.importance
        return importance != NotificationManager.IMPORTANCE_NONE
    }

    /**
     * Posts a notification that opens the app on Home when tapped (and then
     * goes away). Returns false when Android wouldn't show it.
     */
    fun post(context: Context, channel: NotifyChannel, id: Int, title: String, text: String): Boolean =
        post(context, channel, id, builder(context, channel, title, text).setContentIntent(openHome(context)))

    /**
     * One request feed item: tapping opens the Requests screen at
     * [RequestFeed.target] (the series for an available one). Bundled in
     * [REQUESTS_GROUP]; [postRequestSummary] adds the bundle's summary.
     */
    fun postRequest(context: Context, item: FeedItem): Boolean {
        val id = RequestFeed.notificationId(item.id)
        val builder = builder(context, NotifyChannel.REQUESTS, item.title.ifBlank { "Requests" }, item.body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(item.body))
            .setContentIntent(openRequests(context, id, RequestFeed.target(item)))
            .setGroup(REQUESTS_GROUP)
            .setCategory(NotificationCompat.CATEGORY_SOCIAL)
        item.at?.let { Requests.instant(it) }?.let { builder.setWhen(it.toEpochMilli()).setShowWhen(true) }
        return post(context, NotifyChannel.REQUESTS, id, builder)
    }

    /**
     * The group summary once [showing] (two or more) request notifications
     * are up ("3 request updates", a line per new item); Android shows the
     * bundle with it. Counted by the caller: a notification just posted is
     * not in Android's active list straight away.
     */
    fun postRequestSummary(context: Context, items: List<FeedItem>, showing: Int): Boolean {
        if (showing < 2) return false
        val style = NotificationCompat.InboxStyle()
        items.takeLast(6).forEach { style.addLine(it.body.ifBlank { it.title }) }
        val title = RequestFeed.summaryTitle(showing)
        style.setSummaryText(title)
        val builder = builder(context, NotifyChannel.REQUESTS, title, items.lastOrNull()?.body.orEmpty())
            .setStyle(style)
            .setContentIntent(openRequests(context, REQUESTS_SUMMARY_ID, RequestFeed.target(items)))
            .setGroup(REQUESTS_GROUP)
            .setGroupSummary(true)
            .setGroupAlertBehavior(NotificationCompat.GROUP_ALERT_CHILDREN)
        return post(context, NotifyChannel.REQUESTS, REQUESTS_SUMMARY_ID, builder)
    }

    /**
     * The one new-chapters notification for [pending] (domain.ChapterUpdates
     * builds its text): one series → its name and count, tapping opens it;
     * several → "New chapters in N series" with a line per series, tapping
     * opens Home. Re-posting updates it in place; [alert] false posts it
     * without sound or vibration. Swiping it away clears what was pending
     * ([NewChaptersDismissedReceiver]).
     */
    fun postNewChapters(context: Context, pending: List<ChapterUpdates.Pending>, alert: Boolean): Boolean {
        if (pending.isEmpty()) return false
        val channel = NotifyChannel.NEW_CHAPTERS
        val target = ChapterUpdates.target(pending)
        val builder = builder(context, channel, ChapterUpdates.title(pending), ChapterUpdates.text(pending))
            .setContentIntent(if (target != null) openSeries(context, target) else openHome(context, fromNewChapters = true))
            .setDeleteIntent(newChaptersDismissed(context))
            .setSilent(!alert)
            .setCategory(NotificationCompat.CATEGORY_RECOMMENDATION)
            .setNumber(pending.size)
        if (pending.size > 1) {
            val style = NotificationCompat.InboxStyle()
            ChapterUpdates.lines(pending).forEach { style.addLine(it) }
            builder.setStyle(style)
        }
        return post(context, channel, NEW_CHAPTERS_ID, builder)
    }

    /** Clears every request notification (the Requests screen is open: they are seen). */
    fun cancelRequests(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        runCatching {
            manager.activeNotifications.filter { it.notification.group == REQUESTS_GROUP }.forEach { manager.cancel(it.id) }
        }
    }

    /** Request notifications showing now (the summary not counted). */
    fun activeRequestCount(context: Context): Int =
        runCatching {
            context.getSystemService(NotificationManager::class.java)?.activeNotifications
                ?.count { it.notification.group == REQUESTS_GROUP && it.id != REQUESTS_SUMMARY_ID } ?: 0
        }.getOrDefault(0)

    private fun builder(context: Context, channel: NotifyChannel, title: String, text: String): NotificationCompat.Builder =
        NotificationCompat.Builder(context, channel.id)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(text)
            .setAutoCancel(true)
            .setOnlyAlertOnce(true)
            .setPriority(if (channel.importance <= NotificationManager.IMPORTANCE_LOW) NotificationCompat.PRIORITY_LOW else NotificationCompat.PRIORITY_DEFAULT)

    private fun post(context: Context, channel: NotifyChannel, id: Int, builder: NotificationCompat.Builder): Boolean {
        if (!canPost(context, channel)) return false
        val notification = builder.build()
        return try {
            NotificationManagerCompat.from(context).notify(id, notification)
            true
        } catch (_: SecurityException) {
            // The permission was revoked between the check and the post.
            false
        }
    }

    /** Whether notification [id] is on screen now (false when Android won't say). */
    fun isShowing(context: Context, id: Int): Boolean =
        runCatching { context.getSystemService(NotificationManager::class.java)?.activeNotifications?.any { it.id == id } == true }.getOrDefault(false)

    fun cancel(context: Context, id: Int) {
        NotificationManagerCompat.from(context).cancel(id)
    }

    /** Android's notification settings for Novarr (the switch, and each channel). */
    fun settingsIntent(context: Context): Intent =
        Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName)

    /** Brings the app to the front on the Requests screen; a distinct [requestCode] per notification keeps each one's extras. */
    private fun openRequests(context: Context, requestCode: Int, target: RequestFeed.Target): PendingIntent {
        val intent = Intent(context, MainActivity::class.java)
            .setAction(ACTION_OPEN_REQUESTS)
            .putExtra(EXTRA_SECTION, target.section.name)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        target.novelId?.let { intent.putExtra(EXTRA_NOVEL_ID, it) }
        return PendingIntent.getActivity(context, requestCode, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    /** Brings the app to the front on Home; the Home banner offers the update from there. */
    private fun openHome(context: Context, fromNewChapters: Boolean = false): PendingIntent {
        val intent = Intent(context, MainActivity::class.java)
            .setAction(ACTION_OPEN_HOME)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        if (fromNewChapters) intent.putExtra(EXTRA_FROM_NEW_CHAPTERS, true)
        // Its own request code: the update notice's Home intent carries no extra.
        val requestCode = if (fromNewChapters) NEW_CHAPTERS_ID else 0
        return PendingIntent.getActivity(context, requestCode, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    /** Brings the app to the front on one series (over Home). */
    private fun openSeries(context: Context, novelId: Int): PendingIntent {
        val intent = Intent(context, MainActivity::class.java)
            .setAction(ACTION_OPEN_SERIES)
            .putExtra(EXTRA_NOVEL_ID, novelId)
            .putExtra(EXTRA_FROM_NEW_CHAPTERS, true)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        return PendingIntent.getActivity(context, NEW_CHAPTERS_ID, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    /** Swiped away (or "Clear all"): [NewChaptersDismissedReceiver] clears what was pending. */
    private fun newChaptersDismissed(context: Context): PendingIntent {
        val intent = Intent(context, NewChaptersDismissedReceiver::class.java)
        return PendingIntent.getBroadcast(context, NEW_CHAPTERS_ID, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }
}
