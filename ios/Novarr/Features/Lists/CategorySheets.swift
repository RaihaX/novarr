import SwiftUI

private let offlineNoteText = "You're offline. Your categories show as last saved; they can be changed once the server is reachable."

/// "You're offline…" above a category control.
struct CategoriesOfflineNote: View {
    var body: some View {
        Label(offlineNoteText, systemImage: "icloud.slash")
            .font(.ui(.footnote))
            .foregroundStyle(.secondary)
    }
}

/// "12 series", with "hidden on Home" for a category Home leaves out.
func categoryNote(_ list: ReadingList) -> String {
    seriesCount(list.count) + (list.hiddenOnHome ? " · hidden on Home" : "")
}

/// A category's box in "Set categories".
private struct TriStateBox: View {
    let state: Categories.Tri

    var body: some View {
        Image(systemName: symbol)
            .font(.system(size: 22))
            .foregroundStyle(state == .off ? Color.secondary : Color.brandAccent)
            .accessibilityHidden(true)
    }

    private var symbol: String {
        switch state {
        case .on: "checkmark.square.fill"
        case .off: "square"
        case .mixed: "minus.square.fill"
        }
    }
}

/// "Set categories…" for a multi-select: every category (the built-ins
/// too) with a box that is ticked when all the selected series are in it,
/// clear when none are, and mixed when some are. A new category can be made
/// inline (ticked). Apply sends one memberships call with only what changed;
/// `onDone` gets nil when saved (a failure stays in the sheet).
/// Mirrors android ui/lists/CategorySheets.kt.
struct SetCategoriesSheet: View {
    let novelIds: [Int]
    let onDone: (String?) -> Void

    @Environment(AppModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @State private var initial: [Int: Categories.Tri]?
    @State private var current: [Int: Categories.Tri] = [:]
    @State private var sending = false
    @State private var message: String?

    var body: some View {
        let lists = app.lists
        let enabled = app.isReachable && !sending
        let diff = initial.map { Categories.diff(initial: $0, current: current) }
        NavigationStack {
            Form {
                Section {
                    if !app.isReachable { CategoriesOfflineNote() }
                    if initial == nil {
                        ProgressView().frame(maxWidth: .infinity)
                    } else if lists.lists.isEmpty {
                        Text("No categories yet: name your first one below.")
                    } else {
                        ForEach(lists.sorted) { list in
                            let started = initial?[list.id] ?? .off
                            let box = current[list.id] ?? started
                            Button {
                                current[list.id] = Categories.next(box, started: started)
                            } label: {
                                HStack(spacing: 12) {
                                    TriStateBox(state: box)
                                    Text(list.name).lineLimit(1)
                                        .frame(maxWidth: .infinity, alignment: .leading)
                                    Text(categoryNote(list)).font(.ui(.footnote)).foregroundStyle(.secondary)
                                }
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                            .disabled(!enabled)
                            .accessibilityLabel(list.name)
                            .accessibilityValue(box == .on ? "Ticked" : box == .mixed ? "Some selected series" : "Not ticked")
                        }
                    }
                } header: {
                    Text(seriesCount(novelIds.count))
                }
                if initial != nil {
                    Section {
                        NewCategoryField(lists: lists.lists, enabled: enabled, label: "New category") { name in
                            switch await lists.create(name: name) {
                            case .success(let made):
                                // Made empty, then ticked here: Apply puts the selection in it with the rest.
                                current[made.id] = .on
                                return nil
                            case .failure(let problem):
                                return problem.message
                            }
                        }
                    }
                }
            }
            .navigationTitle("Set categories")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }.disabled(sending)
                }
                ToolbarItem(placement: .confirmationAction) {
                    if sending {
                        ProgressView()
                    } else {
                        Button("Apply") { apply(diff) }
                            .disabled(!enabled || diff?.isEmpty != false)
                    }
                }
            }
            .toast($message)
        }
        .presentationDetents([.medium, .large])
        .interactiveDismissDisabled(sending)
        .task {
            if !app.lists.loaded { await app.lists.refresh() }
            if initial == nil {
                let boxes = Categories.initial(app.lists.lists, novelIds: novelIds)
                initial = boxes
                current = boxes
            }
        }
    }

    private func apply(_ diff: Categories.Diff?) {
        guard let diff, !diff.isEmpty else { return }
        sending = true
        Task {
            let failure = await app.lists.setMemberships(novelIds: novelIds, add: diff.add, remove: diff.remove)
            sending = false
            if let failure {
                message = failure
            } else {
                onDone(nil)
            }
        }
    }
}

/// The series screen's "Categories": a box per own category (each tap
/// saves at once; a failure is undone and explained), a field for a new one
/// (made with this series in it) and, with none yet, one-tap starters.
/// Read-only offline. Mirrors android ui/lists/ListSheets.kt (SeriesListsSheet).
struct SeriesCategoriesSheet: View {
    let novelId: Int
    let novelName: String

    @Environment(AppModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @State private var creating = false
    @State private var message: String?

    var body: some View {
        let lists = app.lists
        // The reader's own lists: "Want to read" and the heart are their own buttons.
        let own = ReadingLists.sorted(ReadingLists.own(lists.lists))
        NavigationStack {
            Form {
                Section {
                    if !app.isReachable { CategoriesOfflineNote() }
                    if !lists.loaded {
                        ProgressView().frame(maxWidth: .infinity)
                    } else if own.isEmpty {
                        Text("Keep series together: start with one of these, or name your own below.")
                        HStack {
                            ForEach(ReadingLists.starters(lists.lists), id: \.self) { name in
                                Button {
                                    creating = true
                                    Task {
                                        if case .failure(let problem) = await lists.create(name: name, addNovel: novelId) {
                                            message = problem.message
                                        }
                                        creating = false
                                    }
                                } label: {
                                    Label(name, systemImage: "plus")
                                }
                                .buttonStyle(.bordered)
                                .disabled(!app.isReachable || creating)
                            }
                        }
                    } else {
                        ForEach(own) { list in
                            let checked = list.contains(novelId)
                            let busy = lists.state.busy(listId: list.id, novelId: novelId)
                            Button {
                                Task { message = await lists.toggle(listId: list.id, novelId: novelId, add: !checked) }
                            } label: {
                                HStack(spacing: 12) {
                                    Image(systemName: checked ? "checkmark.square.fill" : "square")
                                        .font(.system(size: 22))
                                        .foregroundStyle(checked ? Color.brandAccent : Color.secondary)
                                    Text(list.name).lineLimit(1).frame(maxWidth: .infinity, alignment: .leading)
                                    Text(seriesCount(list.count)).font(.ui(.footnote)).foregroundStyle(.secondary)
                                }
                                .contentShape(Rectangle())
                            }
                            .buttonStyle(.plain)
                            .disabled(!app.isReachable || busy)
                            .accessibilityLabel(list.name)
                            .accessibilityAddTraits(checked ? .isSelected : [])
                        }
                    }
                } header: {
                    Text(novelName).lineLimit(1)
                }
                if lists.loaded {
                    Section {
                        NewCategoryField(lists: lists.lists, enabled: app.isReachable, label: "New category with this series") { name in
                            if case .failure(let problem) = await lists.create(name: name, addNovel: novelId) { return problem.message }
                            return nil
                        }
                    }
                }
            }
            .navigationTitle("Categories")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) { Button("Done") { dismiss() } }
            }
            .toast($message)
        }
        .presentationDetents([.medium, .large])
        .task { if !app.lists.loaded { await app.lists.refresh() } }
    }
}

/// A name field checked as it is typed (the server's limits); `onCreate`
/// answers nil when made, else the message to show under the field.
struct NewCategoryField: View {
    let lists: [ReadingList]
    let enabled: Bool
    let label: String
    let onCreate: (String) async -> String?

    @State private var name = ""
    @State private var error: String?
    @State private var sending = false

    var body: some View {
        let problem = name.trimmingCharacters(in: .whitespaces).isEmpty ? nil : ReadingLists.nameProblem(name, lists: lists)
        let canCreate = enabled && !sending && !ReadingLists.clean(name).isEmpty && problem == nil
        VStack(alignment: .leading, spacing: 4) {
            HStack {
                TextField(label, text: $name)
                    .textInputAutocapitalization(.sentences)
                    .submitLabel(.done)
                    .onSubmit { create(canCreate) }
                    .onChange(of: name) { error = nil }
                    .disabled(!enabled)
                if sending {
                    ProgressView()
                } else {
                    Button("Add") { create(canCreate) }
                        .buttonStyle(.bordered)
                        .disabled(!canCreate)
                }
            }
            let shown = problem ?? error
            Text(shown ?? "\(ReadingLists.length(name)) / \(ReadingLists.nameMax)")
                .font(.ui(.caption))
                .foregroundStyle(shown == nil ? Color.secondary : Color.red)
        }
    }

    private func create(_ allowed: Bool) {
        guard allowed else { return }
        sending = true
        Task {
            let failure = await onCreate(name)
            sending = false
            if let failure { error = failure } else { name = "" }
        }
    }
}

/// "New category" or "Rename": a name checked as it is typed, saved on the
/// server (categories are never changed offline).
struct CategoryNameSheet: View {
    enum Mode {
        case create
        case rename(ReadingList)
    }

    let mode: Mode
    var onDone: (ReadingList?) -> Void = { _ in }

    @Environment(AppModel.self) private var app
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var error: String?
    @State private var sending = false
    @FocusState private var focused: Bool

    private var renaming: ReadingList? {
        if case .rename(let list) = mode { return list }
        return nil
    }

    var body: some View {
        let lists = app.lists.lists
        let problem: String? = {
            if renaming == nil, ReadingLists.clean(name).isEmpty { return nil }
            return ReadingLists.nameProblem(name, lists: lists, renaming: renaming?.id)
        }()
        let unchanged = renaming.map { ReadingLists.clean(name) == $0.name } ?? false
        let canSave = !sending && app.isReachable && !ReadingLists.clean(name).isEmpty && problem == nil && !unchanged
        NavigationStack {
            Form {
                Section {
                    TextField("Name", text: $name)
                        .textInputAutocapitalization(.sentences)
                        .focused($focused)
                        .submitLabel(.done)
                        .onSubmit { if canSave { save() } }
                        .onChange(of: name) { error = nil }
                } footer: {
                    VStack(alignment: .leading, spacing: 8) {
                        let shown = problem ?? error
                        Text(shown ?? "\(ReadingLists.length(name)) / \(ReadingLists.nameMax)")
                            .foregroundStyle(shown == nil ? Color.secondary : Color.red)
                        if renaming == nil {
                            Text("Group anything — books, manga, web novels — like “Manga – Action”. Then select series in the Library to put them in it.")
                        }
                        if !app.isReachable { CategoriesOfflineNote() }
                    }
                }
            }
            .navigationTitle(renaming == nil ? "New category" : "Rename category")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() }.disabled(sending) }
                ToolbarItem(placement: .confirmationAction) {
                    if sending {
                        ProgressView()
                    } else {
                        Button(renaming == nil ? "Create" : "Save") { save() }.disabled(!canSave)
                    }
                }
            }
        }
        .presentationDetents([.medium])
        .interactiveDismissDisabled(sending)
        .onAppear {
            if let renaming, name.isEmpty { name = renaming.name }
            focused = true
        }
    }

    private func save() {
        sending = true
        Task {
            if let renaming {
                let failure = await app.lists.rename(listId: renaming.id, name: name)
                sending = false
                if let failure { error = failure } else { onDone(nil); dismiss() }
            } else {
                let result = await app.lists.create(name: name)
                sending = false
                switch result {
                case .success(let list):
                    onDone(list)
                    dismiss()
                case .failure(let problem):
                    error = problem.message
                }
            }
        }
    }
}

extension View {
    /// "Delete “Action”?": the series stay in the library; only the category goes.
    func deleteCategoryConfirmation(_ list: Binding<ReadingList?>, onDeleted: @escaping () -> Void = {},
                                    onFailure: @escaping (String) -> Void) -> some View {
        modifier(DeleteCategoryConfirmation(list: list, onDeleted: onDeleted, onFailure: onFailure))
    }
}

private struct DeleteCategoryConfirmation: ViewModifier {
    @Binding var list: ReadingList?
    let onDeleted: () -> Void
    let onFailure: (String) -> Void
    @Environment(AppModel.self) private var app

    func body(content: Content) -> some View {
        content.alert(
            list.map { "Delete “\($0.name)”?" } ?? "Delete?",
            isPresented: Binding(get: { list != nil }, set: { if !$0 { list = nil } }),
            presenting: list
        ) { target in
            Button("Delete", role: .destructive) {
                Task {
                    if let failure = await app.lists.delete(listId: target.id) {
                        onFailure(failure)
                    } else {
                        onDeleted()
                    }
                }
            }
            Button("Cancel", role: .cancel) {}
        } message: { target in
            Text(target.count == 0 ? "The category is empty."
                 : "The \(seriesCount(target.count)) in it stay in your library; only the category goes.")
        }
    }
}
