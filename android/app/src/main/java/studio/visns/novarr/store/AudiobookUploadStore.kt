package studio.visns.novarr.store

import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.builtins.ListSerializer
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.domain.AudiobookUploads

/** One picked file: where to read it (a content:// or file:// URI), its name and size as sent. */
@Serializable
data class UploadFileRef(
    val uri: String,
    val name: String,
    val size: Long,
    val mime: String? = null,
)

/**
 * An audiobook upload started on this device, kept so it resumes after the
 * app (or the phone) restarts and so "My uploads" can list it. [localId] is
 * this device's; [serverId] the server's once it was created.
 */
@Serializable
data class AudiobookUploadEntry(
    @SerialName("local_id") val localId: String,
    @SerialName("server_id") val serverId: String? = null,
    @SerialName("chunk_size") val chunkSize: Long = AudiobookUploads.DEFAULT_CHUNK_BYTES,
    @SerialName("novel_id") val novelId: Int? = null,
    @SerialName("book_title") val bookTitle: String? = null,
    val note: String? = null,
    val files: List<UploadFileRef> = emptyList(),
    /** An [AudiobookUploads.Status] name. */
    val status: String = AudiobookUploads.Status.QUEUED.name,
    /** Why it failed or was declined (the server's sentence). */
    val message: String? = null,
    @SerialName("wifi_only") val wifiOnly: Boolean = true,
    @SerialName("created_at") val createdAt: Long = 0,
    @SerialName("updated_at") val updatedAt: Long = 0,
    /** Bytes the server confirmed, as last seen (the list shows it while nothing runs). */
    @SerialName("done_bytes") val doneBytes: Long = 0,
) {
    val totalBytes: Long get() = files.sumOf { it.size }
    val state: AudiobookUploads.Status get() = runCatching { AudiobookUploads.Status.valueOf(status) }.getOrDefault(AudiobookUploads.Status.FAILED)

    /** A title for the list and the notification: the book, else the first file. */
    val title: String get() = bookTitle?.takeIf { it.isNotBlank() }
        ?: files.firstOrNull()?.name?.substringBeforeLast('.')?.takeIf { it.isNotBlank() }
        ?: "Audiobook"
}

/**
 * The account's uploads in `audiobook-uploads.json` under its OfflineStore
 * root (so per server and user, like the write queue, and kept by "clear
 * cache"). One lock for the whole process: the worker and the screens
 * update the same file.
 */
class AudiobookUploadStore(root: File) {
    private val file = File(root, FILE)

    suspend fun all(): List<AudiobookUploadEntry> = lock.withLock { io { read() } }

    suspend fun get(localId: String): AudiobookUploadEntry? = all().firstOrNull { it.localId == localId }

    suspend fun put(entry: AudiobookUploadEntry) = update { list -> list.filter { it.localId != entry.localId } + entry }

    /** Changes one entry (nothing when it is gone); answers the new value. */
    suspend fun change(localId: String, transform: (AudiobookUploadEntry) -> AudiobookUploadEntry): AudiobookUploadEntry? {
        var result: AudiobookUploadEntry? = null
        update { list ->
            list.map { entry ->
                if (entry.localId == localId) transform(entry).copy(updatedAt = System.currentTimeMillis()).also { result = it } else entry
            }
        }
        return result
    }

    suspend fun remove(localId: String) = update { list -> list.filter { it.localId != localId } }

    private suspend fun update(transform: (List<AudiobookUploadEntry>) -> List<AudiobookUploadEntry>) = lock.withLock {
        io {
            val next = transform(read())
            runCatching {
                file.parentFile?.mkdirs()
                atomicWrite(file, NovarrJson.encodeToString(SERIALIZER, next).toByteArray())
            }
            Unit
        }
    }

    private fun read(): List<AudiobookUploadEntry> =
        if (!file.exists()) emptyList() else runCatching { NovarrJson.decodeFromString(SERIALIZER, file.readText()) }.getOrDefault(emptyList())

    private suspend fun <T> io(block: () -> T): T = withContext(Dispatchers.IO) { block() }

    companion object {
        const val FILE = "audiobook-uploads.json"
        private val SERIALIZER = ListSerializer(AudiobookUploadEntry.serializer())
        private val lock = Mutex()
    }
}
