import Foundation
import Testing
@testable import Novarr

/// The Stats screen: decoding `GET /stats`, formatting, the heatmap layout,
/// chart ticks and donut angles (android StatsTest.kt).
@Suite("Stats")
struct StatsTests {
    private let sample = """
    {
      "generated_at": "2026-11-27T09:40:00Z",
      "timezone": "Australia/Perth",
      "today": "2026-11-27",
      "range": 30,
      "headline": {
        "chapters_read": 1234, "words_read": 2500000, "series_finished": 12, "books_finished": 3,
        "audiobooks_finished": 2, "listening_seconds": 36000,
        "current_streak": 5, "best_streak": 21, "active_today": true,
        "chapters_today": 3, "words_today": 9000, "listening_seconds_today": 1200,
        "chapters_week": 20, "listening_seconds_week": 5400,
        "chapters_per_day": 4.2, "words_per_day": 9000.5, "listening_minutes_per_day": 12.3
      },
      "range_totals": {"days": 30, "chapters": 126, "words": 270015, "listening_seconds": 22140, "active_days": 18},
      "daily": [{"date": "2026-10-29", "chapters": 0, "words": 0, "listening_seconds": 0}],
      "weekly": [{"start": "2026-10-27", "chapters": 10, "words": 20000, "listening_seconds": 600}],
      "heatmap": {"start": "2025-11-24", "end": "2026-11-27", "max_minutes": 180,
                  "days": [{"date": "2025-11-24", "chapters": 0, "minutes": 0, "level": 0}]},
      "by_medium": [{"medium": "novel", "label": "Web novels", "chapters": 80, "words": 200000, "listening_seconds": 0, "minutes": 800, "share": 0.5},
                    {"medium": "manga", "label": "Manga", "chapters": 40, "words": 0, "listening_seconds": 0, "minutes": 200, "share": 0.125},
                    {"medium": "book", "label": "Books", "chapters": 6, "words": 70015, "listening_seconds": 0, "minutes": 280, "share": 0.175},
                    {"medium": "audiobook", "label": "Audiobooks", "chapters": 0, "words": 0, "listening_seconds": 22140, "minutes": 369, "share": 0.2}],
      "top_genres": [{"name": "Fantasy", "chapters": 60, "minutes": 700}],
      "top_series": [{"id": 12, "name": "Shadow Slave", "medium": "novel", "chapters": 40, "words": 120000, "last_read_at": "2026-11-27T08:00:00Z"}],
      "top_audiobooks": [{"id": 3, "title": "Project Hail Mary", "author": "Andy Weir", "novel_id": 55, "listening_seconds": 14000}],
      "hours": [{"hour": 0, "chapters": 1, "minutes": 10}],
      "weekdays": [{"day": 1, "label": "Mon", "chapters": 20, "minutes": 140}],
      "year": {"year": 2026, "chapters": 900, "words": 2000000, "listening_seconds": 30000,
               "series_finished": 8, "books_finished": 2, "audiobooks_finished": 1, "active_days": 200,
               "top_genre": "Fantasy", "top_series": {"id": 12, "name": "Shadow Slave"},
               "busiest_month": {"month": "2026-11", "label": "November", "chapters": 140}},
      "milestones": [{"key": "chapters_100", "title": "Century", "detail": "Read 100 chapters", "earned": true, "progress": 1.0, "current": 1234, "target": 100}]
    }
    """

    private func decode(_ json: String) throws -> ReadingStats {
        try Fixture.decode(ReadingStats.self, json: json)
    }

    private let english = Locale(identifier: "en_US_POSIX")

    // MARK: Decoding

    @Test func decodesTheSampleAnswer() throws {
        let stats = try decode(sample)
        #expect(stats.today == "2026-11-27")
        #expect(stats.range == 30)
        #expect(stats.headline.chaptersRead == 1234)
        #expect(stats.headline.wordsRead == 2_500_000)
        #expect(stats.headline.listeningSeconds == 36_000)
        #expect(stats.headline.activeToday)
        #expect(stats.headline.listeningSecondsToday == 1200)
        #expect(abs(stats.headline.wordsPerDay - 9000.5) < 1e-9)
        #expect(stats.rangeTotals.activeDays == 18)
        #expect(stats.rangeTotals.words == 270_015)
        #expect(stats.daily.map(\.date) == ["2026-10-29"])
        #expect(stats.weekly.map(\.start) == ["2026-10-27"])
        #expect(stats.heatmap.start == "2025-11-24")
        #expect(stats.heatmap.maxMinutes == 180)
        #expect(stats.byMedium.map(\.medium) == ["novel", "manga", "book", "audiobook"])
        #expect(abs(stats.byMedium[1].share - 0.125) < 1e-9)
        #expect(stats.topGenres.map(\.name) == ["Fantasy"])
        #expect(stats.topSeries.map(\.id) == [12])
        #expect(stats.topAudiobooks.first?.novelId == 55)
        #expect(stats.weekdays.first?.label == "Mon")
        #expect(stats.year?.topSeries?.name == "Shadow Slave")
        #expect(stats.year?.busiestMonth?.label == "November")
        #expect(stats.milestones.first?.target == 100)
        #expect(!stats.isEmpty)
    }

    @Test func decodesAnEmptyObjectWithDefaults() throws {
        let stats = try decode("{}")
        #expect(stats.range == 30)
        #expect(stats.headline.chaptersRead == 0)
        #expect(stats.daily.isEmpty)
        #expect(stats.heatmap.days.isEmpty)
        #expect(stats.heatmap.start == nil)
        #expect(stats.year == nil)
        #expect(stats.isEmpty)
    }

    @Test func decodesMissingFieldsNullsAndUnknownKeys() throws {
        let stats = try decode("""
        {"range": 90, "headline": {"chapters_read": 4, "best_streak": null}, "future_field": [1,2],
         "daily": [{"date": "2026-11-27"}], "heatmap": {"days": [{"date": "2026-11-27", "level": 3}]},
         "year": {"year": 2026, "top_genre": null, "top_series": null, "busiest_month": null},
         "by_medium": [{"medium": "novel"}], "milestones": [{"title": "First"}]}
        """)
        #expect(stats.range == 90)
        #expect(stats.headline.chaptersRead == 4)
        #expect(stats.headline.bestStreak == 0)
        #expect(stats.daily.first?.chapters == 0)
        #expect(stats.heatmap.days.first?.level == 3)
        #expect(stats.year?.topGenre == nil)
        #expect(stats.year?.topSeries == nil)
        #expect(stats.year?.busiestMonth == nil)
        #expect(stats.byMedium.first?.share == 0)
        #expect(stats.milestones.first?.earned == false)
        #expect(!stats.isEmpty)
    }

    @Test func survivesTheOfflineCache() throws {
        let stats = try decode(sample)
        let back = try JSONCoding.decoder().decode(ReadingStats.self, from: JSONCoding.encoder().encode(stats))
        #expect(back.headline == stats.headline)
        #expect(back.byMedium == stats.byMedium)
        #expect(back.year == stats.year)
    }

    @Test func anOlderServerIsUnsupported() {
        let notFound = APIError.rejected(HTTPFailure(status: 404, message: "Not Found", code: nil, retryAfter: nil, body: Data()))
        #expect(StatsFormat.isUnsupported(notFound))
        #expect(StatsFormat.isUnsupported(.http(status: 404, message: nil)))
        #expect(!StatsFormat.isUnsupported(.http(status: 500, message: nil)))
        #expect(!StatsFormat.isUnsupported(.timedOut))
        #expect(!StatsFormat.isUnsupported(nil))
        #expect(StatsFormat.failureMessage(notFound) == "This server doesn't have stats yet — update Novarr.")
        #expect(StatsFormat.failureMessage(.timedOut) == APIError.timedOut.localizedDescription)
        #expect(StatsFormat.failureMessage(nil) == "Couldn't load your stats.")
    }

    // MARK: Formatting

    @Test func compactNumbers() {
        #expect(StatsFormat.compact(Int64(0)) == "0")
        #expect(StatsFormat.compact(Int64(950)) == "950")
        #expect(StatsFormat.compact(Int64(999)) == "999")
        #expect(StatsFormat.compact(Int64(1_000)) == "1K")
        #expect(StatsFormat.compact(Int64(1_234)) == "1.2K")
        #expect(StatsFormat.compact(Int64(12_345)) == "12K")
        #expect(StatsFormat.compact(Int64(270_015)) == "270K")
        #expect(StatsFormat.compact(Int64(999_950)) == "1M")
        #expect(StatsFormat.compact(Int64(2_500_000)) == "2.5M")
        #expect(StatsFormat.compact(Int64(3_400_000)) == "3.4M")
        #expect(StatsFormat.compact(Int64(1_100_000_000)) == "1.1B")
        #expect(StatsFormat.compact(Int64(-1_500)) == "-1.5K")
    }

    @Test func durations() {
        #expect(StatsFormat.duration(0) == "0 min")
        #expect(StatsFormat.duration(59) == "0 min")
        #expect(StatsFormat.duration(45 * 60) == "45 min")
        #expect(StatsFormat.duration(3600) == "1 h")
        #expect(StatsFormat.duration(3 * 3600 + 20 * 60) == "3 h 20 min")
        #expect(StatsFormat.duration(99 * 3600 + 59 * 60) == "99 h 59 min")
        #expect(StatsFormat.duration(120 * 3600 + 30 * 60) == "120 h")
        #expect(StatsFormat.duration(-5) == "0 min")
    }

    @Test func hoursDecimalsAndRates() {
        #expect(StatsFormat.hours(36_000) == "10.0")
        #expect(StatsFormat.hours(5_400) == "1.5")
        #expect(StatsFormat.hours(0) == "0.0")
        #expect(StatsFormat.decimal(4.2) == "4.2")
        #expect(StatsFormat.decimal(12.0) == "12")
        #expect(StatsFormat.decimal(.nan) == "0")
        #expect(abs(StatsFormat.perDay(126, 30) - 4.2) < 1e-9)
        #expect(StatsFormat.perDay(10, 0) == 0)
        #expect(StatsFormat.minutes(1_200) == 20)
        #expect(StatsFormat.days(1) == "1 day")
        #expect(StatsFormat.days(21) == "21 days")
        #expect(StatsFormat.percent(0.125) == "13%")
        #expect(StatsFormat.rangeLabel(365) == "1 year")
        #expect(StatsFormat.rangeLabel(90) == "90 days")
        #expect(StatsFormat.shortDate("2026-11-27", locale: english) == "27 Nov")
        #expect(StatsFormat.shortDate("nonsense", locale: english) == "nonsense")
        #expect(StatsFormat.grouped(Int64(1_234_567), locale: Locale(identifier: "en_US")) == "1,234,567")
    }

    // MARK: Heatmap

    private func day(_ iso: String) -> Date { StatsFormat.date(iso)! }

    private func heatDays(from: String, through: String) -> [StatsHeatDay] {
        var days: [StatsHeatDay] = []
        var current = day(from)
        let last = day(through)
        while current <= last {
            let dom = StatsFormat.calendar.component(.day, from: current)
            days.append(StatsHeatDay(date: StatsFormat.iso(current), level: dom % 5))
            current = StatsFormat.calendar.date(byAdding: .day, value: 1, to: current)!
        }
        return days
    }

    @Test func heatmapIsWeekColumnsMondayFirstWithThePartialWeekPadded() {
        // 2025-11-24 is a Monday, 2026-11-27 a Friday.
        let weeks = StatsFormat.heatmapWeeks("2025-11-24", heatDays(from: "2025-11-24", through: "2026-11-27"))
        #expect(weeks.count == 53)
        #expect(weeks.allSatisfy { $0.cells.count == 7 && StatsFormat.isoWeekday($0.start) == 1 })
        #expect(weeks.first?.cells.first??.date == "2025-11-24")
        #expect(weeks.first?.cells.last??.date == "2025-11-30")
        let last = weeks[weeks.count - 1]
        #expect(last.start == day("2026-11-23"))
        #expect(last.cells[4]?.date == "2026-11-27")
        #expect(last.cells[5] == nil)
        #expect(last.cells[6] == nil)
    }

    @Test func heatmapStartNotAMondayAndGapsAreHandled() {
        // Wednesday start: the week begins on its Monday, the first two cells are padding; a missing day is nil.
        let days = [StatsHeatDay(date: "2026-11-04", level: 1), StatsHeatDay(date: "2026-11-06", level: 2),
                    StatsHeatDay(date: "2026-11-10", level: 4)]
        let weeks = StatsFormat.heatmapWeeks("2026-11-04", days)
        #expect(weeks.count == 2)
        #expect(weeks[0].start == day("2026-11-02"))
        #expect(weeks[0].cells[0] == nil)
        #expect(weeks[0].cells[2]?.level == 1)
        #expect(weeks[0].cells[3] == nil)
        #expect(weeks[0].cells[4]?.level == 2)
        #expect(weeks[1].cells[1]?.level == 4)
        // No start given: from the first day; nothing at all: no weeks.
        #expect(StatsFormat.heatmapWeeks(nil, days).count == 2)
        #expect(StatsFormat.heatmapWeeks("2026-11-02", []).isEmpty)
    }

    @Test func monthLabelsSitWhereTheMonthChanges() {
        let weeks = StatsFormat.heatmapWeeks("2025-11-24", heatDays(from: "2025-11-24", through: "2026-11-27"))
        let labels = StatsFormat.monthLabels(weeks, locale: english)
        // The first column (Nov 2025) is dropped: December starts one column later.
        #expect(labels.first?.label == "Dec")
        #expect(labels.first?.week == 1)
        #expect(labels.last?.label == "Nov")
        #expect(labels.count == 12)
        #expect(zip(labels, labels.dropFirst()).allSatisfy { $1.week > $0.week })
        // A first month with room keeps its label.
        let roomy = StatsFormat.heatmapWeeks("2026-01-05", heatDays(from: "2026-01-05", through: "2026-02-20"))
        let roomyLabels = StatsFormat.monthLabels(roomy, locale: english)
        #expect(roomyLabels.map(\.label) == ["Jan", "Feb"])
        #expect(roomyLabels.map(\.week) == [0, 4])
    }

    // MARK: Charts

    @Test func niceTicks() {
        #expect(StatsFormat.niceTicks(0) == [0, 1])
        #expect(StatsFormat.niceTicks(3) == [0, 1, 2, 3])
        #expect(StatsFormat.niceTicks(7) == [0, 2, 4, 6, 8])
        #expect(StatsFormat.niceTicks(10) == [0, 5, 10])
        #expect(StatsFormat.niceTicks(180) == [0, 50, 100, 150, 200])
        #expect(StatsFormat.niceTicks(401) == [0, 200, 400, 600])
        #expect(StatsFormat.niceTicks(0.3) == [0, 1])
        #expect(StatsFormat.niceTicks(0.3, whole: false) == [0, 0.1, 0.2, 0.3])
        // Never more than 4 bands, always reaching the maximum, steps of 1/2/5 × 10^k.
        for max in [1.0, 9, 11, 23, 99, 101, 999, 12_345] {
            let ticks = StatsFormat.niceTicks(max)
            #expect((1...4).contains(ticks.count - 1), "\(max): \(ticks)")
            #expect(ticks.last! >= max, "\(max): \(ticks)")
            let step = ticks[1]
            let mantissa = step / pow(10, log10(step).rounded(.down))
            #expect([1.0, 2, 5].contains { abs($0 - mantissa) < 1e-9 }, "\(max): \(step)")
        }
    }

    @Test func donutSweepsSkipZeroesAndSumTo360() {
        let sweeps = StatsFormat.donutSweeps([0.5, 0.125, 0.0, 0.175, 0.2])
        #expect(sweeps.map(\.index) == [0, 1, 3, 4])
        #expect(abs(sweeps.reduce(0) { $0 + $1.sweep } - 360) < 1e-3)
        #expect(sweeps[0].start == 0)
        #expect(abs(sweeps[0].sweep - 180) < 1e-3)
        #expect(abs(sweeps[1].start - 180) < 1e-3)
        #expect(abs(sweeps[1].sweep - 45) < 1e-3)
        // Each slice starts where the previous ended.
        #expect(zip(sweeps, sweeps.dropFirst()).allSatisfy { abs($0.start + $0.sweep - $1.start) < 1e-3 })
        // Shares not summing to 1 are scaled; none at all: no slices.
        #expect(StatsFormat.donutSweeps([1, 1]).map(\.sweep) == [180, 180])
        #expect(StatsFormat.donutSweeps([0, 0]).isEmpty)
        #expect(StatsFormat.donutSweeps([]).isEmpty)
        #expect(StatsFormat.donutSweeps([0, 0.3]).map(\.sweep) == [360])
    }

    @Test func aYearChartsWeeksAndShorterRangesChartDays() {
        let daily = (1...30).map { StatsDay(date: String(format: "2026-11-%02d", $0), chapters: $0) }
        let weekly = [StatsWeek(start: "2026-11-02", chapters: 7), StatsWeek(start: "2026-11-09", chapters: 8)]
        let month = StatsFormat.series(ReadingStats(range: 30, daily: daily, weekly: weekly))
        #expect(!month.weekly)
        #expect(month.points.count == 30)
        #expect(!StatsFormat.series(ReadingStats(range: 90, daily: daily, weekly: weekly)).weekly)
        let year = StatsFormat.series(ReadingStats(range: 365, daily: daily, weekly: weekly))
        #expect(year.weekly)
        #expect(year.points.map(\.chapters) == [7, 8])
        #expect(year.points.first?.date == "2026-11-02")
        // A year without weeks (an older answer) falls back to days.
        #expect(!StatsFormat.series(ReadingStats(range: 365, daily: daily)).weekly)
    }

    // MARK: The endpoint

    @Test func theClientReadsARangeAndAnOlderServersAnswerIsUnsupported() async throws {
        let calls = Locked(0)
        let sample = sample
        let client = StubURLProtocol.client { request, _ in
            calls.mutate { $0 += 1 }
            #expect(request.url?.path == "/api/v1/stats")
            if request.url?.query?.contains("range=90") == true {
                return (404, Data(#"{"message": "Not Found"}"#.utf8))
            }
            return (200, Data(sample.utf8))
        }
        do {
            _ = try await client.stats(range: 90)
            Issue.record("expected a 404")
        } catch {
            #expect(StatsFormat.isUnsupported(APIError.from(error)))
            #expect(StatsFormat.failureMessage(APIError.from(error)) == StatsFormat.unsupported)
        }
        let stats = try await client.stats(range: 30)
        #expect(stats.headline.chaptersRead == 1234)
        #expect(calls.value == 2)
    }
}
