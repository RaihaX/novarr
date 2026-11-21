package studio.visns.novarr.app

import android.content.Context
import android.net.ConnectivityManager
import android.net.Network
import android.os.Build
import android.provider.Settings
import java.io.File
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.flow.flatMapLatest
import kotlinx.coroutines.flow.flowOf
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.Cache
import okhttp3.OkHttpClient
import studio.visns.novarr.BuildConfig
import studio.visns.novarr.api.AndroidRelease
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.AuthTokenResponse
import studio.visns.novarr.api.DeviceIdentity
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.api.ServerUrl
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.domain.AppUpdate
import studio.visns.novarr.domain.RequestFeed
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.store.AppPrefs
import studio.visns.novarr.store.Credential
import studio.visns.novarr.store.CredentialStore
import studio.visns.novarr.store.KeystoreSecretBox
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.store.PendingWrite
import studio.visns.novarr.store.WriteKind
import studio.visns.novarr.store.WriteQueue
import studio.visns.novarr.store.hasUnsentChapterWrite
import studio.visns.novarr.store.unsentHighlights

/** Who the app is talking to, and as whom. */
sealed interface Session {
    /** Reading the saved server at launch. */
    data object Starting : Session

    /** No server yet: show Connect. */
    data object NoServer : Session

    /**
     * Sign-in is on for this server and nobody is signed in: show sign-in with
     * the server prefilled. There is no client, store or queue (queued writes
     * stay on disk for the next sign-in), so nothing can loop on 401.
     */
    data class SignIn(val server: String, val notice: String?, val lastUsername: String?) : Session

    /** Reading: a client (with the token, if any) and this (server, user)'s store and queue. */
    data class Active(
        val server: String,
        val client: ApiClient,
        val store: OfflineStore,
        val queue: WriteQueue,
        /** Null with sign-in off: everything is the owner's. */
        val account: Credential?,
    ) : Session {
        /** Changes whenever the server or user does, so screens are rebuilt and never show another account's data. */
        val id: String get() = "$server|${account?.user?.id ?: "-"}"
    }
}

/** A read that may have come from the offline cache. */
data class Loaded<T>(val value: T?, val fromCache: Boolean, val error: ApiError?)

/**
 * App-wide state (the iOS AppModel): the configured server, who is signed in
 * to it, its client, offline store and write queue, plus connectivity. All
 * state changes happen on the main thread.
 */
class AppModel(private val context: Context) {
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    val prefs = AppPrefs(context)
    private val credentials = CredentialStore(File(context.filesDir, "credentials"), KeystoreSecretBox())
    private val storageBase = File(context.filesDir, "Novarr/servers")

    /** API calls: a modest HTTP cache so ETag revalidation (304) works for the big lists. */
    val http: OkHttpClient = OkHttpClient.Builder()
        .cache(Cache(File(context.cacheDir, "http"), 64L shl 20))
        .connectTimeout(10, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .build()

    /** Images (covers, pre-signed manga pages): no cache of its own and never any credentials. */
    val imageHttp: OkHttpClient = http.newBuilder().cache(null).build()

    private val _session = MutableStateFlow<Session>(Session.Starting)
    val session: StateFlow<Session> = _session.asStateFlow()

    private val _meta = MutableStateFlow<ServerMeta?>(null)
    val serverMeta: StateFlow<ServerMeta?> = _meta.asStateFlow()

    /** When meta last answered (epoch ms); null until it has. */
    private var metaFetchedAt: Long? = null

    /** A meta request is running, or the launch one is still to come: [refreshMetaIfDue] waits for it. */
    private var metaRefreshing = true

    /** Whether the last request reached the server. */
    private val _reachable = MutableStateFlow(true)
    val isReachable: StateFlow<Boolean> = _reachable.asStateFlow()

    private val _pending = MutableStateFlow(0)
    val pendingWrites: StateFlow<Int> = _pending.asStateFlow()

    /** Bumped after read-state or library changes so list screens know to reload. */
    private val _revision = MutableStateFlow(0)
    val readStateRevision: StateFlow<Int> = _revision.asStateFlow()

    /**
     * Bumped whenever the write queue changed (a write queued, or some sent),
     * position-only writes included, so screens that apply the unsent writes
     * locally (Continue: [studio.visns.novarr.domain.ResumeRule]) re-read them.
     */
    private val _queueRevision = MutableStateFlow(0)
    val queueRevision: StateFlow<Int> = _queueRevision.asStateFlow()

    lateinit var device: DeviceIdentity
        private set

    val downloads = DownloadManager(this)

    /** In-app update: downloads the server's APK and hands it to Android's installer. */
    val updater = AppUpdater(context, imageHttp, scope, prefs)
    val checks = ChapterChecks(onChaptersChanged = { libraryChanged() })

    val active: Session.Active? get() = _session.value as? Session.Active

    /** A tapped notification asked for Home; MainNavigation goes there and clears it. */
    private val _openHome = MutableStateFlow(false)
    val openHomeRequest: StateFlow<Boolean> = _openHome.asStateFlow()

    /** The request notification feed (background, foreground and the Requests screen). */
    val requestFeed = RequestFeedSync(context, prefs)

    /** Whether the current server offers reader requests (its last meta; saved, so known offline). */
    private val _requestsOffered = MutableStateFlow(false)
    val requestsOffered: StateFlow<Boolean> = _requestsOffered.asStateFlow()

    /** A tapped request notification asked for the Requests screen; MainNavigation goes there and clears it. */
    private val _openRequests = MutableStateFlow<RequestFeed.Target?>(null)
    val openRequestsRequest: StateFlow<RequestFeed.Target?> = _openRequests.asStateFlow()

    /** The new-chapters notification's state (background check, app start, tap, swipe). */
    val chapterUpdates = ChapterUpdatesSync(context, prefs)

    /** Whether the current server sends new chapters for the combined notification (meta `updates`; saved). */
    private val _updatesOffered = MutableStateFlow(false)
    val updatesOffered: StateFlow<Boolean> = _updatesOffered.asStateFlow()

    /** Whether the current server has reading lists (meta `lists`; saved, so known offline). Without it lists are hidden. */
    private val _listsOffered = MutableStateFlow(false)
    val listsOffered: StateFlow<Boolean> = _listsOffered.asStateFlow()

    /** The reader's lists for this session (Library and the series screen). */
    val lists = ListsModel(this)

    /** A tapped new-chapters notification asked for one series; MainNavigation opens it (over Home) and clears it. */
    private val _openSeries = MutableStateFlow<Int?>(null)
    val openSeriesRequest: StateFlow<Int?> = _openSeries.asStateFlow()

    /**
     * The sign-in code and link-code steps after the password form: in
     * memory here, so rotation keeps them and process death drops them.
     */
    val signInFlow = SignInFlow(
        scope = scope,
        client = { server, token -> ApiClient(server, http, device, token) },
        device = { device },
        complete = { server, response, username -> completeSignIn(server, response, username) },
    )

    /** The Requests screen's search, per session (Session.Active.id), so it survives leaving the screen. */
    var requestSearchMemory: Pair<String, Requests.SearchMemory>? = null

    init {
        updater.cleanUp()
        Notifications.ensureChannels(context)
        scope.launch {
            clearInstalledUpdateNotice()
            // KEEP: a schedule that already exists keeps its timing.
            BackgroundChecks.sync(context, prefs)
        }
        scope.launch {
            val (identity, isNew) = DeviceIdentity.resolve(prefs.deviceId(), deviceName())
            if (isNew) prefs.setDeviceId(identity.id)
            device = identity
            val server = prefs.serverUrl()?.let(ServerUrl::normalize)
            if (server == null) {
                metaRefreshing = false
                _session.value = Session.NoServer
            } else {
                _requestsOffered.value = prefs.requestsOffered(server) == true
                _updatesOffered.value = prefs.featureOffered(server, FEATURE_UPDATES) == true
                _listsOffered.value = prefs.featureOffered(server, FEATURE_LISTS) == true
                configure(server, notice = null)
                // Learn whether the server now wants a sign-in (it may have turned auth on
                // since last time) and whether it publishes a newer app.
                refreshMeta()
            }
        }
        watchConnectivity()
    }

    // Server

    /** Saves a server that ApiClient.meta() already verified. */
    fun connect(server: String, meta: ServerMeta) = scope.launch {
        // Another server: its new-chapters cursor and pending series start over.
        if (prefs.serverUrl()?.let(ServerUrl::normalize) != ServerUrl.normalize(server)) chapterUpdates.reset()
        // A code or link code belongs to the server it was asked from.
        signInFlow.reset()
        prefs.setServerUrl(server)
        prefs.setAuthRequired(server, meta.requiresSignIn)
        _meta.value = meta
        metaFetchedAt = System.currentTimeMillis()
        _reachable.value = true
        _requestsOffered.value = meta.requests
        _updatesOffered.value = meta.updates
        _listsOffered.value = meta.lists
        noteRequestsOffered(server, meta.requests)
        noteFeaturesOffered(server, meta)
        configure(server, notice = null)
    }

    private suspend fun configure(server: String, notice: String?) {
        val required = prefs.authRequired(server)
        val credential = if (required != false) credentials.credential(server) else null
        when {
            credential != null -> activate(server, credential)
            required == true -> requireSignIn(server, notice)
            else -> activate(server, null)
        }
    }

    /** Starts a session: a client (with the token, if any) and the store and queue for this server and user. */
    private suspend fun activate(server: String, credential: Credential?) {
        checks.cancelAll()
        downloads.cancelAll()
        // Signed in (or sign-in is off): no code screen or link code is left running.
        signInFlow.reset()
        val client = ApiClient(server, http, device, credential?.token) { rejected ->
            scope.launch { handleUnauthorized(rejected) }
        }
        val directory = withContext(Dispatchers.IO) { OfflineStore.resolveDirectory(storageBase, server, credential?.user) }
        val store = OfflineStore(directory)
        val queue = withContext(Dispatchers.IO) { WriteQueue(store.writeQueueFile) }
        lists.reset()
        _session.value = Session.Active(server, client, store, queue, credential)
        _pending.value = queue.count()
        _revision.update { it + 1 }
        // Never in the caller's scope: the screen that started this (sign-in)
        // goes away with the session change, and a flush cancelled between a
        // write's send and its removal would send it twice.
        scope.launch { flushWrites() }
        // Just signed in: request news for this account, as the dot on Requests.
        pollRequestFeed()
    }

    /** Ends the session without touching anything on disk: the queue file stays for the next sign-in. */
    private suspend fun requireSignIn(server: String, notice: String?) {
        checks.cancelAll()
        downloads.cancelAll()
        val unsent = _pending.value
        val text = if (notice != null && unsent > 0) {
            "$notice Your unsynced reading progress is kept and will be sent after you sign in."
        } else {
            notice
        }
        _pending.value = 0
        lists.reset()
        _session.value = Session.SignIn(server, text, prefs.lastUsername(server))
        _revision.update { it + 1 }
    }

    val currentServer: String?
        get() = when (val s = _session.value) {
            is Session.Active -> s.server
            is Session.SignIn -> s.server
            else -> null
        }

    suspend fun refreshMeta() {
        val server = currentServer ?: return
        metaRefreshing = true
        try {
            // Meta is public, so this works signed out too.
            val meta = ApiClient(server, http).meta()
            if (currentServer != server) return
            metaFetchedAt = System.currentTimeMillis()
            apply(meta, server)
            _reachable.value = true
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            noteFailure(ApiError.from(e))
            return
        } finally {
            metaRefreshing = false
        }
        refreshAccount()
        // Launch (or Settings): anything new since last time, as the dot on Requests.
        pollRequestFeed()
    }

    /**
     * Meta outside launch and Settings (back in the foreground, Home): only
     * when the last answer is older than [AppUpdate.META_REFRESH_MS], so a
     * newly published APK is noticed without asking on every screen.
     */
    fun refreshMetaIfDue() {
        if (metaRefreshing || !AppUpdate.metaDue(metaFetchedAt, System.currentTimeMillis())) return
        scope.launch { refreshMeta() }
    }

    /** The server's APK when it is newer than this app, else null (the Settings row). */
    val availableUpdate: Flow<AndroidRelease?> = _meta.map { meta ->
        meta?.android?.takeIf { AppUpdate.available(it, BuildConfig.VERSION_CODE) }
    }

    /** [availableUpdate] unless its banner was dismissed (Home). */
    val updateBanner: Flow<AndroidRelease?> = combine(_meta, prefs.dismissedUpdateBuild) { meta, dismissed ->
        meta?.android?.takeIf { AppUpdate.available(it, BuildConfig.VERSION_CODE, dismissed) }
    }

    /** Hides the banner for [release]'s build; a newer build brings it back. */
    fun dismissUpdate(release: AndroidRelease) {
        val build = release.build ?: return
        scope.launch { prefs.setDismissedUpdateBuild(build) }
    }

    /**
     * The banner or Settings row is showing [release]: a notification about it
     * goes, and the background check won't post one for this build later.
     */
    fun updateShown(release: AndroidRelease) {
        Notifications.cancel(context, Notifications.APP_UPDATE_ID)
        val build = release.build ?: return
        scope.launch { prefs.noteUpdateNotified(build) }
    }

    /** After an update installed: the "newer version" notification for that build (or an older one) goes. */
    suspend fun clearInstalledUpdateNotice() {
        val notified = prefs.notifiedUpdateBuild() ?: return
        if (notified <= BuildConfig.VERSION_CODE) Notifications.cancel(context, Notifications.APP_UPDATE_ID)
    }

    /** Settings' "Notify me about app updates": schedules or cancels the background check. */
    fun setUpdateNotifications(on: Boolean) = scope.launch {
        prefs.setUpdateNotifications(on)
        if (!on) Notifications.cancel(context, Notifications.APP_UPDATE_ID)
        BackgroundChecks.sync(context, prefs)
    }

    /**
     * Whether Home should ask for the notification permission now: Android
     * 13+, not granted, the switch on, and never asked before (asking counts
     * as asked, whatever the answer).
     */
    suspend fun takeNotificationPermissionPrompt(): Boolean {
        if (!Notifications.needsPermission(context)) return false
        val wanted = prefs.updateNotifications.first() ||
            (_requestsOffered.value && prefs.requestNotifications.first()) ||
            (_updatesOffered.value && prefs.newChapterNotifications.first())
        if (!wanted || prefs.notificationPermissionAsked()) return false
        prefs.setNotificationPermissionAsked()
        return true
    }

    // New chapters and reading lists

    /**
     * The server's offer of the new-chapters feed and of reading lists, from
     * a meta answer: saved per server (for the worker and offline launches),
     * the background work rescheduled when the feed's offer changed.
     */
    suspend fun noteFeaturesOffered(server: String, meta: ServerMeta) {
        if (server == currentServer) {
            _updatesOffered.value = meta.updates
            _listsOffered.value = meta.lists
        }
        prefs.setFeatureOffered(server, FEATURE_LISTS, meta.lists)
        if (prefs.featureOffered(server, FEATURE_UPDATES) == meta.updates) return
        prefs.setFeatureOffered(server, FEATURE_UPDATES, meta.updates)
        if (!meta.updates) chapterUpdates.reset()
        BackgroundChecks.sync(context, prefs)
    }

    /** Settings' "New chapter notifications": off cancels it, forgets what was pending and stops the check. */
    fun setNewChapterNotifications(on: Boolean) = scope.launch {
        prefs.setNewChapterNotifications(on)
        if (!on) chapterUpdates.reset()
        BackgroundChecks.sync(context, prefs)
    }

    /** In the app (back in the foreground, or the notification tapped): the new-chapters notification is seen. */
    fun clearNewChapters() {
        scope.launch { chapterUpdates.clearPending() }
    }

    fun openSeries(novelId: Int) {
        _openSeries.value = novelId
    }

    fun seriesOpened() {
        _openSeries.value = null
    }

    // Reader requests

    /**
     * The server's offer of requests, from a meta answer: saved per server
     * (so the hourly worker and an offline launch know it), and the
     * background work rescheduled when it changed.
     */
    suspend fun noteRequestsOffered(server: String, offered: Boolean) {
        if (server == currentServer) _requestsOffered.value = offered
        if (prefs.requestsOffered(server) == offered) return
        prefs.setRequestsOffered(server, offered)
        BackgroundChecks.sync(context, prefs)
    }

    /** Settings' "Notify me about requests": the worker runs hourly while it is on. */
    fun setRequestNotifications(on: Boolean) = scope.launch {
        prefs.setRequestNotifications(on)
        BackgroundChecks.sync(context, prefs)
    }

    /** The signed-in account is the owner (always, with sign-in off). */
    val isAdmin: Boolean
        get() = active?.let { it.account == null || it.account.user.role.equals("admin", ignoreCase = true) } == true

    /** Unseen feed items for this session: the dot on the Requests entry. */
    @OptIn(ExperimentalCoroutinesApi::class)
    val requestsUnseen: Flow<Int> = _session.flatMapLatest { session ->
        if (session is Session.Active) prefs.requestsUnseen(RequestFeedSync.scope(session)) else flowOf(0)
    }

    /**
     * In the foreground (back in the app, launch, the Requests screen):
     * reads the feed into the dot. Silent on failure; the next one tries again.
     */
    fun pollRequestFeed() {
        val session = active ?: return
        if (!_requestsOffered.value) return
        scope.launch {
            // Started in the background (by the worker), the worker reads the feed itself, quietly on a 401.
            if (!inForeground()) return@launch
            try {
                requestFeed.poll(session, session.client, reportUnauthorized = true)
            } catch (e: Exception) {
                e.rethrowIfCancellation()
            }
        }
    }

    /** The Requests screen is showing ([visible]) or went away: it clears the dot and the notifications while open. */
    fun requestsScreenVisible(visible: Boolean) {
        requestFeed.screenVisible = visible
        if (!visible) return
        Notifications.cancelRequests(context)
        val session = active ?: return
        scope.launch { prefs.clearRequestsUnseen(RequestFeedSync.scope(session)) }
        pollRequestFeed()
    }

    fun openRequests(target: RequestFeed.Target) {
        _openRequests.value = target
    }

    fun requestsOpened() {
        _openRequests.value = null
    }

    fun requestHome() {
        _openHome.value = true
    }

    fun homeOpened() {
        _openHome.value = false
    }

    /** Follows the server's sign-in switch: on → sign in (or keep the session); off → no accounts at all. */
    private suspend fun apply(meta: ServerMeta, server: String) {
        _meta.value = meta
        prefs.setAuthRequired(server, meta.requiresSignIn)
        noteRequestsOffered(server, meta.requests)
        noteFeaturesOffered(server, meta)
        val session = _session.value
        if (meta.requiresSignIn) {
            if (session is Session.Active && session.account == null) {
                val credential = credentials.credential(server)
                if (credential != null) activate(server, credential) else requireSignIn(server, null)
            }
        } else if ((session is Session.Active && session.account != null) || session is Session.SignIn) {
            // The saved sign-in is kept (unused) in case sign-in comes back on.
            activate(server, null)
        }
    }

    /** Picks up a changed name or role. A 401 here ends the session like any other request. */
    private suspend fun refreshAccount() {
        val session = active ?: return
        val current = session.account ?: return
        val user = runCatching { session.client.me() }.getOrNull() ?: return
        val now = active ?: return
        if (now.account != current || user.id != current.user.id || user == current.user) return
        val updated = current.copy(user = user)
        credentials.save(updated, now.server)
        _session.value = now.copy(account = updated)
    }

    // Sign-in

    /**
     * Password sign-in. Signed in, or the code screen comes up
     * ([signInFlow]'s second step). Throws ApiError (422 wrong name or
     * password, 429 throttled, 403 `second_step_unavailable`, connectivity).
     * The password is not kept anywhere.
     */
    suspend fun signIn(username: String, password: String) {
        val server = currentServer ?: throw ApiError.InvalidServerUrl
        signInFlow.signIn(server, username, password)
    }

    /**
     * Every way of signing in ends here (password, sign-in code, link code):
     * the credential is saved and the session starts. [username] prefills the
     * next sign-in form.
     */
    private suspend fun completeSignIn(server: String, response: AuthTokenResponse, username: String) {
        if (currentServer != server) return
        val credential = Credential(response.token, response.user)
        // If saving fails the token still works until the app quits.
        credentials.save(credential, server)
        prefs.setAuthRequired(server, true)
        if (username.isNotBlank()) prefs.setLastUsername(server, username)
        clearHttpCache()
        _reachable.value = true
        activate(server, credential)
    }

    /**
     * Revokes the token on the server (best effort: offline still signs out
     * here), forgets it and returns to sign-in. Queued writes stay in this
     * user's directory for their next sign-in.
     */
    suspend fun signOut() {
        val session = active ?: return
        if (session.account == null) return
        runCatching { session.client.signOut() }
        if (currentServer != session.server) return
        credentials.remove(session.server)
        clearHttpCache()
        chapterUpdates.reset()
        requireSignIn(session.server, null)
    }

    /**
     * A request was rejected with 401. Acts once per token: a late 401 from a
     * token already replaced by a new sign-in is ignored, and once on the
     * sign-in screen there is no client left to send anything, so this can't loop.
     */
    suspend fun handleUnauthorized(rejectedToken: String?) {
        val session = active ?: return
        if (rejectedToken != session.account?.token) return
        val wasSignedIn = session.account != null
        if (wasSignedIn) credentials.remove(session.server)
        // Signed out all the same: nothing about this account's series may show.
        chapterUpdates.reset()
        prefs.setAuthRequired(session.server, true)
        clearHttpCache()
        requireSignIn(
            session.server,
            if (wasSignedIn) "You've been signed out. Sign in again to continue." else "This server now asks you to sign in.",
        )
    }

    /** Responses carry per-user read state: never revalidate one account's cached copy for another. */
    private suspend fun clearHttpCache() = withContext(Dispatchers.IO) { runCatching { http.cache?.evictAll() } }

    // Reads with offline fallback

    /**
     * Fetches from the server and caches the result; if that fails, returns
     * the cached copy (marked `fromCache`) with the error.
     */
    suspend fun <T> load(key: OfflineStore.Key<T>, fetch: suspend (ApiClient) -> T): Loaded<T> {
        val session = active ?: return Loaded(null, false, ApiError.InvalidServerUrl)
        return try {
            val value = fetch(session.client)
            noteSuccess()
            session.store.save(value, key)
            Loaded(value, false, null)
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            val error = ApiError.from(e)
            noteFailure(error)
            val cached = session.store.load(key)
            Loaded(cached, cached != null, error)
        }
    }

    suspend fun <T> cached(key: OfflineStore.Key<T>): T? = active?.store?.load(key)

    // Writes

    /**
     * Queues a read-state write (persisted immediately) and tries to send it.
     * The send runs in the app's scope so leaving a screen can't cut it short.
     */
    suspend fun record(kind: WriteKind, novelId: Int?) {
        val session = active ?: return
        session.queue.enqueue(kind, novelId)
        _pending.value = session.queue.count()
        _queueRevision.update { it + 1 }
        if (kind.changesReadState) _revision.update { it + 1 }
        scope.launch { flushWrites() }
    }

    suspend fun flushWrites() {
        val session = active ?: return
        if (session.queue.count() == 0) {
            _pending.value = 0
            return
        }
        val outcome = session.queue.flush { kind, age -> session.client.send(kind, age) }
        // The session may have ended (401) while this flush ran.
        if (active?.queue !== session.queue) return
        _pending.value = session.queue.count()
        val sent = when (outcome) {
            is WriteQueue.FlushOutcome.Drained -> outcome.sent + outcome.dropped
            is WriteQueue.FlushOutcome.Stalled -> outcome.sent
            is WriteQueue.FlushOutcome.Unauthenticated -> outcome.sent
            WriteQueue.FlushOutcome.Busy -> 0
        }
        if (sent > 0) _queueRevision.update { it + 1 }
        when (outcome) {
            is WriteQueue.FlushOutcome.Drained -> if (outcome.sent > 0) {
                _reachable.value = true
                _revision.update { it + 1 }
            }
            is WriteQueue.FlushOutcome.Stalled -> _reachable.value = false
            // The client already reported it; the writes stay queued on disk.
            else -> Unit
        }
    }

    /** Position-moving writes for a series still waiting to be sent, by when they were recorded. */
    suspend fun unsentWriteTimes(novelId: Int): List<Long> =
        active?.queue?.items().orEmpty().filter { it.novelId == novelId && it.kind.movesPosition }.map { it.recordedAt }

    /** One series' writes not sent yet, oldest first (the local resume rule, [studio.visns.novarr.domain.ResumeRule]). */
    suspend fun unsentWrites(novelId: Int): List<PendingWrite> =
        active?.queue?.items().orEmpty().filter { it.novelId == novelId }

    /** New highlights for a series still waiting to be sent. */
    suspend fun unsentHighlights(novelId: Int): List<WriteKind.CreateHighlight> =
        unsentHighlights(active?.queue?.items().orEmpty(), novelId)

    /** Whether a progress or read/unread write for this chapter is still waiting to be sent. */
    suspend fun hasUnsentWrite(chapterId: Int): Boolean =
        hasUnsentChapterWrite(active?.queue?.items().orEmpty(), chapterId)

    // Latest-position prompt

    private fun declineScope(session: Session.Active): String =
        session.server + (session.account?.let { "|user-${it.user.id}" } ?: "")

    suspend fun declinedLatestPosition(novelId: Int): Long? = active?.let { prefs.declinedAt(declineScope(it), novelId) }

    suspend fun declineLatestPosition(at: Long, novelId: Int) {
        active?.let { prefs.decline(declineScope(it), novelId, at) }
    }

    // Library changes and checks

    fun libraryChanged() = _revision.update { it + 1 }

    fun startCheck(novelId: Int) {
        val session = active ?: return
        checks.start(novelId, session.client, scope)
    }

    // Connectivity bookkeeping

    fun noteSuccess() {
        val wasUnreachable = !_reachable.value
        _reachable.value = true
        if (wasUnreachable && _pending.value > 0) scope.launch { flushWrites() }
    }

    fun noteFailure(error: ApiError) {
        if (error.isConnectivity) _reachable.value = false
    }

    private fun watchConnectivity() {
        val manager = context.getSystemService(ConnectivityManager::class.java) ?: return
        runCatching {
            manager.registerDefaultNetworkCallback(object : ConnectivityManager.NetworkCallback() {
                override fun onAvailable(network: Network) {
                    scope.launch { flushWrites() }
                }
            })
        }
    }

    // Storage

    suspend fun clearCaches() {
        active?.store?.clearAll()
        clearHttpCache()
        withContext(Dispatchers.IO) { coverCacheDir().deleteRecursively() }
        downloads.contentChanged()
    }

    fun coverCacheDir(): File = File(context.cacheDir, "covers")

    /** The user's name for this device ("Pixel 8"), else the model. */
    private fun deviceName(): String {
        val userName = runCatching { Settings.Global.getString(context.contentResolver, Settings.Global.DEVICE_NAME) }.getOrNull()
        return DeviceIdentity.cleanName(userName?.takeIf { it.isNotBlank() } ?: Build.MODEL)
    }

    companion object {
        /** Meta keys for optional server features, as saved per server (AppPrefs.featureOffered). */
        const val FEATURE_UPDATES = "updates"
        const val FEATURE_LISTS = "lists"
    }
}
