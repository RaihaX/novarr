package studio.visns.novarr.playback

import android.content.Context
import android.net.Uri
import androidx.annotation.OptIn
import androidx.media3.common.util.UnstableApi
import androidx.media3.datasource.DataSource
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.ResolvingDataSource
import androidx.media3.datasource.okhttp.OkHttpDataSource
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.app.AppModel

/**
 * Where the player reads audio: local files (a download) straight from app
 * storage, and the server's parts over OkHttp. Part URLs are pre-signed, but
 * requests to the reader's own server also carry the bearer token and device
 * headers (added per request, so they always follow the current sign-in, and
 * never sent to any other host).
 */
@OptIn(UnstableApi::class)
object AudioDataSource {
    fun factory(context: Context, app: AppModel): DataSource.Factory {
        val http = OkHttpDataSource.Factory(app.audioHttp).setUserAgent("Novarr-Android")
        val withHeaders = ResolvingDataSource.Factory(http) { spec ->
            val headers = headersFor(spec.uri, app.active?.server, app.active?.client)
            if (headers.isEmpty()) spec else spec.withAdditionalHeaders(headers)
        }
        return DefaultDataSource.Factory(context, withHeaders)
    }

    /** The bearer token and device headers for a URL on [server]; nothing for any other host or scheme. */
    fun headersFor(uri: Uri, server: String?, client: ApiClient?): Map<String, String> =
        headersFor(uri.toString(), server, client?.token, client?.device?.headers.orEmpty())

    fun headersFor(url: String, server: String?, token: String?, device: Map<String, String>): Map<String, String> {
        if (server == null) return emptyMap()
        val target = url.toHttpUrlOrNull() ?: return emptyMap()
        val base = server.toHttpUrlOrNull() ?: return emptyMap()
        if (target.host != base.host || target.port != base.port || target.scheme != base.scheme) return emptyMap()
        return buildMap {
            putAll(device)
            if (!token.isNullOrEmpty()) put(ApiClient.AUTHORIZATION, "Bearer $token")
        }
    }
}
