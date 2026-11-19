package studio.visns.novarr.ui.components

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
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
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import coil3.compose.SubcomposeAsyncImage
import studio.visns.novarr.app.AppModel
import studio.visns.novarr.ui.theme.success
import studio.visns.novarr.ui.theme.warning

/** The app model, provided once at the root (manual wiring, no DI framework). */
val LocalApp = staticCompositionLocalOf<AppModel> { error("No AppModel") }

/** The "Serial" brand mark: four flat bars, 22/22/15/8 units long, 4 tall, 6.5 apart. */
@Composable
fun BrandMark(modifier: Modifier = Modifier, color: Color = MaterialTheme.colorScheme.primary) {
    Canvas(modifier.aspectRatio(22f / 23.5f)) {
        val unit = minOf(size.width / 22f, size.height / 23.5f)
        listOf(22f, 22f, 15f, 8f).forEachIndexed { index, length ->
            drawRect(color, topLeft = Offset(0f, index * 6.5f * unit), size = Size(length * unit, 4f * unit))
        }
    }
}

/** A 2:3 cover that loads through Coil, with a typographic placeholder when there is none. */
@Composable
fun Cover(url: String?, title: String, modifier: Modifier = Modifier, corner: Dp = 6.dp, overlay: @Composable BoxScope.() -> Unit = {}) {
    val shape = RoundedCornerShape(corner)
    Box(
        modifier
            .aspectRatio(2f / 3f)
            .clip(shape)
            .border(0.5.dp, MaterialTheme.colorScheme.onSurface.copy(alpha = 0.08f), shape)
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
    // A stable muted hue per title so placeholders don't all look identical.
    var hash = 5381L
    title.forEach { hash = hash * 33 + it.code }
    val hue = ((hash % 360 + 360) % 360).toFloat()
    Box(
        Modifier
            .fillMaxSize()
            .background(Brush.verticalGradient(listOf(Color.hsv(hue, 0.18f, 0.42f), Color.hsv(hue, 0.22f, 0.28f)))),
    ) {
        Column(Modifier.padding(10.dp).fillMaxSize()) {
            Text(
                title,
                color = Color.White.copy(alpha = 0.92f),
                style = MaterialTheme.typography.labelMedium.copy(fontFamily = FontFamily.Serif, fontWeight = FontWeight.SemiBold),
                maxLines = 5,
                overflow = TextOverflow.Ellipsis,
            )
            Spacer(Modifier.weight(1f))
            BrandMark(Modifier.width(18.dp), color = Color.White.copy(alpha = 0.35f))
        }
    }
}

/** Thin rounded progress bar, announced as a percentage. */
@Composable
fun ProgressLine(
    fraction: Float,
    modifier: Modifier = Modifier,
    color: Color = MaterialTheme.colorScheme.primary,
    track: Color = MaterialTheme.colorScheme.onSurface.copy(alpha = 0.12f),
    height: Dp = 4.dp,
) {
    val value = fraction.coerceIn(0f, 1f)
    Box(
        modifier
            .fillMaxWidth()
            .height(height)
            .clip(CircleShape)
            .background(track)
            .semantics { progressBarRangeInfo = ProgressBarRangeInfo(value, 0f..1f) },
    ) {
        if (value > 0f) {
            Box(Modifier.fillMaxWidth(value).height(height).clip(CircleShape).background(color))
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
    Text(
        label.uppercase(),
        style = MaterialTheme.typography.labelSmall,
        color = color,
        modifier = Modifier
            .clip(CircleShape)
            .background(color.copy(alpha = 0.14f))
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
        CircularProgressIndicator()
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
