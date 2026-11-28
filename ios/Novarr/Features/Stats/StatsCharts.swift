import Charts
import SwiftUI
import UIKit

// The Stats screen's charts (Swift Charts and a Canvas heatmap). Mirrors
// android ui/stats/StatsCharts.kt.

extension Color {
    /// A colour with its own light and dark values.
    static func dynamic(light: UInt32, dark: UInt32) -> Color {
        Color(UIColor { traits in
            let hex = traits.userInterfaceStyle == .dark ? dark : light
            return UIColor(red: CGFloat((hex >> 16) & 0xFF) / 255, green: CGFloat((hex >> 8) & 0xFF) / 255,
                           blue: CGFloat(hex & 0xFF) / 255, alpha: 1)
        })
    }

    /// The charts' second colour (Android's tertiary): teal.
    static let statsTertiary = Color.dynamic(light: 0x00796B, dark: 0x4DD0C1)
}

enum StatsColors {
    /// One colour per medium (novel, manga, comic, book, audiobook), distinct in both themes.
    static func medium(_ medium: String) -> Color {
        switch medium {
        case "novel": .brandAccent
        case "manga": .statsTertiary
        case "comic": .dynamic(light: 0xB35900, dark: 0xF2A65A)
        case "book": .dynamic(light: 0x2E7D32, dark: 0x7BD88F)
        case "audiobook": .dynamic(light: 0xB0306A, dark: 0xE77BA6)
        default: .secondary
        }
    }

    /// The heatmap's five shades, level 0 (nothing) to 4 (the most).
    static let heat: [Color] = [
        Color(UIColor.tertiarySystemFill),
        .brandAccent.opacity(0.28),
        .brandAccent.opacity(0.5),
        .brandAccent.opacity(0.75),
        .brandAccent,
    ]
}

/// Vertical bars with a few y ticks (0 up to a "nice" maximum) and x labels under some bars.
struct StatsBarChart: View {
    var values: [Double]
    var description: String
    var height: CGFloat = 160
    var color: Color = .brandAccent
    /// Bar index → its label.
    var xLabels: [Int: String] = [:]

    var body: some View {
        let ticks = StatsFormat.niceTicks(values.max() ?? 0)
        // One category per bar: the band scale centres each label under its bar.
        Chart {
            ForEach(Array(values.enumerated()), id: \.offset) { index, value in
                BarMark(x: .value("Index", String(index)), y: .value("Value", value), width: .ratio(0.7))
                    .foregroundStyle(color)
                    .cornerRadius(2)
            }
        }
        .chartXScale(domain: values.indices.map(String.init))
        .chartYScale(domain: 0...(ticks.last ?? 1))
        .chartYAxis {
            AxisMarks(position: .leading, values: ticks) { value in
                AxisGridLine()
                AxisValueLabel { if let v = value.as(Double.self) { Text(StatsFormat.compact(Int64(v))) } }
            }
        }
        .chartXAxis {
            AxisMarks(values: xLabels.keys.sorted().map(String.init)) { value in
                if let i = value.as(String.self).flatMap(Int.init), let label = xLabels[i] {
                    AxisValueLabel(anchor: edgeAnchor(i, count: values.count), collisionResolution: .disabled) {
                        Text(label)
                    }
                }
            }
        }
        .frame(height: height)
        .accessibilityElement()
        .accessibilityLabel(description)
    }
}

/// Labels at the chart's edges hang inwards so they are never clipped.
func edgeAnchor(_ index: Int, count: Int) -> UnitPoint {
    // A few wide bars (weekdays): every label sits under its bar.
    if count <= 7 { return .top }
    if index == 0 { return .topLeading }
    if index >= count - 1 { return .topTrailing }
    return .top
}

/// A line with a soft fill under it.
struct StatsLineChart: View {
    var values: [Double]
    var description: String
    var height: CGFloat = 160
    var color: Color = .statsTertiary
    var xLabels: [Int: String] = [:]

    var body: some View {
        let ticks = StatsFormat.niceTicks(values.max() ?? 0)
        Chart {
            ForEach(Array(values.enumerated()), id: \.offset) { index, value in
                AreaMark(x: .value("Index", index), y: .value("Value", value))
                    .foregroundStyle(color.opacity(0.18))
                    .interpolationMethod(.monotone)
                LineMark(x: .value("Index", index), y: .value("Value", value))
                    .foregroundStyle(color)
                    .lineStyle(StrokeStyle(lineWidth: 2))
                    .interpolationMethod(.monotone)
            }
        }
        .chartXScale(domain: 0...Double(max(values.count - 1, 1)))
        .chartYScale(domain: 0...(ticks.last ?? 1))
        .chartYAxis {
            AxisMarks(position: .leading, values: ticks) { value in
                AxisGridLine()
                AxisValueLabel { if let v = value.as(Double.self) { Text(StatsFormat.compact(Int64(v))) } }
            }
        }
        .chartXAxis {
            AxisMarks(values: xLabels.keys.sorted()) { value in
                if let i = value.as(Int.self), let label = xLabels[i] {
                    AxisValueLabel(anchor: edgeAnchor(i, count: values.count), collisionResolution: .disabled) {
                        Text(label)
                    }
                }
            }
        }
        .frame(height: height)
        .accessibilityElement()
        .accessibilityLabel(description)
    }
}

/// The by-medium ring with a figure in the middle.
struct StatsDonut: View {
    var shares: [Double]
    var colors: [Color]
    var description: String
    var center: String
    var centerCaption: String

    var body: some View {
        let sweeps = StatsFormat.donutSweeps(shares)
        ZStack {
            if sweeps.isEmpty {
                Circle().stroke(Color(UIColor.tertiarySystemFill), lineWidth: 18)
            } else {
                Chart(sweeps, id: \.index) { sweep in
                    SectorMark(angle: .value("Share", sweep.sweep), innerRadius: .ratio(0.68), angularInset: 1)
                        .foregroundStyle(colors[sweep.index])
                }
                .chartLegend(.hidden)
            }
            VStack(spacing: 0) {
                Text(center).font(.uiMono(.subheadline)).lineLimit(1).minimumScaleFactor(0.6)
                Text(centerCaption).font(.ui(.caption2)).foregroundStyle(.secondary)
            }
            .padding(.horizontal, 22)
        }
        .frame(width: 130, height: 130)
        .accessibilityElement()
        .accessibilityLabel(description)
    }
}

/// A labelled bar: the label and value above, the bar's fill as `fraction`.
struct StatsHorizontalBar: View {
    var label: String
    var value: String
    var fraction: Double
    var color: Color

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                Text(label).font(.ui(.subheadline)).lineLimit(1)
                Spacer()
                Text(value).font(.uiMono(.footnote)).foregroundStyle(.secondary)
            }
            GeometryReader { proxy in
                ZStack(alignment: .leading) {
                    Capsule().fill(Color(UIColor.tertiarySystemFill))
                    Capsule().fill(color).frame(width: proxy.size.width * min(max(fraction, 0), 1))
                }
            }
            .frame(height: 8)
        }
        .padding(.vertical, 4)
        .accessibilityElement(children: .combine)
    }
}

/// The GitHub-style activity grid: a column per week (Monday on top), one
/// cell per day shaded by its level, month names above; scrolls sideways and
/// opens at the newest week.
struct HeatmapChart: View {
    var weeks: [StatsFormat.HeatWeek]
    var description: String

    private let cell: CGFloat = 12
    private let gap: CGFloat = 3
    private let labelHeight: CGFloat = 16

    var body: some View {
        let labels = StatsFormat.monthLabels(weeks)
        HStack(alignment: .top, spacing: 4) {
            VStack(alignment: .leading, spacing: gap) {
                ForEach(Array(["Mon", "", "Wed", "", "Fri", "", ""].enumerated()), id: \.offset) { _, day in
                    Text(day).font(.custom(BrandFont.geistRegular, size: 9)).foregroundStyle(.secondary)
                        .frame(height: cell)
                }
            }
            .padding(.top, labelHeight)
            ScrollView(.horizontal, showsIndicators: false) {
                Canvas { context, _ in
                    for label in labels {
                        context.draw(
                            Text(label.label).font(.ui(.caption2)).foregroundStyle(.secondary),
                            at: CGPoint(x: CGFloat(label.week) * (cell + gap), y: 0), anchor: .topLeading)
                    }
                    for (w, week) in weeks.enumerated() {
                        for (d, day) in week.cells.enumerated() {
                            guard let day else { continue }
                            let rect = CGRect(x: CGFloat(w) * (cell + gap), y: labelHeight + CGFloat(d) * (cell + gap),
                                              width: cell, height: cell)
                            context.fill(Path(roundedRect: rect, cornerRadius: 2),
                                         with: .color(StatsColors.heat[min(max(day.level, 0), 4)]))
                        }
                    }
                }
                .frame(width: max((cell + gap) * CGFloat(weeks.count), 1), height: labelHeight + (cell + gap) * 7)
            }
            .defaultScrollAnchor(.trailing)
        }
        .accessibilityElement()
        .accessibilityLabel(description)
    }
}

/// "Less ▢▢▢▢▢ More" under the heatmap.
struct StatsHeatLegend: View {
    var body: some View {
        HStack(spacing: 3) {
            Text("Less").font(.ui(.caption2)).foregroundStyle(.secondary).padding(.trailing, 2)
            ForEach(0..<StatsColors.heat.count, id: \.self) { i in
                RoundedRectangle(cornerRadius: 2).fill(StatsColors.heat[i]).frame(width: 10, height: 10)
            }
            Text("More").font(.ui(.caption2)).foregroundStyle(.secondary).padding(.leading, 2)
        }
        .accessibilityHidden(true)
    }
}
