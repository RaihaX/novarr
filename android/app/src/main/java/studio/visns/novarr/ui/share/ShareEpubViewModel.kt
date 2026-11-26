package studio.visns.novarr.ui.share

import android.content.ContentResolver
import android.net.Uri
import android.provider.OpenableColumns
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import java.io.File
import java.io.FileNotFoundException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.BookUploadResponse
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.BookUploads

/** One shared file, with what stops it from being sent (null: it can go). */
data class SharedEpub(
    val uri: Uri,
    val picked: BookUploads.Picked,
    val uploadName: String,
    val problem: BookUploads.Problem?,
)

sealed interface UploadPhase {
    data object Ready : UploadPhase
    data object Sending : UploadPhase
    data class Answered(val response: BookUploadResponse) : UploadPhase
    data class Failed(val message: String, val signedOut: Boolean) : UploadPhase
}

/**
 * "Share to Novarr": reads the shared files' names and sizes, then sends the
 * ePubs among them in one upload. In viewModelScope, so the upload carries on
 * through rotation and while the screen is briefly in the background.
 */
class ShareEpubViewModel(
    private val app: AppModel,
    private val resolver: ContentResolver,
    private val uris: List<Uri>,
    private val intentType: String?,
) : ViewModel() {
    /** Null while the files are being read. */
    var files by mutableStateOf<List<SharedEpub>?>(null)
        private set
    var note by mutableStateOf("")
        private set
    var phase by mutableStateOf<UploadPhase>(UploadPhase.Ready)
        private set

    private val _progress = MutableStateFlow(0f)
    /** 0..1 of the bytes written; stays 0 when the total is unknown. */
    val progress: StateFlow<Float> = _progress.asStateFlow()

    val limits: BookUploads.Limits get() = BookUploads.Limits.from(app.serverMeta.value?.bookUploads)

    init {
        viewModelScope.launch { files = withContext(Dispatchers.IO) { uris.mapIndexed(::describe) } }
    }

    fun updateNote(value: String) {
        note = BookUploads.clampNote(value)
    }

    /** Re-checked against the latest limits (meta may answer after the files were read). */
    fun problemOf(file: SharedEpub): BookUploads.Problem? = BookUploads.problem(file.picked, limits)

    fun send() {
        if (phase == UploadPhase.Sending) return
        val client = app.active?.client ?: run {
            phase = UploadPhase.Failed("Sign in to Novarr first.", signedOut = true)
            return
        }
        val chosen = files.orEmpty().filter { problemOf(it) == null }
        if (chosen.isEmpty() || BookUploads.tooMany(chosen.size, limits) != null) return
        phase = UploadPhase.Sending
        _progress.value = 0f
        viewModelScope.launch {
            phase = try {
                UploadPhase.Answered(upload(client, chosen))
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                val error = ApiError.from(e)
                UploadPhase.Failed(BookUploads.failureMessage(error), signedOut = error.isUnauthenticated)
            }
        }
    }

    fun retry() {
        phase = UploadPhase.Ready
    }

    private suspend fun upload(client: ApiClient, chosen: List<SharedEpub>): BookUploadResponse {
        val parts = chosen.map { file ->
            ApiClient.UploadFile(file.uploadName, file.picked.size ?: -1L) {
                resolver.openInputStream(file.uri) ?: throw FileNotFoundException("Couldn't open ${file.uploadName}")
            }
        }
        var lastReported = -1
        return client.uploadBooks(parts, note.trim().ifBlank { null }) { written, total ->
            if (total <= 0) return@uploadBooks
            // Whole percents only: this runs for every buffer written.
            val percent = (written * 100 / total).toInt().coerceIn(0, 100)
            if (percent != lastReported) {
                lastReported = percent
                _progress.value = percent / 100f
            }
        }
    }

    /** Name, type and size from the provider (or the file itself for file://); never throws. */
    private fun describe(index: Int, uri: Uri): SharedEpub {
        var name: String? = null
        var size: Long? = null
        var readable = true
        try {
            if (uri.scheme == ContentResolver.SCHEME_FILE) {
                val file = uri.path?.let(::File)
                name = file?.name
                size = file?.takeIf { it.isFile }?.length()
                readable = file?.canRead() == true
            } else {
                resolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME, OpenableColumns.SIZE), null, null, null)?.use { cursor ->
                    if (cursor.moveToFirst()) {
                        val nameColumn = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                        val sizeColumn = cursor.getColumnIndex(OpenableColumns.SIZE)
                        if (nameColumn >= 0 && !cursor.isNull(nameColumn)) name = cursor.getString(nameColumn)
                        if (sizeColumn >= 0 && !cursor.isNull(sizeColumn)) size = cursor.getLong(sizeColumn).takeIf { it >= 0 }
                    }
                }
            }
        } catch (e: Exception) {
            // SecurityException (no grant), a provider that crashed: shown as unreadable.
            readable = false
        }
        if (name == null) name = uri.lastPathSegment
        val mime = runCatching { resolver.getType(uri) }.getOrNull() ?: intentType?.takeUnless { it.contains('*') }
        val picked = BookUploads.Picked(name, mime, size, readable)
        return SharedEpub(uri, picked, BookUploads.uploadName(name, index), BookUploads.problem(picked, limits))
    }
}
