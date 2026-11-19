package studio.visns.novarr.app

import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import studio.visns.novarr.api.ApiClient
import studio.visns.novarr.api.ApiError
import studio.visns.novarr.api.rethrowIfCancellation
import studio.visns.novarr.domain.CheckDisplay
import studio.visns.novarr.domain.CheckPolling

/**
 * Runs "check for new chapters" for any number of series, app-wide, so a
 * check keeps being watched while the user moves around. Polls only while
 * the app is in the foreground and looks again straight away on return.
 */
class ChapterChecks(private val onChaptersChanged: (Int) -> Unit) {
    private val _displays = MutableStateFlow<Map<Int, CheckDisplay>>(emptyMap())
    val displays: StateFlow<Map<Int, CheckDisplay>> = _displays.asStateFlow()

    /** Bumped per series whenever its chapter list may have changed. */
    private val _revisions = MutableStateFlow<Map<Int, Int>>(emptyMap())
    val revisions: StateFlow<Map<Int, Int>> = _revisions.asStateFlow()

    private val _running = MutableStateFlow<Set<Int>>(emptySet())
    val running: StateFlow<Set<Int>> = _running.asStateFlow()

    private val tasks = mutableMapOf<Int, Job>()
    private var foreground = true
    private val wake = mutableSetOf<Int>()

    /** Starts (or joins) a check; does nothing if this series is already being watched. */
    fun start(novelId: Int, client: ApiClient, scope: CoroutineScope) {
        if (tasks.containsKey(novelId)) return
        _displays.update { it + (novelId to CheckDisplay.Checking(0)) }
        _running.update { it + novelId }
        tasks[novelId] = scope.launch {
            try {
                run(novelId, client)
            } finally {
                tasks.remove(novelId)
                _running.update { it - novelId }
            }
        }
    }

    fun setForeground(value: Boolean) {
        if (value && !foreground) wake.addAll(tasks.keys)
        foreground = value
    }

    fun cancelAll() {
        tasks.values.forEach { it.cancel() }
        tasks.clear()
        _displays.value = emptyMap()
        _running.value = emptySet()
        wake.clear()
    }

    private suspend fun run(novelId: Int, client: ApiClient) {
        val polling = CheckPolling(System.currentTimeMillis())
        var step: CheckPolling.Step
        try {
            // GET first: join a check that is already running rather than ask for another.
            val current = runCatching { client.check(novelId) }.getOrNull()
            val check = if (current != null && !CheckPolling.shouldStart(current)) current else client.startCheck(novelId)
            step = polling.receive(check, System.currentTimeMillis())
        } catch (e: Exception) {
            e.rethrowIfCancellation()
            _displays.update { it + (novelId to CheckDisplay.Failed(ApiError.from(e).userMessage)) }
            return
        }
        apply(polling, novelId)

        while (step is CheckPolling.Step.Poll) {
            waitForeground(step.afterMs, novelId)
            step = try {
                polling.receive(client.check(novelId), System.currentTimeMillis())
            } catch (e: Exception) {
                e.rethrowIfCancellation()
                polling.receiveError(ApiError.from(e).userMessage, System.currentTimeMillis())
            }
            apply(polling, novelId)
        }
        // Finished either way: refresh the series and its chapter list.
        if (!polling.chaptersChanged) chaptersChanged(novelId)
    }

    private fun apply(polling: CheckPolling, novelId: Int) {
        _displays.update { it + (novelId to polling.display) }
        if (polling.chaptersChanged) chaptersChanged(novelId)
    }

    private fun chaptersChanged(novelId: Int) {
        _revisions.update { it + (novelId to ((it[novelId] ?: 0) + 1)) }
        onChaptersChanged(novelId)
    }

    /** Sleeps [ms] of foreground time, or until woken on return to the foreground. */
    private suspend fun waitForeground(ms: Long, novelId: Int) {
        wake.remove(novelId)
        var waited = 0L
        while (true) {
            delay(TICK_MS)
            if (!foreground) continue
            waited += TICK_MS
            if (wake.remove(novelId) || waited >= ms) return
        }
    }

    private companion object {
        const val TICK_MS = 1_000L
    }
}
