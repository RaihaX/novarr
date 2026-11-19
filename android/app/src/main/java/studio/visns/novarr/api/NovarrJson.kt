package studio.visns.novarr.api

import kotlinx.serialization.KSerializer
import kotlinx.serialization.builtins.ListSerializer
import kotlinx.serialization.descriptors.SerialDescriptor
import kotlinx.serialization.encoding.Decoder
import kotlinx.serialization.encoding.Encoder
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonDecoder

/**
 * The one Json configuration for API responses and the on-disk cache, so
 * cached files read back through exactly the same path as live responses.
 *
 * Deliberately lenient (like the iOS client): unknown keys are ignored,
 * nulls and unknown values fall back to defaults, and quoted numbers or
 * booleans are accepted.
 */
val NovarrJson: Json = Json {
    ignoreUnknownKeys = true
    coerceInputValues = true
    isLenient = true
    explicitNulls = false
    encodeDefaults = true
}

/**
 * Decodes a JSON array element by element, skipping elements that fail, so
 * one malformed row never empties a whole list. Anything that is not an
 * array decodes as an empty list.
 */
open class LossyListSerializer<T>(private val element: KSerializer<T>) : KSerializer<List<T>> {
    private val strict = ListSerializer(element)
    override val descriptor: SerialDescriptor = strict.descriptor

    override fun deserialize(decoder: Decoder): List<T> {
        val json = decoder as? JsonDecoder ?: return strict.deserialize(decoder)
        val array = json.decodeJsonElement() as? JsonArray ?: return emptyList()
        return array.mapNotNull { item ->
            runCatching { json.json.decodeFromJsonElement(element, item) }.getOrNull()
        }
    }

    override fun serialize(encoder: Encoder, value: List<T>) = strict.serialize(encoder, value)
}
