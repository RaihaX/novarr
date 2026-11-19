package studio.visns.novarr.app

import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.net.Uri
import android.os.Build
import android.provider.Settings
import android.util.Log
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import studio.visns.novarr.NovarrApplication
import studio.visns.novarr.api.AndroidRelease
import studio.visns.novarr.domain.ApkCheck
import studio.visns.novarr.domain.UpdateEvent
import studio.visns.novarr.domain.UpdateFailure
import studio.visns.novarr.domain.UpdateMachine
import studio.visns.novarr.domain.UpdateState
import java.io.File
import java.io.IOException
import kotlin.coroutines.coroutineContext

/**
 * Downloads the server's APK into the cache and installs it with a
 * PackageInstaller session. The decisions live in [UpdateMachine]; this only
 * does the I/O and the Android calls. It runs in the app's scope, so leaving
 * the screen doesn't stop a download.
 *
 * The download goes through [http], a client that never carries credentials:
 * the APK link is public (and may be on another host than the API).
 */
class AppUpdater(
    private val context: Context,
    private val http: OkHttpClient,
    private val scope: CoroutineScope,
) {
    private val _state = MutableStateFlow<UpdateState>(UpdateState.Idle)
    val state: StateFlow<UpdateState> = _state.asStateFlow()

    private var job: Job? = null
    private var release: AndroidRelease? = null

    /** The system's "Install this update?" screen, kept so the notice can show it again. */
    private var confirmIntent: Intent? = null
    private var sessionId: Int? = null

    private val dir: File get() = File(context.cacheDir, "updates")
    private val apk: File get() = File(dir, "novarr-update.apk")

    private fun send(event: UpdateEvent) = _state.update { UpdateMachine.reduce(it, event) }

    /** At launch: a previous run's APK (installed, failed or abandoned) and stale sessions go. */
    fun cleanUp() {
        scope.launch(Dispatchers.IO) {
            dir.listFiles()?.forEach { it.delete() }
            runCatching {
                val installer = context.packageManager.packageInstaller
                installer.mySessions.forEach { runCatching { installer.abandonSession(it.sessionId) } }
            }
        }
    }

    /** "Update": download, check and install [release]. Ignored while one is already running. */
    fun start(release: AndroidRelease) {
        if (_state.value.busy) return
        this.release = release
        send(UpdateEvent.Start)
        job = scope.launch {
            try {
                withContext(Dispatchers.IO) { download(release) }
            } catch (e: IOException) {
                Log.w(TAG, "Update download failed", e)
                return@launch fail(UpdateFailure.Network)
            }
            send(UpdateEvent.Downloaded)
            val ok = withContext(Dispatchers.IO) { runCatching { ApkCheck.verify(apk, release.size, release.sha256) }.getOrDefault(false) }
            if (!ok) return@launch fail(UpdateFailure.Mismatch)
            send(UpdateEvent.Verified(canInstall()))
            // Otherwise NeedsPermission: [onForeground] continues once Settings allows it.
            if (_state.value == UpdateState.AwaitingConfirmation) installOrFail()
        }
    }

    private suspend fun installOrFail() {
        val reason = try {
            install()
        } catch (e: IOException) {
            Log.w(TAG, "Update install failed", e)
            UpdateFailure.Storage.takeIf { e.message?.contains("ENOSPC") == true } ?: UpdateFailure.Other
        } catch (e: SecurityException) {
            Log.w(TAG, "Update install refused", e)
            UpdateFailure.Blocked
        }
        reason?.let { fail(it) }
    }

    /** Cancel: stops a download or drops a waiting install; the notice offers the update again. */
    fun cancel() {
        job?.cancel()
        job = null
        abandonSession()
        confirmIntent = null
        send(UpdateEvent.Cancel)
        scope.launch(Dispatchers.IO) { apk.delete() }
    }

    /** Back in the foreground: continue after the user allowed installs in Settings. */
    fun onForeground() {
        if (_state.value == UpdateState.NeedsPermission && canInstall()) {
            send(UpdateEvent.PermissionGranted)
            job = scope.launch { installOrFail() }
        }
    }

    /** Android's "Install unknown apps" screen for Novarr. */
    fun permissionSettingsIntent(): Intent =
        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}"))

    /** Shows the system install confirmation again (it was dismissed, or Android didn't let it open from the background). */
    fun showConfirmation(): Boolean {
        val intent = confirmIntent ?: return false
        return runCatching { context.startActivity(Intent(intent).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
    }

    private fun canInstall(): Boolean =
        Build.VERSION.SDK_INT < Build.VERSION_CODES.O || context.packageManager.canRequestPackageInstalls()

    private suspend fun download(release: AndroidRelease) {
        dir.mkdirs()
        apk.delete()
        val call = http.newCall(Request.Builder().url(release.url).build())
        val ctx = coroutineContext
        ctx[Job]?.invokeOnCompletion { if (it != null) call.cancel() }
        call.execute().use { response ->
            if (!response.isSuccessful) throw IOException("HTTP ${response.code}")
            val body = response.body ?: throw IOException("Empty response")
            val total = body.contentLength().takeIf { it > 0 } ?: release.size
            var received = 0L
            var lastPercent = -1
            body.byteStream().use { input ->
                apk.outputStream().use { output ->
                    val buffer = ByteArray(64 * 1024)
                    while (true) {
                        ctx.ensureActive()
                        val n = input.read(buffer)
                        if (n < 0) break
                        output.write(buffer, 0, n)
                        received += n
                        // One state change per percent, not per buffer.
                        val percent = if (total != null && total > 0) ((received * 100) / total).toInt() else (received shr 20).toInt()
                        if (percent != lastPercent) {
                            lastPercent = percent
                            send(UpdateEvent.Progress(received, total))
                        }
                    }
                }
            }
        }
    }

    /**
     * Writes the APK into a PackageInstaller session and commits it; the
     * result arrives at [InstallResultReceiver]. Returns a failure, or null
     * when the session was committed.
     *
     * The system confirmation always shows: a possible follow-up is
     * SessionParams.setRequireUserAction(USER_ACTION_NOT_REQUIRED) (API 31+),
     * which lets an installer update itself silently. Not done on purpose.
     */
    private suspend fun install(): UpdateFailure? = withContext(Dispatchers.IO) {
        if (!apk.isFile) return@withContext UpdateFailure.Other
        val installer = context.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
            setAppPackageName(context.packageName)
            setSize(apk.length())
        }
        val id = installer.createSession(params)
        sessionId = id
        installer.openSession(id).use { session ->
            apk.inputStream().use { input ->
                session.openWrite("novarr.apk", 0, apk.length()).use { out ->
                    input.copyTo(out, 64 * 1024)
                    session.fsync(out)
                }
            }
            coroutineContext.ensureActive()
            val intent = Intent(context, InstallResultReceiver::class.java).setPackage(context.packageName)
            // Mutable: the installer adds the status extras to this intent.
            val flags = PendingIntent.FLAG_UPDATE_CURRENT or
                (if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) PendingIntent.FLAG_MUTABLE else 0)
            val pending = PendingIntent.getBroadcast(context, id, intent, flags)
            session.commit(pending.intentSender)
        }
        null
    }

    /** From [InstallResultReceiver]. */
    internal fun onInstallStatus(status: Int, confirm: Intent?, message: String?) {
        Log.i(TAG, "Install status $status ${message.orEmpty()}")
        if (status == PackageInstaller.STATUS_PENDING_USER_ACTION) {
            confirmIntent = confirm
            if (confirm != null && !showConfirmation()) Log.w(TAG, "Couldn't open the install confirmation")
            send(UpdateEvent.InstallStatus(status))
            return
        }
        if (status == PackageInstaller.STATUS_SUCCESS && Build.VERSION.SDK_INT < Build.VERSION_CODES.Q) {
            // This runs in the new version's process (Android stopped the old one to replace it).
            // Android 10+ blocks activity starts from a background receiver (seen on API 35:
            // BAL_BLOCK), so there the user reopens Novarr from the launcher; 8 and 9 allow it.
            val launch = context.packageManager.getLaunchIntentForPackage(context.packageName)
            launch?.let { runCatching { context.startActivity(it.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) } }
        }
        send(UpdateEvent.InstallStatus(status))
        // Done either way (installed, cancelled or failed): the file and session aren't needed.
        confirmIntent = null
        sessionId = null
        job = null
        scope.launch(Dispatchers.IO) { apk.delete() }
    }

    private fun fail(reason: UpdateFailure) {
        abandonSession()
        confirmIntent = null
        send(UpdateEvent.Fail(reason))
        scope.launch(Dispatchers.IO) { apk.delete() }
    }

    private fun abandonSession() {
        val id = sessionId ?: return
        sessionId = null
        runCatching { context.packageManager.packageInstaller.abandonSession(id) }
    }

    /** The release being installed: the browser fallback opens its link. */
    val currentUrl: String? get() = release?.url

    private companion object {
        const val TAG = "NovarrUpdate"
    }
}

/** Receives the committed session's status (exported=false; PackageInstaller calls it through our PendingIntent). */
class InstallResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)
        val confirm = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            intent.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java)
        } else {
            @Suppress("DEPRECATION")
            intent.getParcelableExtra(Intent.EXTRA_INTENT)
        }
        val message = intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE)
        val app = context.applicationContext as? NovarrApplication ?: return
        app.model.updater.onInstallStatus(status, confirm, message)
    }
}

/** MY_PACKAGE_REPLACED: the new version is installed, so its "newer version" notification goes. */
class UpdateInstalledReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_MY_PACKAGE_REPLACED) return
        val app = context.applicationContext as? NovarrApplication ?: return
        val pending = goAsync()
        app.model.scope.launch {
            try {
                app.model.clearInstalledUpdateNotice()
            } finally {
                pending.finish()
            }
        }
    }
}
