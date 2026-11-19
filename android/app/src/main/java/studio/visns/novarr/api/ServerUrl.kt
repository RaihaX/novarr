package studio.visns.novarr.api

import okhttp3.HttpUrl.Companion.toHttpUrlOrNull

object ServerUrl {
    /**
     * Turns what the user typed into a base URL without a trailing slash:
     * trims whitespace, assumes `http://` when there is no scheme, drops the
     * query, fragment, trailing slashes and a pasted `/api` or `/api/v1`
     * suffix. Null if no usable http(s) host remains.
     */
    fun normalize(raw: String): String? {
        var text = raw.trim()
        if (text.isEmpty() || text.any { it.isWhitespace() }) return null
        if (!text.contains("://")) text = "http://$text"
        val url = text.toHttpUrlOrNull() ?: return null
        if (url.host.isEmpty()) return null

        var path = url.encodedPath.trimEnd('/')
        for (suffix in listOf("/api/v1", "/api")) {
            if (path.lowercase().endsWith(suffix)) {
                path = path.dropLast(suffix.length)
                break
            }
        }
        path = path.trimEnd('/')

        val built = url.newBuilder()
            .query(null)
            .fragment(null)
            .encodedPath(if (path.isEmpty()) "/" else path)
            .build()
            .toString()
        return built.trimEnd('/')
    }

    /**
     * A filesystem-safe key per server (same rule as the iOS app), so cached
     * data and tokens from one server never show up for another.
     */
    fun storageKey(base: String): String =
        base.lowercase()
            .map { if (it.isLetterOrDigit() && it.code < 128) it else '_' }
            .joinToString("")
            .replace(Regex("__+"), "_")
}
