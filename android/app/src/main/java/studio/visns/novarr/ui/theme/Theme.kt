package studio.visns.novarr.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight

// The brand accent is the iOS AccentColor (indigo; lighter in dark mode).
// Dynamic colour is off on purpose: the app keeps its own identity.
val Indigo = Color(0xFF4F46E5)
val IndigoDark = Color(0xFF818CF8)
val Amber = Color(0xFFF0B429)

private val Light = lightColorScheme(
    primary = Indigo,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFE2E1FD),
    onPrimaryContainer = Color(0xFF1E1A6E),
    secondary = Color(0xFF5B5D72),
    onSecondary = Color.White,
    secondaryContainer = Color(0xFFE2E1F2),
    onSecondaryContainer = Color(0xFF181A2C),
    tertiary = Color(0xFF8A5A00),
    onTertiary = Color.White,
    tertiaryContainer = Color(0xFFFFDEA6),
    onTertiaryContainer = Color(0xFF2B1A00),
    background = Color(0xFFFBFBFE),
    onBackground = Color(0xFF1A1B21),
    surface = Color(0xFFFBFBFE),
    onSurface = Color(0xFF1A1B21),
    surfaceVariant = Color(0xFFE3E2EC),
    onSurfaceVariant = Color(0xFF555761),
    surfaceContainerLowest = Color.White,
    surfaceContainerLow = Color(0xFFF5F5FA),
    surfaceContainer = Color(0xFFEFEFF5),
    surfaceContainerHigh = Color(0xFFE9E9F0),
    surfaceContainerHighest = Color(0xFFE3E3EA),
    outline = Color(0xFF777985),
    outlineVariant = Color(0xFFC7C6D2),
    error = Color(0xFFBA1A1A),
)

private val Dark = darkColorScheme(
    primary = IndigoDark,
    onPrimary = Color(0xFF14105A),
    primaryContainer = Color(0xFF3730A3),
    onPrimaryContainer = Color(0xFFE2E1FD),
    secondary = Color(0xFFC4C4DD),
    onSecondary = Color(0xFF2D2F42),
    secondaryContainer = Color(0xFF434559),
    onSecondaryContainer = Color(0xFFE2E1F2),
    tertiary = Amber,
    onTertiary = Color(0xFF452B00),
    tertiaryContainer = Color(0xFF633F00),
    onTertiaryContainer = Color(0xFFFFDEA6),
    background = Color(0xFF121318),
    onBackground = Color(0xFFE4E3EA),
    surface = Color(0xFF121318),
    onSurface = Color(0xFFE4E3EA),
    surfaceVariant = Color(0xFF45464F),
    onSurfaceVariant = Color(0xFFC6C5D0),
    surfaceContainerLowest = Color(0xFF0D0E12),
    surfaceContainerLow = Color(0xFF1A1B21),
    surfaceContainer = Color(0xFF1E1F25),
    surfaceContainerHigh = Color(0xFF292A30),
    surfaceContainerHighest = Color(0xFF34343B),
    outline = Color(0xFF90909A),
    outlineVariant = Color(0xFF45464F),
    error = Color(0xFFFFB4AB),
)

/** Titles in a serif, like the iOS app's New York headings; body text stays sans. */
private fun typography(): Typography {
    val base = Typography()
    fun TextStyle.serif() = copy(fontFamily = FontFamily.Serif, fontWeight = FontWeight.SemiBold)
    return base.copy(
        displaySmall = base.displaySmall.serif(),
        headlineLarge = base.headlineLarge.serif(),
        headlineMedium = base.headlineMedium.serif(),
        headlineSmall = base.headlineSmall.serif(),
        titleLarge = base.titleLarge.serif(),
    )
}

private val NovarrTypography = typography()

@Composable
fun NovarrTheme(dark: Boolean = isSystemInDarkTheme(), content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = if (dark) Dark else Light, typography = NovarrTypography, content = content)
}

/** Success green that reads on both themes. */
val ColorScheme.success: Color get() = if (background.luminanceIsDark()) Color(0xFF7BD88F) else Color(0xFF1E7B34)

/** Warning orange that reads on both themes. */
val ColorScheme.warning: Color get() = if (background.luminanceIsDark()) Color(0xFFFFB86B) else Color(0xFFB45309)

private fun Color.luminanceIsDark(): Boolean = (0.299 * red + 0.587 * green + 0.114 * blue) < 0.5
