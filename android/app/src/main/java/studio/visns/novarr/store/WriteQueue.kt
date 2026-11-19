package studio.visns.novarr.store

import java.io.File
import java.util.UUID
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.withContext
import kotlinx.coroutines.sync.withLock
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.BulkReadRequest
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.rethrowIfCancellation

/** A write for the server (read state, or a new highlight), as sent. */
@Serializable
sealed class WriteKind {
    /**
     * POST chapters/{id}/progress: a scroll/page position, and/or "read" when
     * the end was reached. [lookup]: the chapter was opened at a highlight
     * (a look-up), so the server must not make this the series' latest
     * position. Defaults to false, so queues persisted before it decode.
     */
    @Serializable
    @SerialName("progress")
    data class Progress(val chapterId: Int, val progress: Int?, val read: Boolean, val lookup: Boolean = false) : WriteKind()

    /** POST chapters/{id}/read: an explicit mark read / unread. */
    @Serializable
    @SerialName("set_read")
    data class SetRead(val chapterId: Int, val read: Boolean) : WriteKind()

    /** POST chapters/bulk-read: mark all / up to here / from here. */
    @Serializable
    @SerialName("bulk_read")
    data class BulkRead(val request: BulkReadRequest) : WriteKind()

    /**
     * POST highlights: a passage of a chapter (already trimmed and within the
     * server's limits, [Highlights.MAX_LENGTH]) with an optional note.
     */
    @Serializable
    @SerialName("highlight")
    data class CreateHighlight(val chapterId: Int, val excerpt: String, val note: String? = null) : WriteKind()

    /**
     * Writes that move the series' latest position on the server (bulk
     * marking, marking unread, look-ups and highlights don't): the
     * latest-position contract.
     */
    val movesPosition: Boolean
        get() = when (this) {
            is Progress -> !lookup && (progress != null || read)
            is SetRead -> read
            is BulkRead -> false
            is CreateHighlight -> false
        }

    /** Writes that change read state, so list screens should reload once they are queued. */
    val changesReadState: Boolean
        get() = when (this) {
            is Progress -> read
            is SetRead, is BulkRead -> true
            is CreateHighlight -> false
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
 * Whether [writes] hold an unsent progress or read/unread write for chapter
 * [chapterId] (bulk marking doesn't count): then the local copy of that
 * chapter's state is newer than what the server just returned.
 */
fun hasUnsentChapterWrite(writes: List<PendingWrite>, chapterId: Int): Boolean =
    writes.any {
        when (val kind = it.kind) {
            is WriteKind.Progress -> kind.chapterId == chapterId
            is WriteKind.SetRead -> kind.chapterId == chapterId
            is WriteKind.BulkRead, is WriteKind.CreateHighlight -> false
        }
    }

/** The highlights waiting in [writes] for series [novelId] (shown in the reader until the server has them). */
fun unsentHighlights(writes: List<PendingWrite>, novelId: Int): List<WriteKind.CreateHighlight> =
    writes.filter { it.novelId == novelId }.mapNotNull { it.kind as? WriteKind.CreateHighlight }

/**
 * A persistent FIFO of read-state writes (and new highlights). Every write goes through here: it
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
                currentCoroutineContext().ensureActive()
                val next = lock.withLock { items.firstOrNull() } ?: break
                try {
                    // One write is sent and removed as a unit: a cancellation
                    // landing between the two would send it again later.
                    withContext(NonCancellable) {
                        send(next.kind, next.age(now()))
                        remove(next.id)
                    }
                    sent++
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
