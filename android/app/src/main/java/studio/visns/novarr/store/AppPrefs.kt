package studio.visns.novarr.store

import android.content.Context
import androidx.datastore.core.DataStore
import androidx.datastore.preferences.core.Preferences
import androidx.datastore.preferences.core.booleanPreferencesKey
import androidx.datastore.preferences.core.edit
import androidx.datastore.preferences.core.floatPreferencesKey
import androidx.datastore.preferences.core.intPreferencesKey
import androidx.datastore.preferences.core.longPreferencesKey
import androidx.datastore.preferences.core.stringPreferencesKey
import androidx.datastore.preferences.preferencesDataStore
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import studio.visns.novarr.api.ServerUrl
import studio.visns.novarr.domain.MangaModeOverride
import studio.visns.novarr.domain.TextReadingMode

private val Context.dataStore: DataStore<Preferences> by preferencesDataStore(name = "novarr")

/** Reader appearance, shared by the reader and Settings. */
data class ReaderSettings(
    val fontSize: Float = 19f,
    val font: ReaderFont = ReaderFont.LITERATA,
    val spacing: ReaderSpacing = ReaderSpacing.NORMAL,
    val margins: ReaderMargins = ReaderMargins.MEDIUM,
    val width: ReaderWidth = ReaderWidth.STANDARD,
    val theme: ReaderTheme = ReaderTheme.SYSTEM,
    val justify: Boolean = false,
    val keepScreenOn: Boolean = false,
    /** Continuous scroll or screen-sized pages; a reader default for every novel on this device. */
    val mode: TextReadingMode = TextReadingMode.SCROLL,
) {
    companion object {
        const val MIN_SIZE = 13f
        const val MAX_SIZE = 34f
    }
}

enum class ReaderTheme(val title: String) { SYSTEM("System"), LIGHT("Light"), SEPIA("Sepia"), DARK("Dark"), BLACK("Black") }
enum class ReaderFont(val title: String) { LITERATA("Literata"), GEIST("Geist"), SERIF("Serif"), SANS("Sans") }

/** Line height as a multiple of the font size. */
enum class ReaderSpacing(val title: String, val lineHeight: Float) {
    COMPACT("Compact", 1.3f), NORMAL("Normal", 1.55f), RELAXED("Relaxed", 1.85f)
}

enum class ReaderMargins(val title: String, val paddingDp: Int) { NARROW("Narrow", 14), MEDIUM("Medium", 24), WIDE("Wide", 40) }

/** Maximum text column width; matters on tablets and in landscape. */
enum class ReaderWidth(val title: String, val maxWidthDp: Int) { NARROW("Narrow", 560), STANDARD("Standard", 680), WIDE("Wide", 860) }

/**
 * App preferences in DataStore: the server, per-server sign-in facts, the
 * device id, reader settings and small UI choices. Never holds a token
 * (that is the CredentialStore's job).
 */
class AppPrefs internal constructor(private val store: DataStore<Preferences>) {
    constructor(context: Context) : this(context.dataStore)

    suspend fun snapshot(): Preferences = store.data.first()

    suspend fun serverUrl(): String? = snapshot()[SERVER_URL]
    suspend fun setServerUrl(url: String) = store.edit { it[SERVER_URL] = url }

    suspend fun deviceId(): String? = snapshot()[DEVICE_ID]
    suspend fun setDeviceId(id: String) = store.edit { it[DEVICE_ID] = id }

    /** Whether the server last said it needs a sign-in, so an offline launch knows before meta answers. */
    suspend fun authRequired(server: String): Boolean? = snapshot()[authKey(server)]
    suspend fun setAuthRequired(server: String, required: Boolean) = store.edit { it[authKey(server)] = required }

    /** The name last used to sign in to this server, to prefill the form. */
    suspend fun lastUsername(server: String): String? = snapshot()[userKey(server)]
    suspend fun setLastUsername(server: String, username: String) = store.edit { it[userKey(server)] = username }

    /** The latest-position `at` (epoch ms) the user said "Stay here" to, per server, account and series. */
    suspend fun declinedAt(scope: String, novelId: Int): Long? = snapshot()[declineKey(scope, novelId)]
    suspend fun decline(scope: String, novelId: Int, at: Long) = store.edit { it[declineKey(scope, novelId)] = at }

    /** The app build whose "newer version" banner was dismissed; a newer build shows it again. */
    val dismissedUpdateBuild: Flow<Int?> = store.data.map { it[UPDATE_DISMISSED] }
    suspend fun setDismissedUpdateBuild(build: Int) = store.edit { it[UPDATE_DISMISSED] = build }

    suspend fun string(name: String): String? = snapshot()[stringPreferencesKey(name)]
    suspend fun setString(name: String, value: String) = store.edit { it[stringPreferencesKey(name)] = value }

    val readerSettings: Flow<ReaderSettings> = store.data.map { p ->
        ReaderSettings(
            fontSize = p[R_SIZE] ?: 19f,
            font = enumOr(p[R_FONT], ReaderFont.LITERATA),
            spacing = enumOr(p[R_SPACING], ReaderSpacing.NORMAL),
            margins = enumOr(p[R_MARGINS], ReaderMargins.MEDIUM),
            width = enumOr(p[R_WIDTH], ReaderWidth.STANDARD),
            theme = enumOr(p[R_THEME], ReaderTheme.SYSTEM),
            justify = p[R_JUSTIFY] ?: false,
            keepScreenOn = p[R_SCREEN_ON] ?: false,
            mode = TextReadingMode.from(p[R_MODE]),
        )
    }

    suspend fun updateReader(transform: (ReaderSettings) -> ReaderSettings) {
        val next = transform(readerSettings.first())
        store.edit {
            it[R_SIZE] = next.fontSize.coerceIn(ReaderSettings.MIN_SIZE, ReaderSettings.MAX_SIZE)
            it[R_FONT] = next.font.name
            it[R_SPACING] = next.spacing.name
            it[R_MARGINS] = next.margins.name
            it[R_WIDTH] = next.width.name
            it[R_THEME] = next.theme.name
            it[R_JUSTIFY] = next.justify
            it[R_SCREEN_ON] = next.keepScreenOn
            it[R_MODE] = next.mode.name
        }
    }

    /** This device's reading-mode choice for a manga series on [server] (never sent to the server). */
    fun mangaMode(server: String, novelId: Int): Flow<MangaModeOverride> =
        store.data.map { MangaModeOverride.from(it[mangaModeKey(server, novelId)]) }

    suspend fun setMangaMode(server: String, novelId: Int, mode: MangaModeOverride) = store.edit {
        // "Follow series" is the default: drop the key rather than store it.
        if (mode == MangaModeOverride.FOLLOW) it.remove(mangaModeKey(server, novelId)) else it[mangaModeKey(server, novelId)] = mode.name
    }

    private fun authKey(server: String) = booleanPreferencesKey("authRequired:${ServerUrl.storageKey(server)}")
    private fun userKey(server: String) = stringPreferencesKey("lastUsername:${ServerUrl.storageKey(server)}")
    private fun mangaModeKey(server: String, novelId: Int) = stringPreferencesKey("mangaMode:${ServerUrl.storageKey(server)}#$novelId")
    private fun declineKey(scope: String, novelId: Int) = longPreferencesKey("latestDeclined:$scope#$novelId")

    private companion object {
        val SERVER_URL = stringPreferencesKey("serverURL")
        val DEVICE_ID = stringPreferencesKey("deviceID")
        val R_SIZE = floatPreferencesKey("reader.fontSize")
        // "reader.font" held the 1.0/1.1 choice (system fonts only); a new key so everyone starts on Literata.
        val R_FONT = stringPreferencesKey("reader.font2")
        val R_SPACING = stringPreferencesKey("reader.spacing")
        val R_MARGINS = stringPreferencesKey("reader.margins")
        val R_WIDTH = stringPreferencesKey("reader.width")
        val R_THEME = stringPreferencesKey("reader.theme")
        val R_JUSTIFY = booleanPreferencesKey("reader.justify")
        val R_SCREEN_ON = booleanPreferencesKey("reader.keepScreenOn")
        val R_MODE = stringPreferencesKey("reader.mode")
        val UPDATE_DISMISSED = intPreferencesKey("update.dismissedBuild")

        inline fun <reified E : Enum<E>> enumOr(raw: String?, default: E): E =
            raw?.let { name -> enumValues<E>().firstOrNull { it.name == name } } ?: default
    }
}
