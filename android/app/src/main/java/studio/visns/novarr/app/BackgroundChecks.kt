package studio.visns.novarr.app

import android.content.Context
import android.util.Log
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.ProcessLifecycleOwner
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import studio.visns.novarr.BuildConfig
import studio.visns.novarr.NovarrApplication
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ServerUrl
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.domain.AppUpdate
import studio.visns.novarr.domain.BackgroundSchedule
import studio.visns.novarr.store.AppPrefs

/**
 * Something the app looks at on the server while it isn't open, and may
 * notify about. Every check runs in the one periodic [BackgroundCheckWorker];
 * a new kind is another implementation added to [BackgroundChecks.all],
 * with its own switch and [Notifications.NotifyChannel].
 */
interface BackgroundCheck {
    /** Names the check's last-run time in AppPrefs. */
    val key: String

    /**
     * How often it should run. The worker runs at the shortest period of the
     * enabled checks; a check with a longer one keeps its own spacing
     * ([BackgroundSchedule.due]).
     */
    val periodMs: Long

    /** Whether the user wants this check (its Settings switch). The work is scheduled while any check is enabled. */
    suspend fun enabled(prefs: AppPrefs): Boolean

    /**
     * One run against the saved [server]. Throwing is fine (offline, the
     * server unreachable away from home): it is logged and the next period
     * simply tries again.
     */
    suspend fun run(context: Context, model: AppModel, server: String)
}

/** "Novarr 1.4 is available": public meta, no credentials; one notification per build. */
object UpdateCheck : BackgroundCheck {
    override val key = "update"
    override val periodMs = 12 * BackgroundSchedule.HOUR_MS

    override suspend fun enabled(prefs: AppPrefs): Boolean = prefs.updateNotifications.first()

    override suspend fun run(context: Context, model: AppModel, server: String) {
        // Meta is public: this client carries no token.
        val release = ApiClient(server, model.http).meta().android
        val installed = BuildConfig.VERSION_CODE
        if (!AppUpdate.available(release, installed)) {
            // Installed (or the server stepped back): a notice still showing is out of date.
            Notifications.cancel(context, Notifications.APP_UPDATE_ID)
            return
        }
        // In the foreground the app shows the update itself (Home banner, Settings row).
        if (inForeground()) return
        val enabled = enabled(model.prefs) && Notifications.canPost(context, Notifications.NotifyChannel.APP_UPDATES)
        if (release == null || !AppUpdate.shouldNotify(release, installed, model.prefs.notifiedUpdateBuild(), enabled)) return
        val posted = Notifications.post(
            context,
            Notifications.NotifyChannel.APP_UPDATES,
            Notifications.APP_UPDATE_ID,
            title = "Novarr ${AppUpdate.label(release)} is available",
            text = "Tap to update.",
        )
        // Only a notice that showed counts: blocked now, it can still show once allowed.
        if (posted) release.build?.let { model.prefs.noteUpdateNotified(it) }
    }
}

/**
 * Reader requests: reads the server's notification feed for the signed-in
 * account and posts one notification per item ([RequestFeedSync]). Hourly;
 * only while the server offers requests (its last meta said so) and the
 * switch is on. A 401 or any failure is silent and tried again next run.
 */
object RequestsCheck : BackgroundCheck {
    override val key = "requests"
    override val periodMs = BackgroundSchedule.HOUR_MS

    override suspend fun enabled(prefs: AppPrefs): Boolean {
        if (!prefs.requestNotifications.first()) return false
        val server = prefs.serverUrl()?.let(ServerUrl::normalize) ?: return false
        return prefs.requestsOffered(server) == true
    }

    override suspend fun run(context: Context, model: AppModel, server: String) {
        // Public meta: the server may have turned requests off since.
        val meta = ApiClient(server, model.http).meta()
        model.noteRequestsOffered(server, meta.requests)
        if (!meta.requests) return
        // Started by WorkManager, the app may still be reading the saved sign-in.
        val session = withTimeoutOrNull(SESSION_WAIT_MS) { model.session.first { it !is Session.Starting } } as? Session.Active ?: return
        if (session.server != server) return
        // Its own client without the 401 handler: a rejected token is left for the app to find in the foreground.
        val client = ApiClient(server, model.http, token = session.account?.token)
        model.requestFeed.poll(session, client, reportUnauthorized = false)
    }

    private const val SESSION_WAIT_MS = 15_000L
}

/** Whether the app is on screen: then it shows things itself instead of posting notifications. */
internal suspend fun inForeground(): Boolean = withContext(Dispatchers.Main) {
    ProcessLifecycleOwner.get().lifecycle.currentState.isAtLeast(Lifecycle.State.STARTED)
}

/**
 * Runs every enabled [BackgroundCheck] that is due against the saved server.
 * Always succeeds: a failed check waits for the next run rather than
 * retrying with backoff (its last-run time is only stored on success), and
 * with no server saved yet there is nothing to do.
 */
class BackgroundCheckWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val model = (applicationContext as? NovarrApplication)?.model ?: return Result.success()
        val prefs = model.prefs
        val server = prefs.serverUrl()?.let(ServerUrl::normalize) ?: return Result.success()
        val now = System.currentTimeMillis()
        val due = BackgroundSchedule.dueChecks(BackgroundChecks.specs(prefs), now)
        for (check in BackgroundChecks.all.filter { it.key in due }) {
            try {
                check.run(applicationContext, model, server)
                prefs.setCheckLastRun(check.key, now)
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                // Expected away from home: one line, no stack trace.
                Log.i(TAG, "${check::class.simpleName} failed ($e); trying again next period")
            }
        }
        return Result.success()
    }

    private companion object {
        const val TAG = "NovarrChecks"
    }
}

/**
 * The schedule: one unique periodic work while any check is enabled, at the
 * shortest enabled period (hourly with request notifications, else every 12
 * hours). WorkManager keeps it across reboots.
 */
object BackgroundChecks {
    const val WORK_NAME = "novarr-background-checks"

    val all: List<BackgroundCheck> = listOf(UpdateCheck, RequestsCheck)

    suspend fun specs(prefs: AppPrefs): List<BackgroundSchedule.Spec> =
        all.map { BackgroundSchedule.Spec(it.key, it.enabled(prefs), it.periodMs, prefs.checkLastRun(it.key)) }

    /**
     * At launch and when a switch or the server's offer changes: schedules
     * the work, or cancels it when no check is on. KEEP while the interval is
     * unchanged, so an existing schedule keeps its timing; UPDATE when it
     * changed (requests turned on or off).
     */
    suspend fun sync(context: Context, prefs: AppPrefs) {
        val work = WorkManager.getInstance(context)
        val interval = BackgroundSchedule.intervalMs(specs(prefs))
        if (interval == null) {
            work.cancelUniqueWork(WORK_NAME)
            return
        }
        val existing = work.getWorkInfosForUniqueWorkFlow(WORK_NAME).first().firstOrNull { !it.state.isFinished }
        val changed = existing != null && existing.periodicityInfo?.repeatIntervalMillis != interval
        val request = PeriodicWorkRequestBuilder<BackgroundCheckWorker>(interval, TimeUnit.MILLISECONDS)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .build()
        work.enqueueUniquePeriodicWork(WORK_NAME, if (changed) ExistingPeriodicWorkPolicy.UPDATE else ExistingPeriodicWorkPolicy.KEEP, request)
    }
}
