package studio.visns.novarr

import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import studio.visns.novarr.api.NovarrJson
import studio.visns.novarr.domain.AudiobookUploads

/** POST /complete answering 422 checksum / incomplete with `missing`: those chunks go again. */
class AudiobookUploadResendTest {
    private fun body(json: String): JsonObject = NovarrJson.parseToJsonElement(json).jsonObject

    @Test
    fun checksumNamesChunksToResend() {
        val answer = body("""{"message":"A chunk was damaged.","code":"checksum","missing":{"0":[2,3],"1":[0]}}""")
        assertEquals(mapOf(0 to setOf(2, 3), 1 to setOf(0)), AudiobookUploads.resendAfterComplete(422, "checksum", answer))
    }

    @Test
    fun incompleteAlsoCarriesMissing() {
        val answer = body("""{"code":"incomplete","missing":{"1":[4]}}""")
        assertEquals(mapOf(1 to setOf(4)), AudiobookUploads.resendAfterComplete(422, "incomplete", answer))
        // An array of arrays (by file position) is read too; empty files drop out.
        val arrays = body("""{"code":"incomplete","missing":[[],[1,2]]}""")
        assertEquals(mapOf(1 to setOf(1, 2)), AudiobookUploads.resendAfterComplete(422, "incomplete", arrays))
    }

    @Test
    fun otherRefusalsStayRefusals() {
        val notAudio = body("""{"code":"not_audio","message":"Not an audiobook."}""")
        assertNull(AudiobookUploads.resendAfterComplete(422, "not_audio", notAudio))
        // A checksum answer with nothing readable listed: a real failure, not an endless loop.
        assertNull(AudiobookUploads.resendAfterComplete(422, "checksum", body("""{"code":"checksum"}""")))
        assertNull(AudiobookUploads.resendAfterComplete(422, "checksum", body("""{"code":"checksum","missing":{"0":[]}}""")))
        assertNull(AudiobookUploads.resendAfterComplete(409, "checksum", body("""{"missing":{"0":[1]}}""")))
    }

    @Test
    fun namedChunksAreResentEvenWhenListedPresent() {
        val plan = AudiobookUploads.plan(listOf(25L, 10L), chunkSize = 10)
        // The server lists every chunk as present, but says file 0's chunk 2 and file 1's chunk 0 were damaged.
        val present = mapOf(0 to setOf(0, 1, 2), 1 to setOf(0))
        assertEquals(emptyList<Any>(), AudiobookUploads.missing(plan, present))
        val again = AudiobookUploads.named(plan, mapOf(0 to setOf(2), 1 to setOf(0)))
        assertEquals(listOf(0 to 2, 1 to 0), again.map { it.file to it.index })
        assertEquals(5L + 10L, AudiobookUploads.bytesOf(again))
    }
}
