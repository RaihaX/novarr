package studio.visns.novarr.ui.reader

import android.content.Context
import android.view.KeyEvent
import android.view.MotionEvent
import android.view.ViewGroup
import android.widget.FrameLayout
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.platform.LocalView
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import studio.visns.novarr.domain.PageKeyMap
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.theme.LocalEink

/** The reader that page keys turn, while one is on screen (see [PageKeyFrame]). */
object PageKeys {
    class Target(val volumeKeys: () -> Boolean, val turn: (forward: Boolean) -> Unit)

    @Volatile
    var target: Target? = null

    /**
     * Turns a page for a page key while a reader is registered; true when the
     * key was one (its repeats and key-up are swallowed too: one turn per press).
     */
    fun handle(event: KeyEvent): Boolean {
        val target = target ?: return false
        val forward = PageKeyMap.direction(event.keyCode, event.isShiftPressed, target.volumeKeys()) ?: return false
        if (event.action == KeyEvent.ACTION_DOWN && event.repeatCount == 0) target.turn(forward)
        return true
    }
}

/**
 * Holds the app's Compose content and takes page keys before the IME stage.
 * Later, Android swallows the first arrow, page or space key after any touch
 * (to leave touch mode), so after every tap the next page-button press would
 * be lost; and Compose would use arrows and space to move focus or press buttons.
 *
 * Pre-IME keys only reach views on the focus path, and a tap in the reader
 * leaves no view focused; so while a reader is open this frame is focusable
 * and takes focus whenever nothing inside it has it. Elsewhere it is not focusable.
 */
class PageKeyFrame(context: Context) : FrameLayout(context) {
    init {
        descendantFocusability = ViewGroup.FOCUS_AFTER_DESCENDANTS
        defaultFocusHighlightEnabled = false
    }

    override fun dispatchKeyEventPreIme(event: KeyEvent): Boolean = PageKeys.handle(event) || super.dispatchKeyEventPreIme(event)

    override fun dispatchTouchEvent(event: MotionEvent): Boolean {
        val handled = super.dispatchTouchEvent(event)
        if (event.actionMasked == MotionEvent.ACTION_UP || event.actionMasked == MotionEvent.ACTION_CANCEL) post { holdKeyFocus() }
        return handled
    }

    /** While a reader is registered: focusable, and focused unless a child already is. */
    fun holdKeyFocus() {
        val reading = PageKeys.target != null
        isFocusable = reading
        isFocusableInTouchMode = reading
        if (reading && !hasFocus()) requestFocus()
    }
}

/**
 * Makes [turn] the page-key target while this is composed. [novelPaged]: the
 * novel Pages mode, where the volume keys turn pages by default even outside
 * e-ink mode (PageKeyMap.volumeKeys).
 */
@Composable
internal fun RegisterPageKeys(novelPaged: Boolean, turn: (forward: Boolean) -> Unit) {
    val app = LocalApp.current
    val stored by app.prefs.volumeKeys.collectAsStateWithLifecycle(null)
    val volume by rememberUpdatedState(PageKeyMap.volumeKeys(stored, LocalEink.current, novelPaged))
    val currentTurn by rememberUpdatedState(turn)
    val view = LocalView.current
    DisposableEffect(Unit) {
        val target = PageKeys.Target({ volume }) { currentTurn(it) }
        PageKeys.target = target
        val frame = generateSequence(view.parent) { it.parent }.filterIsInstance<PageKeyFrame>().firstOrNull()
        frame?.post { frame.holdKeyFocus() }
        onDispose {
            if (PageKeys.target === target) PageKeys.target = null
            frame?.post { frame.holdKeyFocus() }
        }
    }
}
