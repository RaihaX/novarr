package studio.visns.novarr.store

import java.io.File
import java.util.UUID
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.rethrowIfCancellation

/** A read-state write, as sent to the server. */
@Serializable
sealed class WriteKind {
    /** POST chapters/{id}/progress: a scroll/page position, and/or "read" when the end was reached. */
    @Serializable
    @SerialName("progress")
    data class Progress(val chapterId: Int, val progress: Int?, val read: Boolean) : WriteKind()

    /** POST chapters/{id}/read: an explicit mark read / unread. */
    @Serializable
    @SerialName("set_read")
    data class SetRead(val chapterId: Int, val read: Boolean) : WriteKind()

    /** POST chapters/bulk-read: mark all / up to here / from here. */
    @Serializable
    @SerialName("bulk_read")
    data class BulkRead(val request: BulkReadRequest) : WriteKind()

    /**
     * Writes that move the series' latest position on the server (bulk
     * marking and marking unread don't): the latest-position contract.
     */
    val movesPosition: Boolean
        get() = when (this) {
            is Progress -> progress != null || read
            is SetRead -> read
            is BulkRead -> false
        }
}

@Serializable
data class PendingWrite(
    val id: String,
    val kind: WriteKind,
    /**
     * Device wall-clock time (ms) when the write was recorded. Only
     * differences against the same clock are sent (`age`), so clock skew
     * between phone and server doesn't matter.
     */
    val recordedAt: Long,
    /** The series this write belongs to (for the latest-position check); never sent. */
    val novelId: Int? = null,
) {
    /** Milliseconds since this write was recorded, never negative. */
    fun age(now: Long): Long = (now - recordedAt).coerceAtLeast(0)
}

/**
 * A persistent FIFO of read-state writes. Every write goes through here: it
 * is persisted first, then sent; it leaves the queue only when the server
 * accepted it (or rejected it permanently), so nothing is lost offline and
 * nothing is lost on a 401 either.
 */
class WriteQueue(private val file: File, private val now: () -> Long = System::currentTimeMillis) {
    sealed class FlushOutcome {
        /** Queue emptied. `dropped` counts writes the server rejected permanently. */
        data class Drained(val sent: Int, val dropped: Int) : FlushOutcome()

        /** Stopped at a retryable failure; the remaining writes stay queued. */
        data class Stalled(val sent: Int, val remaining: Int) : FlushOutcome()

        /** The sign-in is gone (401). Nothing is dropped: the writes wait for the next sign-in. */
        data class Unauthenticated(val sent: Int, val remaining: Int) : FlushOutcome()

        /** Another flush was already running. */
        data object Busy : FlushOutcome()
    }

    private val lock = Mutex()
    private val flushing = Mutex()
    private var items: MutableList<PendingWrite> = load()

    suspend fun items(): List<PendingWrite> = lock.withLock { items.toList() }

    suspend fun count(): Int = lock.withLock { items.size }

    suspend fun enqueue(kind: WriteKind, novelId: Int? = null) = lock.withLock {
        // A newer scroll position supersedes an older unsent one for the same
        // chapter; read flags and explicit read/unread writes are never merged,
        // so their order is kept exactly.
        if (kind is WriteKind.Progress && !kind.read) {
            items.removeAll { it.kind is WriteKind.Progress && !it.kind.read && it.kind.chapterId == kind.chapterId }
        }
        items.add(PendingWrite(UUID.randomUUID().toString(), kind, now(), novelId))
        persist()
    }

    /**
     * Sends queued writes oldest-first, stopping at the first retryable
     * failure (or a 401) so ordering is preserved.
     */
    suspend fun flush(send: suspend (WriteKind, Long) -> Unit): FlushOutcome {
        if (!flushing.tryLock()) return FlushOutcome.Busy
        try {
            var sent = 0
            var dropped = 0
            while (true) {
                val next = lock.withLock { items.firstOrNull() } ?: break
                try {
                    send(next.kind, next.age(now()))
                    sent++
                    remove(next.id)
                } catch (e: Exception) {
                    e.rethrowIfCancellation()
                    val error = ApiError.from(e)
                    if (error.isUnauthenticated) return FlushOutcome.Unauthenticated(sent, count())
                    if (error.isRetryable) return FlushOutcome.Stalled(sent, count())
                    dropped++
                    remove(next.id)
                }
            }
            return FlushOutcome.Drained(sent, dropped)
        } finally {
            flushing.unlock()
        }
    }

    private suspend fun remove(id: String) = lock.withLock {
        items.removeAll { it.id == id }
        persist()
    }

    private fun load(): MutableList<PendingWrite> =
        runCatching { NovarrJson.decodeFromString(SERIALIZER, file.readText()).toMutableList() }
            .getOrDefault(mutableListOf())

    private fun persist() {
        runCatching {
            file.parentFile?.mkdirs()
            atomicWrite(file, NovarrJson.encodeToString(SERIALIZER, items).toByteArray())
        }
    }

    companion object {
        private val SERIALIZER = ListSerializer(PendingWrite.serializer())
    }
}

/** Write to a temp file then rename, so a crash never leaves half a file. */
internal fun atomicWrite(target: File, bytes: ByteArray) {
    val tmp = File(target.parentFile, ".${target.name}.tmp")
    tmp.writeBytes(bytes)
    if (!tmp.renameTo(target)) {
        target.delete()
        if (!tmp.renameTo(target)) {
            tmp.delete()
            throw java.io.IOException("Could not replace ${target.name}")
        }
    }
}
