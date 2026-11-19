package studio.visns.novarr.api

import java.security.SecureRandom

/**
 * Who is writing read state, so the server can tell this device apart from
 * the Kindle or the web reader (the "latest position" prompt). Sent as the
 * `X-Novarr-Device` / `X-Novarr-Device-Name` headers, and with sign-in.
 */
data class DeviceIdentity(val id: String, val name: String) {
    val headers: Map<String, String> get() = mapOf(ID_HEADER to id, NAME_HEADER to name)

    companion object {
        const val ID_HEADER = "X-Novarr-Device"
        const val NAME_HEADER = "X-Novarr-Device-Name"

        /** Every client prefixes its ids (`k_` Kindle, `i_` iOS, `a_` Android) to ease debugging. */
        const val ID_PREFIX = "a_"
        private const val MAX_NAME = 40
        private val ID_PATTERN = Regex("^[A-Za-z0-9_-]{8,64}$")
        private const val ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789"

        fun isValidId(id: String): Boolean = ID_PATTERN.matches(id)

        fun makeId(random: java.util.Random = SecureRandom()): String =
            ID_PREFIX + (1..22).map { ALPHABET[random.nextInt(ALPHABET.length)] }.joinToString("")

        /**
         * The persisted id when it fits the contract (8–64 of `[A-Za-z0-9_-]`),
         * else a new one that the caller must save.
         */
        fun resolve(stored: String?, name: String): Pair<DeviceIdentity, Boolean> =
            if (stored != null && isValidId(stored)) {
                DeviceIdentity(stored, cleanName(name)) to false
            } else {
                DeviceIdentity(makeId(), cleanName(name)) to true
            }

        /** A short human name ("Pixel 8"), at most 40 characters. */
        fun cleanName(raw: String?): String {
            val name = raw?.trim()?.replace(Regex("\\s+"), " ").orEmpty()
            return name.ifEmpty { "Android" }.take(MAX_NAME)
        }
    }
}
