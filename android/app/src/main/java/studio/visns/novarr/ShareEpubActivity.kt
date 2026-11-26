package studio.visns.novarr

import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.getValue
import androidx.core.content.IntentCompat
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import studio.visns.novarr.app.Notifications
import studio.visns.novarr.domain.Eink
import studio.visns.novarr.domain.EinkMode
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.share.ShareEpubScreen
import studio.visns.novarr.ui.share.ShareEpubViewModel
import studio.visns.novarr.ui.theme.LocalEink
import studio.visns.novarr.ui.theme.NovarrTheme

/**
 * "Share to Novarr" / "Open with Novarr" for ePubs (ACTION_SEND,
 * ACTION_SEND_MULTIPLE, ACTION_VIEW): sends them to the owner's approval
 * queue. Its own activity, so sharing never disturbs whatever the main
 * app has open; it uses the same AppModel (server, account, client).
 */
class ShareEpubActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        val app = (application as NovarrApplication).model
        val uris = sharedUris(intent)
        val type = intent?.type
        setContent {
            val einkMode by app.prefs.einkMode.collectAsStateWithLifecycle(null)
            val eink = Eink.resolve(einkMode ?: EinkMode.AUTO, Build.MANUFACTURER, Build.BRAND)
            CompositionLocalProvider(LocalEink provides eink) {
                NovarrTheme(eink = eink) {
                    CompositionLocalProvider(LocalApp provides app) {
                        // The application's resolver: the read grant is for this app, not just this activity instance.
                        val model = viewModel { ShareEpubViewModel(app, applicationContext.contentResolver, uris, type) }
                        ShareEpubScreen(
                            model,
                            onOpenApp = { openMain(Intent(this, MainActivity::class.java)) },
                            onOpenRequests = {
                                openMain(
                                    Intent(this, MainActivity::class.java)
                                        .setAction(Notifications.ACTION_OPEN_REQUESTS)
                                        .putExtra(Notifications.EXTRA_SECTION, Requests.Section.MINE.name),
                                )
                                finish()
                            },
                            onDone = ::finish,
                        )
                    }
                }
            }
        }
    }

    private fun openMain(intent: Intent) {
        startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP))
    }

    /** The files in a share or "open with", once each, in the order given. */
    private fun sharedUris(intent: Intent?): List<Uri> {
        intent ?: return emptyList()
        val found = buildList {
            when (intent.action) {
                Intent.ACTION_SEND -> IntentCompat.getParcelableExtra(intent, Intent.EXTRA_STREAM, Uri::class.java)?.let(::add)
                Intent.ACTION_SEND_MULTIPLE ->
                    IntentCompat.getParcelableArrayListExtra(intent, Intent.EXTRA_STREAM, Uri::class.java)?.let(::addAll)
                Intent.ACTION_VIEW -> intent.data?.let(::add)
            }
            // Some apps only fill ClipData (which is also what carries the read grant).
            intent.clipData?.let { clip -> for (i in 0 until clip.itemCount) clip.getItemAt(i).uri?.let(::add) }
        }
        return found.filter { it.scheme == "content" || it.scheme == "file" }.distinct()
    }
}
