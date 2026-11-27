package studio.visns.novarr.app

import android.app.Notification
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.ContentResolver
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.Uri
import android.os.Build
import android.os.ParcelFileDescriptor
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
import java.io.FileInputStream
import java.io.FileNotFoundException
import java.io.IOException
import java.nio.ByteBuffer
import java.util.UUID
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicLong
import kotlin.random.Random
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull
import okhttp3.MediaType
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody
import okio.BufferedSink
import studio.visns.novarr.NovarrApplication
import studio.visns.novarr.R
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.domain.AudiobookUploads
import studio.visns.novarr.domain.AudiobookUploads.Status
import studio.visns.novarr.store.AudiobookUploadEntry
import studio.visns.novarr.store.AudiobookUploadStore
import studio.visns.novarr.store.UploadFileRef

/** One upload as the screens show it: the saved entry plus the worker's live progress. */
data class AudiobookUploadView(
    val entry: AudiobookUploadEntry,
    /** Bytes the server has (live while the worker runs, else as last saved). */
    val doneBytes: Long,
    /** The worker is running, or queued (waiting for a network). */
    val running: Boolean,
    val waitingForNetwork: Boolean,
) {
    val percent: Int get() = AudiobookUploads.percent(doneBytes, entry.totalBytes)
}

/**
 * Audiobook uploads (`/api/v1/audiobook-uploads`): one unique WorkManager
 * job per upload (and account) that creates it on the server, streams every
 * file in chunks (three at a time, each retried with backoff), resumes from
 * the chunks the server says it has, then completes it and waits for the
 * server's verdict. Mirrors playback/AudiobookDownloads: a foreground data
 * sync with a progress notification, optionally only on Wi-Fi. State lives in
 * the account's [AudiobookUploadStore], so a restart carries on.
 */
class AudiobookUploadManager(private val context: Context, private val app: AppModel) {
    private val work get() = WorkManager.getInstance(context)

    private val _revision = MutableStateFlow(0)
    /** Bumped whenever an entry changes (the worker does it too). */
    val revision = _revision.asStateFlow()

    fun changed() {
        _revision.value = _revision.value + 1
    }

    private fun store(session: Session.Active) = AudiobookUploadStore(session.store.root)

    /**
     * Saves a new upload and queues it; answers its local id (null when
     * nobody is signed in). Read grants are made persistable where the
     * provider allows it (the system file picker), so a restart can read the
     * files again. A shared file's grant ends with the share screen, so such
     * files are first copied into the app's own storage (deleted once the
     * upload is over); throws [UploadStartException] when that is impossible.
     */
    suspend fun start(files: List<UploadFileRef>, novelId: Int?, bookTitle: String?, note: String?): String? {
        val session = app.active ?: return null
        if (files.isEmpty()) return null
        val localId = UUID.randomUUID().toString()
        val readable = withContext(Dispatchers.IO) { keepReadable(localId, files) }
        val wifiOnly = app.prefs.listeningNow().wifiOnlyUploads
        val now = System.currentTimeMillis()
        val entry = AudiobookUploadEntry(
            localId = localId,
            novelId = novelId?.takeIf { it > 0 },
            bookTitle = bookTitle?.takeIf { it.isNotBlank() },
            note = note?.trim()?.takeIf { it.isNotEmpty() }?.let(AudiobookUploads::clampNote),
            files = readable,
            wifiOnly = wifiOnly,
            createdAt = now,
            updatedAt = now,
        )
        store(session).put(entry)
        enqueue(session.id, entry, ExistingWorkPolicy.KEEP)
        changed()
        return entry.localId
    }

    /** The files as the worker will read them: persisted grants, or copies of shared files. */
    private fun keepReadable(localId: String, files: List<UploadFileRef>): List<UploadFileRef> {
        val needCopy = files.filter { !keepReadAccess(Uri.parse(it.uri)) }
        if (needCopy.isEmpty()) return files
        val dir = copiesDir(context, localId)
        val needed = needCopy.sumOf { it.size } + 200L * 1024 * 1024
        dir.mkdirs()
        if (dir.usableSpace in 0 until needed) {
            dir.deleteRecursively()
            throw UploadStartException("Not enough space on this phone to keep the shared files while they upload. Open Novarr and use Upload an audiobook (on the Listen tab) instead.")
        }
        return try {
            files.mapIndexed { index, file ->
                if (file !in needCopy) return@mapIndexed file
                val target = java.io.File(dir, "%03d-%s".format(index, file.name.filter { it.isLetterOrDigit() || it in "._- " }.take(80)))
                val input = context.contentResolver.openInputStream(Uri.parse(file.uri)) ?: throw IOException("no stream")
                input.use { source -> target.outputStream().use { source.copyTo(it, 256 * 1024) } }
                if (target.length() != file.size) throw IOException("size changed")
                file.copy(uri = Uri.fromFile(target).toString())
            }
        } catch (e: Exception) {
            dir.deleteRecursively()
            throw UploadStartException("Couldn't read the shared files. Save them to your phone first, then pick them in Novarr's Upload an audiobook.")
        }
    }

    /** True when the file can still be read after a restart: a file:// path, or a grant that could be persisted. */
    private fun keepReadAccess(uri: Uri): Boolean {
        if (uri.scheme == ContentResolver.SCHEME_FILE) return true
        if (uri.scheme != ContentResolver.SCHEME_CONTENT) return false
        // Only document-picker grants can be persisted.
        return runCatching { context.contentResolver.takePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION) }.isSuccess
    }

    private fun enqueue(sessionId: String, entry: AudiobookUploadEntry, policy: ExistingWorkPolicy, retryFailed: Boolean = false) {
        val request = OneTimeWorkRequestBuilder<AudiobookUploadWorker>()
            .setConstraints(Constraints.Builder().setRequiredNetworkType(if (entry.wifiOnly) NetworkType.UNMETERED else NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .setInputData(workDataOf(KEY_LOCAL to entry.localId, KEY_SESSION to sessionId, KEY_TITLE to entry.title, KEY_RETRY_FAILED to retryFailed))
            .addTag(TAG)
            .addTag(localTag(entry.localId))
            .build()
        work.enqueueUniqueWork(name(sessionId, entry.localId), policy, request)
    }

    /**
     * Cancels an upload: the running job is replaced by one that tells the
     * server (DELETE: its chunks are deleted) and then forgets the entry.
     */
    suspend fun cancel(localId: String, sessionId: String? = null) {
        val session = app.active ?: return
        if (sessionId != null && sessionId != session.id) return
        val entry = store(session).change(localId) { it.copy(status = Status.CANCELLED.name, message = null) } ?: return
        val request = OneTimeWorkRequestBuilder<AudiobookUploadWorker>()
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .setInputData(workDataOf(KEY_LOCAL to localId, KEY_SESSION to session.id, KEY_TITLE to entry.title, KEY_CANCEL to true))
            .addTag(TAG)
            .addTag(localTag(localId))
            .build()
        work.enqueueUniqueWork(name(session.id, localId), ExistingWorkPolicy.REPLACE, request)
        Notifications.cancel(context, notificationId(localId))
        changed()
    }

    /** Starts a failed (or stalled) upload again, from the chunks the server still has. */
    suspend fun retry(localId: String) {
        val session = app.active ?: return
        val wifiOnly = app.prefs.listeningNow().wifiOnlyUploads
        val entry = store(session).change(localId) { it.copy(status = Status.QUEUED.name, message = null, wifiOnly = wifiOnly) } ?: return
        enqueue(session.id, entry, ExistingWorkPolicy.REPLACE, retryFailed = true)
        changed()
    }

    /** Takes a finished upload off the list (nothing on the server changes). */
    suspend fun remove(localId: String) {
        val session = app.active ?: return
        val entry = store(session).get(localId) ?: return
        if (!entry.state.finished) return
        store(session).remove(localId)
        releaseReadAccess(entry)
        changed()
    }

    private fun releaseReadAccess(entry: AudiobookUploadEntry) = release(context, entry)

    /**
     * Brings the list up to date: an upload waiting for the owner is asked
     * for its status (approved, declined); one that should be running but
     * has no job (another account was signed in meanwhile) is queued again.
     * Old finished entries (30 days) are dropped.
     */
    suspend fun refresh() {
        val session = app.active ?: return
        val store = store(session)
        val infos = withContext(Dispatchers.IO) { runCatching { work.getWorkInfosByTag(TAG).get() }.getOrDefault(emptyList()) }
        val now = System.currentTimeMillis()
        for (entry in store.all()) {
            val live = infos.any { localTag(entry.localId) in it.tags && !it.state.isFinished }
            when {
                entry.state.finished && entry.state != Status.PENDING && now - entry.updatedAt > PRUNE_AFTER_MS -> {
                    store.remove(entry.localId)
                    releaseReadAccess(entry)
                }
                entry.state == Status.CANCELLED && !live -> enqueueCancel(session, entry)
                !entry.state.finished && !live -> enqueue(session.id, entry, ExistingWorkPolicy.KEEP)
                entry.state == Status.PENDING && entry.serverId != null -> {
                    val server = runCatching { session.client.audiobookUpload(entry.serverId) }.getOrNull() ?: continue
                    val status = server.status ?: continue
                    if (status != entry.state && status.finished) {
                        store.change(entry.localId) { it.copy(status = status.name, message = server.message ?: it.message) }
                        if (status != Status.PENDING) releaseReadAccess(entry)
                    }
                }
            }
        }
        changed()
    }

    private fun enqueueCancel(session: Session.Active, entry: AudiobookUploadEntry) {
        val request = OneTimeWorkRequestBuilder<AudiobookUploadWorker>()
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setInputData(workDataOf(KEY_LOCAL to entry.localId, KEY_SESSION to session.id, KEY_TITLE to entry.title, KEY_CANCEL to true))
            .addTag(TAG)
            .addTag(localTag(entry.localId))
            .build()
        work.enqueueUniqueWork(name(session.id, entry.localId), ExistingWorkPolicy.KEEP, request)
    }

    /** This account's uploads, newest first, with live progress. */
    fun uploads(): Flow<List<AudiobookUploadView>> {
        val session = app.active ?: return flowOf(emptyList())
        val store = store(session)
        return combine(work.getWorkInfosByTagFlow(TAG), revision) { infos, _ -> infos }
            .map { infos ->
                store.all().filter { it.state != Status.CANCELLED }.sortedByDescending { it.createdAt }.map { entry ->
                    val info = infos.firstOrNull { localTag(entry.localId) in it.tags && !it.state.isFinished }
                    val liveDone = info?.takeIf { it.state == WorkInfo.State.RUNNING }?.progress?.getLong(KEY_DONE, -1L)?.takeIf { it >= 0 }
                    AudiobookUploadView(
                        entry = entry,
                        doneBytes = liveDone ?: entry.doneBytes,
                        running = info != null,
                        waitingForNetwork = info?.state == WorkInfo.State.ENQUEUED || info?.state == WorkInfo.State.BLOCKED,
                    )
                }
            }
    }

    companion object {
        const val TAG = "audiobook-upload"
        const val KEY_LOCAL = "local"
        const val KEY_SESSION = "session"
        const val KEY_TITLE = "title"
        const val KEY_CANCEL = "cancel"
        const val KEY_RETRY_FAILED = "retryFailed"
        const val KEY_DONE = "done"
        const val KEY_TOTAL = "total"
        const val KEY_MESSAGE = "message"
        const val ACTION_CANCEL = "studio.visns.novarr.action.CANCEL_UPLOAD"
        private const val PRUNE_AFTER_MS = 30L * 24 * 60 * 60 * 1000

        fun name(sessionId: String, localId: String) = "audiobook-upload-${Integer.toHexString(sessionId.hashCode())}-$localId"
        fun localTag(localId: String) = "audiobook-upload-local-$localId"
        fun notificationId(localId: String) = Notifications.AUDIOBOOK_UPLOAD_BASE_ID + (localId.hashCode() and 0xFFFFF)

        /** Copies of shared files for upload [localId] (app storage, never backed up). */
        fun copiesDir(context: Context, localId: String) = java.io.File(context.noBackupFilesDir, "audiobook-uploads/$localId")

        /** The upload no longer needs its files: persisted grants are released and copies deleted. */
        fun release(context: Context, entry: AudiobookUploadEntry) {
            entry.files.forEach { file ->
                val uri = Uri.parse(file.uri)
                if (uri.scheme == ContentResolver.SCHEME_CONTENT) {
                    runCatching { context.contentResolver.releasePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION) }
                }
            }
            runCatching { copiesDir(context, entry.localId).deleteRecursively() }
        }
    }
}

/** An upload that can't start, with the sentence to show. */
class UploadStartException(message: String) : Exception(message)

/** The progress notification's "Cancel": cancels the upload on the server too. */
class AudiobookUploadCancelReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val localId = intent.getStringExtra(AudiobookUploadManager.KEY_LOCAL) ?: return
        val sessionId = intent.getStringExtra(AudiobookUploadManager.KEY_SESSION)
        val app = (context.applicationContext as NovarrApplication).model
        val pending = goAsync()
        app.scope.launch {
            try {
                withTimeoutOrNull(8_000) {
                    app.session.first { it !is Session.Starting }
                    app.audiobookUploads.cancel(localId, sessionId)
                }
            } finally {
                pending.finish()
            }
        }
    }
}

/** Sends one upload ([AudiobookUploadManager] schedules it), or with KEY_CANCEL tells the server it is cancelled. */
class AudiobookUploadWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    private val app: AppModel get() = (applicationContext as NovarrApplication).model
    private val localId get() = inputData.getString(AudiobookUploadManager.KEY_LOCAL).orEmpty()
    private val title get() = inputData.getString(AudiobookUploadManager.KEY_TITLE) ?: "Audiobook"
    private val notificationId get() = AudiobookUploadManager.notificationId(localId)

    /** A server answer that ends the upload (refused, declined…): not retried. */
    private class Final(val status: Status, message: String?) : Exception(message)

    /** The server no longer has this upload (404): start a new one. */
    private class Gone : Exception()

    override suspend fun doWork(): Result {
        if (localId.isEmpty()) return Result.failure()
        val session = withTimeoutOrNull(20_000) {
            withContext(Dispatchers.Main) { app.session.first { it !is Session.Starting } }
        } as? Session.Active ?: return if (runAttemptCount < MAX_ATTEMPTS) Result.retry() else Result.failure()
        // Another account or server: the entry is in that account's store; refresh() queues it again when it is back.
        if (session.id != inputData.getString(AudiobookUploadManager.KEY_SESSION)) return Result.failure()
        val store = AudiobookUploadStore(session.store.root)
        return if (inputData.getBoolean(AudiobookUploadManager.KEY_CANCEL, false)) cancel(session, store) else upload(session, store)
    }

    private suspend fun cancel(session: Session.Active, store: AudiobookUploadStore): Result {
        val entry = store.get(localId) ?: return Result.success()
        entry.serverId?.let { id ->
            try {
                session.client.cancelAudiobookUpload(id)
            } catch (e: Exception) {
                if (e is CancellationException) throw e
                val error = ApiError.from(e)
                if (error.isRetryable && runAttemptCount < MAX_ATTEMPTS) return Result.retry()
                // Not retryable (or gave up): the server's nightly tidy-up removes abandoned uploads.
            }
        }
        store.remove(localId)
        AudiobookUploadManager.release(applicationContext, entry)
        Notifications.cancel(applicationContext, notificationId)
        changed()
        return Result.success()
    }

    private suspend fun changed() = withContext(Dispatchers.Main) { app.audiobookUploads.changed() }

    private suspend fun upload(session: Session.Active, store: AudiobookUploadStore): Result {
        var entry = store.get(localId) ?: return Result.success()
        if (entry.state.finished) return Result.success()
        val client = session.client
        val total = entry.totalBytes
        runCatching { setForeground(foregroundInfo(entry.doneBytes, total)) }
        var retryFailed = inputData.getBoolean(AudiobookUploadManager.KEY_RETRY_FAILED, false)
        var restarted = false
        try {
            checkFiles(entry)
            var rounds = 0
            // Chunks complete asked for again (422 checksum / incomplete), sent in the next round.
            var resend: Map<Int, Set<Int>> = emptyMap()
            while (true) {
                if (++rounds > MAX_ROUNDS) throw IOException("The server keeps asking for the same chunks.")
                // 1. Create it on the server (once; the id is saved before anything else).
                if (entry.serverId == null) {
                    val created = client.createAudiobookUpload(entry.files.map { it.name to it.size }, entry.novelId, entry.note)
                    entry = save(store) { it.copy(serverId = created.id, chunkSize = AudiobookUploads.chunkSize(created.chunkSize), status = Status.UPLOADING.name, doneBytes = 0) }
                        ?: return Result.success()
                }
                val serverId = entry.serverId!!
                // 2. What it already has (after a drop, a restart, another attempt).
                val server = try {
                    client.audiobookUpload(serverId)
                } catch (e: ApiError.Http) {
                    if (e.status == 404) null else throw e
                }
                if (server == null || (retryFailed && server.status in setOf(Status.FAILED, Status.DECLINED, Status.CANCELLED))) {
                    if (restarted) throw Final(Status.FAILED, "The server lost this upload. Try again.")
                    restarted = true
                    retryFailed = false
                    entry = save(store) { it.copy(serverId = null, doneBytes = 0) } ?: return Result.success()
                    continue
                }
                retryFailed = false
                when (server.status) {
                    Status.PENDING, Status.APPROVED, Status.DECLINED, Status.FAILED, Status.CANCELLED ->
                        return finish(store, server.status, server.message)
                    Status.COMPLETING -> {
                        if (waitForVerdict(store, client, serverId)) continue else return finishedResult(store)
                    }
                    else -> Unit
                }
                server.chunkSize?.let { AudiobookUploads.chunkSize(it) }?.takeIf { it != entry.chunkSize }?.let { size ->
                    entry = save(store) { it.copy(chunkSize = size) } ?: return Result.success()
                }
                // 3. The missing chunks, three at a time.
                val plan = AudiobookUploads.plan(entry.files.map { it.size }, entry.chunkSize)
                // Plus any chunks complete said to send again (damaged: the server may still list them as present).
                val missing = (AudiobookUploads.missing(plan, server.present, server.doneFiles) + AudiobookUploads.named(plan, resend))
                    .distinctBy { it.file to it.index }
                resend = emptyMap()
                val confirmed = AtomicLong(total - AudiobookUploads.bytesOf(missing))
                entry = save(store) { it.copy(status = Status.UPLOADING.name, doneBytes = confirmed.get()) } ?: return Result.success()
                report(confirmed.get(), total, force = true)
                sendAll(store, client, serverId, entry, missing, confirmed, total)
                // 4. Done: the server assembles, verifies and probes.
                entry = save(store) { it.copy(status = Status.COMPLETING.name, doneBytes = total) } ?: return Result.success()
                report(total, total, force = true)
                val answer = try {
                    client.completeAudiobookUpload(serverId)
                } catch (e: ApiError.Http) {
                    // 422 `checksum` / `incomplete` with `missing`: re-send those chunks and complete again.
                    val named = AudiobookUploads.resendAfterComplete(e.status, e.code, e.body)
                    if (named != null) {
                        resend = named
                        continue
                    }
                    when (e.status) {
                        404 -> throw Gone()
                        // Refused (not audio, a size or checksum mismatch, a limit): the server says why.
                        422, 409 -> throw Final(Status.FAILED, AudiobookUploads.failureMessage(e))
                        else -> throw e
                    }
                } catch (e: ApiError) {
                    // No answer in time (it reads gigabytes): ask how it went.
                    if (!e.isConnectivity) throw e
                    null
                }
                val status = answer?.status
                when {
                    status == Status.UPLOADING -> continue // it still misses chunks: send them
                    status != null && status.finished -> return finish(store, status, answer.message)
                    waitForVerdict(store, client, serverId) -> continue
                    else -> return finishedResult(store)
                }
            }
        } catch (e: Exception) {
            if (e is CancellationException) throw e
            if (store.get(localId)?.state == Status.CANCELLED) return Result.success()
            return when (e) {
                is Final -> finish(store, e.status, e.message)
                is Gone -> {
                    // Gone between the chunks and complete: one fresh start.
                    if (restarted) finish(store, Status.FAILED, "The server lost this upload. Try again.")
                    else {
                        save(store) { it.copy(serverId = null, doneBytes = 0, status = Status.QUEUED.name) }
                        Result.retry()
                    }
                }
                is SourceUnreadable -> finish(store, Status.FAILED, e.message)
                else -> {
                    val error = ApiError.from(e)
                    if (error.isRetryable && runAttemptCount < MAX_ATTEMPTS) {
                        save(store) { it.copy(status = Status.QUEUED.name, message = null) }
                        Result.retry()
                    } else {
                        finish(store, Status.FAILED, AudiobookUploads.failureMessage(error))
                    }
                }
            }
        }
    }

    /** Polls until the server has a verdict; true when it says chunks are missing (send them), false when finished. */
    private suspend fun waitForVerdict(store: AudiobookUploadStore, client: ApiClient, serverId: String): Boolean {
        repeat(POLLS) { attempt ->
            delay(if (attempt == 0) 2_000 else POLL_MS)
            val server = try {
                client.audiobookUpload(serverId)
            } catch (e: ApiError.Http) {
                if (e.status == 404) throw Gone()
                if (!e.isRetryable) throw e
                null
            } catch (e: ApiError) {
                if (!e.isConnectivity) throw e
                null
            } ?: return@repeat
            when (val status = server.status) {
                Status.UPLOADING -> return true
                null, Status.QUEUED, Status.COMPLETING -> Unit
                else -> {
                    finish(store, status, server.message)
                    return false
                }
            }
        }
        // Still checking after a long while: WorkManager tries again later (and asks again).
        throw IOException("Still checking the files")
    }

    private suspend fun finishedResult(store: AudiobookUploadStore): Result {
        val state = store.get(localId)?.state
        return if (state == Status.FAILED || state == Status.DECLINED) Result.failure() else Result.success()
    }

    /** Sends [missing] with up to three chunks in flight; each chunk is retried with backoff before giving up. */
    private suspend fun sendAll(
        store: AudiobookUploadStore,
        client: ApiClient,
        serverId: String,
        entry: AudiobookUploadEntry,
        missing: List<AudiobookUploads.Chunk>,
        confirmed: AtomicLong,
        total: Long,
    ) = coroutineScope {
        if (missing.isEmpty()) return@coroutineScope
        val queue = Channel<AudiobookUploads.Chunk>(Channel.UNLIMITED)
        missing.forEach { queue.trySend(it) }
        queue.close()
        val inFlight = ConcurrentHashMap<AudiobookUploads.Chunk, Long>()
        val ticker = launch {
            while (isActive) {
                delay(1_000)
                report(confirmed.get() + inFlight.values.sum(), total)
            }
        }
        val senders = List(AudiobookUploads.PARALLEL_CHUNKS) {
            launch(Dispatchers.IO) {
                for (chunk in queue) {
                    sendChunk(client, serverId, entry.files[chunk.file], chunk) { written -> inFlight[chunk] = written }
                    inFlight.remove(chunk)
                    val done = confirmed.addAndGet(chunk.length)
                    save(store) { it.copy(doneBytes = done) }
                }
            }
        }
        senders.forEach { it.join() }
        ticker.cancel()
    }

    private suspend fun sendChunk(client: ApiClient, serverId: String, file: UploadFileRef, chunk: AudiobookUploads.Chunk, onBytes: (Long) -> Unit) {
        var attempt = 0
        while (true) {
            val body = openChunk(file, chunk, onBytes)
            try {
                client.putAudiobookChunk(serverId, chunk, body)
                return
            } catch (e: Exception) {
                if (e is CancellationException) throw e
                val error = ApiError.from(e)
                if (error is ApiError.Http && error.status == 404) throw Gone()
                if (!AudiobookUploads.isRetryable(error) || attempt >= CHUNK_TRIES - 1) throw error
                delay(AudiobookUploads.backoffMillis(attempt, Random.nextDouble(), (error as? ApiError.Http)?.retryAfter))
                attempt++
            } finally {
                body.close()
            }
        }
    }

    /** The chunk's bytes, read straight from the file when written (never the whole file in memory). */
    private fun openChunk(file: UploadFileRef, chunk: AudiobookUploads.Chunk, onBytes: (Long) -> Unit): ChunkBody {
        val uri = Uri.parse(file.uri)
        val descriptor = try {
            applicationContext.contentResolver.openFileDescriptor(uri, "r")
        } catch (e: SecurityException) {
            throw SourceUnreadable(file.name)
        } catch (e: FileNotFoundException) {
            throw SourceUnreadable(file.name)
        } ?: throw SourceUnreadable(file.name)
        return ChunkBody(descriptor, chunk, onBytes)
    }

    /** Every file still there, readable, and the size it had when picked. */
    private fun checkFiles(entry: AudiobookUploadEntry) {
        for (file in entry.files) {
            val descriptor = try {
                applicationContext.contentResolver.openFileDescriptor(Uri.parse(file.uri), "r")
            } catch (e: Exception) {
                null
            } ?: throw SourceUnreadable(file.name)
            descriptor.use {
                val size = it.statSize
                if (size >= 0 && size != file.size) throw SourceUnreadable(file.name, changed = true)
            }
        }
    }

    private suspend fun save(store: AudiobookUploadStore, transform: (AudiobookUploadEntry) -> AudiobookUploadEntry): AudiobookUploadEntry? {
        // Cancelled meanwhile: never written over (the cancel job owns it now).
        var result: AudiobookUploadEntry? = null
        store.change(localId) { current -> if (current.state == Status.CANCELLED) current else transform(current) }?.let { result = it }
        changed()
        return result?.takeIf { it.state != Status.CANCELLED }
    }

    private suspend fun finish(store: AudiobookUploadStore, status: Status, message: String?): Result {
        val entry = save(store) { it.copy(status = status.name, message = message, doneBytes = if (status == Status.FAILED) it.doneBytes else it.totalBytes) }
            ?: return Result.success()
        val (heading, text) = when (status) {
            Status.PENDING -> "Audiobook sent" to "${entry.title}: waiting for the owner's approval."
            Status.APPROVED -> "Audiobook added" to "${entry.title} is in the library."
            Status.DECLINED -> "Audiobook declined" to (message ?: "${entry.title} wasn't added.")
            else -> "Upload failed" to (message ?: "${entry.title} couldn't be uploaded.")
        }
        Notifications.post(applicationContext, Notifications.NotifyChannel.UPLOADS, notificationId, heading, text)
        // Sent (or over): the files are no longer needed. A failed one keeps them for "Try again".
        if (status != Status.FAILED) AudiobookUploadManager.release(applicationContext, entry)
        return if (status == Status.FAILED || status == Status.DECLINED) {
            Result.failure(workDataOf(AudiobookUploadManager.KEY_MESSAGE to (message ?: text)))
        } else {
            Result.success()
        }
    }

    private var lastReport = 0L

    private suspend fun report(done: Long, total: Long, force: Boolean = false) {
        val now = System.currentTimeMillis()
        if (!force && now - lastReport < 900) return
        lastReport = now
        setProgress(workDataOf(AudiobookUploadManager.KEY_DONE to done, AudiobookUploadManager.KEY_TOTAL to total))
        runCatching { setForeground(foregroundInfo(done, total)) }
    }

    override suspend fun getForegroundInfo(): ForegroundInfo = foregroundInfo(0, 0)

    private fun foregroundInfo(done: Long, total: Long): ForegroundInfo {
        val notification = notification(done, total)
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ForegroundInfo(notificationId, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC)
        } else {
            ForegroundInfo(notificationId, notification)
        }
    }

    private fun notification(done: Long, total: Long): Notification {
        val percent = AudiobookUploads.percent(done, total)
        val cancelling = inputData.getBoolean(AudiobookUploadManager.KEY_CANCEL, false)
        val cancel = PendingIntent.getBroadcast(
            applicationContext,
            notificationId,
            Intent(applicationContext, AudiobookUploadCancelReceiver::class.java)
                .setAction(AudiobookUploadManager.ACTION_CANCEL)
                .putExtra(AudiobookUploadManager.KEY_LOCAL, localId)
                .putExtra(AudiobookUploadManager.KEY_SESSION, inputData.getString(AudiobookUploadManager.KEY_SESSION)),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        val builder = NotificationCompat.Builder(applicationContext, Notifications.NotifyChannel.UPLOADS.id)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(if (cancelling) "Cancelling the upload" else "Uploading $title")
            .setContentText(
                when {
                    cancelling -> title
                    total > 0 && done >= total -> "Checking the files…"
                    total > 0 -> "$percent%"
                    else -> "Starting…"
                },
            )
            .setProgress(100, percent, cancelling || total <= 0 || done >= total)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setSilent(true)
        if (!cancelling) builder.addAction(0, "Cancel", cancel)
        return builder.build()
    }

    private companion object {
        const val MAX_ATTEMPTS = 10
        const val CHUNK_TRIES = 5
        const val MAX_ROUNDS = 6
        const val POLLS = 120
        const val POLL_MS = 5_000L
    }
}

/** A picked file can't be read any more (access gone, deleted, changed): the upload stops with this sentence. */
class SourceUnreadable(name: String, changed: Boolean = false) : IOException(
    if (changed) "$name changed since it was picked. Pick the files again."
    else "Novarr can no longer read $name. Pick the files again (or share them again).",
)

/**
 * One chunk streamed from an open file: positional reads, so OkHttp can
 * write it again on a retried connection; [onBytes] gets the bytes written
 * so far (from 0 on every write). [close] once the call is over.
 */
private class ChunkBody(
    private val descriptor: ParcelFileDescriptor,
    private val chunk: AudiobookUploads.Chunk,
    private val onBytes: (Long) -> Unit,
) : RequestBody() {
    private val input = FileInputStream(descriptor.fileDescriptor)

    override fun contentType(): MediaType = OCTET
    override fun contentLength(): Long = chunk.length

    override fun writeTo(sink: BufferedSink) {
        val channel = input.channel
        val buffer = ByteBuffer.allocate(BUFFER)
        var position = chunk.offset
        var written = 0L
        onBytes(0)
        while (written < chunk.length) {
            buffer.clear()
            buffer.limit(minOf(BUFFER.toLong(), chunk.length - written).toInt())
            val read = try {
                channel.read(buffer, position)
            } catch (e: IOException) {
                throw e
            } catch (e: Exception) {
                // A pipe or a provider that can't seek: never crash OkHttp's thread.
                throw IOException("Couldn't read the file", e)
            }
            if (read < 0) throw IOException("The file ended early")
            buffer.flip()
            sink.write(buffer)
            position += read
            written += read
            onBytes(written)
        }
    }

    fun close() {
        runCatching { input.close() }
        runCatching { descriptor.close() }
    }

    companion object {
        private const val BUFFER = 64 * 1024
        private val OCTET = "application/octet-stream".toMediaType()
    }
}
