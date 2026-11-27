package studio.visns.novarr.ui.stats

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ReadingStats
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.StatsFormat
import studio.visns.novarr.store.OfflineStore

/**
 * The Stats screen: the chosen range's answer, the saved copy first (so it
 * shows at once and offline), then a fresh one when the server is reachable.
 */
class StatsViewModel(private val app: AppModel) : ViewModel() {
    var range by mutableIntStateOf(StatsFormat.RANGES.first())
        private set
    var stats by mutableStateOf<ReadingStats?>(null)
        private set
    var fromCache by mutableStateOf(false)
        private set
    var failure by mutableStateOf<String?>(null)
        private set
    var refreshing by mutableStateOf(false)
        private set
    private var job: Job? = null

    fun select(newRange: Int) {
        if (newRange == range) return
        range = newRange
        load()
    }

    fun load(pull: Boolean = false) {
        job?.cancel()
        val wanted = range
        job = viewModelScope.launch {
            if (pull) refreshing = true
            val cached = app.cached(OfflineStore.Key.stats(wanted))
            // Another range's figures are not this one's: the saved copy, or a spinner.
            if (cached != null || stats?.range != wanted) {
                stats = cached
                fromCache = cached != null
            }
            val result = app.load(OfflineStore.Key.stats(wanted)) { it.stats(wanted) }
            result.value?.let { stats = it }
            fromCache = result.fromCache
            failure = if (result.value != null) null else StatsFormat.failureMessage(result.error)
            refreshing = false
        }
    }
}

/**
 * Home's "Your stats" card: the 30-day answer (saved copy, then fresh),
 * reloaded when read state changed or it is a few minutes old. Null (card
 * hidden) when there is nothing to show.
 */
class StatsCardViewModel(private val app: AppModel) : ViewModel() {
    var stats by mutableStateOf<ReadingStats?>(null)
        private set
    private var loadedAt = 0L
    private var loadedRevision = -1
    private var job: Job? = null

    fun refreshIfStale() {
        val stale = System.currentTimeMillis() - loadedAt > 5 * 60_000
        if (stale || loadedRevision != app.readStateRevision.value) load()
    }

    private fun load() {
        if (job?.isActive == true) return
        loadedRevision = app.readStateRevision.value
        job = viewModelScope.launch {
            val key = OfflineStore.Key.stats(StatsFormat.RANGES.first())
            if (stats == null) stats = app.cached(key)
            val result = app.load(key) { it.stats(StatsFormat.RANGES.first()) }
            when {
                result.value != null && !result.fromCache -> {
                    stats = result.value
                    loadedAt = System.currentTimeMillis()
                }
                // An older server without stats (and nothing saved): no card.
                StatsFormat.isUnsupported(result.error) && result.value == null -> stats = null
                else -> result.value?.let { stats = it }
            }
        }
    }
}
