package studio.visns.novarr

import java.io.File
import java.nio.file.Files
import kotlinx.coroutines.test.runTest
import kotlinx.serialization.builtins.ListSerializer
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import studio.visns.novarr.api.HomeFeed
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.api.NovelEnvelope
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.api.NovelsEnvelope
import studio.visns.novarr.api.ReadingList
import studio.visns.novarr.domain.LibraryFilter
import studio.visns.novarr.domain.LibraryFilter.Filter
import studio.visns.novarr.domain.LibraryFilter.Sort
import studio.visns.novarr.domain.Origin
import studio.visns.novarr.domain.Origin.Country
import studio.visns.novarr.store.OfflineStore

class OriginTest {
    @Test
    fun novelLabels() {
        assertEquals("Japanese", Origin.label("JP", manga = false))
        assertEquals("Korean", Origin.label("KR", manga = false))
        assertEquals("Chinese", Origin.label("CN", manga = false))
        assertEquals("English original", Origin.label("EN", manga = false))
    }

    @Test
    fun mangaLabels() {
        assertEquals("Japanese manga", Origin.label("JP", manga = true))
        assertEquals("Korean manhwa", Origin.label("KR", manga = true))
        assertEquals("Chinese manhua", Origin.label("CN", manga = true))
        assertEquals("English original", Origin.label("EN", manga = true))
    }

    @Test
    fun labelFollowsTheSeriesMedium() {
        assertEquals("Korean manhwa", Origin.label(NovelSummary(1, medium = "manga", originCountry = "KR")))
        assertEquals("Korean", Origin.label(NovelSummary(1, medium = "novel", originCountry = "KR")))
        assertEquals("Korean manhwa", Origin.label(NovelSummary(1, medium = "MANGA", originCountry = "KR")))
    }

    @Test
    fun aBookHasNoOrigin() {
        val book = NovelSummary(1, medium = "book", originCountry = "JP")
        assertNull(Origin.label(book))
        assertNull(Origin.country(book))
        assertNull(Origin.label(NovelSummary(2, medium = "book")))
        // So the Origin filter is not offered for a library of books alone.
        assertFalse(Origin.offered(listOf(book)))
    }

    @Test
    fun lowercaseAndPaddedCodesAreRead() {
        assertEquals(Country.JP, Origin.country("jp"))
        assertEquals(Country.KR, Origin.country(" kr "))
        assertEquals(Country.CN, Origin.country("Cn"))
    }

    @Test
    fun unknownBlankAndNullCodesAreNoOrigin() {
        assertNull(Origin.country(null))
        assertNull(Origin.country(""))
        assertNull(Origin.country("   "))
        assertNull(Origin.country("FR"))
        assertNull(Origin.country("ja")) // a language, not a country code
        assertNull(Origin.country("JPN"))
        assertNull(Origin.label("XX", manga = true))
    }

    // Library filter

    private val series = listOf(
        NovelSummary(1, "Lanterns", originCountry = "CN", chaptersRead = 3, chaptersTotal = 9),
        NovelSummary(2, "Glass Archive", originCountry = "KR"),
        NovelSummary(3, "Moonlit Ferryman", originCountry = "JP", chaptersRead = 2, chaptersTotal = 6),
        NovelSummary(4, "Salt Road", originCountry = "EN"),
        NovelSummary(5, "Nine Heavens", originCountry = null, chaptersRead = 1, chaptersTotal = 20),
        NovelSummary(6, "Odd Code", originCountry = "zz"),
        NovelSummary(7, "Korean Lanterns", originCountry = "kr", author = "Lee"),
    )

    private fun ids(origin: Origin.Filter, filter: Filter = Filter.ALL, search: String = "", list: ReadingList? = null) =
        LibraryFilter.apply(series, filter, Sort.NAME, search, list, origin).map { it.id }.sorted()

    @Test
    fun originFilterAlone() {
        assertEquals(listOf(1, 2, 3, 4, 5, 6, 7), ids(Origin.Filter.ALL))
        assertEquals(listOf(3), ids(Origin.Filter.JAPANESE))
        assertEquals(listOf(2, 7), ids(Origin.Filter.KOREAN))
        assertEquals(listOf(1), ids(Origin.Filter.CHINESE))
        assertEquals(listOf(4), ids(Origin.Filter.ENGLISH))
        // Null and unrecognised codes are both "Unknown": each series is in exactly one option.
        assertEquals(listOf(5, 6), ids(Origin.Filter.UNKNOWN))
        val buckets = Origin.Filter.entries.filter { it != Origin.Filter.ALL }.sumOf { ids(it).size }
        assertEquals(series.size, buckets)
    }

    @Test
    fun originFilterCombinesWithStatusSearchAndList() {
        assertEquals(listOf(1, 3, 5), ids(Origin.Filter.ALL, Filter.READING))
        assertEquals(listOf(5), ids(Origin.Filter.UNKNOWN, Filter.READING))
        assertEquals(listOf(1), ids(Origin.Filter.CHINESE, Filter.READING))
        assertEquals(emptyList<Int>(), ids(Origin.Filter.KOREAN, Filter.READING))
        // Search, then origin.
        assertEquals(listOf(1, 7), ids(Origin.Filter.ALL, search = "lanterns"))
        assertEquals(listOf(7), ids(Origin.Filter.KOREAN, search = "lanterns"))
        assertEquals(listOf(7), ids(Origin.Filter.KOREAN, search = "lee"))
        assertEquals(emptyList<Int>(), ids(Origin.Filter.JAPANESE, search = "lanterns"))
        // A reading list, then origin, then search.
        val list = ReadingList(10, "Favourites", novelIds = listOf(1, 2, 5, 7))
        assertEquals(listOf(1, 2, 5, 7), ids(Origin.Filter.ALL, list = list))
        assertEquals(listOf(2, 7), ids(Origin.Filter.KOREAN, list = list))
        assertEquals(listOf(5), ids(Origin.Filter.UNKNOWN, list = list))
        assertEquals(listOf(7), ids(Origin.Filter.KOREAN, search = "korean", list = list))
        assertEquals(emptyList<Int>(), ids(Origin.Filter.JAPANESE, list = list))
    }

    @Test
    fun countsCombineStatusOriginAndList() {
        val list = ReadingList(10, "Favourites", novelIds = listOf(1, 2, 5, 7))
        assertEquals(2, LibraryFilter.count(series, null, Filter.ALL, Origin.Filter.KOREAN))
        assertEquals(1, LibraryFilter.count(series, null, Filter.READING, Origin.Filter.CHINESE))
        assertEquals(1, LibraryFilter.count(series, list, Filter.READING, Origin.Filter.UNKNOWN))
        assertEquals(4, LibraryFilter.count(series, list, Filter.ALL))
        assertEquals(2, LibraryFilter.count(series, list, Filter.READING))
    }

    @Test
    fun oldServerHidesTheFilterAndIgnoresARememberedChoice() {
        val old = listOf(NovelSummary(1, "A"), NovelSummary(2, "B", originCountry = ""), NovelSummary(3, "C", originCountry = "??"))
        assertFalse(Origin.offered(old))
        assertFalse(Origin.offered(emptyList()))
        assertEquals(Origin.Filter.ALL, Origin.effective(Origin.Filter.KOREAN, old))
        assertEquals(Origin.Filter.ALL, Origin.effective(Origin.Filter.UNKNOWN, old))
        assertEquals(3, LibraryFilter.apply(old, Filter.ALL, Sort.NAME, "", null, Origin.effective(Origin.Filter.UNKNOWN, old)).size)
        assertTrue(Origin.offered(series))
        assertEquals(Origin.Filter.KOREAN, Origin.effective(Origin.Filter.KOREAN, series))
    }

    @Test
    fun filterOptionTitles() {
        assertEquals(
            listOf("All", "Japanese", "Korean", "Chinese", "English original", "Unknown"),
            Origin.Filter.entries.map { it.title },
        )
    }

    // Decoding

    @Test
    fun decodesWithAndWithoutTheField() {
        val json = """{"novels":[
            {"id":1,"name":"With","origin_country":"KR","medium":"manga"},
            {"id":2,"name":"Null","origin_country":null},
            {"id":3,"name":"Absent"},
            {"id":4,"name":"Lower","origin_country":"jp"},
            {"id":5,"name":"Odd","origin_country":"Atlantis"},
            {"id":6,"name":"Object","origin_country":{"code":"KR"}},
            {"id":7,"name":"Number","origin_country":7}
        ]}"""
        val novels = NovarrJson.decodeFromString(NovelsEnvelope.serializer(), json).novels
        // A malformed value never drops the series.
        assertEquals(listOf(1, 2, 3, 4, 5, 6, 7), novels.map { it.id })
        assertEquals("KR", novels[0].originCountry)
        assertEquals("Korean manhwa", Origin.label(novels[0]))
        assertNull(novels[1].originCountry)
        assertNull(novels[2].originCountry)
        assertEquals(Country.JP, Origin.country(novels[3]))
        assertNull(Origin.country(novels[4]))
        assertNull(novels[5].originCountry)
        assertNull(Origin.country(novels[6]))
    }

    @Test
    fun decodesInSeriesDetailAndHomeShelves() {
        val detail = NovarrJson.decodeFromString(
            NovelEnvelope.serializer(),
            """{"novel":{"id":9,"name":"D","origin_country":"CN","description":"Hi"}}""",
        ).novel
        assertEquals("CN", detail.summary.originCountry)
        assertEquals("Hi", detail.description)
        val home = NovarrJson.decodeFromString(
            HomeFeed.serializer(),
            """{"continue":[{"novel":{"id":1,"origin_country":"EN"},"chapter":{"id":5}}],
               "new_chapters":[{"novel":{"id":2,"origin_country":"KR"},"count":2},{"novel":{"id":3},"count":1}]}""",
        )
        assertEquals("EN", home.continueReading.single().novel.originCountry)
        assertEquals(listOf("KR", null), home.newChapters.map { it.novel.originCountry })
    }

    // Offline cache

    private fun tempDir(): File = Files.createTempDirectory("origin").toFile()

    @Test
    fun originSurvivesTheOfflineLibraryCache() = runTest {
        val store = OfflineStore(tempDir())
        store.save(listOf(NovelSummary(1, "One", originCountry = "KR", medium = "manga"), NovelSummary(2, "Two")), OfflineStore.Key.novels)
        val back = store.load(OfflineStore.Key.novels)!!
        assertEquals(listOf("KR", null), back.map { it.originCountry })
        assertEquals("Korean manhwa", Origin.label(back[0]))
    }

    @Test
    fun anOldCacheWithoutTheFieldStillLoads() = runTest {
        val dir = tempDir()
        // As 1.6 wrote it: every NovelSummary field but origin_country.
        val old = NovarrJson.encodeToString(ListSerializer(NovelSummary.serializer()), listOf(NovelSummary(1, "One"), NovelSummary(2, "Two")))
            .replace(",\"origin_country\":null", "")
        assertFalse(old.contains("origin_country"))
        File(dir, "novels.json").writeText(old)
        val back = OfflineStore(dir).load(OfflineStore.Key.novels)
        assertEquals(listOf(1, 2), back?.map { it.id })
        assertEquals(listOf<String?>(null, null), back?.map { it.originCountry })
        assertFalse(Origin.offered(back.orEmpty()))
    }
}
