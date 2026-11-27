package studio.visns.novarr.store

import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.domain.ListeningRecord
import studio.visns.novarr.domain.ListeningSync

/**
 * This device's listening state for one (server, user), in one small JSON
 * file next to the write queue: the latest position per audiobook (sent or
 * not), which of them still have to be sent, and the audiobook last played
 * (restored after the process was killed).
 *
 * Progress is absolute (a whole-book position), so only an audiobook's
 * newest position matters: a new one replaces an older unsent one, and an
 * older one never replaces a newer one (last write wins by recorded time).
 */
class ListeningStore(private val file: File) {
    @Serializable
    data class Entry(val record: ListeningRecord, val pending: Boolean)

    @Serializable
    data class Snapshot(val entries: List<Entry> = emptyList(), val lastAudiobookId: Int? = null)

    sealed class FlushOutcome {
        data class Drained(val sent: Int, val dropped: Int) : FlushOutcome()
        data class Stalled(val sent: Int, val remaining: Int) : FlushOutcome()
        data class Unauthenticated(val sent: Int, val remaining: Int) : FlushOutcome()
        data object Busy : FlushOutcome()
    }

    private val lock = Mutex()
    private val flushing = Mutex()
    private var state: Snapshot = load()

    /** Records a position to send. False when a newer one was already recorded (it is kept). */
    suspend fun record(record: ListeningRecord): Boolean = lock.withLock {
        val current = state.entries.firstOrNull { it.record.audiobookId == record.audiobookId }
        if (!ListeningSync.supersedes(record, current?.record)) return@withLock false
        state = state.copy(entries = state.entries.filter { it.record.audiobookId != record.audiobookId } + Entry(record, pending = true))
        persist()
        true
    }

    /** This device's latest position for [audiobookId] (sent or not). */
    suspend fun latest(audiobookId: Int): ListeningRecord? = lock.withLock {
        state.entries.firstOrNull { it.record.audiobookId == audiobookId }?.record
    }

    /** Positions still to be sent, oldest first. */
    suspend fun pending(): List<ListeningRecord> = lock.withLock {
        state.entries.filter { it.pending }.map { it.record }.sortedBy { it.recordedAt }
    }

    suspend fun pendingCount(): Int = lock.withLock { state.entries.count { it.pending } }

    suspend fun lastAudiobookId(): Int? = lock.withLock { state.lastAudiobookId }

    suspend fun setLastAudiobookId(id: Int?) = lock.withLock {
        if (state.lastAudiobookId == id) return@withLock
        state = state.copy(lastAudiobookId = id)
        persist()
    }

    /**
     * Sends every pending position, oldest first. A position recorded while
     * one was being sent stays pending (only the one sent is marked done).
     * Stops at a retryable failure or a 401, keeping the rest; a position the
     * server refuses outright (a deleted audiobook) is dropped.
     */
    suspend fun flush(send: suspend (ListeningRecord) -> Unit): FlushOutcome {
        if (!flushing.tryLock()) return FlushOutcome.Busy
        try {
            var sent = 0
            var dropped = 0
            for (record in pending()) {
                try {
                    withContext(NonCancellable) {
                        send(record)
                        markSent(record)
                    }
                    sent++
                } catch (e: Exception) {
                    e.rethrowIfCancellation()
                    val error = ApiError.from(e)
                    if (error.isUnauthenticated) return FlushOutcome.Unauthenticated(sent, pendingCount())
                    if (error.isRetryable) return FlushOutcome.Stalled(sent, pendingCount())
                    dropped++
                    markSent(record)
                }
            }
            return FlushOutcome.Drained(sent, dropped)
        } finally {
            flushing.unlock()
        }
    }

    private suspend fun markSent(record: ListeningRecord) = lock.withLock {
        state = state.copy(
            entries = state.entries.map {
                if (it.record == record) it.copy(pending = false) else it
            },
        )
        persist()
    }

    private fun load(): Snapshot =
        runCatching { NovarrJson.decodeFromString(Snapshot.serializer(), file.readText()) }.getOrDefault(Snapshot())

    private fun persist() {
        runCatching {
            file.parentFile?.mkdirs()
            atomicWrite(file, NovarrJson.encodeToString(Snapshot.serializer(), state).toByteArray())
        }
    }

    companion object {
        const val FILE = "listening.json"

        /** Off the main thread: the first read of the file. */
        suspend fun open(file: File): ListeningStore = withContext(Dispatchers.IO) { ListeningStore(file) }
    }
}
