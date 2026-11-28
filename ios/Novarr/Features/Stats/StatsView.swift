import SwiftUI
import UIKit

/// The reader's own stats: totals, streaks and graphs over 30 days, 90 days
/// or a year. Mirrors android ui/stats/StatsScreen.kt.
struct StatsView: View {
    @Environment(AppModel.self) private var app
    @State private var model = StatsModel()

    var body: some View {
        ScrollViewReader { proxy in
        ScrollView {
            LazyVStack(spacing: 12) {
                if model.fromCache, model.stats != nil { OfflinePill() }
                Picker("Range", selection: Binding(
                    get: { model.range },
                    set: { newRange in Task { await model.select(newRange, app: app) } }
                )) {
                    ForEach(StatsFormat.ranges, id: \.self) { Text(StatsFormat.rangeLabel($0)).tag($0) }
                }
                .pickerStyle(.segmented)
                .accessibilityLabel("Range")

                content
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 8)
            .frame(maxWidth: 720)
            .frame(maxWidth: .infinity)
        }
        .background(Color.appBackground.ignoresSafeArea())
        .navigationTitle("Your stats")
        .navigationBarTitleDisplayMode(.inline)
        .refreshable { await model.load(app: app) }
        .task {
            #if DEBUG
            // Debug builds only: `-debugStatsRange 90|365` opens on that range.
            let debugRange = UserDefaults.standard.integer(forKey: "debugStatsRange")
            if StatsFormat.ranges.contains(debugRange), debugRange != model.range {
                await model.select(debugRange, app: app)
                await debugScroll(proxy)
                return
            }
            #endif
            await model.load(app: app)
            #if DEBUG
            await debugScroll(proxy)
            #endif
        }
        }
    }

    #if DEBUG
    /// Debug builds only: `-debugStatsAnchor <section>` scrolls to a section
    /// (chapters, medium, hours, year, milestones) for screenshots.
    private func debugScroll(_ proxy: ScrollViewProxy) async {
        guard let anchor = UserDefaults.standard.string(forKey: "debugStatsAnchor") else { return }
        try? await Task.sleep(for: .seconds(1))
        proxy.scrollTo(anchor, anchor: .top)
    }
    #endif

    @ViewBuilder private var content: some View {
        if let stats = model.stats {
            if stats.isEmpty {
                ContentUnavailableView("Nothing yet", systemImage: "chart.bar.xaxis",
                                       description: Text("Read or listen to something and your stats will show up here."))
                    .padding(.top, 40)
            } else {
                StatsContent(stats: stats)
            }
        } else if model.failure == StatsFormat.unsupported {
            ContentUnavailableView("No stats on this server", systemImage: "chart.bar.xaxis",
                                   description: Text(StatsFormat.unsupported))
                .padding(.top, 40)
        } else if let failure = model.failure {
            LoadFailedView(title: "Can't load your stats", message: failure) { await model.load(app: app) }
                .padding(.top, 40)
        } else {
            ProgressView().padding(.top, 60)
        }
    }
}

private struct StatsContent: View {
    let stats: ReadingStats

    var body: some View {
        HeadlineTiles(stats: stats)
        if !stats.heatmap.days.isEmpty { HeatmapCard(stats: stats) }
        ChaptersCard(stats: stats).id("chapters")
        ListeningCard(stats: stats)
        if stats.byMedium.contains(where: { $0.minutes > 0 || $0.share > 0 }) { MediumCard(stats: stats).id("medium") }
        if !stats.topGenres.isEmpty { GenresCard(stats: stats) }
        if !stats.topSeries.isEmpty { TopSeriesCard(stats: stats) }
        if !stats.topAudiobooks.isEmpty { TopAudiobooksCard(stats: stats) }
        if !stats.hours.isEmpty { HoursCard(stats: stats).id("hours") }
        if !stats.weekdays.isEmpty { WeekdaysCard(stats: stats) }
        PaceCard(stats: stats)
        if let year = stats.year { YearCard(year: year).id("year") }
        if !stats.milestones.isEmpty { MilestonesCard(milestones: stats.milestones).id("milestones") }
    }
}

// MARK: Building blocks

private struct StatCard<Content: View>: View {
    var title: String
    var subtitle: String?
    @ViewBuilder var content: Content

    var body: some View {
        VStack(alignment: .leading, spacing: 0) {
            Text(title).font(.ui(.headline)).accessibilityAddTraits(.isHeader)
            if let subtitle {
                Text(subtitle).font(.ui(.footnote)).foregroundStyle(.secondary)
            }
            Spacer().frame(height: 12)
            content
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.surface, in: RoundedRectangle(cornerRadius: 16))
    }
}

/// A big mono figure with its label and an optional second line.
private struct Tile: View {
    var label: String
    var value: String
    var secondary: String?
    var accent: Color?

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(label).font(.ui(.caption, .medium)).foregroundStyle(.secondary).lineLimit(1)
            Text(value).font(.uiMono(.title2)).foregroundStyle(accent ?? .primary).lineLimit(1).minimumScaleFactor(0.7)
            if let secondary {
                Text(secondary).font(.ui(.caption)).foregroundStyle(.secondary).lineLimit(2)
            }
        }
        .padding(.horizontal, 14)
        .padding(.vertical, 12)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .background(Color.surface, in: RoundedRectangle(cornerRadius: 16))
        .accessibilityElement(children: .combine)
    }
}

/// Two equal columns.
private struct TwoColumns<Item: View>: View {
    var count: Int
    @ViewBuilder var item: (Int) -> Item

    var body: some View {
        Grid(horizontalSpacing: 10, verticalSpacing: 10) {
            ForEach(Array(stride(from: 0, to: count, by: 2)), id: \.self) { row in
                GridRow {
                    item(row)
                    if row + 1 < count { item(row + 1) } else { Color.clear.gridCellUnsizedAxes([.horizontal, .vertical]) }
                }
            }
        }
    }
}

/// First, middle and last bar labelled with their date.
private func dateLabels(_ dates: [String]) -> [Int: String] {
    guard !dates.isEmpty else { return [:] }
    var labels: [Int: String] = [:]
    for i in [0, dates.count / 2, dates.count - 1] { labels[i] = StatsFormat.shortDate(dates[i]) }
    return labels
}

private func mediumName(_ medium: String?) -> String? {
    switch medium {
    case "novel": "Web novel"
    case "manga": "Manga"
    case "comic": "Comic"
    case "book": "Book"
    case "audiobook": "Audiobook"
    default: nil
    }
}

// MARK: Sections

private struct HeadlineTiles: View {
    let stats: ReadingStats

    var body: some View {
        let h = stats.headline
        let t = stats.rangeTotals
        let days = t.days > 0 ? t.days : stats.range
        let range = stats.range >= 365 ? "the last year" : StatsFormat.rangeLabel(stats.range)
        let chaptersPerDay = t.days > 0 ? StatsFormat.perDay(Double(t.chapters), days) : h.chaptersPerDay
        let minutesPerDay = t.days > 0 ? StatsFormat.perDay(Double(t.listeningSeconds) / 60, days) : h.listeningMinutesPerDay
        let finishedExtra = [h.booksFinished > 0 ? "\(h.booksFinished) books" : nil,
                             h.audiobooksFinished > 0 ? "\(h.audiobooksFinished) audiobooks" : nil]
            .compactMap { $0 }.joined(separator: " · ")
        let tiles: [Tile] = [
            Tile(label: "Chapters read", value: StatsFormat.compact(h.chaptersRead),
                 secondary: "\(StatsFormat.grouped(t.chapters)) in \(range)"),
            Tile(label: "Words read", value: StatsFormat.compact(h.wordsRead),
                 secondary: "\(StatsFormat.compact(t.words)) in \(range)"),
            Tile(label: "Series finished", value: StatsFormat.grouped(h.seriesFinished),
                 secondary: finishedExtra.isEmpty ? "All time" : finishedExtra),
            Tile(label: "Hours listened", value: StatsFormat.hours(h.listeningSeconds),
                 secondary: "\(StatsFormat.duration(t.listeningSeconds)) in \(range)"),
            Tile(label: "Current streak", value: StatsFormat.days(h.currentStreak),
                 secondary: "Best \(StatsFormat.days(h.bestStreak))" + (h.activeToday ? " · read today" : ""),
                 accent: h.activeToday ? .brandAmber : nil),
            Tile(label: "Average per day", value: "\(StatsFormat.decimal(chaptersPerDay)) ch",
                 secondary: "\(StatsFormat.decimal(minutesPerDay)) min listening"),
        ]
        TwoColumns(count: tiles.count) { tiles[$0] }
    }
}

private struct HeatmapCard: View {
    let stats: ReadingStats

    var body: some View {
        let weeks = StatsFormat.heatmapWeeks(stats.heatmap.start, stats.heatmap.days)
        let days = stats.heatmap.days
        let active = days.filter { $0.level > 0 || $0.minutes > 0 || $0.chapters > 0 }.count
        let busiest = days.max { $0.minutes < $1.minutes }
        var description = "Activity heatmap for the last year: \(active) active days"
        if let busiest, busiest.minutes > 0 {
            description += ", busiest \(StatsFormat.shortDate(busiest.date)) with \(busiest.minutes) minutes"
        }
        description += "."
        return StatCard(title: "Activity", subtitle: "\(active) active days in the last year") {
            HeatmapChart(weeks: weeks, description: description)
            HStack {
                Spacer()
                StatsHeatLegend()
            }
            .padding(.top, 8)
        }
    }
}

private struct ChaptersCard: View {
    let stats: ReadingStats

    var body: some View {
        let series = StatsFormat.series(stats)
        let total = series.points.reduce(0) { $0 + $1.chapters }
        let most = series.points.max { $0.chapters < $1.chapters }
        let unit = series.weekly ? "week" : "day"
        var description = "Chapters per \(unit), \(StatsFormat.rangeLabel(stats.range)): \(total) in total"
        if let most, most.chapters > 0 {
            description += ", most \(most.chapters) \(series.weekly ? "in the week of" : "on") \(StatsFormat.shortDate(most.date))"
        }
        description += "."
        return StatCard(title: "Chapters per \(unit)",
                        subtitle: "\(StatsFormat.grouped(total)) chapters · \(StatsFormat.rangeLabel(stats.range))") {
            StatsBarChart(values: series.points.map { Double($0.chapters) }, description: description,
                          xLabels: dateLabels(series.points.map(\.date)))
        }
    }
}

private struct ListeningCard: View {
    let stats: ReadingStats

    var body: some View {
        let series = StatsFormat.series(stats)
        let total = series.points.reduce(Int64(0)) { $0 + $1.listeningSeconds }
        let most = series.points.max { $0.listeningSeconds < $1.listeningSeconds }
        let unit = series.weekly ? "week" : "day"
        var description = "Listening minutes per \(unit), \(StatsFormat.rangeLabel(stats.range)): \(StatsFormat.duration(total)) in total"
        if let most, most.listeningSeconds >= 60 {
            description += ", most \(StatsFormat.duration(most.listeningSeconds)) \(series.weekly ? "in the week of" : "on") \(StatsFormat.shortDate(most.date))"
        }
        description += "."
        return StatCard(title: "Listening minutes per \(unit)",
                        subtitle: "\(StatsFormat.duration(total)) · \(StatsFormat.rangeLabel(stats.range))") {
            StatsLineChart(values: series.points.map { Double($0.listeningSeconds) / 60 }, description: description,
                           xLabels: dateLabels(series.points.map(\.date)))
        }
    }
}

private struct MediumCard: View {
    let stats: ReadingStats

    var body: some View {
        let mediums = stats.byMedium
        let colors = mediums.map { StatsColors.medium($0.medium) }
        let description = "Time by medium: " + mediums.filter { $0.share > 0 }
            .map { "\($0.label.isEmpty ? $0.medium : $0.label) \(StatsFormat.percent($0.share))" }
            .joined(separator: ", ") + "."
        let totalMinutes = mediums.reduce(0) { $0 + $1.minutes }
        return StatCard(title: "By medium", subtitle: StatsFormat.rangeLabel(stats.range)) {
            HStack(spacing: 20) {
                StatsDonut(shares: mediums.map(\.share), colors: colors, description: description,
                           center: StatsFormat.duration(Int64(totalMinutes) * 60), centerCaption: "in total")
                VStack(alignment: .leading, spacing: 8) {
                    ForEach(Array(mediums.enumerated()), id: \.offset) { i, medium in
                        HStack(spacing: 8) {
                            Circle().fill(colors[i]).frame(width: 10, height: 10)
                            Text(medium.label.isEmpty ? medium.medium : medium.label)
                                .font(.ui(.subheadline)).lineLimit(1)
                            Spacer(minLength: 4)
                            Text(StatsFormat.percent(medium.share)).font(.uiMono(.subheadline)).foregroundStyle(.secondary)
                        }
                        .accessibilityElement(children: .combine)
                    }
                }
            }
        }
    }
}

private struct GenresCard: View {
    let stats: ReadingStats

    var body: some View {
        let genres = stats.topGenres
        let byMinutes = genres.contains { $0.minutes > 0 }
        let top = max(genres.map { byMinutes ? $0.minutes : $0.chapters }.max() ?? 1, 1)
        return StatCard(title: "Top genres", subtitle: StatsFormat.rangeLabel(stats.range)) {
            ForEach(Array(genres.enumerated()), id: \.offset) { _, genre in
                let value = byMinutes ? genre.minutes : genre.chapters
                StatsHorizontalBar(
                    label: genre.name,
                    value: byMinutes ? StatsFormat.duration(Int64(genre.minutes) * 60) : "\(StatsFormat.grouped(genre.chapters)) ch",
                    fraction: Double(value) / Double(top), color: .brandAccent)
            }
        }
    }
}

private struct RankRow<Value: Hashable>: View {
    var rank: Int
    var title: String
    var caption: String?
    var value: String
    /// Where tapping goes; nil: not a link.
    var route: Value?

    var body: some View {
        if let route {
            NavigationLink(value: route) { row(chevron: true) }.buttonStyle(.plain)
        } else {
            row(chevron: false)
        }
    }

    private func row(chevron: Bool) -> some View {
        HStack(spacing: 0) {
            Text("\(rank)").font(.uiMono(.headline)).foregroundStyle(Color.brandAccent).frame(width: 28, alignment: .leading)
            VStack(alignment: .leading, spacing: 1) {
                Text(title).font(.ui(.body)).lineLimit(1)
                if let caption { Text(caption).font(.ui(.caption)).foregroundStyle(.secondary).lineLimit(1) }
            }
            Spacer(minLength: 8)
            Text(value).font(.uiMono(.subheadline)).foregroundStyle(.secondary)
            if chevron {
                Image(systemName: "chevron.right").font(.caption.weight(.semibold)).foregroundStyle(.tertiary).padding(.leading, 6)
            }
        }
        .frame(minHeight: 44)
        .padding(.vertical, 2)
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
    }
}

private struct TopSeriesCard: View {
    let stats: ReadingStats

    var body: some View {
        StatCard(title: "Top series", subtitle: StatsFormat.rangeLabel(stats.range)) {
            ForEach(Array(stats.topSeries.enumerated()), id: \.offset) { i, series in
                RankRow(rank: i + 1, title: series.name.isEmpty ? "Series \(series.id)" : series.name,
                        caption: mediumName(series.medium), value: "\(StatsFormat.grouped(series.chapters)) ch",
                        route: series.id > 0 ? NovelRoute(id: series.id, summary: nil) : nil)
            }
        }
    }
}

private struct TopAudiobooksCard: View {
    let stats: ReadingStats

    var body: some View {
        StatCard(title: "Top audiobooks", subtitle: StatsFormat.rangeLabel(stats.range)) {
            ForEach(Array(stats.topAudiobooks.enumerated()), id: \.offset) { i, book in
                RankRow(rank: i + 1, title: book.title.isEmpty ? "Audiobook \(book.id)" : book.title,
                        caption: book.author.flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 },
                        value: "\(StatsFormat.hours(book.listeningSeconds)) h",
                        route: book.id > 0 ? PlayerRoute(id: book.id) : nil)
            }
        }
    }
}

private func peakIndex(_ values: [Double]) -> Int? {
    guard let index = values.indices.max(by: { values[$0] < values[$1] }), values[index] > 0 else { return nil }
    // Android's maxBy keeps the first of equal maxima.
    return values.firstIndex(of: values[index])
}

private struct HoursCard: View {
    let stats: ReadingStats

    var body: some View {
        let hours = (0..<24).map { h in stats.hours.first { $0.hour == h } }
        let byMinutes = stats.hours.contains { $0.minutes > 0 }
        let values = hours.map { Double((byMinutes ? $0?.minutes : $0?.chapters) ?? 0) }
        let peak = peakIndex(values)
        let unit = byMinutes ? "minutes" : "chapters"
        let description = peak.map { "Time of day: most active at \(String(format: "%02d", $0)):00 with \(Int(values[$0])) \(unit)." }
            ?? "Time of day: no activity yet."
        return StatCard(title: "Time of day", subtitle: peak.map { "Most active around \(String(format: "%02d", $0)):00" }) {
            StatsBarChart(values: values, description: description, height: 120, color: .statsTertiary,
                          xLabels: [0: "00", 6: "06", 12: "12", 18: "18", 23: "23"])
        }
    }
}

private struct WeekdaysCard: View {
    let stats: ReadingStats

    var body: some View {
        let labels = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"]
        let days = (1...7).map { d in stats.weekdays.first { $0.day == d } }
        let byMinutes = stats.weekdays.contains { $0.minutes > 0 }
        let values = days.map { Double((byMinutes ? $0?.minutes : $0?.chapters) ?? 0) }
        let names = days.enumerated().map { i, day in
            day.map(\.label).flatMap { $0.trimmingCharacters(in: .whitespaces).isEmpty ? nil : $0 } ?? labels[i]
        }
        let peak = peakIndex(values)
        let unit = byMinutes ? "minutes" : "chapters"
        let description = peak.map { "Weekdays: busiest is \(names[$0]) with \(Int(values[$0])) \(unit)." }
            ?? "Weekdays: no activity yet."
        var xLabels: [Int: String] = [:]
        for (i, name) in names.enumerated() { xLabels[i] = name }
        return StatCard(title: "Weekdays", subtitle: peak.map { "Busiest on \(names[$0])" }) {
            StatsBarChart(values: values, description: description, height: 120, xLabels: xLabels)
        }
    }
}

private struct PaceCard: View {
    let stats: ReadingStats

    var body: some View {
        let t = stats.rangeTotals
        let days = t.days > 0 ? t.days : stats.range
        let wordsPerDay = t.days > 0 ? StatsFormat.perDay(Double(t.words), days) : stats.headline.wordsPerDay
        return StatCard(title: "Reading pace", subtitle: StatsFormat.rangeLabel(stats.range)) {
            HStack(alignment: .lastTextBaseline, spacing: 0) {
                Text(StatsFormat.compact(Int64(wordsPerDay))).font(.uiMono(.largeTitle)).foregroundStyle(Color.brandAccent)
                Text(" words a day").font(.ui(.body))
            }
            .accessibilityElement(children: .combine)
            Text("\(StatsFormat.grouped(t.words)) words over \(StatsFormat.days(days)) · active on \(t.activeDays) of them")
                .font(.ui(.footnote))
                .foregroundStyle(.secondary)
        }
    }
}

private struct YearCard: View {
    let year: StatsYear

    var body: some View {
        let figures: [(String, String)] = [
            ("Chapters", StatsFormat.grouped(year.chapters)),
            ("Words", StatsFormat.compact(year.words)),
            ("Hours listened", StatsFormat.hours(year.listeningSeconds)),
            ("Active days", StatsFormat.grouped(year.activeDays)),
            ("Series finished", StatsFormat.grouped(year.seriesFinished)),
            ("Books · audiobooks", "\(year.booksFinished) · \(year.audiobooksFinished)"),
        ]
        var lines: [(String, String)] = []
        if let genre = year.topGenre, !genre.trimmingCharacters(in: .whitespaces).isEmpty { lines.append(("Top genre", genre)) }
        if let month = year.busiestMonth, !month.label.isEmpty || !month.month.isEmpty {
            lines.append(("Busiest month", "\(month.label.isEmpty ? month.month : month.label) · \(month.chapters) chapters"))
        }
        return VStack(alignment: .leading, spacing: 0) {
            Text("\(String(year.year)) in review").font(.ui(.title3, .semibold)).accessibilityAddTraits(.isHeader)
            Spacer().frame(height: 12)
            Grid(alignment: .leading, horizontalSpacing: 10, verticalSpacing: 10) {
                ForEach(Array(stride(from: 0, to: figures.count, by: 2)), id: \.self) { row in
                    GridRow {
                        ForEach(row..<min(row + 2, figures.count), id: \.self) { i in
                            VStack(alignment: .leading, spacing: 1) {
                                Text(figures[i].1).font(.uiMono(.title3))
                                Text(figures[i].0).font(.ui(.caption, .medium)).opacity(0.75)
                            }
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .accessibilityElement(children: .combine)
                        }
                    }
                }
            }
            if !lines.isEmpty || year.topSeries != nil { Spacer().frame(height: 12) }
            ForEach(Array(lines.enumerated()), id: \.offset) { _, line in YearLine(label: line.0, value: line.1) }
            if let series = year.topSeries, !series.name.isEmpty {
                if series.id > 0 {
                    NavigationLink(value: NovelRoute(id: series.id, summary: nil)) {
                        YearLine(label: "Top series", value: series.name, chevron: true)
                    }
                    .buttonStyle(.plain)
                } else {
                    YearLine(label: "Top series", value: series.name)
                }
            }
        }
        .padding(16)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.brandAccent.opacity(0.16), in: RoundedRectangle(cornerRadius: 16))
    }
}

private struct YearLine: View {
    var label: String
    var value: String
    var chevron = false

    var body: some View {
        HStack {
            Text(label).font(.ui(.subheadline)).opacity(0.75)
            Spacer(minLength: 8)
            Text(value).font(.ui(.subheadline)).lineLimit(1)
            if chevron {
                Image(systemName: "chevron.right").font(.caption.weight(.semibold)).foregroundStyle(.tertiary)
            }
        }
        .frame(minHeight: 36)
        .contentShape(Rectangle())
        .accessibilityElement(children: .combine)
    }
}

private struct MilestonesCard: View {
    let milestones: [StatsMilestone]

    var body: some View {
        let earned = milestones.filter(\.earned).count
        // Earned first, then the closest to done (stable, as Android's sortedWith).
        let sorted = milestones.enumerated().sorted { a, b in
            if a.element.earned != b.element.earned { return a.element.earned }
            if a.element.progress != b.element.progress { return a.element.progress > b.element.progress }
            return a.offset < b.offset
        }.map(\.element)
        return StatCard(title: "Milestones", subtitle: "\(earned) of \(milestones.count) earned") {
            TwoColumns(count: sorted.count) { MilestoneBadge(milestone: sorted[$0]) }
        }
    }
}

private struct MilestoneBadge: View {
    let milestone: StatsMilestone

    var body: some View {
        var description = milestone.title
        if !milestone.detail.isEmpty { description += ": \(milestone.detail)" }
        description += milestone.earned ? ". Earned."
            : ". \(StatsFormat.percent(milestone.progress)) done, \(StatsFormat.grouped(milestone.current)) of \(StatsFormat.grouped(milestone.target))."
        return VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 6) {
                Image(systemName: milestone.earned ? "trophy" : "lock")
                    .foregroundStyle(milestone.earned ? Color.brandAmber : .secondary)
                Text(milestone.title).font(.ui(.subheadline, .semibold)).lineLimit(1)
            }
            if !milestone.detail.isEmpty {
                Text(milestone.detail).font(.ui(.caption)).foregroundStyle(.secondary).lineLimit(2).padding(.top, 4)
            }
            if !milestone.earned {
                ProgressView(value: min(max(milestone.progress, 0), 1)).tint(.brandAccent).padding(.top, 8)
                Text("\(StatsFormat.compact(milestone.current)) / \(StatsFormat.compact(milestone.target))")
                    .font(.uiMono(.caption2)).foregroundStyle(.secondary).padding(.top, 4)
            }
        }
        .padding(12)
        .frame(maxWidth: .infinity, maxHeight: .infinity, alignment: .topLeading)
        .background(milestone.earned ? Color.brandAmber.opacity(0.18) : Color(UIColor.tertiarySystemFill),
                    in: RoundedRectangle(cornerRadius: 14))
        .accessibilityElement(children: .ignore)
        .accessibilityLabel(description)
    }
}
