package studio.visns.novarr.ui.reader

import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.calculatePan
import androidx.compose.foundation.gestures.calculateZoom
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.PointerEventPass
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.input.pointer.positionChanged
import androidx.compose.ui.unit.IntSize

/** Scale and pan of a zoomable page; reset with [reset]. */
@Stable
class ZoomState(private val maxScale: Float = 5f) {
    var scale by mutableFloatStateOf(1f)
        private set
    var offset by mutableStateOf(Offset.Zero)
        private set
    val zoomed: Boolean get() = scale > 1.01f

    fun reset() {
        scale = 1f
        offset = Offset.Zero
    }

    fun toggle(at: Offset, size: IntSize) {
        if (zoomed) {
            reset()
        } else {
            scale = 2.5f
            // Zoom towards the tapped point.
            val center = Offset(size.width / 2f, size.height / 2f)
            offset = clamp((center - at) * (scale - 1f), size)
        }
    }

    fun transform(zoom: Float, pan: Offset, size: IntSize, panY: Boolean) {
        scale = (scale * zoom).coerceIn(1f, maxScale)
        val move = if (panY) pan else Offset(pan.x, 0f)
        offset = if (scale <= 1f) Offset.Zero else clamp(offset + move, size)
    }

    private fun clamp(value: Offset, size: IntSize): Offset {
        val maxX = size.width * (scale - 1f) / 2f
        val maxY = size.height * (scale - 1f) / 2f
        return Offset(value.x.coerceIn(-maxX, maxX), value.y.coerceIn(-maxY, maxY))
    }
}

/**
 * Pinch to zoom, pan while zoomed, double-tap to toggle zoom, single tap for
 * [onTap] (with where it landed, in the unzoomed page's coordinates). Gestures are read in the Initial pass so a pinch wins over the
 * pager or list underneath; a one-finger drag at 1x is left alone so paging
 * and scrolling keep working.
 *
 * [panY]: false for the vertical (webtoon) list, where vertical movement must
 * keep scrolling the list even when zoomed in.
 * [doubleTap]: false leaves out double-tap zoom, so a single tap is reported
 * straight away instead of after the double-tap timeout (e-ink).
 */
fun Modifier.zoomable(
    state: ZoomState,
    panY: Boolean = true,
    doubleTap: Boolean = true,
    onTap: (at: Offset, width: Int) -> Unit = { _, _ -> },
): Modifier = this
    .pointerInput(state, panY) {
        awaitEachGesture {
            awaitFirstDown(requireUnconsumed = false, pass = PointerEventPass.Initial)
            do {
                val event = awaitPointerEvent(PointerEventPass.Initial)
                val fingers = event.changes.count { it.pressed }
                if (fingers >= 2 || state.zoomed) {
                    state.transform(event.calculateZoom(), event.calculatePan(), size, panY)
                    // Keep the pager from turning pages while pinching or panning a zoomed page.
                    if (fingers >= 2 || panY) event.changes.forEach { if (it.positionChanged()) it.consume() }
                }
            } while (event.changes.any { it.pressed })
        }
    }
    .pointerInput(state, doubleTap) {
        if (doubleTap) {
            detectTapGestures(onDoubleTap = { state.toggle(it, size) }, onTap = { onTap(it, size.width) })
        } else {
            detectTapGestures(onTap = { onTap(it, size.width) })
        }
    }
    .graphicsLayer {
        scaleX = state.scale
        scaleY = state.scale
        translationX = state.offset.x
        translationY = state.offset.y
    }
