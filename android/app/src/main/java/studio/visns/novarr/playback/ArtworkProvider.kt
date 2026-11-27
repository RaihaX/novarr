package studio.visns.novarr.playback

import android.content.ContentProvider
import android.content.ContentValues
import android.content.Context
import android.database.Cursor
import android.net.Uri
import android.os.ParcelFileDescriptor
import java.io.File
import java.io.FileNotFoundException
import java.security.MessageDigest
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import okhttp3.OkHttpClient
import okhttp3.Request
import studio.visns.novarr.BuildConfig
import studio.visns.novarr.NovarrApplication

/**
 * Covers for Android Auto, the lock screen and the media notification as
 * content:// URIs. The car loads artwork itself, from another process, and
 * can't be counted on to fetch http (nor to have a network at all while
 * playing a download): this provider hands it a local copy, fetched once
 * (through the app's image client, which never carries credentials) and
 * kept in app storage so downloaded books show their covers offline.
 *
 * Exported read-only so the car's process can open it, but it serves only
 * covers this app registered itself ([Artwork.uri]): a URI names a hash,
 * never an address, so no other app can make it fetch anything.
 */
class ArtworkProvider : ContentProvider() {
    override fun onCreate(): Boolean = true

    override fun openFile(uri: Uri, mode: String): ParcelFileDescriptor {
        if (mode != "r") throw SecurityException("Artwork is read-only.")
        val context = context ?: throw FileNotFoundException()
        val key = Artwork.keyOf(uri) ?: throw FileNotFoundException("Not a cover.")
        val file = Artwork.fetchBlocking(context, key) ?: throw FileNotFoundException("Cover unavailable.")
        return ParcelFileDescriptor.open(file, ParcelFileDescriptor.MODE_READ_ONLY)
    }

    override fun getType(uri: Uri): String? = if (Artwork.keyOf(uri) != null) "image/*" else null

    override fun query(uri: Uri, projection: Array<out String>?, selection: String?, selectionArgs: Array<out String>?, sortOrder: String?): Cursor? = null
    override fun insert(uri: Uri, values: ContentValues?): Uri? = null
    override fun delete(uri: Uri, selection: String?, selectionArgs: Array<out String>?): Int = 0
    override fun update(uri: Uri, values: ContentValues?, selection: String?, selectionArgs: Array<out String>?): Int = 0
}

/** The cover store behind [ArtworkProvider]. */
object Artwork {
    val AUTHORITY: String = "${BuildConfig.APPLICATION_ID}.artwork"

    /** Kept covers; the oldest go past this many. */
    private const val MAX_FILES = 400

    /** Registered this process: hash → cover address. A file beside each cover keeps it across restarts. */
    private val urls = ConcurrentHashMap<String, String>()

    /** The content:// URI for a cover address (http/https only), registering it; null for none. */
    fun uri(url: String?): Uri? {
        val address = url?.trim()?.takeIf { it.isNotEmpty() } ?: return null
        val parsed = address.toHttpUrlOrNull() ?: return null
        val key = keyFor(parsed.toString())
        urls[key] = parsed.toString()
        return Uri.Builder().scheme("content").authority(AUTHORITY).appendPath("cover").appendPath(key).build()
    }

    fun keyOf(uri: Uri): String? {
        if (uri.authority != AUTHORITY) return null
        val segments = uri.pathSegments
        if (segments.size != 2 || segments[0] != "cover") return null
        return segments[1].takeIf { KEY.matches(it) }
    }

    fun keyFor(url: String): String =
        MessageDigest.getInstance("SHA-1").digest(url.toByteArray()).joinToString("") { "%02x".format(it) }

    private val KEY = Regex("[0-9a-f]{40}")

    private fun dir(context: Context) = File(context.filesDir, "artwork").apply { mkdirs() }

    /** Fetches covers ahead (a downloaded book's, for the car offline); quiet on failure. */
    suspend fun prefetch(context: Context, urls: List<String?>) = withContext(Dispatchers.IO) {
        urls.forEach { url -> uri(url)?.let { keyOf(it) }?.let { runCatching { fetchBlocking(context, it) } } }
        prune(context)
    }

    /** The local cover for [key], fetched now if needed (on a binder thread: blocking is fine). */
    fun fetchBlocking(context: Context, key: String): File? {
        val dir = dir(context)
        val file = File(dir, "$key.img")
        if (file.isFile && file.length() > 0) return file
        val marker = File(dir, "$key.url")
        val url = urls[key] ?: marker.takeIf { it.isFile }?.readText()?.trim() ?: return null
        if (keyFor(url) != key) return null
        val client = client(context) ?: return null
        return try {
            client.newCall(Request.Builder().url(url).build()).execute().use { response ->
                if (!response.isSuccessful) return null
                val body = response.body ?: return null
                val type = body.contentType()
                if (type != null && type.type != "image") return null
                val temp = File(dir, "$key.tmp")
                temp.outputStream().use { out -> body.byteStream().use { it.copyTo(out) } }
                if (temp.length() == 0L || !temp.renameTo(file)) {
                    temp.delete()
                    return null
                }
                marker.writeText(url)
                file
            }
        } catch (e: Exception) {
            null
        }
    }

    private fun client(context: Context): OkHttpClient? =
        (context.applicationContext as? NovarrApplication)?.model?.imageHttp
            ?.newBuilder()?.callTimeout(15, TimeUnit.SECONDS)?.build()

    private fun prune(context: Context) {
        val covers = dir(context).listFiles { f -> f.name.endsWith(".img") }.orEmpty()
        if (covers.size <= MAX_FILES) return
        covers.sortedBy { it.lastModified() }.take(covers.size - MAX_FILES).forEach { cover ->
            cover.delete()
            File(cover.parentFile, cover.name.removeSuffix(".img") + ".url").delete()
        }
    }
}
