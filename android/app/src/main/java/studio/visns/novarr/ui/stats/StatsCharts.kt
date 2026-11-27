package studio.visns.novarr.ui.stats

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ColorScheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.drawText
import androidx.compose.ui.text.rememberTextMeasurer
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import studio.visns.novarr.domain.StatsFormat
import studio.visns.novarr.ui.theme.BrandFonts
import studio.visns.novarr.ui.theme.LocalEink
import studio.visns.novarr.ui.theme.isDark
import studio.visns.novarr.ui.theme.success

// Charts drawn with Compose Canvas (no chart library). Each one carries a
// TalkBack summary in place of its marks.

/** Small mono figures on axes. */
@Composable
private fun axisStyle() = TextStyle(
    fontFamily = BrandFonts.GeistMono,
    fontSize = 10.sp,
    color = MaterialTheme.colorScheme.onSurfaceVariant,
)

/** The heatmap's five shades, level 0 (nothing) to 4 (the most). */
@Composable
fun heatShades(): List<Color> {
    val scheme = MaterialTheme.colorScheme
    if (LocalEink.current) return listOf(Color(0xFFEEEEEE), Color(0xFFBBBBBB), Color(0xFF888888), Color(0xFF444444), Color.Black)
    val base = scheme.primary
    return listOf(scheme.surfaceContainerHighest, base.copy(alpha = 0.28f), base.copy(alpha = 0.5f), base.copy(alpha = 0.75f), base)
}

/** One colour per medium (novel, manga, book, audiobook), distinct in both themes. */
fun mediumColor(medium: String, scheme: ColorScheme, eink: Boolean): Color {
    if (eink) {
        return when (medium) {
            "novel" -> Color.Black
            "manga" -> Color(0xFF555555)
            "book" -> Color(0xFF999999)
            else -> Color(0xFFCCCCCC)
        }
    }
    return when (medium) {
        "novel" -> scheme.primary
        "manga" -> scheme.tertiary
        "book" -> scheme.success
        "audiobook" -> if (scheme.isDark) Color(0xFFE77BA6) else Color(0xFFB0306A)
        else -> scheme.secondary
    }
}

/**
 * Vertical bars with a few y ticks (0 up to a "nice" maximum) and optional
 * x labels under some bars ([xLabels]: index → text).
 */
@Composable
fun BarChart(
    values: List<Float>,
    description: String,
    modifier: Modifier = Modifier,
    color: Color = MaterialTheme.colorScheme.primary,
    height: Dp = 160.dp,
    xLabels: Map<Int, String> = emptyMap(),
    format: (Double) -> String = { StatsFormat.compact(it.toLong()) },
) {
    val measurer = rememberTextMeasurer()
    val style = axisStyle()
    val grid = MaterialTheme.colorScheme.outlineVariant
    val ticks = remember(values) { StatsFormat.niceTicks((values.maxOrNull() ?: 0f).toDouble()) }
    val density = LocalDensity.current
    val labelRoom = with(density) { if (xLabels.isEmpty()) 0f else 16.dp.toPx() }
    Canvas(modifier.fillMaxWidth().height(height).clearAndSetSemantics { contentDescription = description }) {
        val tickTexts = ticks.map { measurer.measure(format(it), style) }
        val axisWidth = (tickTexts.maxOfOrNull { it.size.width } ?: 0) + 6.dp.toPx()
        val top = 6.dp.toPx()
        val bottom = size.height - labelRoom
        val chartHeight = bottom - top
        val top0 = ticks.last().coerceAtLeast(1.0)
        // Grid lines and tick labels.
        ticks.forEachIndexed { i, tick ->
            val y = bottom - (tick / top0 * chartHeight).toFloat()
            drawLine(grid, Offset(axisWidth, y), Offset(size.width, y), strokeWidth = 1.dp.toPx())
            val text = tickTexts[i]
            drawText(text, topLeft = Offset(0f, (y - text.size.height / 2f).coerceIn(0f, size.height - text.size.height)))
        }
        if (values.isEmpty()) return@Canvas
        val slot = (size.width - axisWidth) / values.size
        val barWidth = (slot * 0.72f).coerceAtLeast(1f)
        val radius = CornerRadius((barWidth / 4).coerceAtMost(3.dp.toPx()))
        values.forEachIndexed { i, value ->
            if (value <= 0f) return@forEachIndexed
            val h = (value / top0 * chartHeight).toFloat().coerceAtLeast(1.5f)
            val x = axisWidth + i * slot + (slot - barWidth) / 2
            drawRoundRect(color, Offset(x, bottom - h), Size(barWidth, h), radius)
        }
        xLabels.forEach { (i, label) ->
            if (i !in values.indices) return@forEach
            val text = measurer.measure(label, style)
            val center = axisWidth + i * slot + slot / 2
            val x = (center - text.size.width / 2f).coerceIn(axisWidth, size.width - text.size.width)
            drawText(text, topLeft = Offset(x, bottom + 3.dp.toPx()))
        }
    }
}

/** A line with a soft fill under it, with y ticks; for listening minutes. */
@Composable
fun LineChart(
    values: List<Float>,
    description: String,
    modifier: Modifier = Modifier,
    color: Color = MaterialTheme.colorScheme.tertiary,
    height: Dp = 140.dp,
    xLabels: Map<Int, String> = emptyMap(),
) {
    val measurer = rememberTextMeasurer()
    val style = axisStyle()
    val grid = MaterialTheme.colorScheme.outlineVariant
    val eink = LocalEink.current
    val ticks = remember(values) { StatsFormat.niceTicks((values.maxOrNull() ?: 0f).toDouble()) }
    val labelRoom = with(LocalDensity.current) { if (xLabels.isEmpty()) 0f else 16.dp.toPx() }
    Canvas(modifier.fillMaxWidth().height(height).clearAndSetSemantics { contentDescription = description }) {
        val tickTexts = ticks.map { measurer.measure(StatsFormat.compact(it.toLong()), style) }
        val axisWidth = (tickTexts.maxOfOrNull { it.size.width } ?: 0) + 6.dp.toPx()
        val top = 6.dp.toPx()
        val bottom = size.height - labelRoom
        val chartHeight = bottom - top
        val top0 = ticks.last().coerceAtLeast(1.0)
        ticks.forEachIndexed { i, tick ->
            val y = bottom - (tick / top0 * chartHeight).toFloat()
            drawLine(grid, Offset(axisWidth, y), Offset(size.width, y), strokeWidth = 1.dp.toPx())
            val text = tickTexts[i]
            drawText(text, topLeft = Offset(0f, (y - text.size.height / 2f).coerceIn(0f, size.height - text.size.height)))
        }
        if (values.isEmpty()) return@Canvas
        val width = size.width - axisWidth
        fun x(i: Int) = axisWidth + if (values.size == 1) width / 2 else i * width / (values.size - 1)
        fun y(v: Float) = bottom - (v / top0 * chartHeight).toFloat()
        val line = Path().apply {
            values.forEachIndexed { i, v -> if (i == 0) moveTo(x(i), y(v)) else lineTo(x(i), y(v)) }
        }
        if (!eink) {
            val area = Path().apply {
                addPath(line)
                lineTo(x(values.lastIndex), bottom)
                lineTo(x(0), bottom)
                close()
            }
            drawPath(area, color.copy(alpha = 0.18f))
        }
        drawPath(line, color, style = Stroke(width = 2.dp.toPx(), cap = StrokeCap.Round, join = StrokeJoin.Round))
        xLabels.forEach { (i, label) ->
            if (i !in values.indices) return@forEach
            val text = measurer.measure(label, style)
            val px = (x(i) - text.size.width / 2f).coerceIn(axisWidth, size.width - text.size.width)
            drawText(text, topLeft = Offset(px, bottom + 3.dp.toPx()))
        }
    }
}

/** A ring of slices ([colors] per input index) with [center] text inside. */
@Composable
fun Donut(
    shares: List<Double>,
    colors: List<Color>,
    description: String,
    modifier: Modifier = Modifier,
    size: Dp = 132.dp,
    center: String? = null,
    centerCaption: String? = null,
) {
    val sweeps = remember(shares) { StatsFormat.donutSweeps(shares) }
    val track = MaterialTheme.colorScheme.surfaceContainerHighest
    Box(modifier.size(size).clearAndSetSemantics { contentDescription = description }, contentAlignment = Alignment.Center) {
        Canvas(Modifier.size(size)) {
            val stroke = 18.dp.toPx()
            val inset = stroke / 2
            val arcSize = Size(this.size.width - stroke, this.size.height - stroke)
            drawArc(track, 0f, 360f, useCenter = false, topLeft = Offset(inset, inset), size = arcSize, style = Stroke(stroke))
            // A hairline gap between slices when there are several.
            val gap = if (sweeps.size > 1) 1.5f else 0f
            sweeps.forEach { slice ->
                drawArc(
                    colors.getOrElse(slice.index) { track },
                    startAngle = slice.start - 90f + gap / 2,
                    sweepAngle = (slice.sweep - gap).coerceAtLeast(0.5f),
                    useCenter = false,
                    topLeft = Offset(inset, inset),
                    size = arcSize,
                    style = Stroke(stroke),
                )
            }
        }
        if (center != null) {
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text(center, style = MaterialTheme.typography.titleMedium.copy(fontFamily = BrandFonts.GeistMono))
                centerCaption?.let { Text(it, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            }
        }
    }
}

/** A horizontal bar row: label, a bar of [fraction] and a figure. */
@Composable
fun HorizontalBar(label: String, value: String, fraction: Float, color: Color, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth().padding(vertical = 4.dp)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(label, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.weight(1f), maxLines = 1)
            Text(value, style = MaterialTheme.typography.bodySmall.copy(fontFamily = BrandFonts.GeistMono), color = MaterialTheme.colorScheme.onSurfaceVariant)
        }
        Box(
            Modifier.padding(top = 4.dp).fillMaxWidth().height(8.dp)
                .background(MaterialTheme.colorScheme.surfaceContainerHighest, RoundedCornerShape(4.dp)),
        ) {
            if (fraction > 0f) {
                Box(Modifier.fillMaxWidth(fraction.coerceIn(0.02f, 1f)).height(8.dp).background(color, RoundedCornerShape(4.dp)))
            }
        }
    }
}

/**
 * The GitHub-style activity grid: a column per week (Monday on top), one
 * cell per day shaded by its level, month names above; scrolls sideways and
 * opens at the newest week.
 */
@Composable
fun Heatmap(weeks: List<StatsFormat.HeatWeek>, description: String, modifier: Modifier = Modifier) {
    val shades = heatShades()
    val measurer = rememberTextMeasurer()
    val style = axisStyle()
    val labels = remember(weeks) { StatsFormat.monthLabels(weeks) }
    val cell = 12.dp
    val gap = 3.dp
    val labelHeight = 16.dp
    val scroll = rememberScrollState()
    LaunchedEffect(weeks.size, scroll.maxValue) { scroll.scrollTo(scroll.maxValue) }
    Row(modifier.fillMaxWidth().clearAndSetSemantics { contentDescription = description }) {
        // Weekday hints for the rows.
        Column(Modifier.padding(top = labelHeight, end = 4.dp), verticalArrangement = Arrangement.spacedBy(gap)) {
            listOf("Mon", "", "Wed", "", "Fri", "", "").forEach { day ->
                Box(Modifier.height(cell), contentAlignment = Alignment.CenterStart) {
                    Text(day, style = style.copy(fontSize = 9.sp, lineHeight = 9.sp))
                }
            }
        }
        Box(Modifier.weight(1f).horizontalScroll(scroll)) {
            val width = (cell + gap) * weeks.size
            Canvas(Modifier.width(width).height(labelHeight + (cell + gap) * 7)) {
                val c = cell.toPx()
                val g = gap.toPx()
                val top = labelHeight.toPx()
                val radius = CornerRadius(2.dp.toPx())
                labels.forEach { label ->
                    drawText(measurer.measure(label.label, style), topLeft = Offset(label.week * (c + g), 0f))
                }
                weeks.forEachIndexed { w, week ->
                    week.cells.forEachIndexed { d, day ->
                        if (day == null) return@forEachIndexed
                        drawRoundRect(
                            shades[day.level.coerceIn(0, 4)],
                            topLeft = Offset(w * (c + g), top + d * (c + g)),
                            size = Size(c, c),
                            cornerRadius = radius,
                        )
                    }
                }
            }
        }
    }
}

/** "Less ▢▢▢▢▢ More" under the heatmap. */
@Composable
fun HeatLegend(modifier: Modifier = Modifier) {
    val shades = heatShades()
    Row(modifier.clearAndSetSemantics { }, verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(3.dp)) {
        Text("Less", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(end = 2.dp))
        shades.forEach { Box(Modifier.size(10.dp).background(it, RoundedCornerShape(2.dp))) }
        Text("More", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(start = 2.dp))
    }
}
