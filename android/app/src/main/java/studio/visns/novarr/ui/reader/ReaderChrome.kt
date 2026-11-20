package studio.visns.novarr.ui.reader

import android.app.Activity
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Sync
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.paneTitle
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import studio.visns.novarr.store.ReaderFont
import studio.visns.novarr.ui.theme.BrandFonts
import studio.visns.novarr.store.ReaderTheme

/** Colours of a reading surface. [eink]: the e-ink palette, opaque throughout. */
data class ReaderColors(val background: Color, val text: Color, val isDark: Boolean, val eink: Boolean = false) {
    val secondary: Color get() = if (eink) Color(0xFF333333) else text.copy(alpha = 0.62f)

    /**
     * The tint behind highlighted passages: a translucent amber, light enough
     * on white and sepia to keep the text crisp, and dimmer on dark and black
     * grounds so the light text still reads over it. A solid light grey on e-ink.
     */
    val highlight: Color get() = if (eink) Color(0xFFD9D9D9) else if (isDark) Color(0x52E0A800) else Color(0x73FFC94D)

    /** Quiet fills (secondary buttons): a faint tint, or white with [rule] on e-ink. */
    val quietFill: Color get() = if (eink) background else text.copy(alpha = 0.08f)

    /** On e-ink, the 1 dp black line that takes the place of shadows and faint fills. */
    val rule: BorderStroke? get() = if (eink) BorderStroke(1.dp, text) else null

    companion object {
        /** E-ink reading: black on white whatever the stored reader theme (which is kept). */
        val Eink = ReaderColors(Color.White, Color.Black, isDark = false, eink = true)
    }
}

@Composable
fun ReaderTheme.colors(): ReaderColors = when (this) {
    ReaderTheme.SYSTEM -> MaterialTheme.colorScheme.let { ReaderColors(it.background, it.onBackground, it.background.luminance() < 0.5f) }
    ReaderTheme.LIGHT -> ReaderColors(Color.White, Color(0xFF1A1A1A), false)
    ReaderTheme.SEPIA -> ReaderColors(Color(0xFFF9F1E0), Color(0xFF4F3D29), false)
    ReaderTheme.DARK -> ReaderColors(Color(0xFF1C1C1F), Color(0xFFDBDBDB), true)
    ReaderTheme.BLACK -> ReaderColors(Color.Black, Color(0xFFC7C7C7), true)
}

fun ReaderFont.family(): FontFamily = when (this) {
    ReaderFont.LITERATA -> BrandFonts.Literata
    ReaderFont.GEIST -> BrandFonts.Geist
    ReaderFont.SERIF -> FontFamily.Serif
    ReaderFont.SANS -> FontFamily.SansSerif
}

/**
 * Immersive reading: system bars hide with the reader's own chrome, and their
 * icons follow the reading surface (dark icons on light/sepia). Restored on exit.
 */
@Composable
fun ReaderSystemBars(hidden: Boolean, darkSurface: Boolean, keepScreenOn: Boolean) {
    val view = LocalView.current
    val window = (view.context as? Activity)?.window ?: return
    val controller = WindowCompat.getInsetsController(window, view)
    SideEffect {
        controller.isAppearanceLightStatusBars = !darkSurface
        controller.isAppearanceLightNavigationBars = !darkSurface
    }
    LaunchedEffect(hidden) {
        controller.systemBarsBehavior = WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
        if (hidden) controller.hide(WindowInsetsCompat.Type.systemBars()) else controller.show(WindowInsetsCompat.Type.systemBars())
    }
    DisposableEffect(keepScreenOn) {
        view.keepScreenOn = keepScreenOn
        onDispose { view.keepScreenOn = false }
    }
    val appDark = MaterialTheme.colorScheme.background.luminance() < 0.5f
    DisposableEffect(Unit) {
        onDispose {
            controller.show(WindowInsetsCompat.Type.systemBars())
            controller.isAppearanceLightStatusBars = !appDark
            controller.isAppearanceLightNavigationBars = !appDark
        }
    }
}

/**
 * Card at the bottom of the reader offering to jump to where the series was
 * read more recently on another device. Non-blocking: the text stays
 * readable and scrollable behind it until the user picks an option.
 */
@Composable
fun LatestPositionBanner(message: String, colors: ReaderColors, onGo: () -> Unit, onStay: () -> Unit, modifier: Modifier = Modifier) {
    Surface(
        shape = RoundedCornerShape(16.dp),
        color = if (colors.isDark) Color(0xFF2A2A30) else colors.background,
        shadowElevation = if (colors.eink) 0.dp else 8.dp,
        modifier = modifier
            .widthIn(max = 560.dp)
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 12.dp)
            .border(1.dp, if (colors.eink) colors.text else colors.text.copy(alpha = 0.08f), RoundedCornerShape(16.dp))
            .semantics {
                paneTitle = "Latest reading position"
                liveRegion = LiveRegionMode.Polite
            },
    ) {
        Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Icon(Icons.Outlined.Sync, contentDescription = null, tint = MaterialTheme.colorScheme.primary)
                Text(message, color = colors.text, style = MaterialTheme.typography.bodyMedium)
            }
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                Button(
                    onClick = onStay,
                    colors = ButtonDefaults.buttonColors(containerColor = colors.quietFill, contentColor = colors.text),
                    border = colors.rule,
                    modifier = Modifier.weight(1f).heightIn(min = 48.dp),
                ) { Text("Stay here", textAlign = TextAlign.Center) }
                Button(onClick = onGo, modifier = Modifier.weight(1f).heightIn(min = 48.dp)) {
                    Text("Go to latest", textAlign = TextAlign.Center)
                }
            }
        }
    }
}

/** A round theme swatch with "Aa", for the settings form. */
@Composable
fun ThemeSwatch(theme: ReaderTheme, selected: Boolean, onClick: () -> Unit) {
    val colors = theme.colors()
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        modifier = Modifier
            .clickable(role = Role.RadioButton, onClick = onClick)
            .padding(4.dp)
            .semantics(mergeDescendants = true) {
                contentDescription = "${theme.title} theme"
                this.selected = selected
            },
    ) {
        Box(
            Modifier
                .size(44.dp)
                .border(if (selected) 3.dp else 1.dp, if (selected) MaterialTheme.colorScheme.primary else MaterialTheme.colorScheme.outlineVariant, CircleShape)
                .padding(if (selected) 3.dp else 1.dp),
            contentAlignment = Alignment.Center,
        ) {
            Surface(shape = CircleShape, color = colors.background, modifier = Modifier.size(38.dp)) {
                Box(contentAlignment = Alignment.Center) { Text("Aa", color = colors.text, style = MaterialTheme.typography.labelLarge) }
            }
        }
        Text(theme.title, style = MaterialTheme.typography.labelSmall)
    }
}
