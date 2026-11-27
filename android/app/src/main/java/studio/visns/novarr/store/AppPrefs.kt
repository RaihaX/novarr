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
import studio.visns.novarr.domain.EinkMode
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
    /** On unless changed: the reader keeps the screen awake, as on iOS and the web. */
    val keepScreenOn: Boolean = true,
    /** Continuous scroll or screen-sized pages; a reader default for every novel on this device. */
    val mode: TextReadingMode = TextReadingMode.SCROLL,
    /** "Bolder text"; null until the reader chooses, so it follows e-ink mode (domain.Eink.bolderText). */
    val bolder: Boolean? = null,
) {
    companion object {
        const val MIN_SIZE = 13f
        const val MAX_SIZE = 34f
    }
}

/** The audiobook player's device-wide choices (Settings › Listening and the player). */
data class ListeningPrefs(
    val skipBackSeconds: Int = DEFAULT_SKIP_BACK,
    val skipForwardSeconds: Int = DEFAULT_SKIP_FORWARD,
    val speed: Float = 1f,
    val skipSilence: Boolean = false,
    /** Volume boost (LoudnessEnhancer) for quiet recordings. */
    val boost: Boolean = false,
    /** Audiobook downloads wait for Wi-Fi (an unmetered network). */
    val wifiOnlyDownloads: Boolean = true,
    /** Audiobook uploads wait for Wi-Fi (an unmetered network). */
    val wifiOnlyUploads: Boolean = true,
) {
    companion object {
        const val DEFAULT_SKIP_BACK = 15
        const val DEFAULT_SKIP_FORWARD = 30
        val SKIP_CHOICES = listOf(10, 15, 30, 45, 60)
        const val MIN_SPEED = 0.5f
        const val MAX_SPEED = 3.0f
        const val SPEED_STEP = 0.05f
        val SPEED_PRESETS = listOf(0.75f, 1f, 1.25f, 1.5f, 1.75f, 2f, 2.5f, 3f)

        fun validSkip(seconds: Int?, default: Int): Int = seconds?.takeIf { it in SKIP_CHOICES } ?: default

        /** Clamped to 0.5–3.0 and snapped to 0.05 steps. */
        fun validSpeed(speed: Float): Float {
            if (speed.isNaN()) return 1f
            val clamped = speed.coerceIn(MIN_SPEED, MAX_SPEED)
            return (Math.round(clamped / SPEED_STEP) * SPEED_STEP * 100).let { Math.round(it) / 100f }
        }
    }
}

/** Which tab the app opens on (Settings › Home). */
enum class StartOn(val title: String) { HOME("Home"), LIBRARY("Library"), LISTEN("Listen") }

/** Home's choices: the compact Continue strip's two halves (Settings › Home) and the start tab. */
data class HomeLayout(
    val continueReading: Boolean = true,
    val continueListening: Boolean = true,
    val startOn: StartOn = StartOn.HOME,
)

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

    /** Settings' "Notify me about app updates" (default on): the background check posts a notification. */
    val updateNotifications: Flow<Boolean> = store.data.map { it[UPDATE_NOTIFY] ?: true }
    suspend fun setUpdateNotifications(on: Boolean) = store.edit { it[UPDATE_NOTIFY] = on }

    /** The newest app build the user has been told about (notified, or shown in the app); a newer build notifies again. */
    suspend fun notifiedUpdateBuild(): Int? = snapshot()[UPDATE_NOTIFIED]

    /** Records [build] as told about; never moves back to an older build. */
    suspend fun noteUpdateNotified(build: Int) = store.edit { prefs ->
        prefs[UPDATE_NOTIFIED] = maxOf(build, prefs[UPDATE_NOTIFIED] ?: build)
    }

    /** The newest app build an in-app install was started for (the Play Protect hint shows before the first). */
    suspend fun attemptedUpdateBuild(): Int? = snapshot()[UPDATE_ATTEMPTED]

    /** Records [build] as attempted; never moves back to an older build. */
    suspend fun noteUpdateAttempted(build: Int) = store.edit { prefs ->
        prefs[UPDATE_ATTEMPTED] = maxOf(build, prefs[UPDATE_ATTEMPTED] ?: build)
    }

    /** The app build whose last in-app install didn't finish (cancelled, or blocked by Play Protect); null: none. */
    suspend fun unfinishedUpdateBuild(): Int? = snapshot()[UPDATE_UNFINISHED]
    suspend fun setUnfinishedUpdateBuild(build: Int?) = store.edit {
        if (build == null) it.remove(UPDATE_UNFINISHED) else it[UPDATE_UNFINISHED] = build
    }

    /** Whether Home has already asked for the notification permission (Android 13+): it asks once. */
    suspend fun notificationPermissionAsked(): Boolean = snapshot()[NOTIFY_ASKED] ?: false
    suspend fun setNotificationPermissionAsked() = store.edit { it[NOTIFY_ASKED] = true }

    /** Settings' "Notify me about requests" (default on): the background check posts the request feed. */
    val requestNotifications: Flow<Boolean> = store.data.map { it[REQUESTS_NOTIFY] ?: true }
    suspend fun setRequestNotifications(on: Boolean) = store.edit { it[REQUESTS_NOTIFY] = on }

    /** Whether [server]'s meta last said it offers reader requests (null: not known yet). */
    suspend fun requestsOffered(server: String): Boolean? = snapshot()[requestsOfferedKey(server)]
    suspend fun setRequestsOffered(server: String, offered: Boolean) = store.edit { it[requestsOfferedKey(server)] = offered }

    /**
     * Where the notification feed was read up to, per server and account
     * ([scope]): the server's opaque cursor and the newest item id handled.
     */
    suspend fun feedPosition(scope: String): Pair<String?, Long?> = snapshot().let { it[feedCursorKey(scope)] to it[feedLastKey(scope)] }

    suspend fun setFeedPosition(scope: String, cursor: String?, lastId: Long?) = store.edit {
        if (cursor != null) it[feedCursorKey(scope)] = cursor
        if (lastId != null) it[feedLastKey(scope)] = lastId
    }

    /** Feed items not seen yet in the app (the dot on the Requests entry), per server and account. */
    fun requestsUnseen(scope: String): Flow<Int> = store.data.map { it[unseenKey(scope)] ?: 0 }
    suspend fun addRequestsUnseen(scope: String, count: Int) = store.edit { it[unseenKey(scope)] = (it[unseenKey(scope)] ?: 0) + count }
    suspend fun clearRequestsUnseen(scope: String) = store.edit { it.remove(unseenKey(scope)) }

    /** Settings' "New chapter notifications" (default on): the background check posts the one combined notification. */
    val newChapterNotifications: Flow<Boolean> = store.data.map { it[NEW_CHAPTERS_NOTIFY] ?: true }
    suspend fun setNewChapterNotifications(on: Boolean) = store.edit { it[NEW_CHAPTERS_NOTIFY] = on }

    /**
     * Whether [server]'s meta last said it offers [feature] (`updates`,
     * `lists`); null: not known yet. Saved so the background check and an
     * offline launch know without asking.
     */
    suspend fun featureOffered(server: String, feature: String): Boolean? = snapshot()[featureKey(server, feature)]
    suspend fun setFeatureOffered(server: String, feature: String, offered: Boolean) = store.edit { it[featureKey(server, feature)] = offered }

    /** The new-chapters check's state (domain.ChapterUpdates.State as JSON): cursor, pending series, last alert. */
    suspend fun chapterUpdatesState(): String? = snapshot()[CHAPTER_UPDATES_STATE]
    suspend fun setChapterUpdatesState(json: String?) = store.edit {
        if (json == null) it.remove(CHAPTER_UPDATES_STATE) else it[CHAPTER_UPDATES_STATE] = json
    }

    /** When a background check last ran successfully (epoch ms), by its key. */
    suspend fun checkLastRun(key: String): Long? = snapshot()[checkRunKey(key)]
    suspend fun setCheckLastRun(key: String, at: Long) = store.edit { it[checkRunKey(key)] = at }

    /** Settings' "E-ink mode" (default Auto: on for Onyx Boox devices). */
    val einkMode: Flow<EinkMode> = store.data.map { EinkMode.from(it[EINK_MODE]) }
    suspend fun setEinkMode(mode: EinkMode) = store.edit { it[EINK_MODE] = mode.name }

    /** Settings' "Volume keys turn pages"; null until touched (domain.PageKeyMap.volumeKeys). */
    val volumeKeys: Flow<Boolean?> = store.data.map { it[VOLUME_KEYS] }
    suspend fun setVolumeKeys(on: Boolean) = store.edit { it[VOLUME_KEYS] = on }

    /**
     * The first time e-ink mode is on: Pages and screen-off reading, once. The
     * reading mode is stored by every reader edit, so a choice can't be told
     * apart and it is set regardless; keep-screen-on only if never chosen.
     * True when it applied.
     */
    suspend fun applyEinkDefaultsOnce(): Boolean {
        var applied = false
        store.edit {
            if (it[EINK_APPLIED] == true) return@edit
            it[R_MODE] = TextReadingMode.PAGES.name
            if (R_SCREEN_ON !in it) it[R_SCREEN_ON] = false
            it[EINK_APPLIED] = true
            applied = true
        }
        return applied
    }

    // Listening (audiobooks): device-wide player choices.

    val listening: Flow<ListeningPrefs> = store.data.map { p ->
        ListeningPrefs(
            skipBackSeconds = ListeningPrefs.validSkip(p[A_SKIP_BACK], ListeningPrefs.DEFAULT_SKIP_BACK),
            skipForwardSeconds = ListeningPrefs.validSkip(p[A_SKIP_FORWARD], ListeningPrefs.DEFAULT_SKIP_FORWARD),
            speed = ListeningPrefs.validSpeed(p[A_SPEED] ?: 1f),
            skipSilence = p[A_SKIP_SILENCE] ?: false,
            boost = p[A_BOOST] ?: false,
            wifiOnlyDownloads = p[A_WIFI_ONLY] ?: true,
            wifiOnlyUploads = p[A_WIFI_ONLY_UPLOADS] ?: true,
        )
    }

    suspend fun listeningNow(): ListeningPrefs = listening.first()

    suspend fun updateListening(transform: (ListeningPrefs) -> ListeningPrefs) {
        val next = transform(listening.first())
        store.edit {
            it[A_SKIP_BACK] = ListeningPrefs.validSkip(next.skipBackSeconds, ListeningPrefs.DEFAULT_SKIP_BACK)
            it[A_SKIP_FORWARD] = ListeningPrefs.validSkip(next.skipForwardSeconds, ListeningPrefs.DEFAULT_SKIP_FORWARD)
            it[A_SPEED] = ListeningPrefs.validSpeed(next.speed)
            it[A_SKIP_SILENCE] = next.skipSilence
            it[A_BOOST] = next.boost
            it[A_WIFI_ONLY] = next.wifiOnlyDownloads
            it[A_WIFI_ONLY_UPLOADS] = next.wifiOnlyUploads
        }
    }

    /** Whether the player has asked for the notification permission (Android 13+): once, on first play. */
    suspend fun listeningNotifyAsked(): Boolean = snapshot()[A_NOTIFY_ASKED] ?: false
    suspend fun setListeningNotifyAsked() = store.edit { it[A_NOTIFY_ASKED] = true }

    // Home: what it shows, and which tab the app opens on.

    val homeLayout: Flow<HomeLayout> = store.data.map { p ->
        HomeLayout(
            continueReading = p[H_CONTINUE_READING] ?: true,
            continueListening = p[H_CONTINUE_LISTENING] ?: true,
            startOn = enumOr(p[H_START_ON], StartOn.HOME),
        )
    }

    suspend fun updateHomeLayout(transform: (HomeLayout) -> HomeLayout) {
        val next = transform(homeLayout.first())
        store.edit {
            it[H_CONTINUE_READING] = next.continueReading
            it[H_CONTINUE_LISTENING] = next.continueListening
            it[H_START_ON] = next.startOn.name
        }
    }

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
            // 1.5 turned the default on; a stored choice still wins.
            keepScreenOn = p[R_SCREEN_ON] ?: true,
            mode = TextReadingMode.from(p[R_MODE]),
            bolder = p[R_BOLDER],
        )
    }

    suspend fun updateReader(transform: (ReaderSettings) -> ReaderSettings) {
        val current = readerSettings.first()
        val next = transform(current)
        store.edit {
            it[R_SIZE] = next.fontSize.coerceIn(ReaderSettings.MIN_SIZE, ReaderSettings.MAX_SIZE)
            it[R_FONT] = next.font.name
            it[R_SPACING] = next.spacing.name
            it[R_MARGINS] = next.margins.name
            it[R_WIDTH] = next.width.name
            it[R_THEME] = next.theme.name
            it[R_JUSTIFY] = next.justify
            // Stored only once it has been chosen, so a default that was never touched can still change.
            if (next.keepScreenOn != current.keepScreenOn || R_SCREEN_ON in it) it[R_SCREEN_ON] = next.keepScreenOn
            it[R_MODE] = next.mode.name
            if (next.bolder != null) it[R_BOLDER] = next.bolder else it.remove(R_BOLDER)
        }
    }

    /** This device's reading-mode choice for a manga series on [server] (never sent to the server). */
    fun mangaMode(server: String, novelId: Int): Flow<MangaModeOverride> =
        store.data.map { MangaModeOverride.from(it[mangaModeKey(server, novelId)]) }

    suspend fun setMangaMode(server: String, novelId: Int, mode: MangaModeOverride) = store.edit {
        // "Follow series" is the default: drop the key rather than store it.
        if (mode == MangaModeOverride.FOLLOW) it.remove(mangaModeKey(server, novelId)) else it[mangaModeKey(server, novelId)] = mode.name
    }

    private fun requestsOfferedKey(server: String) = booleanPreferencesKey("requestsOffered:${ServerUrl.storageKey(server)}")
    private fun feedCursorKey(scope: String) = stringPreferencesKey("requests.feedCursor:$scope")
    private fun feedLastKey(scope: String) = longPreferencesKey("requests.feedLastId:$scope")
    private fun unseenKey(scope: String) = intPreferencesKey("requests.unseen:$scope")
    private fun featureKey(server: String, feature: String) = booleanPreferencesKey("offered.$feature:${ServerUrl.storageKey(server)}")
    private fun checkRunKey(key: String) = longPreferencesKey("checks.lastRun:$key")
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
        val R_BOLDER = booleanPreferencesKey("reader.bolder")
        val EINK_MODE = stringPreferencesKey("eink.mode")
        val EINK_APPLIED = booleanPreferencesKey("eink.defaultsApplied")
        val VOLUME_KEYS = booleanPreferencesKey("reader.volumeKeys")
        val UPDATE_DISMISSED = intPreferencesKey("update.dismissedBuild")
        val UPDATE_NOTIFY = booleanPreferencesKey("update.notify")
        val UPDATE_NOTIFIED = intPreferencesKey("update.notifiedBuild")
        val UPDATE_ATTEMPTED = intPreferencesKey("update.attemptedBuild")
        val UPDATE_UNFINISHED = intPreferencesKey("update.unfinishedBuild")
        val NOTIFY_ASKED = booleanPreferencesKey("notifications.permissionAsked")
        val REQUESTS_NOTIFY = booleanPreferencesKey("requests.notify")
        val NEW_CHAPTERS_NOTIFY = booleanPreferencesKey("newChapters.notify")
        val CHAPTER_UPDATES_STATE = stringPreferencesKey("newChapters.state")
        val A_SKIP_BACK = intPreferencesKey("listening.skipBack")
        val A_SKIP_FORWARD = intPreferencesKey("listening.skipForward")
        val A_SPEED = floatPreferencesKey("listening.speed")
        val A_SKIP_SILENCE = booleanPreferencesKey("listening.skipSilence")
        val A_BOOST = booleanPreferencesKey("listening.boost")
        val A_WIFI_ONLY = booleanPreferencesKey("listening.wifiOnlyDownloads")
        val A_WIFI_ONLY_UPLOADS = booleanPreferencesKey("listening.wifiOnlyUploads")
        val A_NOTIFY_ASKED = booleanPreferencesKey("listening.notifyAsked")
        val H_CONTINUE_READING = booleanPreferencesKey("home.continueReading")
        val H_CONTINUE_LISTENING = booleanPreferencesKey("home.continueListening")
        val H_START_ON = stringPreferencesKey("home.startOn")

        inline fun <reified E : Enum<E>> enumOr(raw: String?, default: E): E =
            raw?.let { name -> enumValues<E>().firstOrNull { it.name == name } } ?: default
    }
}
