package studio.visns.novarr.ui.components

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.layout.wrapContentWidth
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.CloudOff
import androidx.compose.material.icons.outlined.WifiOff
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.isSpecified
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalWindowInfo
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.progressBarRangeInfo
import androidx.compose.ui.semantics.ProgressBarRangeInfo
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.rememberTextMeasurer
import androidx.compose.ui.text.style.Hyphens
import androidx.compose.ui.text.style.LineBreak
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import coil3.compose.SubcomposeAsyncImage
import studio.visns.novarr.api.NovelSummary
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.domain.Origin
import studio.visns.novarr.domain.CoverTitle
import androidx.compose.runtime.remember
import androidx.compose.ui.layout.FirstBaseline
import androidx.compose.ui.layout.Layout
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.em
import kotlin.math.roundToInt
import studio.visns.novarr.ui.theme.Amber
import studio.visns.novarr.ui.theme.BrandFonts
import studio.visns.novarr.ui.theme.Indigo
import studio.visns.novarr.ui.theme.LocalEink
import studio.visns.novarr.ui.theme.brandAmber
import studio.visns.novarr.ui.theme.success
import studio.visns.novarr.ui.theme.warning

/** The app model, provided once at the root (manual wiring, no DI framework). */
val LocalApp = staticCompositionLocalOf<AppModel> { error("No AppModel") }

/**
 * The "Serial" brand mark (public/logo.svg): four flat bars, 22/22/15/8 units
 * long, 4 tall, 6.5 apart; bars 1-3 brand indigo and the short 4th amber.
 * With [color] it is the single-colour mark (public/novarr-mark-mono.svg),
 * the 4th bar at 55%.
 */
@Composable
fun BrandMark(modifier: Modifier = Modifier, color: Color? = null) {
    // E-ink: black bars, the 4th an opaque grey instead of a translucent one.
    val eink = LocalEink.current
    Canvas(modifier.aspectRatio(22f / 23.5f)) {
        val unit = minOf(size.width / 22f, size.height / 23.5f)
        listOf(22f, 22f, 15f, 8f).forEachIndexed { index, length ->
            val fill = when {
                eink -> if (index == 3) Color(0xFF8C8C8C) else Color.Black
                color == null -> if (index == 3) Amber else Indigo
                index == 3 -> color.copy(alpha = color.alpha * 0.55f)
                else -> color
            }
            drawRect(fill, topLeft = Offset(0f, index * 6.5f * unit), size = Size(length * unit, 4f * unit))
        }
    }
}

/**
 * The lockup (public/logo.svg, 211x44): the mark, then "NOVARR" in Geist
 * SemiBold with 0.17em tracking and an amber full stop. [height] is the
 * lockup's height; it does not follow the font scale (it is a logo). The
 * wordmark takes the theme's on-surface colour; the bars keep theirs.
 */
@Composable
fun BrandLockup(height: Dp, modifier: Modifier = Modifier, wordColor: Color = MaterialTheme.colorScheme.onSurface) {
    val density = LocalDensity.current
    val amber = MaterialTheme.colorScheme.brandAmber
    // Units of the 44-unit-high logo.
    val u = height / 44f
    val fontSize = with(density) { (u * 30f).toSp() }
    val text = remember(wordColor, amber) {
        buildAnnotatedString {
            withStyle(SpanStyle(color = wordColor)) { append("NOVARR") }
            withStyle(SpanStyle(color = amber)) { append(".") }
        }
    }
    Layout(
        modifier = modifier.height(height).clearAndSetSemantics { contentDescription = "Novarr" },
        content = {
            // Mark: 30.25 x 32.3 units, vertically centred.
            BrandMark(Modifier.width(u * 30.25f).height(u * 32.3125f))
            Text(
                text,
                style = TextStyle(fontFamily = BrandFonts.Geist, fontWeight = FontWeight.SemiBold, fontSize = fontSize, letterSpacing = 0.17.em),
                maxLines = 1,
                softWrap = false,
            )
        },
    ) { measurables, constraints ->
        val loose = constraints.copy(minWidth = 0, minHeight = 0)
        val mark = measurables[0].measure(loose)
        val word = measurables[1].measure(loose)
        val h = height.roundToPx()
        // The N's stem starts 16 units after the mark (minus Geist's ~1.3-unit side bearing).
        val gap = (u * 14.7f).roundToPx()
        // Cap height is 0.71em in Geist: centre the capitals on the mark, as the SVG does.
        val baseline = word[FirstBaseline]
        val capCentreToBaseline = (u * 30f * 0.71f / 2f).toPx()
        val wordTop = (h / 2f + capCentreToBaseline - baseline).roundToInt()
        layout(mark.width + gap + word.width, h) {
            mark.placeRelative(0, (h - mark.height) / 2)
            word.placeRelative(mark.width + gap, wordTop)
        }
    }
}

/** A 2:3 cover that loads through Coil, with a typographic placeholder when there is none. */
@Composable
fun Cover(url: String?, title: String, modifier: Modifier = Modifier, corner: Dp = 6.dp, overlay: @Composable BoxScope.() -> Unit = {}) {
    val shape = RoundedCornerShape(corner)
    val eink = LocalEink.current
    Box(
        modifier
            .aspectRatio(2f / 3f)
            .clip(shape)
            .then(
                if (eink) Modifier.border(1.dp, MaterialTheme.colorScheme.outline, shape)
                else Modifier.border(0.5.dp, MaterialTheme.colorScheme.onSurface.copy(alpha = 0.08f), shape),
            )
            .clearAndSetSemantics { },
    ) {
        if (url == null) {
            CoverPlaceholder(title)
        } else {
            SubcomposeAsyncImage(
                model = url,
                contentDescription = null,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxSize(),
                loading = { CoverPlaceholder(title) },
                error = { CoverPlaceholder(title) },
            )
        }
        overlay()
    }
}

@Composable
private fun CoverPlaceholder(title: String) {
    // As on the web (.cover-placeholder-panel): surface-alt fading into the
    // background, muted title, the single-colour mark.
    // The title wraps between words and shrinks to fit its longest word
    // (CoverTitle); small covers (Home's 50 dp rows) get a smaller inset.
    val scheme = MaterialTheme.colorScheme
    val eink = LocalEink.current
    BoxWithConstraints(
        Modifier
            .fillMaxSize()
            .then(
                if (eink) Modifier.background(scheme.surface)
                else Modifier.background(Brush.verticalGradient(listOf(scheme.surfaceContainerHigh, scheme.background))),
            ),
    ) {
        val inset = (maxWidth * 0.09f).coerceIn(4.dp, 10.dp)
        val markWidth = (maxWidth * 0.2f).coerceIn(10.dp, 18.dp)
        val base = MaterialTheme.typography.labelMedium.copy(fontFamily = BrandFonts.LiterataDisplay, fontWeight = FontWeight.SemiBold)
        val measurer = rememberTextMeasurer()
        val density = LocalDensity.current
        val lineWidth = with(density) { (maxWidth - inset * 2).toPx() }
        val fit = remember(title, lineWidth, base) {
            val longest = CoverTitle.words(title).maxOfOrNull { word ->
                measurer.measure(word, base, softWrap = false, maxLines = 1).size.width.toFloat()
            } ?: 0f
            CoverTitle.fit(longest, lineWidth)
        }
        val style = base.copy(
            fontSize = base.fontSize * fit.scale,
            lineHeight = (if (base.lineHeight.isSp) base.lineHeight else base.fontSize * 1.33f) * fit.scale,
            lineBreak = LineBreak.Heading,
            hyphens = if (fit.hyphenate) Hyphens.Auto else Hyphens.None,
        )
        // As many lines as fit above the mark; the last one ends in an ellipsis.
        val lines = with(density) {
            val room = (maxHeight - inset * 2 - markWidth * (23.5f / 22f) - 4.dp).toPx()
            (room / style.lineHeight.toPx()).toInt().coerceIn(1, 8)
        }
        Column(Modifier.padding(inset).fillMaxSize()) {
            Text(title, color = scheme.onSurfaceVariant, style = style, maxLines = lines, overflow = TextOverflow.Ellipsis)
            Spacer(Modifier.weight(1f))
            BrandMark(Modifier.width(markWidth), color = if (eink) scheme.onSurfaceVariant else scheme.onSurfaceVariant.copy(alpha = 0.6f))
        }
    }
}

/**
 * Thin rounded progress bar, announced as a percentage. On e-ink: a black
 * fill in a white, black-outlined track whatever the colours asked for, so it
 * reads on covers and in pure black and white.
 */
@Composable
fun ProgressLine(
    fraction: Float,
    modifier: Modifier = Modifier,
    color: Color = MaterialTheme.colorScheme.primary,
    track: Color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.12f),
    height: Dp = 4.dp,
) {
    val value = fraction.coerceIn(0f, 1f)
    val eink = LocalEink.current
    val fill = if (eink) Color.Black else color
    val barHeight = if (eink) maxOf(height, 6.dp) else height
    Box(
        modifier
            .fillMaxWidth()
            .height(barHeight)
            .clip(CircleShape)
            .background(if (eink) Color.White else track)
            .then(if (eink) Modifier.border(1.dp, Color.Black, CircleShape) else Modifier)
            .semantics { progressBarRangeInfo = ProgressBarRangeInfo(value, 0f..1f) },
    ) {
        if (value > 0f) {
            Box(Modifier.fillMaxWidth(value).height(barHeight).clip(CircleShape).background(fill))
        }
    }
}

@Composable
fun StateBadge(state: String, label: String) {
    val scheme = MaterialTheme.colorScheme
    val color = when (state.lowercase()) {
        "active" -> scheme.primary
        "completed" -> scheme.success
        "attention" -> scheme.warning
        "failed" -> scheme.error
        else -> scheme.onSurfaceVariant
    }
    val eink = LocalEink.current
    Text(
        label.uppercase(),
        style = MaterialTheme.typography.labelSmall,
        color = color,
        modifier = Modifier
            .clip(CircleShape)
            .then(
                if (eink) Modifier.background(scheme.surface).border(1.dp, color, CircleShape)
                else Modifier.background(color.copy(alpha = 0.14f)),
            )
            .padding(horizontal = 8.dp, vertical = 3.dp),
    )
}

/**
 * The two-letter country-of-origin badge ("JP", "KR", "CN", "EN"), drawn
 * like [StateBadge]; nothing when the series' origin is unknown. Read out as
 * "Japanese" / "Korean manhwa" / "English original" (domain/Origin).
 * [onCover] draws it over a cover image, on an opaque surface so it reads on
 * any artwork. The code never wraps; it is short enough for any tile.
 */
@Composable
fun OriginBadge(novel: NovelSummary, modifier: Modifier = Modifier, onCover: Boolean = false) {
    val country = Origin.country(novel) ?: return
    val label = country.label(novel.isManga)
    val scheme = MaterialTheme.colorScheme
    val color = scheme.onSurfaceVariant
    val eink = LocalEink.current
    if (eink) {
        // Opaque white with a black outline: reads on any artwork and on the page.
        Text(
            country.code,
            style = MaterialTheme.typography.labelSmall,
            color = scheme.onSurface,
            maxLines = 1,
            softWrap = false,
            modifier = modifier
                .clearAndSetSemantics { contentDescription = label }
                .clip(CircleShape)
                .background(scheme.surface)
                .border(1.dp, scheme.outline, CircleShape)
                .padding(horizontal = 8.dp, vertical = 3.dp),
        )
        return
    }
    Text(
        country.code,
        style = MaterialTheme.typography.labelSmall,
        color = if (onCover) scheme.onSurface else color,
        maxLines = 1,
        softWrap = false,
        modifier = modifier
            .clearAndSetSemantics { contentDescription = label }
            .clip(CircleShape)
            .background(if (onCover) scheme.surface.copy(alpha = 0.9f) else color.copy(alpha = 0.14f))
            // On a cover: a hairline so it stands out from pale artwork and the typographic placeholder too.
            .then(if (onCover) Modifier.border(0.5.dp, scheme.onSurface.copy(alpha = 0.2f), CircleShape) else Modifier)
            .padding(horizontal = 8.dp, vertical = 3.dp),
    )
}

/** Small pill shown when a screen is displaying its saved copy. */
@Composable
fun OfflinePill(modifier: Modifier = Modifier, text: String = "Offline · showing saved copy") {
    Surface(shape = CircleShape, color = MaterialTheme.colorScheme.surfaceContainerHigh, modifier = modifier) {
        Row(
            Modifier
                .padding(horizontal = 12.dp, vertical = 6.dp)
                .semantics(mergeDescendants = true) { contentDescription = text },
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(6.dp),
        ) {
            Icon(Icons.Outlined.CloudOff, contentDescription = null, modifier = Modifier.size(16.dp))
            Text(text, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
    }
}

/** Full-screen failure with a retry; used only when there is nothing cached to show. */
@Composable
fun LoadFailed(title: String, message: String, modifier: Modifier = Modifier, onRetry: () -> Unit) {
    Column(
        modifier.fillMaxWidth().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Icon(Icons.Outlined.WifiOff, contentDescription = null, modifier = Modifier.size(40.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(title, style = MaterialTheme.typography.titleLarge, textAlign = TextAlign.Center)
        Text(message, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center)
        Button(onClick = onRetry) { Text("Try again") }
    }
}

@Composable
fun EmptyState(title: String, message: String, icon: androidx.compose.ui.graphics.vector.ImageVector, modifier: Modifier = Modifier) {
    Column(
        modifier.fillMaxWidth().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(10.dp),
    ) {
        Icon(icon, contentDescription = null, modifier = Modifier.size(40.dp), tint = MaterialTheme.colorScheme.onSurfaceVariant)
        Text(title, style = MaterialTheme.typography.titleMedium, textAlign = TextAlign.Center)
        Text(message, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, textAlign = TextAlign.Center)
    }
}

@Composable
fun CenteredProgress(modifier: Modifier = Modifier) {
    Box(modifier.fillMaxWidth().padding(vertical = 80.dp), contentAlignment = Alignment.Center) {
        LoadingIndicator()
    }
}

/** A spinner; on e-ink a still "Loading…" instead (an animation there only smears and flashes). */
@Composable
fun LoadingIndicator(modifier: Modifier = Modifier, color: Color = Color.Unspecified) {
    if (LocalEink.current) {
        Text(
            "Loading…",
            style = MaterialTheme.typography.bodyMedium,
            color = if (color.isSpecified) color else MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = modifier,
        )
    } else {
        CircularProgressIndicator(modifier, color = if (color.isSpecified) color else MaterialTheme.colorScheme.primary)
    }
}

/** Tablet-ish width (>= 600dp): a rail instead of a bottom bar, bigger covers. */
@Composable
fun isWide(): Boolean {
    val size = LocalWindowInfo.current.containerSize
    return with(LocalDensity.current) { size.width.toDp() } >= 600.dp
}

/** Keeps content to a readable width on tablets, centred. */
fun Modifier.readableWidth(max: Dp = 840.dp): Modifier =
    this.fillMaxWidth().wrapContentWidth(Alignment.CenterHorizontally).widthIn(max = max).fillMaxWidth()

/** "1.2 MB" */
fun formatBytes(bytes: Long): String {
    if (bytes < 1024) return "$bytes B"
    val units = listOf("KB", "MB", "GB", "TB")
    var value = bytes / 1024.0
    var unit = 0
    while (value >= 1024 && unit < units.lastIndex) {
        value /= 1024
        unit++
    }
    return if (value >= 100) "%.0f %s".format(value, units[unit]) else "%.1f %s".format(value, units[unit])
}
