package studio.visns.novarr.ui.theme

import android.app.Activity
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.LocalRippleConfiguration
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.em
import studio.visns.novarr.R

// Brand colours (resources/css/_variables.scss). Dynamic colour is off on
// purpose: the app keeps the brand's identity.
/** Brand indigo: the logo, rules and marks. Fills that carry white text use [IndigoFill]. */
val Indigo = Color(0xFF6470FF)

/** Primary fill on light grounds ($accent-fill-light): 5.4:1 with white. */
val IndigoFill = Color(0xFF4A58E8)

/** Accent text on dark grounds ($link / $accent-ink). */
val IndigoInk = Color(0xFF8EA2FF)

/** Bookmark and reading-progress amber; used sparingly, as on the web. */
val Amber = Color(0xFFF0B429)

/** Amber for small text on light grounds ($amber-ink-light). */
val AmberInk = Color(0xFF8A5A00)

/** Brand fonts (SIL OFL, licences in assets/licenses). */
object BrandFonts {
    /** Geist for UI text. */
    val Geist = FontFamily(
        Font(R.font.geist_regular, FontWeight.Normal),
        Font(R.font.geist_medium, FontWeight.Medium),
        Font(R.font.geist_semibold, FontWeight.SemiBold),
        Font(R.font.geist_semibold, FontWeight.Bold),
    )

    /** Geist Mono where the web uses monospace (addresses, names, counters). */
    val GeistMono = FontFamily(Font(R.font.geist_mono_regular, FontWeight.Normal))

    /** Literata SemiBold for display titles (screen and series titles), as the web's serif. */
    val LiterataDisplay = FontFamily(Font(R.font.literata_semibold, FontWeight.SemiBold))

    /** Literata for reading: chapter text, synopses and quoted passages. */
    val Literata = FontFamily(
        Font(R.font.literata_regular, FontWeight.Normal),
        Font(R.font.literata_italic, FontWeight.Normal, FontStyle.Italic),
        Font(R.font.literata_semibold, FontWeight.SemiBold),
        Font(R.font.literata_bold, FontWeight.Bold),
        Font(R.font.literata_bolditalic, FontWeight.Bold, FontStyle.Italic),
    )
}

// Dark is canonical on the web; these are its tokens.
private val Dark = darkColorScheme(
    // Accent text and controls use the web's accent ink (6.8:1 on the background);
    // a white label on #6470FF would miss 4.5:1, so filled buttons carry dark text.
    primary = IndigoInk,
    onPrimary = Color(0xFF0F1216),
    primaryContainer = Color(0xFF2A3170),
    onPrimaryContainer = Color(0xFFDDE2FF),
    inversePrimary = IndigoFill,
    secondary = Color(0xFFAEB7C6),
    onSecondary = Color(0xFF0F1216),
    secondaryContainer = Color(0xFF262D38),
    onSecondaryContainer = Color(0xFFE8EBF0),
    tertiary = Amber,
    onTertiary = Color(0xFF0F1216),
    tertiaryContainer = Color(0xFF4A3608),
    onTertiaryContainer = Color(0xFFFFE2A3),
    background = Color(0xFF0F1216),
    onBackground = Color(0xFFE8EBF0),
    surface = Color(0xFF0F1216),
    onSurface = Color(0xFFE8EBF0),
    surfaceVariant = Color(0xFF1C222A),
    onSurfaceVariant = Color(0xFF8B95A5),
    surfaceTint = Indigo,
    surfaceContainerLowest = Color(0xFF0B0E11),
    surfaceContainerLow = Color(0xFF13171C),
    surfaceContainer = Color(0xFF161A20),
    surfaceContainerHigh = Color(0xFF1C222A),
    surfaceContainerHighest = Color(0xFF232A33),
    outline = Color(0xFF707A8A),
    outlineVariant = Color(0xFF262D38),
    error = Color(0xFFF05845),
    onError = Color(0xFF0F1216),
    errorContainer = Color(0xFF4A1A14),
    onErrorContainer = Color(0xFFFFDAD4),
)

// The web's light companion theme.
private val Light = lightColorScheme(
    primary = IndigoFill,
    onPrimary = Color.White,
    primaryContainer = Color(0xFFE3E6FF),
    onPrimaryContainer = Color(0xFF1D2380),
    inversePrimary = IndigoInk,
    secondary = Color(0xFF5C6675),
    onSecondary = Color.White,
    secondaryContainer = Color(0xFFE3E6EE),
    onSecondaryContainer = Color(0xFF1A1F27),
    tertiary = AmberInk,
    onTertiary = Color.White,
    tertiaryContainer = Color(0xFFFFE7B0),
    onTertiaryContainer = Color(0xFF2B1D00),
    background = Color(0xFFF7F8FA),
    onBackground = Color(0xFF1A1F27),
    surface = Color(0xFFF7F8FA),
    onSurface = Color(0xFF1A1F27),
    surfaceVariant = Color(0xFFEEF0F5),
    onSurfaceVariant = Color(0xFF5C6675),
    surfaceTint = IndigoFill,
    surfaceContainerLowest = Color.White,
    surfaceContainerLow = Color.White,
    surfaceContainer = Color(0xFFF2F3F7),
    surfaceContainerHigh = Color(0xFFEEF0F5),
    surfaceContainerHighest = Color(0xFFE6E9EF),
    outline = Color(0xFF7A8494),
    outlineVariant = Color(0xFFDDE1E8),
    error = Color(0xFFC0392B),
    onError = Color.White,
    errorContainer = Color(0xFFFCE4E1),
    onErrorContainer = Color(0xFF5C1209),
)

/**
 * E-ink: pure white grounds, black text and accents, opaque greys only, no
 * tonal tints. Light grey marks selection (segmented buttons, the nav indicator).
 */
private val Eink = lightColorScheme(
    primary = Color.Black,
    onPrimary = Color.White,
    primaryContainer = Color.White,
    onPrimaryContainer = Color.Black,
    inversePrimary = Color.White,
    secondary = Color.Black,
    onSecondary = Color.White,
    secondaryContainer = Color(0xFFDDDDDD),
    onSecondaryContainer = Color.Black,
    tertiary = Color.Black,
    onTertiary = Color.White,
    tertiaryContainer = Color.White,
    onTertiaryContainer = Color.Black,
    background = Color.White,
    onBackground = Color.Black,
    surface = Color.White,
    onSurface = Color.Black,
    surfaceVariant = Color.White,
    onSurfaceVariant = Color(0xFF333333),
    surfaceTint = Color.White,
    inverseSurface = Color.Black,
    inverseOnSurface = Color.White,
    surfaceBright = Color.White,
    surfaceDim = Color.White,
    surfaceContainerLowest = Color.White,
    surfaceContainerLow = Color.White,
    surfaceContainer = Color.White,
    surfaceContainerHigh = Color.White,
    surfaceContainerHighest = Color.White,
    outline = Color.Black,
    outlineVariant = Color(0xFF555555),
    error = Color.Black,
    onError = Color.White,
    errorContainer = Color.White,
    onErrorContainer = Color.Black,
)

/** Whether e-ink mode is on (resolved from Settings and the device, provided at the root). */
val LocalEink = staticCompositionLocalOf { false }

/** Geist throughout; Literata SemiBold for display titles, with the web's tighter tracking. */
private fun typography(): Typography {
    val base = Typography()
    fun TextStyle.ui() = copy(fontFamily = BrandFonts.Geist)
    fun TextStyle.display(tracking: Double) = copy(fontFamily = BrandFonts.LiterataDisplay, fontWeight = FontWeight.SemiBold, letterSpacing = tracking.em)
    return Typography(
        displayLarge = base.displayLarge.display(-0.02),
        displayMedium = base.displayMedium.display(-0.02),
        displaySmall = base.displaySmall.display(-0.02),
        headlineLarge = base.headlineLarge.display(-0.015),
        headlineMedium = base.headlineMedium.display(-0.015),
        headlineSmall = base.headlineSmall.display(-0.01),
        titleLarge = base.titleLarge.display(-0.01),
        titleMedium = base.titleMedium.ui(),
        titleSmall = base.titleSmall.ui(),
        bodyLarge = base.bodyLarge.ui(),
        bodyMedium = base.bodyMedium.ui(),
        bodySmall = base.bodySmall.ui(),
        labelLarge = base.labelLarge.ui(),
        labelMedium = base.labelMedium.ui(),
        labelSmall = base.labelSmall.ui(),
    )
}

private val NovarrTypography = typography()

/** E-ink: nothing lighter than Medium, so thin strokes don't break up on the panel. */
private val EinkTypography = NovarrTypography.run {
    fun TextStyle.heavier() = if ((fontWeight ?: FontWeight.Normal) < FontWeight.Medium) copy(fontWeight = FontWeight.Medium) else this
    Typography(
        displayLarge = displayLarge.heavier(), displayMedium = displayMedium.heavier(), displaySmall = displaySmall.heavier(),
        headlineLarge = headlineLarge.heavier(), headlineMedium = headlineMedium.heavier(), headlineSmall = headlineSmall.heavier(),
        titleLarge = titleLarge.heavier(), titleMedium = titleMedium.heavier(), titleSmall = titleSmall.heavier(),
        bodyLarge = bodyLarge.heavier(), bodyMedium = bodyMedium.heavier(), bodySmall = bodySmall.heavier(),
        labelLarge = labelLarge.heavier(), labelMedium = labelMedium.heavier(), labelSmall = labelSmall.heavier(),
    )
}

/** [eink]: the e-ink scheme and type whatever the system dark setting, and no ripples. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun NovarrTheme(dark: Boolean = isSystemInDarkTheme(), eink: Boolean = false, content: @Composable () -> Unit) {
    // System bar icons follow the scheme (enableEdgeToEdge only knows the system setting).
    val view = LocalView.current
    LaunchedEffect(eink, dark) {
        val window = (view.context as? Activity)?.window ?: return@LaunchedEffect
        val light = eink || !dark
        WindowCompat.getInsetsController(window, view).apply {
            isAppearanceLightStatusBars = light
            isAppearanceLightNavigationBars = light
        }
    }
    // One tree either way: switching modes in Settings must keep navigation and screen state.
    val ripple = LocalRippleConfiguration.current
    MaterialTheme(
        colorScheme = if (eink) Eink else if (dark) Dark else Light,
        typography = if (eink) EinkTypography else NovarrTypography,
    ) {
        CompositionLocalProvider(LocalRippleConfiguration provides if (eink) null else ripple, content = content)
    }
}

/** Whether this scheme is the dark one. */
val ColorScheme.isDark: Boolean get() = background.luminanceIsDark()

/** Whether this is the e-ink scheme (black accents on white). */
val ColorScheme.isEink: Boolean get() = primary == Color.Black && background == Color.White

/** Success green that reads on both themes ($success / $success-ink-light); black on e-ink. */
val ColorScheme.success: Color get() = if (isEink) Color.Black else if (isDark) Color(0xFF3FB950) else Color(0xFF156C2C)

/** Warning amber that reads on both themes ($warning / $warning-ink-light); black on e-ink. */
val ColorScheme.warning: Color get() = if (isEink) Color.Black else if (isDark) Amber else AmberInk

/** The amber for marks and fills (the wordmark dot, progress fills): darker on light grounds (--nv-amber); black on e-ink. */
val ColorScheme.brandAmber: Color get() = if (isEink) Color.Black else if (isDark) Amber else Color(0xFF916300)

private fun Color.luminanceIsDark(): Boolean = (0.299 * red + 0.587 * green + 0.114 * blue) < 0.5
