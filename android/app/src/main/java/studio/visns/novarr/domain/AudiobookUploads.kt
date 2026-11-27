package studio.visns.novarr.domain

import kotlin.math.min
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import studio.visns.novarr.api.ApiError

/**
 * Readers uploading audiobooks (`/api/v1/audiobook-uploads`): which picked
 * files can go, how each file is cut into chunks, which chunks are still
 * missing after the server says what it has, retry timing and what to say
 * about the server's answers. Pure, so it is unit tested; the worker
 * (playback/AudiobookUploads) does the sending.
 */
object AudiobookUploads {
    /** The plan's chunk size; the server's `chunk_size` wins when it sends one. */
    const val DEFAULT_CHUNK_BYTES: Long = 8L * 1024 * 1024
    const val MAX_FILE_BYTES: Long = 3L * 1024 * 1024 * 1024
    const val MAX_TOTAL_BYTES: Long = 6L * 1024 * 1024 * 1024
    const val MAX_FILES = 200
    const val NOTE_MAX = 500
    const val PARALLEL_CHUNKS = 3
    val EXTENSIONS = setOf("m4b", "m4a", "mp3", "aac", "ogg", "opus", "flac")

    // Picked files

    data class Picked(val name: String?, val mime: String?, val size: Long?, val readable: Boolean = true)

    enum class Problem { NOT_AUDIO, TOO_LARGE, EMPTY, UNKNOWN_SIZE, UNREADABLE }

    fun extension(name: String?): String? =
        name?.trim()?.substringAfterLast('/')?.takeIf { it.contains('.') }?.substringAfterLast('.')?.lowercase()?.takeIf { it.isNotEmpty() }

    /** An audiobook file by its extension (the server checks it); a name without one counts by an audio type. */
    fun isAudio(name: String?, mime: String?): Boolean {
        val ext = extension(name)
        if (ext != null) return ext in EXTENSIONS
        return mime?.lowercase()?.startsWith("audio/") == true
    }

    /** What stops this file from being uploaded; null when it can go. The size must be known (it is sent first). */
    fun problem(file: Picked): Problem? = when {
        !file.readable -> Problem.UNREADABLE
        !isAudio(file.name, file.mime) -> Problem.NOT_AUDIO
        file.size == null -> Problem.UNKNOWN_SIZE
        file.size == 0L -> Problem.EMPTY
        file.size > MAX_FILE_BYTES -> Problem.TOO_LARGE
        else -> null
    }

    fun message(problem: Problem): String = when (problem) {
        Problem.NOT_AUDIO -> "Not an audiobook file (.m4b, .m4a, .mp3, .aac, .ogg, .opus or .flac)."
        Problem.TOO_LARGE -> "Too large: each file can be up to 3 GB."
        Problem.EMPTY -> "This file is empty."
        Problem.UNKNOWN_SIZE -> "Couldn't tell this file's size. Save it to your phone first, then pick it from Files."
        Problem.UNREADABLE -> "Couldn't read this file. Save it to your phone first, then pick it from Files."
    }

    /** Null when these sendable files can go together; otherwise why not. */
    fun tooMuch(count: Int, totalBytes: Long): String? = when {
        count > MAX_FILES -> "Up to $MAX_FILES files at once (you picked $count)."
        totalBytes > MAX_TOTAL_BYTES -> "Up to 6 GB at once. Pick fewer files."
        else -> null
    }

    /** The name sent for a file: no folders or quotes, a fallback when blank, the extension kept. */
    fun uploadName(displayName: String?, index: Int, mime: String?): String {
        val base = displayName?.substringAfterLast('/')?.substringAfterLast('\\')?.trim().orEmpty()
            .filter { it >= ' ' && it != '"' }
        if (base.isNotBlank() && extension(base) in EXTENSIONS) return base
        val ext = extensionForMime(mime) ?: "mp3"
        return (base.ifBlank { "part-%03d".format(index + 1) }) + ".$ext"
    }

    fun extensionForMime(mime: String?): String? = when (mime?.lowercase()?.substringBefore(';')?.trim()) {
        "audio/mpeg", "audio/mp3" -> "mp3"
        "audio/mp4", "audio/m4a", "audio/x-m4a" -> "m4a"
        "audio/x-m4b", "audio/m4b" -> "m4b"
        "audio/aac", "audio/aacp" -> "aac"
        "audio/ogg", "application/ogg" -> "ogg"
        "audio/opus" -> "opus"
        "audio/flac", "audio/x-flac" -> "flac"
        else -> null
    }

    fun clampNote(note: String): String = note.take(NOTE_MAX)

    /**
     * The order files go in: a folder of parts picked in any order is sent
     * "01 …", "02 …", "10 …" (numbers compared as numbers).
     */
    fun naturalOrder(a: String, b: String): Int {
        val pattern = Regex("(\\d+)|(\\D+)")
        val left = pattern.findAll(a.lowercase()).map { it.value }.toList()
        val right = pattern.findAll(b.lowercase()).map { it.value }.toList()
        for (i in 0 until min(left.size, right.size)) {
            val x = left[i]
            val y = right[i]
            val c = if (x[0].isDigit() && y[0].isDigit()) {
                x.trimStart('0').length.compareTo(y.trimStart('0').length).takeIf { it != 0 } ?: x.trimStart('0').compareTo(y.trimStart('0'))
            } else {
                x.compareTo(y)
            }
            if (c != 0) return c
        }
        return left.size.compareTo(right.size)
    }

    // Chunks

    /** Chunk [index] of file [file]: bytes [offset, offset + length). */
    data class Chunk(val file: Int, val index: Int, val offset: Long, val length: Long, val fileSize: Long) {
        val end: Long get() = offset + length
        /** `bytes 0-8388607/20000000` (the last byte inclusive). */
        val contentRange: String get() = "bytes $offset-${end - 1}/$fileSize"
    }

    /** A server `chunk_size`: bytes; a small number (1–64) is read as megabytes; nonsense is the default. */
    fun chunkSize(server: Long?): Long = when {
        server == null || server <= 0 -> DEFAULT_CHUNK_BYTES
        server <= 64 -> server * 1024 * 1024
        server < 64 * 1024 -> DEFAULT_CHUNK_BYTES
        else -> server
    }

    fun chunkCount(size: Long, chunkSize: Long): Int = if (size <= 0) 0 else ((size + chunkSize - 1) / chunkSize).toInt()

    /** Every chunk of every file, file by file, in order. */
    fun plan(sizes: List<Long>, chunkSize: Long): List<Chunk> = sizes.flatMapIndexed { file, size ->
        (0 until chunkCount(size, chunkSize)).map { n ->
            val offset = n * chunkSize
            Chunk(file, n, offset, min(chunkSize, size - offset), size)
        }
    }

    /** The chunks the server doesn't have yet ([present]: file index → chunk numbers, [doneFiles]: files it has whole). */
    fun missing(plan: List<Chunk>, present: Map<Int, Set<Int>>, doneFiles: Set<Int> = emptySet()): List<Chunk> =
        plan.filter { it.file !in doneFiles && it.index !in present[it.file].orEmpty() }

    fun bytesOf(chunks: List<Chunk>): Long = chunks.sumOf { it.length }

    /** The codes of a 422 from complete that mean "send these chunks again", not "refused". */
    val RESEND_CODES = setOf("checksum", "incomplete")

    /**
     * A 422 from `POST /complete` with code `checksum` (a chunk arrived
     * damaged; the upload is back to uploading) or `incomplete` (some never
     * arrived), carrying `missing: {"<file index>": [chunk numbers]}`: those
     * chunks, to send (again) before completing once more. Null for any other
     * answer, or when nothing readable is listed (then it is a real refusal).
     */
    fun resendAfterComplete(status: Int, code: String?, body: JsonObject?): Map<Int, Set<Int>>? {
        if (status != 422 || code?.lowercase() !in RESEND_CODES) return null
        val missing = body?.get("missing") ?: (body?.get("errors") as? JsonObject)?.get("missing")
        val byFile: Map<Int, Set<Int>> = when (missing) {
            is JsonObject -> missing.mapNotNull { (k, v) -> k.trim().toIntOrNull()?.takeIf { it >= 0 }?.let { it to chunkSet(v) } }.toMap()
            is JsonArray -> if (missing.all { it is JsonArray || it is JsonObject }) missing.mapIndexed { i, v -> i to chunkSet(v) }.toMap() else emptyMap()
            else -> emptyMap()
        }.filterValues { it.isNotEmpty() }
        return byFile.ifEmpty { null }
    }

    /** The planned chunks named in [named] (file index → chunk numbers): what to re-send, even if the server lists them as present. */
    fun named(plan: List<Chunk>, named: Map<Int, Set<Int>>): List<Chunk> =
        plan.filter { it.index in named[it.file].orEmpty() }

    fun percent(done: Long, total: Long): Int = if (total <= 0) 0 else ((done * 100) / total).toInt().coerceIn(0, 100)

    // Retries

    /**
     * Wait before retry [attempt] (0-based): 1 s, 2 s, 4 s … up to 60 s, with
     * up to 25% taken off at random ([jitter] 0..1) so three parallel chunks
     * don't retry in step. A Retry-After in seconds wins (capped at 5 min).
     */
    fun backoffMillis(attempt: Int, jitter: Double = 0.0, retryAfter: String? = null, baseMs: Long = 1_000, maxMs: Long = 60_000): Long {
        retryAfter?.trim()?.toLongOrNull()?.takeIf { it >= 0 }?.let { return min(it * 1000, 300_000) }
        val exp = baseMs * (1L shl attempt.coerceIn(0, 20))
        val capped = min(exp, maxMs)
        return (capped - (capped * 0.25 * jitter.coerceIn(0.0, 1.0)).toLong()).coerceAtLeast(0)
    }

    /** Worth trying the same chunk again: no answer, a timeout, 408/429/5xx. */
    fun isRetryable(error: ApiError): Boolean = error.isRetryable

    // The server's answers

    /** Statuses: the server's `uploading|pending|approved|declined|failed`, plus the app's own while it works. */
    enum class Status(val label: String, val finished: Boolean) {
        QUEUED("Waiting to upload", false),
        UPLOADING("Uploading", false),
        COMPLETING("Checking the files", false),
        PENDING("Waiting for the owner", true),
        APPROVED("In the library", true),
        DECLINED("Declined", true),
        FAILED("Failed", true),
        CANCELLED("Cancelled", true);

        companion object {
            fun fromServer(value: String?): Status? = when (value?.trim()?.lowercase()) {
                "uploading", "receiving" -> UPLOADING
                "assembling", "processing", "verifying", "probing", "completing" -> COMPLETING
                "pending", "waiting", "review" -> PENDING
                "approved", "imported", "available", "done" -> APPROVED
                "declined", "rejected" -> DECLINED
                "failed", "refused", "error" -> FAILED
                "cancelled", "canceled" -> CANCELLED
                else -> null
            }
        }
    }

    /** One file as the server reports it: [present] the chunk numbers it has, [done] it has the whole file. */
    data class ServerFile(val index: Int, val present: Set<Int>, val done: Boolean)

    /** `GET /audiobook-uploads/{id}` (or another answer carrying the upload), decoded leniently. */
    data class ServerUpload(
        val id: String?,
        val status: Status?,
        val rawStatus: String?,
        val chunkSize: Long?,
        val files: List<ServerFile>,
        val message: String?,
        val novelId: Int?,
    ) {
        val present: Map<Int, Set<Int>> get() = files.associate { it.index to it.present }
        val doneFiles: Set<Int> get() = files.filter { it.done }.map { it.index }.toSet()
    }

    /**
     * Reads an upload from `{upload: {...}}` or the object itself. Chunks may
     * come per file (`files[i].chunks|present|received|present_chunks|
     * received_chunks|chunks_present`, as an array of numbers or an object
     * of number → true) or at the top (`chunks|present` keyed by file index,
     * or an array of arrays). `done|complete|completed: true` means the whole
     * file is there. Anything unreadable is simply "nothing present"
     * (re-sending a chunk is harmless).
     */
    fun parseUpload(element: JsonElement?): ServerUpload? {
        val root = element as? JsonObject ?: return null
        val upload = root["upload"] as? JsonObject ?: root
        val files = mutableMapOf<Int, ServerFile>()
        (upload["files"] as? JsonArray)?.forEachIndexed { position, item ->
            val file = item as? JsonObject ?: return@forEachIndexed
            val index = file["index"].int() ?: position
            val present = CHUNK_KEYS.firstNotNullOfOrNull { key -> file[key]?.let(::chunkSet) }.orEmpty()
            val done = DONE_KEYS.any { file[it].flag() }
            files[index] = ServerFile(index, present, done)
        }
        listOf("chunks", "present", "present_chunks", "received_chunks").firstNotNullOfOrNull { upload[it] }?.let { top ->
            val byFile: Map<Int, Set<Int>> = when (top) {
                is JsonObject -> top.mapNotNull { (k, v) -> k.trim().toIntOrNull()?.let { it to chunkSet(v) } }.toMap()
                is JsonArray -> if (top.all { it is JsonArray || it is JsonObject }) top.mapIndexed { i, v -> i to chunkSet(v) }.toMap() else emptyMap()
                else -> emptyMap()
            }
            byFile.forEach { (index, set) ->
                val known = files[index]
                files[index] = ServerFile(index, (known?.present.orEmpty()) + set, known?.done ?: false)
            }
        }
        val rawStatus = upload["status"].string()
        return ServerUpload(
            id = upload["id"].string() ?: upload["upload_id"].string(),
            status = Status.fromServer(rawStatus),
            rawStatus = rawStatus,
            chunkSize = (upload["chunk_size"] ?: upload["chunk_bytes"]).long(),
            files = files.values.sortedBy { it.index },
            message = MESSAGE_KEYS.firstNotNullOfOrNull { upload[it].string()?.takeIf(String::isNotBlank) }
                ?: root["message"].string()?.takeIf { it.isNotBlank() },
            novelId = upload["novel_id"].int(),
        )
    }

    private val CHUNK_KEYS = listOf("chunks", "present", "received", "present_chunks", "received_chunks", "chunks_present")
    private val DONE_KEYS = listOf("done", "complete", "completed")
    private val MESSAGE_KEYS = listOf("decline_reason", "failure_reason", "failed_reason", "error", "reason")

    private fun chunkSet(value: JsonElement): Set<Int> = when (value) {
        is JsonArray -> value.mapNotNull { it.int() }.filter { it >= 0 }.toSet()
        is JsonObject -> value.filter { (_, v) -> v.flag() }.keys.mapNotNull { it.trim().toIntOrNull() }.filter { it >= 0 }.toSet()
        else -> emptySet()
    }

    private fun JsonElement?.prim(): JsonPrimitive? = (this as? JsonPrimitive)?.takeIf { it !is JsonNull }
    private fun JsonElement?.string(): String? = prim()?.content?.trim()?.takeIf { it.isNotEmpty() }
    private fun JsonElement?.long(): Long? = prim()?.content?.trim()?.let { it.toLongOrNull() ?: it.toDoubleOrNull()?.toLong() }
    private fun JsonElement?.int(): Int? = long()?.takeIf { it in Int.MIN_VALUE..Int.MAX_VALUE }?.toInt()
    private fun JsonElement?.flag(): Boolean = prim()?.content?.trim()?.lowercase().let { it == "true" || it == "1" }

    /** The one sentence for a failed call (create, a chunk, complete). */
    fun failureMessage(error: ApiError): String {
        if (error !is ApiError.Http) return error.userMessage
        return when (error.status) {
            401 -> "You've been signed out. Sign in to Novarr again, then try the upload again."
            403 -> error.serverMessage ?: "Your account can't upload audiobooks."
            404 -> error.serverMessage?.takeIf { error.code != null }
                ?: "This server doesn't take audiobook uploads yet — update Novarr."
            413 -> "The server refused a piece of the upload as too large."
            422, 409, 507 -> BookUploads.validationMessage(error.body) ?: error.serverMessage ?: "The server didn't accept these files."
            else -> error.serverMessage ?: error.userMessage
        }
    }
}
