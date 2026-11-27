package studio.visns.novarr.domain

import kotlinx.serialization.json.JsonPrimitive
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.SendState

/**
 * "Send to my Kindle / email" (GET / POST /novels/{id}/send): the server
 * queues the send (202) and the app asks again until it is sent or failed.
 * Free of Android so it is unit-tested.
 */
object SendToKindle {
    /** How often a running send is asked again. */
    const val POLL_MS = 2_000L

    /** After this long the app stops asking and says the send goes on without it. */
    const val GIVE_UP_MS = 120_000L

    const val MENU_LABEL = "Send to my Kindle / email"

    /** What the screen shows. */
    sealed interface UiState {
        /** Asking the server to send it. */
        data class Running(val to: String?) : UiState

        /** Sent: the server's message (which carries the Kindle approved-sender reminder). */
        data class Sent(val message: String) : UiState

        data class Failed(val message: String) : UiState

        /** The reader has no address yet: "Add your Kindle or email address on your Account page". */
        data class NoAddress(val message: String, val accountUrl: String?) : UiState

        /** Still going when the app stopped asking. */
        data class StillSending(val message: String) : UiState
    }

    enum class Phase { NONE, RUNNING, SENT, FAILED }

    /** queued / sending → running; sent; failed; none (null). A status this app doesn't know is treated as running (asked again, until [GIVE_UP_MS]). */
    fun phase(state: SendState?): Phase = when (state?.status?.trim()?.lowercase()) {
        null, "" -> Phase.NONE
        "sent" -> Phase.SENT
        "failed" -> Phase.FAILED
        else -> Phase.RUNNING
    }

    const val NO_ADDRESS_MESSAGE = "Add your Kindle or email address on your Account page."
    const val SENT_FALLBACK = "Sent. On a Kindle, make sure your server's address is an approved sender in your Amazon settings."
    const val FAILED_FALLBACK = "The send failed. Try again later."
    const val STILL_SENDING = "Still sending. It should arrive shortly."

    /**
     * The next step after a poll answered [state], [elapsedMs] after the
     * send started: keep polling (null) or what to show.
     */
    fun next(state: SendState, elapsedMs: Long): UiState? = when (phase(state)) {
        Phase.SENT -> UiState.Sent(state.message?.takeIf { it.isNotBlank() } ?: SENT_FALLBACK)
        Phase.FAILED -> UiState.Failed(state.message?.takeIf { it.isNotBlank() } ?: FAILED_FALLBACK)
        // A send the server no longer remembers (expired): it went, as far as anyone can tell.
        Phase.NONE -> UiState.StillSending(STILL_SENDING)
        Phase.RUNNING -> if (elapsedMs >= GIVE_UP_MS) UiState.StillSending(STILL_SENDING) else null
    }

    /** A refused start (422 no_address / manga, 429 too_many, anything else) as what to show. */
    fun refusal(error: Throwable, baseUrl: String?): UiState {
        val api = ApiError.from(error)
        val http = api as? ApiError.Http
        val message = http?.serverMessage?.takeIf { it.isNotBlank() }
        return if (http?.code == "no_address") {
            val url = (http.body?.get("account_url") as? JsonPrimitive)?.takeIf { it.isString }?.content
            UiState.NoAddress(NO_ADDRESS_MESSAGE, resolve(baseUrl, url))
        } else {
            UiState.Failed(message ?: api.userMessage)
        }
    }

    /** An absolute http(s) [url] as is; a path against [baseUrl]; null for anything else. */
    fun resolve(baseUrl: String?, url: String?): String? {
        val u = url?.trim()?.takeIf { it.isNotEmpty() } ?: return null
        if (u.startsWith("http://", ignoreCase = true) || u.startsWith("https://", ignoreCase = true)) return u
        if (!u.startsWith("/")) return null
        val base = baseUrl?.trim()?.trimEnd('/')?.takeIf { it.isNotEmpty() } ?: return null
        return base + u
    }
}
