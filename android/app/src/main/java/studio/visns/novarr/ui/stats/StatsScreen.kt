package studio.visns.novarr.ui.stats

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.outlined.EmojiEvents
import androidx.compose.material.icons.outlined.Insights
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import studio.visns.novarr.api.ReadingStats
import studio.visns.novarr.api.StatsMilestone
import studio.visns.novarr.api.StatsYear
import studio.visns.novarr.domain.StatsFormat
import studio.visns.novarr.ui.NovelRoute
import studio.visns.novarr.ui.PlayerRoute
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.EmptyState
import studio.visns.novarr.ui.components.LoadFailed
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.OfflinePill
import studio.visns.novarr.ui.components.readableWidth
import studio.visns.novarr.ui.theme.BrandFonts
import studio.visns.novarr.ui.theme.LocalEink
import studio.visns.novarr.ui.theme.brandAmber

/** The reader's own stats: totals, streaks and graphs over 30 days, 90 days or a year. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun StatsScreen(nav: NavController) {
    val app = LocalApp.current
    val model = viewModel { StatsViewModel(app) }
    LaunchedEffect(Unit) { model.load() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Your stats") },
                navigationIcon = {
                    IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                },
            )
        },
    ) { padding ->
        PullToRefreshBox(
            isRefreshing = model.refreshing,
            onRefresh = { model.load(pull = true) },
            modifier = Modifier.fillMaxSize().padding(padding),
        ) {
            val stats = model.stats
            LazyColumn(
                Modifier.fillMaxSize(),
                contentPadding = PaddingValues(horizontal = 16.dp, vertical = 8.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                if (model.fromCache && stats != null) item(key = "offline") { OfflinePill() }
                item(key = "range") { RangePicker(model.range, model::select, Modifier.readableWidth()) }
                when {
                    stats == null && model.failure == StatsFormat.UNSUPPORTED -> item(key = "unsupported") {
                        EmptyState("No stats on this server", StatsFormat.UNSUPPORTED, Icons.Outlined.Insights, Modifier.padding(top = 40.dp))
                    }
                    stats == null && model.failure != null -> item(key = "failed") {
                        LoadFailed("Can't load your stats", model.failure.orEmpty(), Modifier.padding(top = 40.dp)) { model.load() }
                    }
                    stats == null -> item(key = "loading") { CenteredProgress() }
                    stats.isEmpty -> item(key = "empty") {
                        EmptyState("Nothing yet", "Read or listen to something and your stats will show up here.", Icons.Outlined.Insights, Modifier.padding(top = 40.dp))
                    }
                    else -> statsContent(stats, nav)
                }
                item(key = "bottom") { Spacer(Modifier.heightIn(min = 16.dp)) }
            }
        }
    }
}

@Composable
private fun RangePicker(range: Int, onSelect: (Int) -> Unit, modifier: Modifier = Modifier) {
    SingleChoiceSegmentedButtonRow(modifier.semantics { contentDescription = "Range" }) {
        StatsFormat.RANGES.forEachIndexed { index, choice ->
            SegmentedButton(
                selected = choice == range,
                onClick = { onSelect(choice) },
                shape = SegmentedButtonDefaults.itemShape(index, StatsFormat.RANGES.size),
                modifier = Modifier.heightIn(min = 48.dp),
                label = { Text(StatsFormat.rangeLabel(choice)) },
            )
        }
    }
}

private fun androidx.compose.foundation.lazy.LazyListScope.statsContent(stats: ReadingStats, nav: NavController) {
    val width = Modifier.readableWidth()
    item(key = "tiles") { HeadlineTiles(stats, width) }
    if (stats.heatmap.days.isNotEmpty()) item(key = "heatmap") { HeatmapCard(stats, width) }
    item(key = "chapters") { ChaptersCard(stats, width) }
    item(key = "listening") { ListeningCard(stats, width) }
    if (stats.byMedium.any { it.minutes > 0 || it.share > 0 }) item(key = "medium") { MediumCard(stats, width) }
    if (stats.topGenres.isNotEmpty()) item(key = "genres") { GenresCard(stats, width) }
    if (stats.topSeries.isNotEmpty()) item(key = "series") { TopSeriesCard(stats, nav, width) }
    if (stats.topAudiobooks.isNotEmpty()) item(key = "audiobooks") { TopAudiobooksCard(stats, nav, width) }
    if (stats.hours.isNotEmpty()) item(key = "hours") { HoursCard(stats, width) }
    if (stats.weekdays.isNotEmpty()) item(key = "weekdays") { WeekdaysCard(stats, width) }
    item(key = "pace") { PaceCard(stats, width) }
    stats.year?.let { year -> item(key = "year") { YearCard(year, nav, width) } }
    if (stats.milestones.isNotEmpty()) item(key = "milestones") { MilestonesCard(stats.milestones, width) }
}

// Building blocks

@Composable
private fun StatCard(title: String, modifier: Modifier = Modifier, subtitle: String? = null, content: @Composable ColumnScope.() -> Unit) {
    Surface(shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.surfaceContainer, modifier = modifier) {
        Column(Modifier.padding(16.dp)) {
            Text(title, style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
            subtitle?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant) }
            Spacer(Modifier.heightIn(min = 12.dp))
            content()
        }
    }
}

/** A big mono figure with its label and an optional second line. */
@Composable
private fun Tile(label: String, value: String, modifier: Modifier = Modifier, secondary: String? = null, accent: Color? = null) {
    Surface(shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.surfaceContainer, modifier = modifier) {
        Column(
            Modifier.padding(horizontal = 14.dp, vertical = 12.dp).semantics(mergeDescendants = true) {},
        ) {
            Text(label, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1)
            Text(
                value,
                style = MaterialTheme.typography.headlineSmall.copy(fontFamily = BrandFonts.GeistMono),
                color = accent ?: MaterialTheme.colorScheme.onSurface,
                maxLines = 1,
            )
            secondary?.let {
                Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

/** Two equal columns. */
@Composable
private fun TwoColumns(cells: List<@Composable (Modifier) -> Unit>, modifier: Modifier = Modifier) {
    Column(modifier, verticalArrangement = Arrangement.spacedBy(10.dp)) {
        cells.chunked(2).forEach { row ->
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                row.forEach { it(Modifier.weight(1f)) }
                if (row.size == 1) Spacer(Modifier.weight(1f))
            }
        }
    }
}

/** First, middle and last bar labelled with their date. */
private fun dateLabels(dates: List<String>): Map<Int, String> {
    if (dates.isEmpty()) return emptyMap()
    val indices = listOf(0, dates.size / 2, dates.lastIndex).distinct()
    return indices.associateWith { StatsFormat.shortDate(dates[it]) }
}

// Sections

@Composable
private fun HeadlineTiles(stats: ReadingStats, modifier: Modifier) {
    val h = stats.headline
    val t = stats.rangeTotals
    val days = if (t.days > 0) t.days else stats.range
    val range = if (stats.range >= 365) "the last year" else StatsFormat.rangeLabel(stats.range)
    val chaptersPerDay = if (t.days > 0) StatsFormat.perDay(t.chapters.toDouble(), days) else h.chaptersPerDay
    val minutesPerDay = if (t.days > 0) StatsFormat.perDay(t.listeningSeconds / 60.0, days) else h.listeningMinutesPerDay
    val streakAccent = if (h.activeToday) MaterialTheme.colorScheme.brandAmber else null
    TwoColumns(
        listOf(
            { m -> Tile("Chapters read", StatsFormat.compact(h.chaptersRead), m, "${StatsFormat.grouped(t.chapters.toLong())} in $range") },
            { m -> Tile("Words read", StatsFormat.compact(h.wordsRead), m, "${StatsFormat.compact(t.words)} in $range") },
            { m ->
                val extra = listOfNotNull(
                    h.booksFinished.takeIf { it > 0 }?.let { "$it books" },
                    h.audiobooksFinished.takeIf { it > 0 }?.let { "$it audiobooks" },
                ).joinToString(" · ").ifEmpty { "All time" }
                Tile("Series finished", StatsFormat.grouped(h.seriesFinished.toLong()), m, extra)
            },
            { m -> Tile("Hours listened", StatsFormat.hours(h.listeningSeconds), m, "${StatsFormat.duration(t.listeningSeconds)} in $range") },
            { m ->
                Tile(
                    "Current streak",
                    StatsFormat.days(h.currentStreak),
                    m,
                    "Best ${StatsFormat.days(h.bestStreak)}" + if (h.activeToday) " · read today" else "",
                    accent = streakAccent,
                )
            },
            { m -> Tile("Average per day", "${StatsFormat.decimal(chaptersPerDay)} ch", m, "${StatsFormat.decimal(minutesPerDay)} min listening") },
        ),
        modifier,
    )
}

@Composable
private fun HeatmapCard(stats: ReadingStats, modifier: Modifier) {
    val weeks = remember(stats.heatmap) { StatsFormat.heatmapWeeks(stats.heatmap.start, stats.heatmap.days) }
    val days = stats.heatmap.days
    val active = days.count { it.level > 0 || it.minutes > 0 || it.chapters > 0 }
    val busiest = days.maxByOrNull { it.minutes }
    val description = buildString {
        append("Activity heatmap for the last year: $active active days")
        if (busiest != null && busiest.minutes > 0) append(", busiest ${StatsFormat.shortDate(busiest.date)} with ${busiest.minutes} minutes")
        append(".")
    }
    StatCard("Activity", modifier, subtitle = "$active active days in the last year") {
        Heatmap(weeks, description)
        HeatLegend(Modifier.align(Alignment.End).padding(top = 8.dp))
    }
}

@Composable
private fun ChaptersCard(stats: ReadingStats, modifier: Modifier) {
    val series = remember(stats) { StatsFormat.series(stats) }
    val values = series.points.map { it.chapters.toFloat() }
    val total = series.points.sumOf { it.chapters }
    val most = series.points.maxByOrNull { it.chapters }
    val unit = if (series.weekly) "week" else "day"
    val description = buildString {
        append("Chapters per $unit, ${StatsFormat.rangeLabel(stats.range)}: $total in total")
        if (most != null && most.chapters > 0) append(", most ${most.chapters} ${if (series.weekly) "in the week of" else "on"} ${StatsFormat.shortDate(most.date)}")
        append(".")
    }
    StatCard("Chapters per $unit", modifier, subtitle = "${StatsFormat.grouped(total.toLong())} chapters · ${StatsFormat.rangeLabel(stats.range)}") {
        BarChart(values, description, xLabels = dateLabels(series.points.map { it.date }))
    }
}

@Composable
private fun ListeningCard(stats: ReadingStats, modifier: Modifier) {
    val series = remember(stats) { StatsFormat.series(stats) }
    val values = series.points.map { (it.listeningSeconds / 60.0).toFloat() }
    val total = series.points.sumOf { it.listeningSeconds }
    val most = series.points.maxByOrNull { it.listeningSeconds }
    val unit = if (series.weekly) "week" else "day"
    val description = buildString {
        append("Listening minutes per $unit, ${StatsFormat.rangeLabel(stats.range)}: ${StatsFormat.duration(total)} in total")
        if (most != null && most.listeningSeconds >= 60) append(", most ${StatsFormat.duration(most.listeningSeconds)} ${if (series.weekly) "in the week of" else "on"} ${StatsFormat.shortDate(most.date)}")
        append(".")
    }
    StatCard("Listening minutes per $unit", modifier, subtitle = "${StatsFormat.duration(total)} · ${StatsFormat.rangeLabel(stats.range)}") {
        LineChart(values, description, xLabels = dateLabels(series.points.map { it.date }))
    }
}

@Composable
private fun MediumCard(stats: ReadingStats, modifier: Modifier) {
    val scheme = MaterialTheme.colorScheme
    val eink = LocalEink.current
    val mediums = stats.byMedium
    val colors = mediums.map { mediumColor(it.medium, scheme, eink) }
    val shares = mediums.map { it.share }
    val description = "Time by medium: " + mediums.filter { it.share > 0 }.joinToString(", ") { "${it.label.ifBlank { it.medium }} ${StatsFormat.percent(it.share)}" } + "."
    val totalMinutes = mediums.sumOf { it.minutes }
    StatCard("By medium", modifier, subtitle = StatsFormat.rangeLabel(stats.range)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(20.dp)) {
            Donut(shares, colors, description, center = StatsFormat.duration(totalMinutes * 60L), centerCaption = "in total")
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                mediums.forEachIndexed { i, medium ->
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.semantics(mergeDescendants = true) {}) {
                        Box(Modifier.size(10.dp).background(colors[i], CircleShape))
                        Text(
                            medium.label.ifBlank { medium.medium },
                            style = MaterialTheme.typography.bodyMedium,
                            modifier = Modifier.padding(start = 8.dp).weight(1f),
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                        )
                        Text(
                            StatsFormat.percent(medium.share),
                            style = MaterialTheme.typography.bodyMedium.copy(fontFamily = BrandFonts.GeistMono),
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
        }
    }
}

@Composable
private fun GenresCard(stats: ReadingStats, modifier: Modifier) {
    val genres = stats.topGenres
    val byMinutes = genres.any { it.minutes > 0 }
    val max = genres.maxOfOrNull { if (byMinutes) it.minutes else it.chapters }?.coerceAtLeast(1) ?: 1
    StatCard("Top genres", modifier, subtitle = StatsFormat.rangeLabel(stats.range)) {
        genres.forEach { genre ->
            val value = if (byMinutes) genre.minutes else genre.chapters
            HorizontalBar(
                genre.name,
                if (byMinutes) StatsFormat.duration(genre.minutes * 60L) else "${StatsFormat.grouped(genre.chapters.toLong())} ch",
                value.toFloat() / max,
                MaterialTheme.colorScheme.primary,
            )
        }
    }
}

@Composable
private fun RankRow(rank: Int, title: String, caption: String?, value: String, onClick: (() -> Unit)?) {
    Row(
        Modifier.fillMaxWidth()
            .let { if (onClick != null) it.clickable(role = Role.Button, onClick = onClick) else it }
            .heightIn(min = 48.dp)
            .padding(vertical = 6.dp)
            .semantics(mergeDescendants = true) {},
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            "$rank",
            style = MaterialTheme.typography.titleMedium.copy(fontFamily = BrandFonts.GeistMono),
            color = MaterialTheme.colorScheme.primary,
            modifier = Modifier.width(28.dp),
        )
        Column(Modifier.weight(1f)) {
            Text(title, style = MaterialTheme.typography.bodyLarge, maxLines = 1, overflow = TextOverflow.Ellipsis)
            caption?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis) }
        }
        Text(value, style = MaterialTheme.typography.bodyMedium.copy(fontFamily = BrandFonts.GeistMono), color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.padding(start = 8.dp))
    }
}

private fun mediumName(medium: String?) = when (medium) {
    "novel" -> "Web novel"
    "manga" -> "Manga"
    "book" -> "Book"
    "audiobook" -> "Audiobook"
    else -> null
}

@Composable
private fun TopSeriesCard(stats: ReadingStats, nav: NavController, modifier: Modifier) {
    StatCard("Top series", modifier, subtitle = StatsFormat.rangeLabel(stats.range)) {
        stats.topSeries.forEachIndexed { i, series ->
            RankRow(
                i + 1,
                series.name.ifBlank { "Series ${series.id}" },
                mediumName(series.medium),
                "${StatsFormat.grouped(series.chapters.toLong())} ch",
                onClick = if (series.id > 0) ({ nav.navigate(NovelRoute(series.id)) }) else null,
            )
        }
    }
}

@Composable
private fun TopAudiobooksCard(stats: ReadingStats, nav: NavController, modifier: Modifier) {
    StatCard("Top audiobooks", modifier, subtitle = StatsFormat.rangeLabel(stats.range)) {
        stats.topAudiobooks.forEachIndexed { i, book ->
            RankRow(
                i + 1,
                book.title.ifBlank { "Audiobook ${book.id}" },
                book.author?.takeIf { it.isNotBlank() },
                "${StatsFormat.hours(book.listeningSeconds)} h",
                onClick = if (book.id > 0) ({ nav.navigate(PlayerRoute(book.id)) }) else null,
            )
        }
    }
}

@Composable
private fun HoursCard(stats: ReadingStats, modifier: Modifier) {
    val hours = (0..23).map { h -> stats.hours.firstOrNull { it.hour == h } }
    val byMinutes = stats.hours.any { it.minutes > 0 }
    val values = hours.map { (if (byMinutes) it?.minutes else it?.chapters)?.toFloat() ?: 0f }
    val peak = values.indices.maxByOrNull { values[it] }?.takeIf { values[it] > 0 }
    val unit = if (byMinutes) "minutes" else "chapters"
    val description = if (peak == null) "Time of day: no activity yet." else "Time of day: most active at ${"%02d".format(peak)}:00 with ${values[peak].toInt()} $unit."
    StatCard("Time of day", modifier, subtitle = peak?.let { "Most active around ${"%02d".format(it)}:00" }) {
        BarChart(
            values,
            description,
            height = 120.dp,
            color = MaterialTheme.colorScheme.tertiary,
            xLabels = mapOf(0 to "00", 6 to "06", 12 to "12", 18 to "18", 23 to "23"),
        )
    }
}

@Composable
private fun WeekdaysCard(stats: ReadingStats, modifier: Modifier) {
    val labels = listOf("Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun")
    val days = (1..7).map { d -> stats.weekdays.firstOrNull { it.day == d } }
    val byMinutes = stats.weekdays.any { it.minutes > 0 }
    val values = days.map { (if (byMinutes) it?.minutes else it?.chapters)?.toFloat() ?: 0f }
    val names = days.mapIndexed { i, day -> day?.label?.takeIf { it.isNotBlank() } ?: labels[i] }
    val peak = values.indices.maxByOrNull { values[it] }?.takeIf { values[it] > 0 }
    val unit = if (byMinutes) "minutes" else "chapters"
    val description = if (peak == null) "Weekdays: no activity yet." else "Weekdays: busiest is ${names[peak]} with ${values[peak].toInt()} $unit."
    StatCard("Weekdays", modifier, subtitle = peak?.let { "Busiest on ${names[it]}" }) {
        BarChart(values, description, height = 120.dp, xLabels = names.withIndex().associate { it.index to it.value })
    }
}

@Composable
private fun PaceCard(stats: ReadingStats, modifier: Modifier) {
    val t = stats.rangeTotals
    val days = if (t.days > 0) t.days else stats.range
    val wordsPerDay = if (t.days > 0) StatsFormat.perDay(t.words.toDouble(), days) else stats.headline.wordsPerDay
    StatCard("Reading pace", modifier, subtitle = StatsFormat.rangeLabel(stats.range)) {
        Row(verticalAlignment = Alignment.Bottom, modifier = Modifier.semantics(mergeDescendants = true) {}) {
            Text(
                StatsFormat.compact(wordsPerDay.toLong()),
                style = MaterialTheme.typography.displaySmall.copy(fontFamily = BrandFonts.GeistMono),
                color = MaterialTheme.colorScheme.primary,
            )
            Text(" words a day", style = MaterialTheme.typography.bodyLarge, modifier = Modifier.padding(bottom = 6.dp))
        }
        Text(
            "${StatsFormat.grouped(t.words)} words over ${StatsFormat.days(days)} · active on ${t.activeDays} of them",
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

@Composable
private fun YearCard(year: StatsYear, nav: NavController, modifier: Modifier) {
    Surface(shape = RoundedCornerShape(16.dp), color = MaterialTheme.colorScheme.primaryContainer, modifier = modifier) {
        Column(Modifier.padding(16.dp)) {
            Text(
                "${year.year} in review",
                style = MaterialTheme.typography.titleLarge,
                color = MaterialTheme.colorScheme.onPrimaryContainer,
                modifier = Modifier.semantics { heading() },
            )
            Spacer(Modifier.heightIn(min = 12.dp))
            val figures = listOf(
                "Chapters" to StatsFormat.grouped(year.chapters.toLong()),
                "Words" to StatsFormat.compact(year.words),
                "Hours listened" to StatsFormat.hours(year.listeningSeconds),
                "Active days" to StatsFormat.grouped(year.activeDays.toLong()),
                "Series finished" to StatsFormat.grouped(year.seriesFinished.toLong()),
                "Books · audiobooks" to "${year.booksFinished} · ${year.audiobooksFinished}",
            )
            Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                figures.chunked(2).forEach { row ->
                    Row(Modifier.fillMaxWidth()) {
                        row.forEach { (label, value) ->
                            Column(Modifier.weight(1f).semantics(mergeDescendants = true) {}) {
                                Text(value, style = MaterialTheme.typography.titleLarge.copy(fontFamily = BrandFonts.GeistMono), color = MaterialTheme.colorScheme.onPrimaryContainer)
                                Text(label, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.75f))
                            }
                        }
                    }
                }
            }
            val lines = listOfNotNull(
                year.topGenre?.takeIf { it.isNotBlank() }?.let { "Top genre" to it },
                year.busiestMonth?.takeIf { it.label.isNotBlank() || it.month.isNotBlank() }?.let { "Busiest month" to "${it.label.ifBlank { it.month }} · ${it.chapters} chapters" },
            )
            if (lines.isNotEmpty() || year.topSeries != null) Spacer(Modifier.heightIn(min = 12.dp))
            lines.forEach { (label, value) -> YearLine(label, value) }
            year.topSeries?.takeIf { it.name.isNotBlank() }?.let { series ->
                YearLine("Top series", series.name, onClick = if (series.id > 0) ({ nav.navigate(NovelRoute(series.id)) }) else null)
            }
        }
    }
}

@Composable
private fun YearLine(label: String, value: String, onClick: (() -> Unit)? = null) {
    Row(
        Modifier.fillMaxWidth()
            .let { if (onClick != null) it.clickable(role = Role.Button, onClick = onClick) else it }
            .heightIn(min = 36.dp)
            .semantics(mergeDescendants = true) {},
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(label, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.75f), modifier = Modifier.weight(1f))
        Text(value, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onPrimaryContainer, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun MilestonesCard(milestones: List<StatsMilestone>, modifier: Modifier) {
    val earned = milestones.count { it.earned }
    StatCard("Milestones", modifier, subtitle = "$earned of ${milestones.size} earned") {
        // Earned first, then the closest to done.
        val sorted = milestones.sortedWith(compareByDescending<StatsMilestone> { it.earned }.thenByDescending { it.progress })
        TwoColumns(sorted.map { milestone -> { m: Modifier -> Badge(milestone, m) } })
    }
}

@Composable
private fun Badge(milestone: StatsMilestone, modifier: Modifier) {
    val scheme = MaterialTheme.colorScheme
    val description = buildString {
        append(milestone.title)
        if (milestone.detail.isNotBlank()) append(": ${milestone.detail}")
        if (milestone.earned) append(". Earned.") else append(". ${StatsFormat.percent(milestone.progress)} done, ${StatsFormat.grouped(milestone.current)} of ${StatsFormat.grouped(milestone.target)}.")
    }
    Surface(
        shape = RoundedCornerShape(14.dp),
        color = if (milestone.earned) scheme.tertiaryContainer else scheme.surfaceContainerHigh,
        modifier = modifier,
    ) {
        Column(Modifier.padding(12.dp).semantics(mergeDescendants = true) { contentDescription = description }) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(
                    if (milestone.earned) Icons.Outlined.EmojiEvents else Icons.Outlined.Lock,
                    contentDescription = null,
                    tint = if (milestone.earned) scheme.onTertiaryContainer else scheme.onSurfaceVariant,
                    modifier = Modifier.size(20.dp),
                )
                Text(
                    milestone.title,
                    style = MaterialTheme.typography.titleSmall,
                    color = if (milestone.earned) scheme.onTertiaryContainer else scheme.onSurface,
                    modifier = Modifier.padding(start = 6.dp),
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            if (milestone.detail.isNotBlank()) {
                Text(
                    milestone.detail,
                    style = MaterialTheme.typography.bodySmall,
                    color = if (milestone.earned) scheme.onTertiaryContainer.copy(alpha = 0.8f) else scheme.onSurfaceVariant,
                    modifier = Modifier.padding(top = 4.dp),
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            if (!milestone.earned) {
                LinearProgressIndicator(
                    progress = { milestone.progress.toFloat().coerceIn(0f, 1f) },
                    modifier = Modifier.fillMaxWidth().padding(top = 8.dp),
                )
                Text(
                    "${StatsFormat.compact(milestone.current)} / ${StatsFormat.compact(milestone.target)}",
                    style = MaterialTheme.typography.labelSmall.copy(fontFamily = BrandFonts.GeistMono),
                    color = scheme.onSurfaceVariant,
                    modifier = Modifier.padding(top = 4.dp),
                )
            }
        }
    }
}

/**
 * Home's compact "Your stats" card: today's chapters and listening and the
 * current streak; tapping opens the Stats screen.
 */
@Composable
fun HomeStatsCard(stats: ReadingStats, modifier: Modifier = Modifier, onOpen: () -> Unit) {
    val h = stats.headline
    val listening = StatsFormat.duration(h.listeningSecondsToday)
    val description = "Your stats. Today ${h.chaptersToday} chapters and $listening of listening. Streak ${StatsFormat.days(h.currentStreak)}. Opens your stats."
    Surface(
        shape = RoundedCornerShape(16.dp),
        color = MaterialTheme.colorScheme.surfaceContainer,
        modifier = modifier
            .clickable(role = Role.Button, onClick = onOpen)
            .semantics(mergeDescendants = true) { contentDescription = description },
    ) {
        Column(Modifier.padding(horizontal = 16.dp, vertical = 12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Icon(Icons.Outlined.Insights, contentDescription = null, tint = MaterialTheme.colorScheme.primary, modifier = Modifier.size(18.dp))
                Text("Your stats", style = MaterialTheme.typography.titleSmall, modifier = Modifier.padding(start = 8.dp).weight(1f))
                Text("Today", style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            Row(Modifier.fillMaxWidth().padding(top = 8.dp)) {
                CardFigure(StatsFormat.grouped(h.chaptersToday.toLong()), if (h.chaptersToday == 1) "chapter" else "chapters", Modifier.weight(1f))
                CardFigure(StatsFormat.minutes(h.listeningSecondsToday).toString(), "min listened", Modifier.weight(1f))
                CardFigure(
                    h.currentStreak.toString(),
                    if (h.currentStreak == 1) "day streak" else "days streak",
                    Modifier.weight(1f),
                    accent = if (h.activeToday && h.currentStreak > 0) MaterialTheme.colorScheme.brandAmber else null,
                )
            }
        }
    }
}

@Composable
private fun CardFigure(value: String, label: String, modifier: Modifier = Modifier, accent: Color? = null) {
    Column(modifier) {
        Text(value, style = MaterialTheme.typography.titleLarge.copy(fontFamily = BrandFonts.GeistMono), color = accent ?: MaterialTheme.colorScheme.onSurface)
        Text(label, style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
    }
}
