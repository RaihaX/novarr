import Foundation

/// Pure helpers for the Stats screen: number and duration formatting, the
/// heatmap's week columns, chart axes and donut angles. Mirrors android
/// domain/StatsFormat.kt (StatsTests is StatsTest.kt).
enum StatsFormat {
    /// The range picker's choices, in days (the server's `range`).
    static let ranges = [30, 90, 365]

    static func rangeLabel(_ range: Int) -> String { range >= 365 ? "1 year" : "\(range) days" }

    static let unsupported = "This server doesn't have stats yet — update Novarr."

    /// An older server answers 404: it has no stats endpoint.
    static func isUnsupported(_ error: APIError?) -> Bool { error?.status == 404 }

    /// What the screen says when there is no answer (and nothing saved).
    static func failureMessage(_ error: APIError?) -> String {
        guard let error else { return "Couldn't load your stats." }
        if isUnsupported(error) { return unsupported }
        return error.localizedDescription
    }

    // MARK: Numbers

    /// 950, 1.2K, 12K, 3.4M, 1.1B: one decimal below 10 of a unit, a trailing ".0" dropped.
    static func compact(_ value: Int64) -> String {
        let sign = value < 0 ? "-" : ""
        let n = value.magnitude
        if n < 1_000 { return "\(sign)\(n)" }
        let units: [(String, Double)] = [("K", 1e3), ("M", 1e6), ("B", 1e9), ("T", 1e12)]
        var index = units.lastIndex { Double(n) >= $0.1 } ?? 0
        while true {
            let (suffix, size) = units[index]
            let scaled = Double(n) / size
            let text = scaled < 10 ? oneDecimal(scaled) : String(Int64(javaRound(scaled)))
            // 999,950 rounds to "1000K": that is "1M".
            if (Double(text) ?? 0) >= 1000, index < units.count - 1 {
                index += 1
                continue
            }
            return "\(sign)\(text)\(suffix)"
        }
    }

    static func compact(_ value: Int) -> String { compact(Int64(value)) }

    /// "4.2", "12" (one decimal, a trailing ".0" dropped).
    static func decimal(_ value: Double) -> String {
        value.isNaN || value.isInfinite ? "0" : oneDecimal(value)
    }

    /// Java's Math.round: half up (toward positive infinity).
    private static func javaRound(_ value: Double) -> Double { (value + 0.5).rounded(.down) }

    private static func oneDecimal(_ value: Double) -> String {
        let rounded = javaRound(value * 10) / 10
        let whole = rounded.rounded(.towardZero)
        if rounded == whole { return String(Int64(whole)) }
        return String(format: "%.1f", locale: Locale(identifier: "en_US_POSIX"), rounded)
    }

    /// "1,234" (grouped, in the device's locale).
    static func grouped(_ value: Int64, locale: Locale = .current) -> String {
        let formatter = NumberFormatter()
        formatter.numberStyle = .decimal
        formatter.maximumFractionDigits = 0
        formatter.locale = locale
        return formatter.string(from: NSNumber(value: value)) ?? String(value)
    }

    static func grouped(_ value: Int, locale: Locale = .current) -> String { grouped(Int64(value), locale: locale) }

    // MARK: Durations

    /// "0 min", "45 min", "3 h", "3 h 20 min", and from 100 hours just "120 h".
    static func duration(_ seconds: Int64) -> String {
        let totalMinutes = max(seconds, 0) / 60
        let hours = totalMinutes / 60
        let minutes = totalMinutes % 60
        if hours == 0 { return "\(minutes) min" }
        if hours >= 100 || minutes == 0 { return "\(hours) h" }
        return "\(hours) h \(minutes) min"
    }

    /// Hours with one decimal: 36000 s → "10.0", 5400 s → "1.5".
    static func hours(_ seconds: Int64) -> String {
        String(format: "%.1f", locale: Locale(identifier: "en_US_POSIX"), Double(max(seconds, 0)) / 3600)
    }

    /// Whole minutes (rounded down).
    static func minutes(_ seconds: Int64) -> Int64 { max(seconds, 0) / 60 }

    /// Per day over `days` (0 without days).
    static func perDay(_ total: Double, _ days: Int) -> Double { days <= 0 ? 0 : total / Double(days) }

    /// "5 days" / "1 day".
    static func days(_ count: Int) -> String { count == 1 ? "1 day" : "\(count) days" }

    // MARK: Dates

    /// The calendar dates are read in: Gregorian, UTC (they are plain days).
    static let calendar: Calendar = {
        var calendar = Calendar(identifier: .gregorian)
        calendar.timeZone = TimeZone(identifier: "UTC")!
        calendar.firstWeekday = 2
        return calendar
    }()

    /// The day of `YYYY-MM-DD…`, nil when it is not one.
    static func date(_ iso: String?) -> Date? {
        guard let iso, iso.count >= 10 else { return nil }
        let parts = iso.prefix(10).split(separator: "-")
        guard parts.count == 3, let y = Int(parts[0]), let m = Int(parts[1]), let d = Int(parts[2]),
              parts[0].count == 4, (1...12).contains(m), (1...31).contains(d) else { return nil }
        let components = DateComponents(year: y, month: m, day: d)
        guard let date = calendar.date(from: components),
              calendar.component(.day, from: date) == d else { return nil }
        return date
    }

    /// `YYYY-MM-DD` of a day from `date(_:)`.
    static func iso(_ date: Date) -> String {
        let c = calendar.dateComponents([.year, .month, .day], from: date)
        return String(format: "%04d-%02d-%02d", c.year ?? 0, c.month ?? 0, c.day ?? 0)
    }

    private static func shortMonth(_ date: Date, locale: Locale) -> String {
        let formatter = DateFormatter()
        formatter.locale = locale
        let month = calendar.component(.month, from: date)
        return formatter.shortMonthSymbols[month - 1]
    }

    /// "27 Nov".
    static func shortDate(_ iso: String?, locale: Locale = .current) -> String {
        guard let day = date(iso) else { return iso ?? "" }
        return "\(calendar.component(.day, from: day)) \(shortMonth(day, locale: locale))"
    }

    /// 1 = Monday … 7 = Sunday.
    static func isoWeekday(_ date: Date) -> Int {
        let weekday = calendar.component(.weekday, from: date) // 1 = Sunday
        return weekday == 1 ? 7 : weekday - 1
    }

    // MARK: Heatmap

    /// One heatmap column: the week starting `start` (a Monday), Monday first; nil cells are padding.
    struct HeatWeek: Hashable, Sendable {
        var start: Date
        var cells: [StatsHeatDay?]
    }

    /// The heatmap as week columns from `startIso` (moved back to its Monday
    /// if it is not one) through the last day given; each week has 7 cells
    /// Monday first, days not in the list (after today, or missing) are nil.
    static func heatmapWeeks(_ startIso: String?, _ days: [StatsHeatDay]) -> [HeatWeek] {
        var byDate: [Date: StatsHeatDay] = [:]
        for day in days {
            if let date = date(day.date) { byDate[date] = day }
        }
        guard let firstDay = byDate.keys.min(), let last = byDate.keys.max() else { return [] }
        let first = date(startIso) ?? firstDay
        guard let start = calendar.date(byAdding: .day, value: -(isoWeekday(first) - 1), to: first),
              last >= start else { return [] }
        var weeks: [HeatWeek] = []
        var monday = start
        while monday <= last {
            let cells = (0..<7).map { offset in
                calendar.date(byAdding: .day, value: offset, to: monday).flatMap { byDate[$0] }
            }
            weeks.append(HeatWeek(start: monday, cells: cells))
            guard let next = calendar.date(byAdding: .day, value: 7, to: monday) else { break }
            monday = next
        }
        return weeks
    }

    struct MonthLabel: Hashable, Sendable {
        var week: Int
        var label: String
    }

    /// Month names over the heatmap's columns: at the first column and at each
    /// column whose Monday falls in a new month. A first label crowded by the
    /// next one (fewer than `minGap` columns apart) is dropped, as GitHub does.
    static func monthLabels(_ weeks: [HeatWeek], locale: Locale = .current, minGap: Int = 3) -> [MonthLabel] {
        var labels: [MonthLabel] = []
        for (i, week) in weeks.enumerated() {
            let month = calendar.component(.month, from: week.start)
            if i == 0 || month != calendar.component(.month, from: weeks[i - 1].start) {
                labels.append(MonthLabel(week: i, label: shortMonth(week.start, locale: locale)))
            }
        }
        if labels.count >= 2, labels[1].week - labels[0].week < minGap { labels.removeFirst() }
        return labels
    }

    // MARK: Charts

    /// Y-axis ticks from 0 up to at least `max`: steps of 1, 2 or 5 × 10^k, at
    /// most `maxBands` bands. `whole`: never a step below 1 (counts).
    static func niceTicks(_ max: Double, maxBands: Int = 4, whole: Bool = true) -> [Double] {
        let bands = Swift.max(maxBands, 1)
        if max.isNaN || max <= 0 { return [0, 1] }
        let raw = max / Double(bands)
        let magnitude = pow(10, (log10(raw)).rounded(.down))
        var step = [1.0, 2.0, 5.0, 10.0].map { $0 * magnitude }.first { $0 >= raw - 1e-9 } ?? 10 * magnitude
        if whole, step < 1 { step = 1 }
        let count = Swift.max(Int((max / step - 1e-9).rounded(.up)), 1)
        return (0...count).map { clean(Double($0) * step) }
    }

    private static func clean(_ value: Double) -> Double { javaRound(value * 1e6) / 1e6 }

    /// One slice of the donut: `index` into the input, angles in degrees from 12 o'clock.
    struct Sweep: Hashable, Sendable {
        var index: Int
        var start: Double
        var sweep: Double
    }

    /// Donut slices from shares: zero (or negative) shares skipped, the rest scaled to exactly 360°.
    static func donutSweeps(_ shares: [Double]) -> [Sweep] {
        let positive = shares.enumerated().filter { $0.element > 0 && !$0.element.isNaN }
        let total = positive.reduce(0) { $0 + $1.element }
        guard total > 0 else { return [] }
        var start = 0.0
        return positive.enumerated().map { i, entry in
            let sweep = i == positive.count - 1 ? 360 - start : entry.element / total * 360
            defer { start += sweep }
            return Sweep(index: entry.offset, start: start, sweep: sweep)
        }
    }

    /// Percent of a share, rounded: 0.125 → "13%".
    static func percent(_ share: Double) -> String {
        "\(Int(javaRound(Swift.min(Swift.max(share, 0), 1) * 100)))%"
    }

    /// A bar of the chapters / listening charts: a day, or a week from its Monday.
    struct Point: Hashable, Sendable {
        var date: String
        var chapters: Int
        var words: Int64
        var listeningSeconds: Int64
    }

    struct Series: Hashable, Sendable {
        var weekly: Bool
        var points: [Point]
    }

    /// Longer ranges (more than 90 days) chart weeks so bars stay readable; days otherwise.
    static func series(_ stats: ReadingStats) -> Series {
        if stats.range > 90, !stats.weekly.isEmpty {
            return Series(weekly: true, points: stats.weekly.map {
                Point(date: $0.start, chapters: $0.chapters, words: $0.words, listeningSeconds: $0.listeningSeconds)
            })
        }
        return Series(weekly: false, points: stats.daily.map {
            Point(date: $0.date, chapters: $0.chapters, words: $0.words, listeningSeconds: $0.listeningSeconds)
        })
    }
}
