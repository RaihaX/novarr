package studio.visns.novarr.api

import java.io.IOException
import java.io.InterruptedIOException
import java.net.SocketTimeoutException
import kotlinx.coroutines.CancellationException
import kotlinx.serialization.SerializationException
import kotlinx.serialization.json.JsonObject

/** Everything a call to the server can fail with, with the message the UI shows. */
sealed class ApiError(message: String) : Exception(message) {
    data object InvalidServerUrl : ApiError("invalid server url")

    /** The request never got an HTTP response (no route, DNS, refused, offline...). */
    data class Unreachable(val reason: String) : ApiError(reason)
    data object TimedOut : ApiError("timed out")
    /**
     * A non-2xx answer. [code] is the body's machine-readable `code` (the
     * requests API's `search_busy`, `duplicate`, ...), [retryAfter] the raw
     * Retry-After header, and [body] the parsed JSON body for any extra
     * fields (`novel_id`, `duplicate`); all null when the server sent none.
     */
    data class Http(
        val status: Int,
        val serverMessage: String?,
        val code: String? = null,
        val retryAfter: String? = null,
        val body: JsonObject? = null,
    ) : ApiError("HTTP $status")
    data class Decoding(val reason: String) : ApiError(reason)
    data object NotNovarr : ApiError("not novarr")
    data class ApiTooOld(val serverVersion: Int, val required: Int) : ApiError("api too old")

    /** No response came back at all: fall back to the offline cache and keep queued writes. */
    val isConnectivity: Boolean get() = this is Unreachable || this is TimedOut

    /** 401: the token is missing, invalid or revoked. */
    val isUnauthenticated: Boolean get() = this is Http && status == 401

    /** 403: signed in, but this account may not do that. */
    val isForbidden: Boolean get() = this is Http && status == 403

    /**
     * Whether a queued write that failed this way should be kept and retried.
     * Client errors (validation, unknown id) will never succeed, so they are dropped.
     */
    val isRetryable: Boolean
        get() = when (this) {
            is Unreachable, TimedOut -> true
            is Http -> status >= 500 || status == 408 || status == 429
            else -> false
        }

    val userMessage: String
        get() = when (this) {
            InvalidServerUrl ->
                "That doesn't look like a server address. Try something like http://192.168.0.10:8080."
            is Unreachable ->
                "Couldn't reach the server. Check the address, that the server is running, and that this device is on the same network (or Tailscale)."
            TimedOut -> "The server took too long to answer."
            is Http -> when {
                status == 404 -> "Not found on the server (404)."
                status == 401 -> "You've been signed out. Sign in again to continue."
                status == 403 -> serverMessage.nonBlank() ?: "Your account isn't allowed to do that."
                status == 429 -> serverMessage.nonBlank() ?: "Too many attempts. Wait a minute and try again."
                serverMessage.nonBlank() != null -> "$serverMessage (HTTP $status)"
                else -> "The server answered with an error (HTTP $status)."
            }
            is Decoding -> "The server's answer wasn't in the expected format."
            NotNovarr -> "That address answered, but it isn't a Novarr server (or its API isn't enabled)."
            is ApiTooOld ->
                "This Novarr server speaks API v$serverVersion; the app needs v$required or newer. Update the server."
        }

    companion object {
        fun from(error: Throwable): ApiError = when (error) {
            is ApiError -> error
            is SocketTimeoutException -> TimedOut
            // OkHttp's call timeout surfaces as a bare InterruptedIOException.
            is InterruptedIOException -> if (error.message == "timeout") TimedOut else Unreachable(error.message ?: "Interrupted")
            is IOException -> Unreachable(error.message ?: error.javaClass.simpleName)
            is SerializationException, is IllegalArgumentException -> Decoding(error.message ?: "Bad JSON")
            else -> Unreachable(error.message ?: error.javaClass.simpleName)
        }
    }
}

internal fun String?.nonBlank(): String? = this?.takeIf { it.isNotBlank() }

/** Rethrows coroutine cancellation so `catch (e: Exception)` never swallows it. */
internal fun Throwable.rethrowIfCancellation() {
    if (this is CancellationException) throw this
}
