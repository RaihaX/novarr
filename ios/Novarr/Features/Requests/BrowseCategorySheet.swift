import SwiftUI

/// Hold a search or Discover card → "Add to category" (android
/// AddToCategorySheet): tick categories; in the library that is one
/// memberships call, not yet a request that carries them ("Request & add").
struct BrowseCategorySheet: View {
    @Environment(AppModel.self) private var app
    let result: SearchResult
    let sending: Bool
    let onCancel: () -> Void
    let onConfirm: (Set<Int>) -> Void

    @State private var chosen: Set<Int> = []
    @State private var newName = ""
    @State private var creating = false
    @State private var createError: String?

    private var inLibrary: Bool { result.inLibrary || result.novelId != nil }

    private var confirmLabel: String {
        if inLibrary || result.requested?.byMe == true { return "Save" }
        return result.requested != nil ? "Me too & add" : "Request & add"
    }

    var body: some View {
        let online = app.isReachable
        let action = Categories.browseAction(result, chosen: chosen)
        NavigationStack {
            Form {
                Section {
                    Text(result.title).font(.literata(.body)).foregroundStyle(.secondary).lineLimit(2)
                    if !inLibrary {
                        Text(result.requested?.byMe == true
                             ? "You've asked for it: it goes into these categories once it's in the library."
                             : "Not in the library yet: this asks the owner for it, and it goes into these categories once it's here.")
                            .font(.ui(.footnote)).foregroundStyle(.secondary)
                    }
                    if !online { Label("Offline · categories need a connection", systemImage: "icloud.slash").foregroundStyle(.secondary) }
                }
                Section("Categories") {
                    let lists = app.lists.sorted
                    if !app.lists.loaded {
                        ProgressView().frame(maxWidth: .infinity)
                    } else if lists.isEmpty {
                        Text("No categories yet: name your first one below.")
                    } else {
                        ForEach(lists) { list in
                            Button {
                                if chosen.contains(list.id) { chosen.remove(list.id) } else { chosen.insert(list.id) }
                            } label: {
                                HStack {
                                    Image(systemName: chosen.contains(list.id) ? "checkmark.square.fill" : "square")
                                        .foregroundStyle(chosen.contains(list.id) ? Color.brandAccent : .secondary)
                                    Text(list.name).foregroundStyle(Color.primary).lineLimit(1)
                                    Spacer()
                                    Text(list.count == 1 ? "1 series" : "\(list.count) series")
                                        .font(.ui(.footnote)).foregroundStyle(.secondary)
                                }
                            }
                            .disabled(!online || sending)
                            .accessibilityAddTraits(chosen.contains(list.id) ? .isSelected : [])
                        }
                    }
                }
                if app.lists.loaded {
                    Section {
                        HStack {
                            TextField("New category", text: $newName)
                                .submitLabel(.done)
                                .onSubmit(create)
                            if creating {
                                ProgressView()
                            } else {
                                Button("Add", action: create)
                                    .disabled(!online || sending || newName.trimmingCharacters(in: .whitespaces).isEmpty)
                            }
                        }
                        if let createError { Text(createError).font(.ui(.footnote)).foregroundStyle(.red) }
                    }
                }
            }
            .navigationTitle("Add to category")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel", action: onCancel).disabled(sending) }
                ToolbarItem(placement: .confirmationAction) {
                    if sending {
                        ProgressView()
                    } else {
                        Button(confirmLabel) { onConfirm(chosen) }
                            .disabled(!online || action == .nothing)
                    }
                }
            }
        }
        .presentationDetents([.medium, .large])
        .interactiveDismissDisabled(sending)
        .task {
            chosen = Categories.preTicked(result)
            if !app.lists.loaded { await app.lists.refresh() }
        }
    }

    private func create() {
        let name = newName.trimmingCharacters(in: .whitespaces)
        guard !name.isEmpty, !creating else { return }
        creating = true
        createError = nil
        Task {
            switch await app.lists.create(name: name) {
            case .success(let list):
                chosen.insert(list.id)
                newName = ""
            case .failure(let problem):
                createError = problem.message
            }
            creating = false
        }
    }
}
