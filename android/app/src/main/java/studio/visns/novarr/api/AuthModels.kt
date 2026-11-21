package studio.visns.novarr.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject

// The sign-in API beyond the password (phase 2 of the sign-in hardening):
// the second step after `POST auth/token` and linking with a code (pairing).
// Like Models.kt, everything has a default so an unexpected body never
// crashes decoding; the callers check what they need.

/** The `code` strings of the sign-in error bodies (fixed by the server contract). */
object AuthCodes {
    /** 401 from `POST auth/token`: the password was right, now a sign-in code. */
    const val SECOND_STEP_REQUIRED = "second_step_required"

    /** 403 from `POST auth/token`: this account has no method it can use at this address. */
    const val SECOND_STEP_UNAVAILABLE = "second_step_unavailable"

    /** 422 from `POST auth/token/second-step`. */
    const val WRONG_CODE = "wrong_code"

    /** 404 from the second-step calls: the challenge is gone (expired, used, or 5 wrong codes). */
    const val CHALLENGE_EXPIRED = "challenge_expired"

    /** 429 from `POST auth/token/email-code`. */
    const val THROTTLED = "throttled"
}

/**
 * The 401 `second_step_required` body: [challenge] (64 hex) is what the
 * code is sent with; [methods] only the ones this account can use;
 * [emailHint] ("r•••@a•••.com.au") only with "email".
 */
@Serializable
data class SecondStepRequired(
    val code: String = "",
    val message: String? = null,
    val challenge: String = "",
    @SerialName("expires_in") val expiresIn: Long = DEFAULT_EXPIRES_IN,
    val methods: List<String> = emptyList(),
    @SerialName("email_hint") val emailHint: String? = null,
) {
    companion object {
        /** Seconds a challenge lives when the server gives no number (the contract's 600). */
        const val DEFAULT_EXPIRES_IN = 600L
    }
}

/**
 * An error body of the sign-in calls: `{code, message}`, plus [triesLeft]
 * with 422 `wrong_code`.
 */
@Serializable
data class SignInErrorBody(
    val code: String? = null,
    val message: String? = null,
    @SerialName("tries_left") val triesLeft: Int? = null,
) {
    companion object {
        fun from(body: JsonObject?): SignInErrorBody? =
            body?.let { runCatching { NovarrJson.decodeFromJsonElement(serializer(), it) }.getOrNull() }
    }
}

/** What `POST auth/token` answered: signed in, or a sign-in code is needed. */
sealed interface SignInAnswer {
    data class Signed(val response: AuthTokenResponse) : SignInAnswer
    data class SecondStep(val required: SecondStepRequired) : SignInAnswer
}

/**
 * 201 from `POST auth/pairings`: the [code] to type on the website and the
 * [secret] to poll with. Numbers are nullable so "missing" can fall back to
 * the defaults (domain/Pairing).
 */
@Serializable
data class PairingStart(
    val code: String = "",
    val secret: String = "",
    @SerialName("expires_in") val expiresIn: Double? = null,
    val interval: Double? = null,
)

/** 200 from `GET auth/pairings/{secret}`: "pending", or "linked" with the token and user (once). */
@Serializable
data class PairingStatus(
    val status: String = "",
    val token: String? = null,
    @Serializable(with = AuthUserOrNull::class) val user: AuthUser? = null,
) {
    companion object {
        const val PENDING = "pending"
        const val LINKED = "linked"
    }
}

object AuthUserOrNull : OrNullSerializer<AuthUser>(AuthUser.serializer())
