import SwiftUI

/// A screen's multi-select over series ids (Library, a category) and the
/// actions on it. The screen passes how to show a mark at once
/// (`markRead`'s apply / revert), since each screen holds its own copy of
/// the series. Mirrors android ui/lists/Selection.kt (SeriesSelection).
@MainActor
@Observable
final class SeriesSelection {
    private(set) var selection = Categories.Selection<Int>()

    /// The series "Set categories" is open for (nil: closed).
    var categoriesFor: SheetIDs?

    /// A line for the toast; cleared once shown.
    var notice: String?

    /// The latest mark, while Undo is still possible: shown with an Undo button.
    private(set) var undoable: Undoable?

    /// A download is being set up.
    private(set) var working = false

    @ObservationIgnored private let bulk: BulkActions

    /// The ids a sheet is for (identifiable for `.sheet(item:)`).
    struct SheetIDs: Identifiable {
        let id = UUID()
        let novelIds: [Int]
    }

    final class Undoable: Identifiable {
        let message: String
        fileprivate let mark: PendingMark
        fileprivate let revert: @MainActor () -> Void
        var id: ObjectIdentifier { ObjectIdentifier(self) }

        fileprivate init(message: String, mark: PendingMark, revert: @escaping @MainActor () -> Void) {
            self.message = message
            self.mark = mark
            self.revert = revert
        }
    }

    init(bulk: BulkActions) {
        self.bulk = bulk
    }

    convenience init(app: AppModel? = nil) {
        self.init(bulk: BulkActions(app: app))
    }

    /// The app, once the screen has it.
    func attach(_ app: AppModel) { bulk.attach(app) }

    var active: Bool { selection.active }
    var count: Int { selection.count }
    var ids: [Int] { selection.keys.sorted() }
    func contains(_ id: Int) -> Bool { selection.contains(id) }

    /// "Select" (a context menu or long-press): starts selecting with `id`, or toggles it.
    func longPress(_ id: Int) { selection = selection.longPress(id) }

    /// A tap: true when it toggled (selecting), false when the item should open as usual.
    @discardableResult
    func tap(_ id: Int) -> Bool {
        guard let next = selection.tap(id) else { return false }
        selection = next
        return true
    }

    func selectAll<C: Collection>(_ shown: C) where C.Element == Int { selection = selection.selectAll(shown) }
    func allSelected<C: Collection>(_ shown: C) -> Bool where C.Element == Int { selection.allSelected(shown) }
    func retain<C: Collection>(_ available: C) where C.Element == Int { selection = selection.retain(available) }
    func exit() { selection = selection.exit() }

    /// "Set categories…" for the selection (or `series`, e.g. one series from its context menu).
    func openCategories(_ series: [Int]? = nil) {
        let novelIds = series ?? ids
        guard !novelIds.isEmpty else { return }
        categoriesFor = SheetIDs(novelIds: novelIds)
    }

    /// The sheet saved (or failed): selecting ends and the toast says so.
    func categoriesDone(_ failure: String?) {
        categoriesFor = nil
        exit()
        notice = failure ?? "Categories saved."
    }

    /// Marks the selection (or `series`) read or unread: `apply` shows it at
    /// once and answers how to put it back (on Undo or a refusal); the call
    /// waits out the Undo time. A mark still waiting is sent first.
    func markRead(_ read: Bool, series: Set<Int>? = nil,
                  apply: (Set<Int>, Bool) -> @MainActor () -> Void = { _, _ in {} }) {
        let chosen = series ?? selection.keys
        guard !chosen.isEmpty else { return }
        if let previous = undoable {
            bulk.flush(previous.mark, revert: previous.revert) { [weak self] message in self?.show(message) }
        }
        let revert = apply(chosen, read)
        exit()
        let mark = bulk.markRead(ids: chosen.sorted(), read: read, revert: revert) { [weak self] message in
            self?.show(message)
        }
        undoable = Undoable(message: Categories.markedLabel(count: chosen.count, read: read), mark: mark, revert: revert)
    }

    /// Undo on the toast: called off in time, so the screen goes back as it was.
    func undo(_ item: Undoable) {
        if bulk.undo(item.mark) {
            item.revert()
        } else {
            notice = "Too late to undo: it was already saved."
        }
        if undoable === item { undoable = nil }
    }

    /// The Undo toast went without Undo.
    func undoShown(_ item: Undoable) {
        if undoable === item { undoable = nil }
    }

    /// Download (offline) for `novels`: their unread chapters.
    func download(_ novels: [NovelSummary]) {
        exit()
        working = true
        Task {
            notice = await bulk.download(novels)
            working = false
        }
    }

    func removeDownloads(_ ids: [Int]) {
        exit()
        Task { notice = await bulk.removeDownloads(ids) }
    }

    private func show(_ message: String?) {
        if let message { notice = message }
    }
}

// MARK: - Selection UI

extension View {
    /// The selection's toolbar in place of the screen's own while selecting
    /// (Cancel, the count, Select all; Set categories, Mark read / unread and
    /// Download in the bottom bar), its toasts (notices and "Marked 3 series
    /// as read · Undo"), and the "Set categories" sheet.
    func seriesSelection(_ selection: SeriesSelection, shown: [NovelSummary], categorise: Bool,
                         markRead: @escaping (Bool) -> Void) -> some View {
        modifier(SeriesSelectionChrome(selection: selection, shown: shown, categorise: categorise, markRead: markRead))
    }
}

private struct SeriesSelectionChrome: ViewModifier {
    @Bindable var selection: SeriesSelection
    let shown: [NovelSummary]
    let categorise: Bool
    let markRead: (Bool) -> Void

    func body(content: Content) -> some View {
        let shownIds = shown.map(\.id)
        let chosen = shown.filter { selection.contains($0.id) }
        content
            .navigationBarBackButtonHidden(selection.active)
            .toolbar(selection.active ? .hidden : .automatic, for: .tabBar)
            .toolbar {
                if selection.active {
                    ToolbarItem(placement: .topBarLeading) {
                        Button("Cancel") { selection.exit() }
                            .accessibilityLabel("Stop selecting")
                    }
                    ToolbarItem(placement: .principal) {
                        Text("\(selection.count) selected").font(.ui(.headline))
                    }
                    ToolbarItem(placement: .topBarTrailing) {
                        if !selection.allSelected(shownIds) {
                            Button("Select All") { selection.selectAll(shownIds) }
                        }
                    }
                    ToolbarItemGroup(placement: .bottomBar) {
                        if categorise {
                            Button { selection.openCategories() } label: {
                                Label("Set categories", systemImage: "tag")
                            }
                            .disabled(selection.working)
                            Spacer()
                        }
                        Button { markRead(true) } label: {
                            Label("Mark as read", systemImage: "checkmark.circle")
                        }
                        .disabled(selection.working)
                        Spacer()
                        Button { markRead(false) } label: {
                            Label("Mark as unread", systemImage: "circle.dashed")
                        }
                        .disabled(selection.working)
                        Spacer()
                        Menu {
                            Button { selection.download(chosen) } label: {
                                Label("Download", systemImage: "arrow.down.circle")
                            }
                            .disabled(selection.working)
                            Button(role: .destructive) { selection.removeDownloads(chosen.map(\.id)) } label: {
                                Label("Remove download", systemImage: "trash")
                            }
                        } label: {
                            Label("More", systemImage: "ellipsis.circle")
                        }
                    }
                }
            }
            .onChange(of: shownIds) { _, ids in selection.retain(ids) }
            .overlay(alignment: .bottom) { SelectionToasts(selection: selection) }
            .sheet(item: $selection.categoriesFor) { sheet in
                SetCategoriesSheet(novelIds: sheet.novelIds) { failure in selection.categoriesDone(failure) }
            }
    }
}

/// The selection's toasts: the Undo one for as long as Undo is possible, then notices.
private struct SelectionToasts: View {
    let selection: SeriesSelection

    var body: some View {
        VStack(spacing: 8) {
            if let undoable = selection.undoable {
                Toast(text: undoable.message, action: "Undo") { selection.undo(undoable) }
                    .id(undoable.id)
                    .task(id: undoable.id) {
                        // Gone when the mark is sent: Undo can't call it off after that.
                        try? await Task.sleep(for: .seconds(Categories.undoSeconds))
                        if !Task.isCancelled { selection.undoShown(undoable) }
                    }
            }
            if let notice = selection.notice {
                Toast(text: notice)
                    .id(notice)
                    .task(id: notice) {
                        try? await Task.sleep(for: .seconds(4))
                        if !Task.isCancelled, selection.notice == notice { selection.notice = nil }
                    }
                    .onTapGesture { selection.notice = nil }
            }
        }
        .padding(.horizontal, 16)
        .padding(.bottom, 12)
        .animation(.snappy, value: selection.undoable?.id)
        .animation(.snappy, value: selection.notice)
    }
}

/// A snackbar-like line at the bottom of a screen, with an optional action.
struct Toast: View {
    let text: String
    var action: String?
    var perform: (() -> Void)?

    var body: some View {
        HStack(spacing: 12) {
            Text(text)
                .font(.ui(.subheadline))
                .foregroundStyle(.white)
                .frame(maxWidth: .infinity, alignment: .leading)
            if let action, let perform {
                Button(action, action: perform)
                    .font(.ui(.subheadline, .semibold))
                    .foregroundStyle(Color.brandAmber)
            }
        }
        .padding(.horizontal, 16)
        .padding(.vertical, 12)
        .background(Color.black.opacity(0.85), in: RoundedRectangle(cornerRadius: 12, style: .continuous))
        .frame(maxWidth: 560)
        .transition(.move(edge: .bottom).combined(with: .opacity))
        .accessibilityElement(children: .combine)
        .accessibilityAddTraits(.updatesFrequently)
    }
}

extension View {
    /// A toast for a one-off message (cleared after a few seconds or a tap).
    func toast(_ message: Binding<String?>) -> some View {
        overlay(alignment: .bottom) {
            if let text = message.wrappedValue {
                Toast(text: text)
                    .padding(.horizontal, 16)
                    .padding(.bottom, 12)
                    .onTapGesture { message.wrappedValue = nil }
                    .task(id: text) {
                        try? await Task.sleep(for: .seconds(4))
                        if !Task.isCancelled, message.wrappedValue == text { message.wrappedValue = nil }
                    }
            }
        }
        .animation(.snappy, value: message.wrappedValue)
    }
}

/// On a selected cover: a tint, an outline and a tick in the corner.
struct SelectedMark: View {
    let selected: Bool

    var body: some View {
        if selected {
            ZStack(alignment: .topLeading) {
                RoundedRectangle(cornerRadius: 6, style: .continuous)
                    .fill(Color.brandAccent.opacity(0.28))
                RoundedRectangle(cornerRadius: 6, style: .continuous)
                    .strokeBorder(Color.brandAccent, lineWidth: 3)
                Image(systemName: "checkmark.circle.fill")
                    .font(.system(size: 22))
                    .symbolRenderingMode(.palette)
                    .foregroundStyle(.white, Color.brandAccent)
                    .padding(6)
            }
            .allowsHitTesting(false)
        }
    }
}

/// A series in a grid (Library, a category, Want to read) that can take
/// part in a multi-select: while selecting a tap toggles it; otherwise it
/// opens the series. Its context menu starts selecting, and acts on just
/// this series (Set categories, Mark read / unread, Download).
struct SelectableSeriesCell: View {
    let novel: NovelSummary
    let selection: SeriesSelection
    var categorise: Bool = true
    var markRead: (Set<Int>, Bool) -> Void = { _, _ in }

    var body: some View {
        let selected = selection.contains(novel.id)
        Group {
            if selection.active {
                Button { selection.tap(novel.id) } label: {
                    LibraryCell(novel: novel, selected: selected)
                }
                .accessibilityAddTraits(selected ? .isSelected : [])
                .accessibilityHint("Toggles the selection")
            } else {
                NavigationLink(value: NovelRoute(id: novel.id, summary: novel)) {
                    LibraryCell(novel: novel)
                }
            }
        }
        .buttonStyle(.plain)
        .contextMenu {
            if !selection.active {
                Button { selection.longPress(novel.id) } label: {
                    Label("Select", systemImage: "checkmark.circle")
                }
                Divider()
                if categorise {
                    Button { selection.openCategories([novel.id]) } label: {
                        Label("Set categories…", systemImage: "tag")
                    }
                }
                Button { markRead([novel.id], true) } label: {
                    Label("Mark as read", systemImage: "checkmark.circle")
                }
                Button { markRead([novel.id], false) } label: {
                    Label("Mark as unread", systemImage: "circle.dashed")
                }
                Button { selection.download([novel]) } label: {
                    Label("Download", systemImage: "arrow.down.circle")
                }
                Button(role: .destructive) { selection.removeDownloads([novel.id]) } label: {
                    Label("Remove download", systemImage: "trash")
                }
            }
        }
        .accessibilityAction(named: selection.active ? "Toggle selection" : "Select") { selection.longPress(novel.id) }
    }
}
