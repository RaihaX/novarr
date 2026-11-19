package studio.visns.novarr.domain

import java.io.File
import java.io.InputStream
import java.security.MessageDigest

/**
 * Where an in-app update is: Idle (offer "Update") → Downloading → Verifying
 * → NeedsPermission (only while Android won't let Novarr install apps yet) →
 * AwaitingConfirmation (the system's install dialog) → the new version
 * starts, or Failed. A retry with the file already downloaded and checked
 * skips the download: Idle/Failed → Verifying ([UpdateEvent.Reuse]).
 */
sealed interface UpdateState {
    data object Idle : UpdateState

    /** [total] is null when neither the response nor meta gives a length. */
    data class Downloading(val received: Long, val total: Long?) : UpdateState {
        val percent: Int? get() = total?.takeIf { it > 0 }?.let { ((received * 100) / it).toInt().coerceIn(0, 100) }
    }

    data object Verifying : UpdateState
    data object NeedsPermission : UpdateState
    data object AwaitingConfirmation : UpdateState
    data class Failed(val reason: UpdateFailure) : UpdateState

    /** Whether work is running or waiting on the user (the notice can't be dismissed, Update can't start). */
    val busy: Boolean get() = this !is Idle && this !is Failed
}

enum class UpdateFailure(val message: String) {
    Network("The download didn't finish. Check the connection and try again."),
    Mismatch("The download didn't match the server's file. Try again."),
    Signature("This build is signed differently from the installed app. Uninstall Novarr and install it again from the link."),
    Storage("There isn't enough storage to install the update. Free some space and try again."),

    /** Android refused to start the install (a device policy), before any dialog. */
    Blocked("This device blocked the install. Try installing it from the browser instead."),
    Invalid("The downloaded file isn't a valid app. Try again, or install it from the browser."),

    /**
     * Any other install that didn't go through, including "aborted": Google
     * Play Protect's "Harmful app blocked" (a false positive on a sideloaded
     * app) ends the session just like the user cancelling, so the two can't
     * be told apart. The downloaded file is kept for the retry.
     */
    Unfinished("The install didn't finish. If Google Play Protect blocked it, tap Update again, then More details → Install anyway.");

    /** Whether the downloaded file is still good for a retry (only an install that merely didn't finish). */
    val keepsFile: Boolean get() = this == Unfinished
}

sealed interface UpdateEvent {
    data object Start : UpdateEvent

    /** Retry with the file a previous attempt downloaded: it is checked again, not downloaded. */
    data object Reuse : UpdateEvent

    /** The kept file no longer matched meta: download it after all. */
    data object Redownload : UpdateEvent
    data class Progress(val received: Long, val total: Long?) : UpdateEvent
    data object Downloaded : UpdateEvent

    /** The file matched meta (or meta gave nothing to check); [canInstall]: Android already allows Novarr to install apps. */
    data class Verified(val canInstall: Boolean) : UpdateEvent
    data object PermissionGranted : UpdateEvent

    /** A PackageInstaller status for the committed session. */
    data class InstallStatus(val status: Int) : UpdateEvent
    data class Fail(val reason: UpdateFailure) : UpdateEvent
    data object Cancel : UpdateEvent
}

object UpdateMachine {
    // PackageInstaller.STATUS_* (stable public API values), repeated here so this stays plain Kotlin.
    const val STATUS_PENDING_USER_ACTION = -1
    const val STATUS_SUCCESS = 0
    const val STATUS_FAILURE = 1
    const val STATUS_FAILURE_BLOCKED = 2
    const val STATUS_FAILURE_ABORTED = 3
    const val STATUS_FAILURE_INVALID = 4
    const val STATUS_FAILURE_CONFLICT = 5
    const val STATUS_FAILURE_STORAGE = 6
    const val STATUS_FAILURE_INCOMPATIBLE = 7

    /**
     * The next state. An event that doesn't fit the current state (a late
     * progress tick after Cancel, a second Start) leaves it unchanged.
     */
    fun reduce(state: UpdateState, event: UpdateEvent): UpdateState = when (event) {
        UpdateEvent.Start ->
            if (state is UpdateState.Idle || state is UpdateState.Failed) UpdateState.Downloading(0, null) else state
        UpdateEvent.Reuse ->
            if (state is UpdateState.Idle || state is UpdateState.Failed) UpdateState.Verifying else state
        UpdateEvent.Redownload ->
            if (state is UpdateState.Verifying) UpdateState.Downloading(0, null) else state
        is UpdateEvent.Progress ->
            if (state is UpdateState.Downloading) UpdateState.Downloading(event.received, event.total) else state
        UpdateEvent.Downloaded ->
            if (state is UpdateState.Downloading) UpdateState.Verifying else state
        is UpdateEvent.Verified ->
            if (state is UpdateState.Verifying) {
                if (event.canInstall) UpdateState.AwaitingConfirmation else UpdateState.NeedsPermission
            } else state
        UpdateEvent.PermissionGranted ->
            if (state is UpdateState.NeedsPermission) UpdateState.AwaitingConfirmation else state
        is UpdateEvent.InstallStatus ->
            if (state is UpdateState.AwaitingConfirmation) afterStatus(event.status) else state
        is UpdateEvent.Fail ->
            if (state.busy) UpdateState.Failed(event.reason) else state
        UpdateEvent.Cancel -> UpdateState.Idle
    }

    /** What a PackageInstaller status leaves the update at. */
    fun afterStatus(status: Int): UpdateState = when (status) {
        STATUS_PENDING_USER_ACTION -> UpdateState.AwaitingConfirmation
        // Android replaces (and restarts) the process; nothing is left to show.
        STATUS_SUCCESS -> UpdateState.Idle
        // Cancelled in the system dialog, or Play Protect blocked it (the same status): say what to do.
        else -> UpdateState.Failed(failureFor(status))
    }

    /**
     * The reason for a failed install status. Only what the app can tell for
     * sure gets its own message; everything else (aborted, blocked, a
     * generic failure, an unknown status) is [UpdateFailure.Unfinished].
     */
    fun failureFor(status: Int): UpdateFailure = when (status) {
        STATUS_FAILURE_CONFLICT, STATUS_FAILURE_INCOMPATIBLE -> UpdateFailure.Signature
        STATUS_FAILURE_STORAGE -> UpdateFailure.Storage
        STATUS_FAILURE_INVALID -> UpdateFailure.Invalid
        else -> UpdateFailure.Unfinished
    }

    /**
     * What the banner and the Settings row show for build [build] while the
     * updater is at [state] for build [stateBuild]. [unfinishedBuild] is the
     * build whose last install didn't finish (saved, so it outlives a
     * restart; null: none). A failure that belongs to an older build than
     * the one now offered is forgotten; an idle updater for the build whose
     * install didn't finish shows that message again, until an attempt
     * succeeds (the app is replaced) or a newer build appears.
     */
    fun shown(state: UpdateState, stateBuild: Int?, build: Int?, unfinishedBuild: Int?): UpdateState = when {
        state is UpdateState.Failed && stateBuild != build -> UpdateState.Idle
        state is UpdateState.Idle && build != null && build == unfinishedBuild -> UpdateState.Failed(UpdateFailure.Unfinished)
        else -> state
    }

    /**
     * Whether to show the one-line Play Protect hint ("Android may warn…")
     * under the notice: only while it offers "Update" ([shown] is Idle) and
     * before the first in-app install attempt of [build]. [attemptedBuild] is
     * the newest build an attempt was started for (null: never).
     */
    fun showsInstallHint(shown: UpdateState, build: Int?, attemptedBuild: Int?): Boolean =
        shown is UpdateState.Idle && (build == null || attemptedBuild == null || attemptedBuild < build)

    const val INSTALL_HINT = "Android may warn about apps from outside the Play Store. Choose More details → Install anyway."
}

/** Checks a downloaded APK against the size and SHA-256 the server's meta gives. */
object ApkCheck {
    /**
     * Whether a file of [size] bytes hashing to [sha256] matches meta. Each
     * expectation is checked only when meta gives it (older servers send
     * neither); the hash compares case-insensitively.
     */
    fun matches(size: Long, sha256: String?, expectedSize: Long?, expectedSha256: String?): Boolean {
        if (expectedSize != null && expectedSize != size) return false
        val want = expectedSha256?.trim()?.takeIf { it.isNotEmpty() } ?: return true
        return sha256 != null && sha256.equals(want, ignoreCase = true)
    }

    /** [matches] for [file]; the hash is only computed when meta gives one to compare with. */
    fun verify(file: File, expectedSize: Long?, expectedSha256: String?): Boolean {
        if (!file.isFile) return false
        val size = file.length()
        if (expectedSize != null && expectedSize != size) return false
        val sha = if (expectedSha256.isNullOrBlank()) null else file.inputStream().use(::sha256Hex)
        return matches(size, sha, expectedSize, expectedSha256)
    }

    fun sha256Hex(input: InputStream): String {
        val digest = MessageDigest.getInstance("SHA-256")
        val buffer = ByteArray(64 * 1024)
        while (true) {
            val n = input.read(buffer)
            if (n < 0) break
            digest.update(buffer, 0, n)
        }
        return digest.digest().joinToString("") { "%02x".format(it) }
    }
}
