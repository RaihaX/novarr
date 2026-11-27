package studio.visns.novarr.domain

import studio.visns.novarr.api.AndroidRelease

/**
 * The "a newer version is available" rules. The app is sideloaded from the
 * server's /app/android, so the server's meta says which build it publishes
 * (`meta.android.build`) and this compares it with the installed version code.
 */
object AppUpdate {
    /** Meta is re-fetched at launch and when Settings opens; otherwise not more often than this. */
    const val META_REFRESH_MS = 6 * 60 * 60 * 1000L

    /**
     * Whether to offer build [build] to an app at version code [installed]:
     * only a known build newer than this one. [dismissedBuild] is the build
     * whose notice the user closed (null: none); that build, or an older one,
     * is not offered again, while a newer one is. Pass null where the notice
     * can't be dismissed (the Settings row).
     */
    fun available(build: Int?, installed: Int, dismissedBuild: Int? = null): Boolean =
        build != null && build > installed && (dismissedBuild == null || build > dismissedBuild)

    /** [available] for a release from meta; a release without a download address is never offered. */
    fun available(release: AndroidRelease?, installed: Int, dismissedBuild: Int? = null): Boolean =
        release != null && release.url.isNotBlank() && available(release.build, installed, dismissedBuild)

    /**
     * Whether the background check posts "Novarr 1.4 is available" for build
     * [build]: only with the notification switch [enabled], for a build newer
     * than the installed [installed], and once per build: [lastNotified] is
     * the build last notified (or already shown in the app; null: none), so
     * that build or an older one stays quiet while a newer one notifies again.
     */
    fun shouldNotify(build: Int?, installed: Int, lastNotified: Int?, enabled: Boolean): Boolean =
        enabled && available(build, installed, lastNotified)

    /** [shouldNotify] for a release from meta; a release without a download address never notifies. */
    fun shouldNotify(release: AndroidRelease?, installed: Int, lastNotified: Int?, enabled: Boolean): Boolean =
        enabled && available(release, installed, lastNotified)

    /** At most this many release notes are shown under the update notice. */
    const val MAX_NOTES = 8

    /**
     * The release notes to show under the notice ("What's new"): trimmed,
     * blank lines dropped, at most [MAX_NOTES]. Empty for a server that
     * sends none (the notice then shows no "What's new").
     */
    fun notes(release: AndroidRelease?): List<String> =
        release?.notes.orEmpty().map { it.trim() }.filter { it.isNotEmpty() }.take(MAX_NOTES)

    /** "1.3", or "build 4" when the server doesn't name the version. */
    fun label(release: AndroidRelease): String =
        release.version?.takeIf { it.isNotBlank() } ?: "build ${release.build}"

    /** Whether meta fetched at [fetchedAt] (epoch ms; null: never) is due again at [now]. */
    fun metaDue(fetchedAt: Long?, now: Long): Boolean =
        fetchedAt == null || now - fetchedAt >= META_REFRESH_MS || now < fetchedAt
}
