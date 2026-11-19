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
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.Cache
import okhttp3.OkHttpClient
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.DeviceIdentity
import studio.visns.novarr.api.ServerMeta
import studio.visns.novarr.api.ServerUrl
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.store.AppPrefs
import studio.visns.novarr.store.Credential
import studio.visns.novarr.store.CredentialStore
import studio.visns.novarr.store.KeystoreSecretBox
import studio.visns.novarr.store.OfflineStore
import studio.visns.novarr.store.WriteKind
import studio.visns.novarr.store.WriteQueue

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

    /** Whether the last request reached the server. */
    private val _reachable = MutableStateFlow(true)
    val isReachable: StateFlow<Boolean> = _reachable.asStateFlow()

    private val _pending = MutableStateFlow(0)
    val pendingWrites: StateFlow<Int> = _pending.asStateFlow()

    /** Bumped after read-state or library changes so list screens know to reload. */
    private val _revision = MutableStateFlow(0)
    val readStateRevision: StateFlow<Int> = _revision.asStateFlow()

    lateinit var device: DeviceIdentity
        private set

    val downloads = DownloadManager(this)
    val checks = ChapterChecks(onChaptersChanged = { libraryChanged() })

    val active: Session.Active? get() = _session.value as? Session.Active

    init {
        scope.launch {
            val (identity, isNew) = DeviceIdentity.resolve(prefs.deviceId(), deviceName())
            if (isNew) prefs.setDeviceId(identity.id)
            device = identity
            val server = prefs.serverUrl()?.let(ServerUrl::normalize)
            if (server == null) {
                _session.value = Session.NoServer
            } else {
                configure(server, notice = null)
                // Learn whether the server now wants a sign-in (it may have turned auth on since last time).
                refreshMeta()
            }
        }
        watchConnectivity()
    }

    // Server

    /** Saves a server that ApiClient.meta() already verified. */
    fun connect(server: String, meta: ServerMeta) = scope.launch {
        prefs.setServerUrl(server)
        prefs.setAuthRequired(server, meta.requiresSignIn)
        _meta.value = meta
        _reachable.value = true
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
        val client = ApiClient(server, http, device, credential?.token) { rejected ->
            scope.launch { handleUnauthorized(rejected) }
        }
        val directory = withContext(Dispatchers.IO) { OfflineStore.resolveDirectory(storageBase, server, credential?.user) }
        val store = OfflineStore(directory)
        val queue = withContext(Dispatchers.IO) { WriteQueue(store.writeQueueFile) }
        _session.value = Session.Active(server, client, store, queue, credential)
        _pending.value = queue.count()
        _revision.update { it + 1 }
        flushWrites()
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
        try {
            // Meta is public, so this works signed out too.
            val meta = ApiClient(server, http).meta()
            if (currentServer != server) return
            apply(meta, server)
            _reachable.value = true
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            noteFailure(ApiError.from(e))
            return
        }
        refreshAccount()
    }

    /** Follows the server's sign-in switch: on → sign in (or keep the session); off → no accounts at all. */
    private suspend fun apply(meta: ServerMeta, server: String) {
        _meta.value = meta
        prefs.setAuthRequired(server, meta.requiresSignIn)
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

    /** Password sign-in. Throws ApiError (422 wrong name or password, 429 throttled, connectivity). */
    suspend fun signIn(username: String, password: String) {
        val server = currentServer ?: throw ApiError.InvalidServerUrl
        val response = ApiClient(server, http, device).signIn(username, password, device)
        if (currentServer != server) return
        val credential = Credential(response.token, response.user)
        // If saving fails the token still works until the app quits.
        credentials.save(credential, server)
        prefs.setAuthRequired(server, true)
        prefs.setLastUsername(server, username)
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

    /** Queues a read-state write (persisted immediately) and tries to send it. */
    suspend fun record(kind: WriteKind, novelId: Int?) {
        val session = active ?: return
        session.queue.enqueue(kind, novelId)
        _pending.value = session.queue.count()
        if (!(kind is WriteKind.Progress && !kind.read)) _revision.update { it + 1 }
        flushWrites()
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
}
