package studio.visns.novarr.domain

import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.BookUploadsMeta

/**
 * "Share to Novarr": which shared files can be sent, the server's limits,
 * and what to say about each failure. Pure, so it is unit tested.
 */
object BookUploads {
    const val DEFAULT_MAX_MB = 50
    const val DEFAULT_MAX_FILES = 20
    const val NOTE_MAX = 500
    const val EPUB_MIME = "application/epub+zip"
    private const val EXTENSION = ".epub"

    data class Limits(val maxMb: Int, val maxFiles: Int) {
        val maxBytes: Long get() = maxMb.toLong() * 1024 * 1024

        companion object {
            /** The server's limits; the defaults for an older server (or nonsense values). */
            fun from(meta: BookUploadsMeta?): Limits = Limits(
                maxMb = meta?.maxMb?.takeIf { it > 0 } ?: DEFAULT_MAX_MB,
                maxFiles = meta?.maxFiles?.takeIf { it > 0 } ?: DEFAULT_MAX_FILES,
            )
        }
    }

    /** A shared file as read from its provider; [size] null when the provider does not say. */
    data class Picked(val name: String?, val mime: String?, val size: Long?, val readable: Boolean = true)

    enum class Problem { NOT_EPUB, TOO_LARGE, EMPTY, UNREADABLE }

    /**
     * An ePub by its name, or by its type when the provider gives no usable
     * name (some send `application/epub+zip` as "document"). A name with
     * another extension is not an ePub whatever the type says.
     */
    fun isEpub(name: String?, mime: String?): Boolean {
        val trimmed = name?.trim().orEmpty()
        if (trimmed.endsWith(EXTENSION, ignoreCase = true)) return true
        val hasOtherExtension = trimmed.substringAfterLast('/').let { it.contains('.') && !it.endsWith('.') }
        return !hasOtherExtension && mime.equals(EPUB_MIME, ignoreCase = true)
    }

    /** What stops this file from being sent, or null when it can go. */
    fun problem(file: Picked, limits: Limits): Problem? = when {
        !file.readable -> Problem.UNREADABLE
        !isEpub(file.name, file.mime) -> Problem.NOT_EPUB
        file.size == 0L -> Problem.EMPTY
        file.size != null && file.size > limits.maxBytes -> Problem.TOO_LARGE
        else -> null
    }

    fun message(problem: Problem, limits: Limits): String = when (problem) {
        Problem.NOT_EPUB -> "Only ePub files (.epub) can be added."
        Problem.TOO_LARGE -> "Too large: ePubs can be up to ${limits.maxMb} MB."
        Problem.EMPTY -> "This file is empty."
        Problem.UNREADABLE -> "Couldn't read this file. Try saving it first, then share it from Files."
    }

    /** Null when [count] ePubs can go in one upload. */
    fun tooMany(count: Int, limits: Limits): String? =
        if (count > limits.maxFiles) "You can send up to ${limits.maxFiles} ePubs at once (you picked $count). Share fewer and try again." else null

    /**
     * The filename sent with the part: the display name without any folders,
     * always ending in .epub (the server checks the extension).
     */
    fun uploadName(displayName: String?, index: Int): String {
        val base = displayName?.substringAfterLast('/')?.substringAfterLast('\\')?.trim().orEmpty()
            .filter { it >= ' ' && it != '"' }
        val name = base.ifBlank { "book-${index + 1}" }
        return if (name.endsWith(EXTENSION, ignoreCase = true)) name else name + EXTENSION
    }

    /** At most [NOTE_MAX] characters, as the server allows. */
    fun clampNote(note: String): String = note.take(NOTE_MAX)

    /** The failure of an upload that got no per-file answer, as one sentence. */
    fun failureMessage(error: ApiError): String {
        if (error !is ApiError.Http) return error.userMessage
        return when (error.status) {
            404 -> if (error.code == "requests_disabled") {
                error.serverMessage ?: "Requests are switched off on this server, so books can't be sent to the owner."
            } else {
                "This server is too old to accept books from the app — update Novarr."
            }
            401 -> "You've been signed out. Open Novarr, sign in, then share the books again."
            413 -> "The server refused the upload as too large. Send fewer or smaller ePubs."
            422 -> validationMessage(error.body) ?: error.serverMessage ?: "The server didn't accept these files."
            else -> error.serverMessage ?: error.userMessage
        }
    }

    /** Laravel's 422: `message`, else the first sentence under `errors`. */
    fun validationMessage(body: JsonObject?): String? {
        body ?: return null
        (body["message"] as? JsonPrimitive)?.content?.takeIf { it.isNotBlank() }?.let { return it }
        val errors = body["errors"] as? JsonObject ?: return null
        return errors.values.firstNotNullOfOrNull { value ->
            ((value as? JsonArray)?.firstOrNull() as? JsonPrimitive)?.content?.takeIf { it.isNotBlank() }
        }
    }
}
