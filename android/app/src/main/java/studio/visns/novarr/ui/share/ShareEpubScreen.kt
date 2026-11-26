package studio.visns.novarr.ui.share

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.outlined.CheckCircle
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material.icons.automirrored.outlined.MenuBook
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
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import studio.visns.novarr.app.Session
import studio.visns.novarr.domain.BookUploads
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.formatBytes
import studio.visns.novarr.ui.components.readableWidth
import studio.visns.novarr.ui.theme.success

/**
 * The shared ePubs, an optional note, "Send to Novarr", then what the server
 * did with each one. [onOpenApp] opens Novarr (to sign in), [onDone] closes.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ShareEpubScreen(model: ShareEpubViewModel, onOpenApp: () -> Unit, onOpenRequests: () -> Unit, onDone: () -> Unit) {
    val app = LocalApp.current
    val session by app.session.collectAsStateWithLifecycle()
    // Read so the limits and the email hint follow meta when it answers.
    val meta by app.serverMeta.collectAsStateWithLifecycle()
    val progress by model.progress.collectAsStateWithLifecycle()
    val limits = BookUploads.Limits.from(meta?.bookUploads)

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Send to Novarr") },
                navigationIcon = {
                    IconButton(onClick = onDone) { Icon(Icons.Filled.Close, contentDescription = "Close") }
                },
            )
        },
    ) { padding ->
        val files = model.files
        when {
            session == Session.Starting || files == null -> CenteredProgress(Modifier.fillMaxSize().padding(padding))
            session !is Session.Active -> SignInFirst(Modifier.padding(padding), session is Session.NoServer, onOpenApp)
            else -> LazyColumn(Modifier.fillMaxSize().padding(padding), contentPadding = PaddingValues(bottom = 32.dp)) {
                val phase = model.phase
                if (files.isEmpty()) {
                    item { Note("Nothing to send: share one or more ePub files (.epub) with Novarr.") }
                }
                if (phase is UploadPhase.Answered) {
                    val response = phase.response
                    item { Headline(response.message ?: if (response.sent.isEmpty()) "No books were sent." else "Sent to the owner.") }
                    items(response.sent) { sent ->
                        ResultRow(Icons.Outlined.CheckCircle, MaterialTheme.colorScheme.success, sent.title.ifBlank { "Book" }, "Waiting for the owner's approval")
                    }
                    items(response.refused) { refused ->
                        ResultRow(Icons.Outlined.ErrorOutline, MaterialTheme.colorScheme.error, refused.file, refused.message.ifBlank { "Not sent." })
                    }
                    // Never uploaded: refused here before sending.
                    items(files.filter { model.problemOf(it) != null }) { file ->
                        ResultRow(Icons.Outlined.ErrorOutline, MaterialTheme.colorScheme.error, file.picked.name ?: file.uploadName, BookUploads.message(model.problemOf(file)!!, limits))
                    }
                    item {
                        Row(Modifier.readableWidth().padding(16.dp), horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            Button(onClick = onDone) { Text("Done") }
                            if (response.sent.isNotEmpty()) OutlinedButton(onClick = onOpenRequests) { Text("My requests") }
                        }
                    }
                } else {
                    items(files, key = { it.uri.toString() }) { file -> FileRow(file, model.problemOf(file), limits) }
                    val sendable = files.filter { model.problemOf(it) == null }
                    val tooMany = BookUploads.tooMany(sendable.size, limits)
                    if (files.isNotEmpty() && sendable.isEmpty()) item { Note("None of these can be sent.") }
                    if (tooMany != null) item { Note(tooMany, error = true) }
                    item {
                        OutlinedTextField(
                            value = model.note,
                            onValueChange = model::updateNote,
                            label = { Text("Note for the owner (optional)") },
                            supportingText = { Text("${model.note.length} / ${BookUploads.NOTE_MAX}") },
                            enabled = phase !is UploadPhase.Sending,
                            modifier = Modifier.readableWidth().padding(horizontal = 16.dp, vertical = 8.dp),
                        )
                    }
                    meta?.bookUploads?.email?.takeIf { it.isNotBlank() }?.let { email ->
                        val from = (session as Session.Active).account?.user?.email?.takeIf { it.isNotBlank() }
                        item { Note("You can also email ePubs to $email" + (from?.let { " from $it" } ?: "") + ".") }
                    }
                    item {
                        Column(Modifier.readableWidth().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                            when (phase) {
                                UploadPhase.Sending -> {
                                    Text(if (progress >= 1f) "Waiting for the server…" else "Sending ${sendable.size} ePub${if (sendable.size == 1) "" else "s"}…")
                                    if (progress > 0f && progress < 1f) {
                                        LinearProgressIndicator(progress = { progress }, modifier = Modifier.fillMaxWidth())
                                    } else {
                                        LinearProgressIndicator(Modifier.fillMaxWidth())
                                    }
                                }
                                is UploadPhase.Failed -> {
                                    Text(phase.message, color = MaterialTheme.colorScheme.error)
                                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                                        if (phase.signedOut) Button(onClick = onOpenApp) { Text("Open Novarr") } else Button(onClick = model::send) { Text("Try again") }
                                        TextButton(onClick = onDone) { Text("Close") }
                                    }
                                }
                                else -> Button(
                                    onClick = model::send,
                                    enabled = sendable.isNotEmpty() && tooMany == null,
                                    modifier = Modifier.fillMaxWidth(),
                                ) { Text("Send to Novarr") }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun SignInFirst(modifier: Modifier, noServer: Boolean, onOpenApp: () -> Unit) {
    Column(
        modifier.fillMaxSize().padding(24.dp),
        verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(if (noServer) "Set up Novarr first" else "Sign in to Novarr first", style = MaterialTheme.typography.titleLarge)
        Spacer(Modifier.height(8.dp))
        Text(
            if (noServer) "Connect the app to your Novarr server, then share the books again." else "Sign in, then come back here (or share the books again) to send them.",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Spacer(Modifier.height(20.dp))
        Button(onClick = onOpenApp) { Text("Open Novarr") }
    }
}

@Composable
private fun FileRow(file: SharedEpub, problem: BookUploads.Problem?, limits: BookUploads.Limits) {
    val detail = buildList {
        file.picked.size?.let { add(formatBytes(it)) }
        problem?.let { add(BookUploads.message(it, limits)) }
    }.joinToString(" · ")
    ResultRow(
        if (problem == null) Icons.AutoMirrored.Outlined.MenuBook else Icons.Outlined.ErrorOutline,
        if (problem == null) MaterialTheme.colorScheme.onSurfaceVariant else MaterialTheme.colorScheme.error,
        file.picked.name ?: file.uploadName,
        detail.ifBlank { null },
    )
    HorizontalDivider(Modifier.readableWidth().padding(horizontal = 16.dp))
}

@Composable
private fun ResultRow(icon: ImageVector, tint: Color, title: String, detail: String?) {
    Row(Modifier.readableWidth().padding(horizontal = 16.dp, vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(24.dp))
        Column(Modifier.padding(start = 14.dp)) {
            Text(title, style = MaterialTheme.typography.bodyLarge, maxLines = 2, overflow = TextOverflow.Ellipsis)
            detail?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
        }
    }
}

@Composable
private fun Headline(text: String) {
    Text(text, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold, modifier = Modifier.readableWidth().padding(16.dp))
}

@Composable
private fun Note(text: String, error: Boolean = false) {
    Text(
        text,
        style = MaterialTheme.typography.bodyMedium,
        color = if (error) MaterialTheme.colorScheme.error else MaterialTheme.colorScheme.onSurfaceVariant,
        modifier = Modifier.readableWidth().padding(horizontal = 16.dp, vertical = 8.dp),
    )
}
