import Foundation

// The reader's own stats (`GET /api/v1/stats?range=30|90|365`). Every field
// is defaulted, so an older or newer server (or a missing block) never
// breaks decoding: what is absent reads as zero / empty. Mirrors android
// api/StatsModels.kt.

struct ReadingStats: Codable, Hashable, Sendable {
    var generatedAt: String?
    var timezone: String?
    /// The server's today, `YYYY-MM-DD` in `timezone`.
    var today: String?
    var range: Int
    var headline: StatsHeadline
    var rangeTotals: StatsRangeTotals
    /// Exactly `range` days, oldest first.
    var daily: [StatsDay]
    /// Monday-start weeks covering the range.
    var weekly: [StatsWeek]
    var heatmap: StatsHeatmap
    var byMedium: [StatsMedium]
    var topGenres: [StatsGenre]
    var topSeries: [StatsSeries]
    var topAudiobooks: [StatsAudiobook]
    /// 24 entries, hour 0–23.
    var hours: [StatsHour]
    /// 7 entries, 1 = Monday … 7 = Sunday.
    var weekdays: [StatsWeekday]
    var year: StatsYear?
    var milestones: [StatsMilestone]

    enum CodingKeys: String, CodingKey {
        case generatedAt, timezone, today, range, headline, rangeTotals, daily, weekly, heatmap, byMedium
        case topGenres, topSeries, topAudiobooks, hours, weekdays, year, milestones
    }

    init(range: Int = 30, headline: StatsHeadline = .init(), rangeTotals: StatsRangeTotals = .init(),
         daily: [StatsDay] = [], weekly: [StatsWeek] = [], heatmap: StatsHeatmap = .init(),
         byMedium: [StatsMedium] = [], topGenres: [StatsGenre] = [], topSeries: [StatsSeries] = [],
         topAudiobooks: [StatsAudiobook] = [], hours: [StatsHour] = [], weekdays: [StatsWeekday] = [],
         year: StatsYear? = nil, milestones: [StatsMilestone] = [], today: String? = nil) {
        self.range = range
        self.headline = headline
        self.rangeTotals = rangeTotals
        self.daily = daily
        self.weekly = weekly
        self.heatmap = heatmap
        self.byMedium = byMedium
        self.topGenres = topGenres
        self.topSeries = topSeries
        self.topAudiobooks = topAudiobooks
        self.hours = hours
        self.weekdays = weekdays
        self.year = year
        self.milestones = milestones
        self.today = today
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        generatedAt = c.lenientString(.generatedAt)
        timezone = c.lenientString(.timezone)
        today = c.lenientString(.today)
        range = c.lenientInt(.range) ?? 30
        headline = (try? c.decodeIfPresent(StatsHeadline.self, forKey: .headline)) ?? StatsHeadline()
        rangeTotals = (try? c.decodeIfPresent(StatsRangeTotals.self, forKey: .rangeTotals)) ?? StatsRangeTotals()
        daily = c.lossyArray(StatsDay.self, .daily)
        weekly = c.lossyArray(StatsWeek.self, .weekly)
        heatmap = (try? c.decodeIfPresent(StatsHeatmap.self, forKey: .heatmap)) ?? StatsHeatmap()
        byMedium = c.lossyArray(StatsMedium.self, .byMedium)
        topGenres = c.lossyArray(StatsGenre.self, .topGenres)
        topSeries = c.lossyArray(StatsSeries.self, .topSeries)
        topAudiobooks = c.lossyArray(StatsAudiobook.self, .topAudiobooks)
        hours = c.lossyArray(StatsHour.self, .hours)
        weekdays = c.lossyArray(StatsWeekday.self, .weekdays)
        year = try? c.decodeIfPresent(StatsYear.self, forKey: .year)
        milestones = c.lossyArray(StatsMilestone.self, .milestones)
    }

    /// Nothing read or listened to at all (in the range and ever).
    var isEmpty: Bool {
        headline.chaptersRead == 0 && headline.listeningSeconds == 0 && headline.wordsRead == 0 &&
            rangeTotals.chapters == 0 && rangeTotals.listeningSeconds == 0 &&
            daily.allSatisfy { $0.chapters == 0 && $0.listeningSeconds == 0 } &&
            heatmap.days.allSatisfy { $0.level == 0 && $0.chapters == 0 && $0.minutes == 0 }
    }
}

struct StatsHeadline: Codable, Hashable, Sendable {
    var chaptersRead = 0
    var wordsRead: Int64 = 0
    var seriesFinished = 0
    var booksFinished = 0
    var audiobooksFinished = 0
    var listeningSeconds: Int64 = 0
    var currentStreak = 0
    var bestStreak = 0
    var activeToday = false
    var chaptersToday = 0
    var wordsToday: Int64 = 0
    var listeningSecondsToday: Int64 = 0
    var chaptersWeek = 0
    var listeningSecondsWeek: Int64 = 0
    var chaptersPerDay: Double = 0
    var wordsPerDay: Double = 0
    var listeningMinutesPerDay: Double = 0

    enum CodingKeys: String, CodingKey {
        case chaptersRead, wordsRead, seriesFinished, booksFinished, audiobooksFinished, listeningSeconds
        case currentStreak, bestStreak, activeToday, chaptersToday, wordsToday, listeningSecondsToday
        case chaptersWeek, listeningSecondsWeek, chaptersPerDay, wordsPerDay, listeningMinutesPerDay
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        chaptersRead = c.lenientInt(.chaptersRead) ?? 0
        wordsRead = c.lenientInt64(.wordsRead) ?? 0
        seriesFinished = c.lenientInt(.seriesFinished) ?? 0
        booksFinished = c.lenientInt(.booksFinished) ?? 0
        audiobooksFinished = c.lenientInt(.audiobooksFinished) ?? 0
        listeningSeconds = c.lenientInt64(.listeningSeconds) ?? 0
        currentStreak = c.lenientInt(.currentStreak) ?? 0
        bestStreak = c.lenientInt(.bestStreak) ?? 0
        activeToday = c.lenientBool(.activeToday) ?? false
        chaptersToday = c.lenientInt(.chaptersToday) ?? 0
        wordsToday = c.lenientInt64(.wordsToday) ?? 0
        listeningSecondsToday = c.lenientInt64(.listeningSecondsToday) ?? 0
        chaptersWeek = c.lenientInt(.chaptersWeek) ?? 0
        listeningSecondsWeek = c.lenientInt64(.listeningSecondsWeek) ?? 0
        chaptersPerDay = c.lenientDouble(.chaptersPerDay) ?? 0
        wordsPerDay = c.lenientDouble(.wordsPerDay) ?? 0
        listeningMinutesPerDay = c.lenientDouble(.listeningMinutesPerDay) ?? 0
    }
}

struct StatsRangeTotals: Codable, Hashable, Sendable {
    var days = 0
    var chapters = 0
    var words: Int64 = 0
    var listeningSeconds: Int64 = 0
    var activeDays = 0

    enum CodingKeys: String, CodingKey { case days, chapters, words, listeningSeconds, activeDays }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        days = c.lenientInt(.days) ?? 0
        chapters = c.lenientInt(.chapters) ?? 0
        words = c.lenientInt64(.words) ?? 0
        listeningSeconds = c.lenientInt64(.listeningSeconds) ?? 0
        activeDays = c.lenientInt(.activeDays) ?? 0
    }
}

struct StatsDay: Codable, Hashable, Sendable {
    var date = ""
    var chapters = 0
    var words: Int64 = 0
    var listeningSeconds: Int64 = 0

    enum CodingKeys: String, CodingKey { case date, chapters, words, listeningSeconds }

    init(date: String = "", chapters: Int = 0, words: Int64 = 0, listeningSeconds: Int64 = 0) {
        self.date = date
        self.chapters = chapters
        self.words = words
        self.listeningSeconds = listeningSeconds
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        date = c.lenientString(.date) ?? ""
        chapters = c.lenientInt(.chapters) ?? 0
        words = c.lenientInt64(.words) ?? 0
        listeningSeconds = c.lenientInt64(.listeningSeconds) ?? 0
    }
}

struct StatsWeek: Codable, Hashable, Sendable {
    var start = ""
    var chapters = 0
    var words: Int64 = 0
    var listeningSeconds: Int64 = 0

    enum CodingKeys: String, CodingKey { case start, chapters, words, listeningSeconds }

    init(start: String = "", chapters: Int = 0, words: Int64 = 0, listeningSeconds: Int64 = 0) {
        self.start = start
        self.chapters = chapters
        self.words = words
        self.listeningSeconds = listeningSeconds
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        start = c.lenientString(.start) ?? ""
        chapters = c.lenientInt(.chapters) ?? 0
        words = c.lenientInt64(.words) ?? 0
        listeningSeconds = c.lenientInt64(.listeningSeconds) ?? 0
    }
}

struct StatsHeatmap: Codable, Hashable, Sendable {
    /// A Monday.
    var start: String?
    /// Today.
    var end: String?
    var maxMinutes = 0
    var days: [StatsHeatDay] = []

    enum CodingKeys: String, CodingKey { case start, end, maxMinutes, days }

    init(start: String? = nil, end: String? = nil, maxMinutes: Int = 0, days: [StatsHeatDay] = []) {
        self.start = start
        self.end = end
        self.maxMinutes = maxMinutes
        self.days = days
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        start = c.lenientString(.start)
        end = c.lenientString(.end)
        maxMinutes = c.lenientInt(.maxMinutes) ?? 0
        days = c.lossyArray(StatsHeatDay.self, .days)
    }
}

struct StatsHeatDay: Codable, Hashable, Sendable {
    var date = ""
    var chapters = 0
    var minutes = 0
    /// 0...4: the shade.
    var level = 0

    enum CodingKeys: String, CodingKey { case date, chapters, minutes, level }

    init(date: String = "", chapters: Int = 0, minutes: Int = 0, level: Int = 0) {
        self.date = date
        self.chapters = chapters
        self.minutes = minutes
        self.level = level
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        date = c.lenientString(.date) ?? ""
        chapters = c.lenientInt(.chapters) ?? 0
        minutes = c.lenientInt(.minutes) ?? 0
        level = c.lenientInt(.level) ?? 0
    }
}

struct StatsMedium: Codable, Hashable, Sendable {
    var medium = ""
    var label = ""
    var chapters = 0
    var words: Int64 = 0
    var listeningSeconds: Int64 = 0
    var minutes = 0
    /// 0...1; all mediums sum to ~1 (or all 0).
    var share: Double = 0

    enum CodingKeys: String, CodingKey { case medium, label, chapters, words, listeningSeconds, minutes, share }

    init(medium: String = "", label: String = "", chapters: Int = 0, words: Int64 = 0, listeningSeconds: Int64 = 0,
         minutes: Int = 0, share: Double = 0) {
        self.medium = medium
        self.label = label
        self.chapters = chapters
        self.words = words
        self.listeningSeconds = listeningSeconds
        self.minutes = minutes
        self.share = share
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        medium = c.lenientString(.medium) ?? ""
        label = c.lenientString(.label) ?? ""
        chapters = c.lenientInt(.chapters) ?? 0
        words = c.lenientInt64(.words) ?? 0
        listeningSeconds = c.lenientInt64(.listeningSeconds) ?? 0
        minutes = c.lenientInt(.minutes) ?? 0
        share = c.lenientDouble(.share) ?? 0
    }
}

struct StatsGenre: Codable, Hashable, Sendable {
    var name = ""
    var chapters = 0
    var minutes = 0

    enum CodingKeys: String, CodingKey { case name, chapters, minutes }

    init(name: String = "", chapters: Int = 0, minutes: Int = 0) {
        self.name = name
        self.chapters = chapters
        self.minutes = minutes
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        name = c.lenientString(.name) ?? ""
        chapters = c.lenientInt(.chapters) ?? 0
        minutes = c.lenientInt(.minutes) ?? 0
    }
}

struct StatsSeries: Codable, Hashable, Sendable {
    var id = 0
    var name = ""
    var medium: String?
    var chapters = 0
    var words: Int64 = 0
    var lastReadAt: String?

    enum CodingKeys: String, CodingKey { case id, name, medium, chapters, words, lastReadAt }

    init(id: Int = 0, name: String = "", medium: String? = nil, chapters: Int = 0, words: Int64 = 0,
         lastReadAt: String? = nil) {
        self.id = id
        self.name = name
        self.medium = medium
        self.chapters = chapters
        self.words = words
        self.lastReadAt = lastReadAt
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.lenientInt(.id) ?? 0
        name = c.lenientString(.name) ?? ""
        medium = c.lenientString(.medium)
        chapters = c.lenientInt(.chapters) ?? 0
        words = c.lenientInt64(.words) ?? 0
        lastReadAt = c.lenientString(.lastReadAt)
    }
}

struct StatsAudiobook: Codable, Hashable, Sendable {
    var id = 0
    var title = ""
    var author: String?
    var novelId: Int?
    var listeningSeconds: Int64 = 0

    enum CodingKeys: String, CodingKey { case id, title, author, novelId, listeningSeconds }

    init(id: Int = 0, title: String = "", author: String? = nil, novelId: Int? = nil, listeningSeconds: Int64 = 0) {
        self.id = id
        self.title = title
        self.author = author
        self.novelId = novelId
        self.listeningSeconds = listeningSeconds
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.lenientInt(.id) ?? 0
        title = c.lenientString(.title) ?? ""
        author = c.lenientString(.author)
        novelId = c.lenientInt(.novelId)
        listeningSeconds = c.lenientInt64(.listeningSeconds) ?? 0
    }
}

struct StatsHour: Codable, Hashable, Sendable {
    var hour = 0
    var chapters = 0
    var minutes = 0

    enum CodingKeys: String, CodingKey { case hour, chapters, minutes }

    init(hour: Int = 0, chapters: Int = 0, minutes: Int = 0) {
        self.hour = hour
        self.chapters = chapters
        self.minutes = minutes
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        hour = c.lenientInt(.hour) ?? 0
        chapters = c.lenientInt(.chapters) ?? 0
        minutes = c.lenientInt(.minutes) ?? 0
    }
}

struct StatsWeekday: Codable, Hashable, Sendable {
    /// 1 = Monday … 7 = Sunday.
    var day = 0
    var label = ""
    var chapters = 0
    var minutes = 0

    enum CodingKeys: String, CodingKey { case day, label, chapters, minutes }

    init(day: Int = 0, label: String = "", chapters: Int = 0, minutes: Int = 0) {
        self.day = day
        self.label = label
        self.chapters = chapters
        self.minutes = minutes
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        day = c.lenientInt(.day) ?? 0
        label = c.lenientString(.label) ?? ""
        chapters = c.lenientInt(.chapters) ?? 0
        minutes = c.lenientInt(.minutes) ?? 0
    }
}

struct StatsYear: Codable, Hashable, Sendable {
    var year = 0
    var chapters = 0
    var words: Int64 = 0
    var listeningSeconds: Int64 = 0
    var seriesFinished = 0
    var booksFinished = 0
    var audiobooksFinished = 0
    var activeDays = 0
    var topGenre: String?
    var topSeries: StatsSeriesRef?
    var busiestMonth: StatsMonth?

    enum CodingKeys: String, CodingKey {
        case year, chapters, words, listeningSeconds, seriesFinished, booksFinished, audiobooksFinished
        case activeDays, topGenre, topSeries, busiestMonth
    }

    init() {}

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        year = c.lenientInt(.year) ?? 0
        chapters = c.lenientInt(.chapters) ?? 0
        words = c.lenientInt64(.words) ?? 0
        listeningSeconds = c.lenientInt64(.listeningSeconds) ?? 0
        seriesFinished = c.lenientInt(.seriesFinished) ?? 0
        booksFinished = c.lenientInt(.booksFinished) ?? 0
        audiobooksFinished = c.lenientInt(.audiobooksFinished) ?? 0
        activeDays = c.lenientInt(.activeDays) ?? 0
        topGenre = c.lenientString(.topGenre)
        topSeries = try? c.decodeIfPresent(StatsSeriesRef.self, forKey: .topSeries)
        busiestMonth = try? c.decodeIfPresent(StatsMonth.self, forKey: .busiestMonth)
    }
}

struct StatsSeriesRef: Codable, Hashable, Sendable {
    var id = 0
    var name = ""

    enum CodingKeys: String, CodingKey { case id, name }

    init(id: Int = 0, name: String = "") {
        self.id = id
        self.name = name
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = c.lenientInt(.id) ?? 0
        name = c.lenientString(.name) ?? ""
    }
}

struct StatsMonth: Codable, Hashable, Sendable {
    /// `YYYY-MM`.
    var month = ""
    var label = ""
    var chapters = 0

    enum CodingKeys: String, CodingKey { case month, label, chapters }

    init(month: String = "", label: String = "", chapters: Int = 0) {
        self.month = month
        self.label = label
        self.chapters = chapters
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        month = c.lenientString(.month) ?? ""
        label = c.lenientString(.label) ?? ""
        chapters = c.lenientInt(.chapters) ?? 0
    }
}

struct StatsMilestone: Codable, Hashable, Sendable {
    var key = ""
    var title = ""
    var detail = ""
    var earned = false
    /// 0...1.
    var progress: Double = 0
    var current: Int64 = 0
    var target: Int64 = 0

    enum CodingKeys: String, CodingKey { case key, title, detail, earned, progress, current, target }

    init(key: String = "", title: String = "", detail: String = "", earned: Bool = false, progress: Double = 0,
         current: Int64 = 0, target: Int64 = 0) {
        self.key = key
        self.title = title
        self.detail = detail
        self.earned = earned
        self.progress = progress
        self.current = current
        self.target = target
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = c.lenientString(.key) ?? ""
        title = c.lenientString(.title) ?? ""
        detail = c.lenientString(.detail) ?? ""
        earned = c.lenientBool(.earned) ?? false
        progress = c.lenientDouble(.progress) ?? 0
        current = c.lenientInt64(.current) ?? 0
        target = c.lenientInt64(.target) ?? 0
    }
}
