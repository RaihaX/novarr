package studio.visns.novarr.domain

import java.util.Locale
import studio.visns.novarr.api.NovelSummary

/**
 * A series' country of origin: the server's `origin_country` ("JP" | "KR" |
 * "CN" | "EN" | null, see Novel::originCountry() on the server). The raw
 * value is kept as a string in the model; anything not recognised here
 * (blank, an unknown code, a newer server's addition) counts as unknown.
 */
object Origin {
    enum class Country(val code: String, private val novel: String, private val manga: String) {
        JP("JP", "Japanese", "Japanese manga"),
        KR("KR", "Korean", "Korean manhwa"),
        CN("CN", "Chinese", "Chinese manhua"),
        EN("EN", "English original", "English original");

        /** The badge's spoken label (as the web's aria-label, Novel::originCountryName()). */
        fun label(manga: Boolean): String = if (manga) this.manga else novel

        /** The Library filter's option name. */
        val title: String get() = novel
    }

    /** The country of a raw `origin_country`; null when absent or not recognised. */
    fun country(raw: String?): Country? {
        val code = raw?.trim()?.uppercase(Locale.ROOT)?.takeIf { it.isNotEmpty() } ?: return null
        return Country.entries.firstOrNull { it.code == code }
    }

    /** Null for a book (no origin, even if a value were sent), so no badge and the Unknown filter. */
    fun country(novel: NovelSummary): Country? = if (novel.isBook) null else country(novel.originCountry)

    /** The badge's content description, or null when there is no badge. */
    fun label(raw: String?, manga: Boolean): String? = country(raw)?.label(manga)

    /** Null for a book: books carry no origin. */
    fun label(novel: NovelSummary): String? = if (novel.isBook) null else country(novel)?.label(novel.isManga)

    /** The Library's Origin filter. */
    enum class Filter(val title: String, val country: Country?) {
        ALL("All", null),
        JAPANESE("Japanese", Country.JP),
        KOREAN("Korean", Country.KR),
        CHINESE("Chinese", Country.CN),
        ENGLISH("English original", Country.EN),
        UNKNOWN("Unknown", null);

        fun matches(novel: NovelSummary): Boolean = when (this) {
            ALL -> true
            UNKNOWN -> country(novel) == null
            else -> country(novel) == country
        }
    }

    /**
     * The filter is offered only when some series in the loaded library has
     * an origin: an older server sends none, and every series would be "Unknown".
     */
    fun offered(novels: List<NovelSummary>): Boolean = novels.any { country(it) != null }

    /** The filter in effect: a remembered choice is ignored while the filter is hidden. */
    fun effective(filter: Filter, novels: List<NovelSummary>): Filter = if (offered(novels)) filter else Filter.ALL
}
