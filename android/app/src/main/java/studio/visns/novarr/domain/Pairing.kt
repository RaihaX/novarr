package studio.visns.novarr.domain

import kotlinx.coroutines.delay
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AuthUser
import studio.visns.novarr.api.PairingStart
import studio.visns.novarr.api.PairingStatus
import studio.visns.novarr.api.rethrowIfCancellation

/**
 * Linking with a code (the KOReader plugin's Auth.Pairing): the app asks
 * for a code, the user types it on the website (Account → Link a device)
 * and the app polls until the server hands over a token.
 */
object PairingRules {
    const val CODE_ALPHABET = "ABCDEFGHJKMNPQRSTUVWXYZ23456789"
    const val CODE_LENGTH = 6
    const val DEFAULT_INTERVAL_S = 3.0
    const val MIN_INTERVAL_S = 2.0
    const val MAX_INTERVAL_S = 30.0
    const val DEFAULT_EXPIRES_IN_S = 600.0
    const val MAX_TOKEN = 1024

    /** 6 characters of [CODE_ALPHABET], any case, spaces ignored. */
    fun validCode(code: String?): Boolean {
        val clean = code?.filterNot { it.isWhitespace() }?.uppercase() ?: return false
        return clean.length == CODE_LENGTH && clean.all { it in CODE_ALPHABET }
    }

    /** "k7m4qx" → "K7M 4QX" (any other length: as given, upper-cased, without spaces). */
    fun format(code: String): String {
        val clean = code.filterNot { it.isWhitespace() }.uppercase()
        return if (clean.length == CODE_LENGTH) clean.take(3) + " " + clean.drop(3) else clean
    }

    /** For a screen reader: one character at a time ("K 7 M, 4 Q X"). */
    fun spoken(code: String): String =
        format(code).split(' ').joinToString(", ") { part -> part.toList().joinToString(" ") }

    /** The poll interval in ms: the server's number clamped to 2–30 s, 3 s when missing or not a number. */
    fun intervalMs(raw: Double?): Long {
        val seconds = raw?.takeIf { !it.isNaN() } ?: DEFAULT_INTERVAL_S
        return (seconds.coerceIn(MIN_INTERVAL_S, MAX_INTERVAL_S) * 1000).toLong()
    }

    /** How long the code lives, in ms: the server's number, 600 s when missing or not positive. */
    fun lifetimeMs(raw: Double?): Long {
        val seconds = raw?.takeIf { !it.isNaN() && it > 0 } ?: DEFAULT_EXPIRES_IN_S
        return (seconds * 1000).toLong()
    }

    /** Can be sent as a bearer token: printable ASCII without spaces, at most [MAX_TOKEN] characters. */
    fun validToken(token: String?): Boolean =
        token != null && token.length in 1..MAX_TOKEN && token.all { it.code in 33..126 }

    fun validSecret(secret: String?): Boolean = secret != null && ApiClient.SAFE_PATH_SEGMENT.matches(secret)

    /** The server's address as people type it: host, plus the port when it is not the scheme's default. */
    fun host(server: String): String {
        val url = server.toHttpUrlOrNull() ?: return server
        val defaultPort = if (url.scheme == "https") 443 else 80
        return if (url.port == defaultPort) url.host else "${url.host}:${url.port}"
    }

    /** The instruction under the code. */
    fun instructions(server: String): String =
        "On a phone or computer where you are signed in, open ${host(server)} → Account → Link a device and enter this code."
}

/** What the link-code screen shows. */
sealed interface PairingState {
    /** Asking the server for a code. */
    data object Starting : PairingState

    /** [code] ("K7M 4QX") is shown until [expiresAt] (epoch ms); the app polls meanwhile. */
    data class Waiting(val code: String, val expiresAt: Long) : PairingState

    /** The code ran out (or the server forgot it): offer a new one. */
    data object Expired : PairingState

    data class Failed(val message: String) : PairingState

    /** The server handed over a token and the app signed in with it. */
    data object Linked : PairingState
}

object PairingMessages {
    const val OFFLINE = "No connection. Try again."
    const val THROTTLED = "Too many link codes were asked for from this network. Wait a while and try again."
    const val NOT_SUPPORTED = "This server can't link devices with a code. Update the server."
    const val BAD_ANSWER = "The server's answer wasn't in the expected format."

    fun startFailed(error: ApiError): String = when {
        error is ApiError.Http && error.status == 429 -> error.serverMessage?.takeIf { it.isNotBlank() } ?: THROTTLED
        error is ApiError.Http && error.status == 404 -> NOT_SUPPORTED
        error is ApiError.Decoding -> BAD_ANSWER
        error.isConnectivity -> OFFLINE
        else -> error.userMessage
    }

    /**
     * Poll errors that only mean "no answer this time" (offline, a timeout,
     * 5xx, 429, an unreadable page): the code is still good, keep polling.
     */
    fun keepsPolling(error: ApiError): Boolean = when (error) {
        is ApiError.Unreachable, ApiError.TimedOut, is ApiError.Decoding -> true
        is ApiError.Http -> error.status >= 500 || error.status == 429 || error.status == 408
        else -> false
    }
}

/**
 * One link code, from asking for it to the token (or expiry). Pure apart
 * from the injected calls, so it is tested with virtual time; run it in a
 * scope that outlives the screen (the app's), or rotation would ask for a
 * second code (the server allows 10 an hour).
 *
 * [now] is epoch ms; [onState] gets each change once (polls that are still
 * pending change nothing, so an e-ink screen draws the code once).
 */
class PairingRun(
    private val start: suspend () -> PairingStart,
    private val poll: suspend (secret: String) -> PairingStatus,
    /** [user] is null when the server left it out (the app then asks auth/me with the token). */
    private val onLinked: suspend (token: String, user: AuthUser?) -> Unit,
    private val now: () -> Long,
    private val onState: (PairingState) -> Unit,
) {
    /** How many polls were sent (tests). */
    var polls = 0
        private set

    suspend fun run() {
        onState(PairingState.Starting)
        val started = try {
            start()
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            onState(PairingState.Failed(PairingMessages.startFailed(ApiError.from(e))))
            return
        }
        if (!PairingRules.validCode(started.code) || !PairingRules.validSecret(started.secret)) {
            onState(PairingState.Failed(PairingMessages.BAD_ANSWER))
            return
        }
        val interval = PairingRules.intervalMs(started.interval)
        val expiresAt = now() + PairingRules.lifetimeMs(started.expiresIn)
        onState(PairingState.Waiting(PairingRules.format(started.code), expiresAt))
        while (true) {
            delay(interval)
            polls++
            val status = try {
                poll(started.secret)
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                val error = ApiError.from(e)
                when {
                    error is ApiError.Http && error.status == 404 -> {
                        onState(PairingState.Expired)
                        return
                    }
                    !PairingMessages.keepsPolling(error) -> {
                        onState(PairingState.Failed(error.userMessage))
                        return
                    }
                }
                null
            }
            when (status?.status) {
                null, PairingStatus.PENDING -> Unit
                PairingStatus.LINKED -> {
                    val token = status.token
                    if (token == null || !PairingRules.validToken(token)) {
                        onState(PairingState.Failed(PairingMessages.BAD_ANSWER))
                        return
                    }
                    try {
                        onLinked(token, status.user)
                    } catch (e: Exception) {
                        e.rethrowIfCancellation()
                        val error = ApiError.from(e)
                        onState(PairingState.Failed(if (error.isConnectivity) PairingMessages.OFFLINE else error.userMessage))
                        return
                    }
                    onState(PairingState.Linked)
                    return
                }
                else -> {
                    onState(PairingState.Failed(PairingMessages.BAD_ANSWER))
                    return
                }
            }
            if (now() >= expiresAt) {
                onState(PairingState.Expired)
                return
            }
        }
    }
}
