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

    /** "1.3", or "build 4" when the server doesn't name the version. */
    fun label(release: AndroidRelease): String =
        release.version?.takeIf { it.isNotBlank() } ?: "build ${release.build}"

    /** Whether meta fetched at [fetchedAt] (epoch ms; null: never) is due again at [now]. */
    fun metaDue(fetchedAt: Long?, now: Long): Boolean =
        fetchedAt == null || now - fetchedAt >= META_REFRESH_MS || now < fetchedAt
}
