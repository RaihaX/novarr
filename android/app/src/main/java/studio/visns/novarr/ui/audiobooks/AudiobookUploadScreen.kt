package studio.visns.novarr.ui.audiobooks

import android.content.ContentResolver
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.selection.toggleable
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.outlined.AudioFile
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material3.Button
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.app.AudiobookUploadView
import studio.visns.novarr.app.Session
import studio.visns.novarr.app.UploadStartException
import studio.visns.novarr.domain.AudiobookUploads
import studio.visns.novarr.domain.AudiobookUploads.Status
import studio.visns.novarr.store.UploadFileRef
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.formatBytes
import studio.visns.novarr.ui.components.readableWidth
import studio.visns.novarr.ui.theme.success

/** One picked (or shared) file, with what stops it from being uploaded (null: it can go). */
data class PickedAudio(val uri: Uri, val picked: AudiobookUploads.Picked, val uploadName: String) {
    val problem: AudiobookUploads.Problem? get() = AudiobookUploads.problem(picked)
}

/**
 * "Upload an audiobook": the files (system picker, or shared to Novarr), an
 * optional book (from its page), a note, then a background upload
 * ([studio.visns.novarr.app.AudiobookUploadManager]).
 */
class AudiobookUploadViewModel(
    private val app: AppModel,
    private val resolver: ContentResolver,
    initial: List<Uri>,
    novelId: Int?,
    bookTitle: String?,
    private val intentType: String? = null,
) : ViewModel() {
    var files by mutableStateOf<List<PickedAudio>?>(if (initial.isEmpty()) emptyList() else null)
        private set
    var book by mutableStateOf(novelId?.takeIf { it > 0 }?.let { it to bookTitle.orEmpty() })
        private set
    var note by mutableStateOf("")
        private set
    var starting by mutableStateOf(false)
        private set
    /** Set once the upload is queued. */
    var started by mutableStateOf(false)
        private set
    var failure by mutableStateOf<String?>(null)
        private set

    init {
        if (initial.isNotEmpty()) add(initial)
    }

    fun add(uris: List<Uri>) {
        if (uris.isEmpty()) return
        viewModelScope.launch {
            val known = files.orEmpty()
            val fresh = withContext(Dispatchers.IO) {
                uris.filter { uri -> known.none { it.uri == uri } }.distinct().mapIndexed { i, uri -> describe(known.size + i, uri) }
            }
            files = (known + fresh).sortedWith { a, b -> AudiobookUploads.naturalOrder(a.uploadName, b.uploadName) }
            started = false
        }
    }

    fun remove(file: PickedAudio) {
        files = files.orEmpty().filter { it.uri != file.uri }
    }

    fun clearBook() {
        book = null
    }

    fun updateNote(value: String) {
        note = AudiobookUploads.clampNote(value)
    }

    val sendable: List<PickedAudio> get() = files.orEmpty().filter { it.problem == null }

    val tooMuch: String? get() = sendable.let { AudiobookUploads.tooMuch(it.size, it.sumOf { f -> f.picked.size ?: 0 }) }

    fun upload() {
        if (starting) return
        val chosen = sendable
        if (chosen.isEmpty() || tooMuch != null) return
        starting = true
        failure = null
        viewModelScope.launch {
            val refs = chosen.map { UploadFileRef(it.uri.toString(), it.uploadName, it.picked.size ?: 0, it.picked.mime) }
            val id = try {
                app.audiobookUploads.start(refs, book?.first, book?.second, note)
            } catch (e: UploadStartException) {
                starting = false
                failure = e.message
                return@launch
            }
            starting = false
            if (id == null) {
                failure = "Sign in to Novarr first."
            } else {
                started = true
                files = emptyList()
                note = ""
            }
        }
    }

    /** Name, type and size from the provider (or the file itself for file://); never throws. */
    private fun describe(index: Int, uri: Uri): PickedAudio {
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
                if (size == null) size = runCatching { resolver.openFileDescriptor(uri, "r")?.use { it.statSize.takeIf { s -> s >= 0 } } }.getOrNull()
            }
        } catch (e: Exception) {
            readable = false
        }
        if (name == null) name = uri.lastPathSegment
        val mime = runCatching { resolver.getType(uri) }.getOrNull() ?: intentType?.takeUnless { it.contains('*') }
        val picked = AudiobookUploads.Picked(name, mime, size, readable)
        return PickedAudio(uri, picked, AudiobookUploads.uploadName(name, index, mime))
    }
}

/**
 * The upload form and, below it, this account's uploads. [onClose] leaves
 * (back from a book, or closes the share sheet); [onOpenApp] opens Novarr to
 * sign in (share flow only).
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AudiobookUploadScreen(model: AudiobookUploadViewModel, onClose: () -> Unit, closeIcon: Boolean = false, onOpenApp: (() -> Unit)? = null) {
    val app = LocalApp.current
    val session by app.session.collectAsStateWithLifecycle()
    val offered by app.audiobooksOffered.collectAsStateWithLifecycle()
    val wifiOnly = app.prefs.listening.collectAsState(initial = null).value?.wifiOnlyUploads ?: true
    val scope = rememberCoroutineScope()
    val picker = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris -> model.add(uris) }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Upload an audiobook") },
                navigationIcon = {
                    IconButton(onClick = onClose) {
                        if (closeIcon) Icon(Icons.Filled.Close, contentDescription = "Close") else Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back")
                    }
                },
            )
        },
    ) { padding ->
        val files = model.files
        when {
            session == Session.Starting || files == null -> CenteredProgress(Modifier.fillMaxSize().padding(padding))
            session !is Session.Active -> Message(
                Modifier.padding(padding),
                "Sign in to Novarr first",
                "Sign in, then share the audiobook files again.",
                onOpenApp?.let { open -> "Open Novarr" to open },
            )
            !offered -> Message(Modifier.padding(padding), "No audiobooks on this server", "This Novarr server doesn't have audiobooks switched on.", null)
            else -> LazyColumn(Modifier.fillMaxSize().padding(padding), contentPadding = PaddingValues(bottom = 32.dp)) {
                item {
                    Text(
                        "One .m4b, or every part of one book (.mp3, .m4a, .aac, .ogg, .opus, .flac). It uploads in the background, even with the app closed; the owner approves it before it joins the library.",
                        style = MaterialTheme.typography.bodyMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        modifier = Modifier.readableWidth().padding(16.dp),
                    )
                }
                model.book?.let { (_, title) ->
                    item {
                        Row(Modifier.readableWidth().padding(horizontal = 16.dp), verticalAlignment = Alignment.CenterVertically) {
                            Text("For: ${title.ifBlank { "this book" }}", style = MaterialTheme.typography.titleSmall, modifier = Modifier.weight(1f))
                            TextButton(onClick = model::clearBook) { Text("Not this book") }
                        }
                    }
                }
                if (model.started) {
                    item {
                        Text(
                            "Uploading in the background. You'll see its progress in the notification and below.",
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.success,
                            modifier = Modifier.readableWidth().padding(horizontal = 16.dp, vertical = 8.dp),
                        )
                    }
                }
                items(files, key = { it.uri.toString() }) { file -> FileRow(file) { model.remove(file) } }
                item {
                    Column(Modifier.readableWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                        OutlinedButton(
                            onClick = { picker.launch(arrayOf("audio/*", "application/octet-stream")) },
                            modifier = Modifier.fillMaxWidth(),
                        ) { Text(if (files.isEmpty()) "Choose files" else "Add more files") }
                        val sendable = model.sendable
                        if (sendable.isNotEmpty()) {
                            Text(
                                "${sendable.size} file${if (sendable.size == 1) "" else "s"} · ${formatBytes(sendable.sumOf { it.picked.size ?: 0 })}",
                                style = MaterialTheme.typography.bodyMedium,
                            )
                        }
                        model.tooMuch?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium) }
                        if (files.isNotEmpty()) {
                            OutlinedTextField(
                                value = model.note,
                                onValueChange = model::updateNote,
                                label = { Text("Note for the owner (optional)") },
                                supportingText = { Text("${model.note.length} / ${AudiobookUploads.NOTE_MAX}") },
                                modifier = Modifier.fillMaxWidth(),
                            )
                        }
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .toggleable(value = wifiOnly, role = Role.Switch) { on -> scope.launch { app.prefs.updateListening { it.copy(wifiOnlyUploads = on) } } }
                                .heightIn(min = 48.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text("Upload on Wi-Fi only", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.weight(1f))
                            Switch(checked = wifiOnly, onCheckedChange = null)
                        }
                        model.failure?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                        Button(
                            onClick = model::upload,
                            enabled = sendable.isNotEmpty() && model.tooMuch == null && !model.starting,
                            modifier = Modifier.fillMaxWidth(),
                        ) { Text(if (model.starting) "Preparing…" else "Upload") }
                    }
                }
                item { AudiobookUploadsList(Modifier.readableWidth()) }
            }
        }
    }
}

@Composable
private fun Message(modifier: Modifier, title: String, text: String, action: Pair<String, () -> Unit>?) {
    Column(
        modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(title, style = MaterialTheme.typography.titleLarge)
        Spacer(Modifier.height(8.dp))
        Text(text, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        action?.let { (label, onClick) ->
            Spacer(Modifier.height(20.dp))
            Button(onClick = onClick) { Text(label) }
        }
    }
}

@Composable
private fun FileRow(file: PickedAudio, onRemove: () -> Unit) {
    val problem = file.problem
    val detail = buildList {
        file.picked.size?.let { add(formatBytes(it)) }
        problem?.let { add(AudiobookUploads.message(it)) }
    }.joinToString(" · ")
    Row(Modifier.readableWidth().padding(start = 16.dp, end = 4.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        RowIcon(if (problem == null) Icons.Outlined.AudioFile else Icons.Outlined.ErrorOutline, problem != null)
        Column(Modifier.weight(1f).padding(start = 14.dp)) {
            Text(file.picked.name ?: file.uploadName, style = MaterialTheme.typography.bodyLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
            if (detail.isNotBlank()) {
                Text(detail, style = MaterialTheme.typography.bodySmall, color = if (problem != null) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant)
            }
        }
        IconButton(onClick = onRemove) { Icon(Icons.Filled.Close, contentDescription = "Remove ${file.picked.name ?: "file"}") }
    }
    HorizontalDivider(Modifier.readableWidth().padding(horizontal = 16.dp))
}

@Composable
private fun RowIcon(icon: ImageVector, error: Boolean) {
    Icon(
        icon,
        contentDescription = null,
        tint = if (error) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.size(24.dp),
    )
}

/**
 * "Your audiobook uploads": the ones started on this device for this
 * account, with progress, status and Cancel / Try again / Remove. Nothing
 * when there are none.
 */
@Composable
fun AudiobookUploadsList(modifier: Modifier = Modifier, title: String = "Your audiobook uploads", inset: Dp = 16.dp) {
    val app = LocalApp.current
    val session by app.session.collectAsStateWithLifecycle()
    val uploads by remember(session) { app.audiobookUploads.uploads() }.collectAsState(initial = emptyList())
    val scope = rememberCoroutineScope()
    LaunchedEffect(session) { app.audiobookUploads.refresh() }
    if (uploads.isEmpty()) return
    Column(modifier.padding(vertical = 8.dp)) {
        Text(
            title,
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.padding(horizontal = inset, vertical = 8.dp).semantics { heading() },
        )
        uploads.forEach { upload ->
            UploadRow(
                upload,
                inset,
                onCancel = { scope.launch { app.audiobookUploads.cancel(upload.entry.localId) } },
                onRetry = { scope.launch { app.audiobookUploads.retry(upload.entry.localId) } },
                onRemove = { scope.launch { app.audiobookUploads.remove(upload.entry.localId) } },
            )
            HorizontalDivider(Modifier.padding(horizontal = inset))
        }
    }
}

@Composable
private fun UploadRow(upload: AudiobookUploadView, inset: Dp, onCancel: () -> Unit, onRetry: () -> Unit, onRemove: () -> Unit) {
    val entry = upload.entry
    val state = entry.state
    val scheme = MaterialTheme.colorScheme
    val line = when {
        state == Status.UPLOADING || state == Status.QUEUED -> buildString {
            append("${formatBytes(upload.doneBytes)} of ${formatBytes(entry.totalBytes)} · ${upload.percent}%")
            if (upload.waitingForNetwork) append(if (entry.wifiOnly) " · waiting for Wi-Fi" else " · waiting for a connection")
        }
        else -> "${entry.files.size} file${if (entry.files.size == 1) "" else "s"} · ${formatBytes(entry.totalBytes)}"
    }
    Column(Modifier.fillMaxWidth().padding(horizontal = inset, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(entry.title, style = MaterialTheme.typography.bodyLarge, maxLines = 2, overflow = TextOverflow.Ellipsis, modifier = Modifier.weight(1f))
            Text(
                state.label,
                style = MaterialTheme.typography.labelMedium,
                color = when (state) {
                    Status.APPROVED -> scheme.success
                    Status.DECLINED, Status.FAILED -> scheme.error
                    Status.PENDING -> scheme.tertiary
                    else -> scheme.primary
                },
                modifier = Modifier.padding(start = 8.dp),
            )
        }
        Text(line, style = MaterialTheme.typography.bodySmall, color = scheme.onSurfaceVariant)
        if (state == Status.UPLOADING || state == Status.QUEUED) {
            LinearProgressIndicator(progress = { upload.percent / 100f }, modifier = Modifier.fillMaxWidth())
        } else if (state == Status.COMPLETING) {
            LinearProgressIndicator(Modifier.fillMaxWidth())
        }
        entry.message?.takeIf { state == Status.FAILED || state == Status.DECLINED }?.let {
            Text(it, style = MaterialTheme.typography.bodySmall, color = scheme.error)
        }
        Row(horizontalArrangement = Arrangement.spacedBy(4.dp)) {
            if (!state.finished) TextButton(onClick = onCancel) { Text("Cancel upload") }
            if (state == Status.FAILED) TextButton(onClick = onRetry) { Text("Try again") }
            if (state.finished) TextButton(onClick = onRemove) { Text("Remove from list") }
        }
    }
}
