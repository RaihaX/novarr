package studio.visns.novarr.playback

import android.app.Notification
import android.content.Context
import android.content.pm.ServiceInfo
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.ForegroundInfo
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkInfo
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import java.io.File
import java.io.FileOutputStream
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import okhttp3.Request
import studio.visns.novarr.NovarrApplication
import studio.visns.novarr.R
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AudiobookPart
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.app.Notifications
import studio.visns.novarr.app.Session
import studio.visns.novarr.store.OfflineStore

/** An audiobook's download, as the UI shows it. */
sealed interface AudiobookDownloadState {
    data object None : AudiobookDownloadState

    /** Queued: waiting for a network ([wifiOnly]: for Wi-Fi) or for its turn. */
    data class Waiting(val wifiOnly: Boolean) : AudiobookDownloadState
    data class Running(val done: Long, val total: Long) : AudiobookDownloadState {
        val fraction: Float get() = if (total <= 0) 0f else (done.toFloat() / total).coerceIn(0f, 1f)
    }
    data class Done(val bytes: Long) : AudiobookDownloadState
    data class Failed(val message: String) : AudiobookDownloadState
}

/**
 * "Download for offline" for audiobooks: one WorkManager job per audiobook
 * (and account), with a foreground notification, resumable (partial files
 * are kept and continued with a Range request), optionally only on Wi-Fi.
 * Files go to the session's offline store; the player uses them when every
 * part is there.
 */
class AudiobookDownloads(private val context: Context, private val app: AppModel) {
    private val work get() = WorkManager.getInstance(context)

    private fun name(session: Session.Active, id: Int) = "audiobook-download-${Integer.toHexString(session.id.hashCode())}-$id"

    fun start(id: Int, title: String) {
        val session = app.active ?: return
        app.scope.launch {
            val wifiOnly = app.prefs.listeningNow().wifiOnlyDownloads
            val request = OneTimeWorkRequestBuilder<AudiobookDownloadWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(if (wifiOnly) NetworkType.UNMETERED else NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .setInputData(workDataOf(KEY_ID to id, KEY_SESSION to session.id, KEY_TITLE to title, KEY_WIFI to wifiOnly))
                .addTag(TAG)
                .build()
            work.enqueueUniqueWork(name(session, id), ExistingWorkPolicy.KEEP, request)
        }
    }

    fun cancel(id: Int) {
        val session = app.active ?: return
        work.cancelUniqueWork(name(session, id))
    }

    /** Cancels a running download and deletes the files (partial ones too). */
    suspend fun remove(id: Int) {
        val session = app.active ?: return
        work.cancelUniqueWork(name(session, id))
        session.store.removeAudiobookDownload(id)
        app.listening.downloadsChanged()
    }

    /** The state of [id]'s download for the current session. */
    fun state(id: Int): Flow<AudiobookDownloadState> {
        val session = app.active ?: return kotlinx.coroutines.flow.flowOf(AudiobookDownloadState.None)
        return combine(work.getWorkInfosForUniqueWorkFlow(name(session, id)), app.listening.downloadsRevision) { infos, _ -> infos }
            .map { infos ->
                val info = infos.firstOrNull()
                val done = session.store.audiobookDownload(id)
                when {
                    info?.state == WorkInfo.State.RUNNING -> {
                        val progress = info.progress
                        AudiobookDownloadState.Running(progress.getLong(KEY_DONE, 0), progress.getLong(KEY_TOTAL, 0))
                    }
                    info?.state == WorkInfo.State.ENQUEUED || info?.state == WorkInfo.State.BLOCKED ->
                        AudiobookDownloadState.Waiting(isWifiOnly(info))
                    done != null -> AudiobookDownloadState.Done(OfflineStore.sizeOf(session.store.audiobookFilesDir(id)))
                    info?.state == WorkInfo.State.FAILED ->
                        AudiobookDownloadState.Failed(info.outputData.getString(KEY_MESSAGE) ?: "The download failed.")
                    else -> AudiobookDownloadState.None
                }
            }
    }

    private fun isWifiOnly(info: WorkInfo): Boolean = info.constraints.requiredNetworkType == NetworkType.UNMETERED

    companion object {
        const val TAG = "audiobook-download"
        const val KEY_ID = "id"
        const val KEY_SESSION = "session"
        const val KEY_TITLE = "title"
        const val KEY_WIFI = "wifiOnly"
        const val KEY_DONE = "done"
        const val KEY_TOTAL = "total"
        const val KEY_MESSAGE = "message"

        /** A file name for a part, by its type (players sniff the content anyway). */
        fun fileName(part: AudiobookPart): String = "part-%03d.%s".format(part.index, extension(part.mime))

        fun extension(mime: String?): String = when (mime?.lowercase()?.substringBefore(';')?.trim()) {
            "audio/mpeg", "audio/mp3" -> "mp3"
            "audio/mp4", "audio/m4a", "audio/x-m4a" -> "m4a"
            "audio/x-m4b", "audio/m4b" -> "m4b"
            "audio/aac", "audio/aacp" -> "aac"
            "audio/ogg", "application/ogg" -> "ogg"
            "audio/opus" -> "opus"
            "audio/flac", "audio/x-flac" -> "flac"
            else -> "audio"
        }
    }
}

/** Downloads one audiobook's parts into the offline store; [AudiobookDownloads] schedules it. */
class AudiobookDownloadWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    private val app: AppModel get() = (applicationContext as NovarrApplication).model
    private val id get() = inputData.getInt(AudiobookDownloads.KEY_ID, 0)
    private val title get() = inputData.getString(AudiobookDownloads.KEY_TITLE) ?: "Audiobook"

    override suspend fun doWork(): Result {
        if (id <= 0) return Result.failure()
        // Started in the background: the app model may still be reading its saved session.
        val session = withTimeoutOrNull(20_000) {
            withContext(Dispatchers.Main) { app.session.first { it !is Session.Starting } }
        } as? Session.Active ?: return if (runAttemptCount < MAX_ATTEMPTS) Result.retry() else fail("Not signed in.")
        // Another account or server since: this download isn't theirs.
        if (session.id != inputData.getString(AudiobookDownloads.KEY_SESSION)) return fail("Signed in as someone else.")

        runCatching { setForeground(foregroundInfo(0, 0)) }
        val detail = try {
            session.client.audiobook(id)
        } catch (e: Exception) {
            val error = ApiError.from(e)
            return if (error.isRetryable && runAttemptCount < MAX_ATTEMPTS) Result.retry() else fail(error.userMessage)
        }
        session.store.save(detail, OfflineStore.Key.audiobook(id))
        val parts = detail.parts
        if (parts.isEmpty()) return fail("This audiobook has no audio files.")
        val dir = session.store.audiobookFilesDir(id).apply { mkdirs() }
        val total = parts.sumOf { it.sizeBytes }
        var done = 0L
        val files = mutableMapOf<Int, String>()
        for (part in parts) {
            val name = AudiobookDownloads.fileName(part)
            val target = File(dir, name)
            try {
                if (!(target.isFile && (part.sizeBytes <= 0 || target.length() == part.sizeBytes))) {
                    download(part, target, session) { written -> report(done + written, total) }
                }
            } catch (e: Exception) {
                if (e is kotlinx.coroutines.CancellationException) throw e
                val error = ApiError.from(e)
                // Partial files stay: the next attempt continues where this one stopped.
                return if ((error.isRetryable || e is IOException) && runAttemptCount < MAX_ATTEMPTS) Result.retry() else fail(error.userMessage)
            }
            files[part.index] = name
            done += target.length()
            report(done, total)
        }
        session.store.saveAudiobookDownload(OfflineStore.AudiobookDownload(id, detail.summary.title, files, done))
        withContext(Dispatchers.Main) { app.listening.downloadsChanged() }
        return Result.success()
    }

    private var lastReport = 0L

    private suspend fun report(done: Long, total: Long) {
        val now = System.currentTimeMillis()
        if (now - lastReport < 750 && done < total) return
        lastReport = now
        setProgress(workDataOf(AudiobookDownloads.KEY_DONE to done, AudiobookDownloads.KEY_TOTAL to total))
        runCatching { setForeground(foregroundInfo(done, total)) }
    }

    /** One part into [target] via a `.partial` file, continuing a partial one with a Range request. */
    private suspend fun download(part: AudiobookPart, target: File, session: Session.Active, onBytes: suspend (Long) -> Unit) = withContext(Dispatchers.IO) {
        val partial = File(target.parentFile, target.name + ".partial")
        val have = if (partial.isFile) partial.length() else 0L
        val builder = Request.Builder().url(part.url)
        AudioDataSource.headersFor(part.url, session.server, session.client.token, session.client.device?.headers.orEmpty())
            .forEach { (k, v) -> builder.header(k, v) }
        if (have > 0) builder.header("Range", "bytes=$have-")
        val call = app.audioHttp.newCall(builder.build())
        call.execute().use { response ->
            val append = response.code == 206 && have > 0
            when {
                response.code == 416 && have > 0 && (part.sizeBytes <= 0 || have >= part.sizeBytes) -> Unit
                response.code == 416 -> {
                    partial.delete()
                    throw IOException("Range not satisfiable")
                }
                !response.isSuccessful -> throw ApiError.Http(response.code, null)
                else -> {
                    var written = if (append) have else 0L
                    FileOutputStream(partial, append).use { out ->
                        val source = response.body.byteStream()
                        val buffer = ByteArray(64 * 1024)
                        while (true) {
                            ensureActive()
                            val read = source.read(buffer)
                            if (read < 0) break
                            out.write(buffer, 0, read)
                            written += read
                            onBytes(written)
                        }
                    }
                }
            }
        }
        if (part.sizeBytes > 0 && partial.length() != part.sizeBytes) throw IOException("Incomplete download")
        target.delete()
        if (!partial.renameTo(target)) throw IOException("Couldn't save the download")
    }

    private fun fail(message: String): Result = Result.failure(workDataOf(AudiobookDownloads.KEY_MESSAGE to message))

    override suspend fun getForegroundInfo(): ForegroundInfo = foregroundInfo(0, 0)

    private fun foregroundInfo(done: Long, total: Long): ForegroundInfo {
        val notification = notification(done, total)
        val notificationId = Notifications.AUDIOBOOK_DOWNLOAD_BASE_ID + id
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ForegroundInfo(notificationId, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC)
        } else {
            ForegroundInfo(notificationId, notification)
        }
    }

    private fun notification(done: Long, total: Long): Notification {
        val percent = if (total > 0) ((done * 100) / total).toInt().coerceIn(0, 100) else 0
        return NotificationCompat.Builder(applicationContext, Notifications.NotifyChannel.DOWNLOADS.id)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle("Downloading $title")
            .setContentText(if (total > 0) "$percent%" else "Starting…")
            .setProgress(100, percent, total <= 0)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .addAction(0, "Cancel", WorkManager.getInstance(applicationContext).createCancelPendingIntent(getId()))
            .build()
    }

    private companion object {
        const val MAX_ATTEMPTS = 8
    }
}
