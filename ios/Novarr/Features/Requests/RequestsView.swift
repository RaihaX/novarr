import SwiftUI

/// Reader requests (android ui/requests/RequestsScreen.kt): search every
/// source for a book, web novel, manga or comic and request it, follow your
/// requests, and (the owner, on the home network) review everyone's. Only
/// offered when the server's meta says `requests: true`.
struct RequestsView: View {
    @Environment(AppModel.self) private var app
    let section: RequestsRoute.Section
    @State private var model: RequestsModel?

    var body: some View {
        Group {
            if let model {
                RequestsContent(model: model)
            } else {
                ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .navigationTitle("Requests")
        .brandBackground()
        .onAppear {
            if model == nil { model = RequestsModel(app: app, section: section) }
        }
    }
}

private struct RequestsContent: View {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel
    private var hub: NotificationHub { .shared }

    var body: some View {
        VStack(spacing: 0) {
            if app.serverMeta?.requests == false {
                ContentUnavailableView("Requests are off", systemImage: "tray",
                                       description: Text("This server doesn't take requests right now."))
            } else {
                Picker("Section", selection: Binding(get: { model.currentSection }, set: { model.section = $0 })) {
                    ForEach(model.sections, id: \.self) { section in
                        Text(title(section)).tag(section)
                    }
                }
                .pickerStyle(.segmented)
                .padding(.horizontal, 16)
                .padding(.vertical, 8)
                if !app.isReachable {
                    OfflinePill(text: "Offline · requests need a connection").padding(.bottom, 6)
                }
                switch model.currentSection {
                case .search: RequestsSearchPart(model: model)
                case .mine: RequestsMinePart(model: model)
                case .review: RequestsReviewPart(model: model)
                }
            }
        }
        .onAppear {
            model.setVisible(true)
            hub.requestsScreen(visible: true, app: app)
        }
        .onDisappear {
            model.setVisible(false)
            hub.requestsScreen(visible: false, app: app)
        }
        // First load, and again whenever the feed brought news (a decision, a new request).
        .task(id: hub.feedRevision) { await model.reloadLists() }
        .modifier(RequestsSheets(model: model))
        .noticeToast($model.notice)
    }

    private func title(_ section: RequestsRoute.Section) -> String {
        switch section {
        case .search: "Search"
        case .mine: "My requests"
        case .review: model.review.isEmpty ? "To review" : "To review (\(model.review.count))"
        }
    }
}

// MARK: - Search

private struct RequestsSearchPart: View {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel
    @State private var now = RequestsModel.now
    @FocusState private var fieldFocused: Bool

    private var online: Bool { app.isReachable }
    private var wait: Int { RetryAfter.remaining(model.blockedUntil, now: now) }
    private var canSearch: Bool { !model.query.trimmingCharacters(in: .whitespaces).isEmpty && !model.searching && wait == 0 }

    var body: some View {
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 14) {
                form
                let search = model.search
                let shelfFirst = search == nil && !model.searching
                if shelfFirst { DiscoverShelf(model: model) }
                status(search)
                if let search, search.isDone { results(search) }
                if !shelfFirst { DiscoverShelf(model: model) }
            }
            .frame(maxWidth: 720)
            .padding(.horizontal, 16)
            .padding(.vertical, 8)
            .frame(maxWidth: .infinity)
        }
        .scrollDismissesKeyboard(.interactively)
        // Counts Retry-After down; re-enables the button when it has passed.
        .task(id: model.blockedUntil) {
            while model.blockedUntil != nil {
                now = RequestsModel.now
                if !model.isBlocked(now: now) {
                    model.unblock()
                    break
                }
                try? await Task.sleep(for: .milliseconds(500))
            }
            now = RequestsModel.now
        }
    }

    private var form: some View {
        VStack(alignment: .leading, spacing: 10) {
            Picker("Medium", selection: Binding(get: { model.medium }, set: { model.selectMedium($0) })) {
                ForEach(model.mediums) { Text($0.title).tag($0) }
            }
            .pickerStyle(.segmented)
            HStack(spacing: 8) {
                Image(systemName: "magnifyingglass").foregroundStyle(.secondary)
                TextField(Requests.placeholder(model.medium), text: Binding(
                    get: { model.query },
                    set: { model.query = String($0.prefix(Requests.queryLimit($0))) }))
                    .focused($fieldFocused)
                    .submitLabel(.search)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                    .onSubmit { if canSearch { runSearch() } }
                    .accessibilityLabel(model.medium.takesLinks ? "Title, author, ISBN or link" : "Title")
                if !model.query.isEmpty {
                    Button { model.query = "" } label: { Image(systemName: "xmark.circle.fill") }
                        .foregroundStyle(.secondary)
                        .accessibilityLabel("Clear title")
                }
            }
            .padding(.horizontal, 12)
            .padding(.vertical, 10)
            .background(Color.surface, in: RoundedRectangle(cornerRadius: 10, style: .continuous))
            HStack(alignment: .center, spacing: 12) {
                Text(Requests.queryHint(model.medium, model.query) ?? "")
                    .font(.ui(.footnote))
                    .foregroundStyle(.secondary)
                    .frame(maxWidth: .infinity, alignment: .leading)
                Button(wait > 0 ? "Wait \(wait)s" : "Search", action: runSearch)
                    .buttonStyle(.borderedProminent)
                    .disabled(!canSearch)
                    .accessibilityValue(wait > 0 ? "Available in \(wait) seconds" : "")
            }
        }
    }

    private func runSearch() {
        // The keyboard goes so the results can be seen.
        fieldFocused = false
        model.startSearch()
    }

    @ViewBuilder
    private func status(_ search: RequestSearch?) -> some View {
        if let error = model.searchError {
            Text(error).font(.ui(.subheadline)).foregroundStyle(.red)
        } else if model.searching {
            VStack(alignment: .leading, spacing: 8) {
                ProgressView().progressViewStyle(.linear)
                Text(search.map { "Searching every source for “\($0.query)”…" } ?? "Starting the search…")
                    .font(.ui(.subheadline))
                if model.slow {
                    Text("Some sources are slow. A search can take up to a minute and a half.")
                        .font(.ui(.footnote)).foregroundStyle(.secondary)
                }
            }
            .accessibilityElement(children: .combine)
        } else if let search, search.isFailed {
            Text(search.message ?? "The search failed.").font(.ui(.subheadline)).foregroundStyle(.red)
        }
    }

    @ViewBuilder
    private func results(_ search: RequestSearch) -> some View {
        if let skipped = Requests.skippedLabel(search.skippedSources.map { $0.sourceName.isEmpty ? $0.source : $0.sourceName }) {
            Text(skipped).font(.ui(.footnote)).foregroundStyle(.secondary)
        }
        if search.results.isEmpty {
            ContentUnavailableView {
                Label("Nothing found for “\(search.query)”", systemImage: "magnifyingglass")
            } description: {
                Text("No source has it under that title. You can still ask the owner to find it.")
            } actions: {
                Button("Request it anyway") { model.openFreeText() }
                    .buttonStyle(.borderedProminent)
                    .disabled(!online)
            }
        } else {
            ForEach(search.results) { result in
                ResultCard(model: model, result: result, fromShelf: false)
            }
            Button("Not here? Request it anyway") { model.openFreeText() }
                .disabled(!online)
                .frame(maxWidth: .infinity)
        }
    }
}

/// The Discover shelf: a source's lists for the picker's medium, a chip
/// each, one row of covers to request from. Offline it shows the saved copy.
private struct DiscoverShelf: View {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel

    var body: some View {
        if model.medium.hasDiscover,
           !(model.browseLoaded && model.browseLists.isEmpty && model.browseResults.isEmpty && model.browseError == nil) {
            VStack(alignment: .leading, spacing: 8) {
                HStack(spacing: 8) {
                    Text("Discover").font(.ui(.headline, .semibold)).accessibilityAddTraits(.isHeader)
                    if let source = model.browseSourceName {
                        Text("from \(source)").font(.ui(.footnote)).foregroundStyle(.secondary).lineLimit(1)
                    }
                    Spacer()
                    if model.browseLoading, !model.browseResults.isEmpty { ProgressView().controlSize(.small) }
                }
                if model.browseLists.count > 1 {
                    ScrollView(.horizontal, showsIndicators: false) {
                        HStack(spacing: 8) {
                            ForEach(model.browseLists) { list in
                                let selected = list.id == model.browseList
                                Button(list.name.isEmpty ? list.id : list.name) { model.selectBrowseList(list.id) }
                                    .font(.ui(.subheadline, .medium))
                                    .padding(.horizontal, 12)
                                    .padding(.vertical, 6)
                                    .foregroundStyle(selected ? Color.onAccent : Color.primary)
                                    .background(selected ? Color.brandAccent : Color.surface, in: Capsule())
                                    .accessibilityAddTraits(selected ? .isSelected : [])
                            }
                        }
                    }
                }
                if model.browseFromCache {
                    Text("Saved copy · \(model.browseError ?? "offline")").font(.ui(.footnote)).foregroundStyle(.secondary)
                }
                content
            }
            .padding(.top, 4)
        }
    }

    @ViewBuilder
    private var content: some View {
        if model.browseResults.isEmpty, model.browseLoading || !model.browseLoaded {
            ProgressView().progressViewStyle(.linear)
        } else if model.browseResults.isEmpty, let error = model.browseError {
            HStack {
                Text(error).font(.ui(.footnote)).foregroundStyle(.red)
                Spacer()
                Button("Try again") { model.loadBrowse(list: model.browseList) }.disabled(!app.isReachable)
            }
        } else if model.browseResults.isEmpty {
            Text("Nothing new on this list: the library has it all.").font(.ui(.footnote)).foregroundStyle(.secondary)
        } else {
            ScrollView(.horizontal, showsIndicators: false) {
                LazyHStack(alignment: .top, spacing: 12) {
                    ForEach(model.browseResults) { result in
                        ShelfCard(model: model, result: result)
                    }
                    if model.browseHasMore {
                        Group {
                            if model.browseLoadingMore {
                                ProgressView()
                            } else {
                                Button("More") { model.loadMoreBrowse() }.disabled(!app.isReachable)
                            }
                        }
                        .frame(width: 96, height: 180)
                    }
                }
            }
        }
    }
}

/// How faded a card is once it has been put somewhere.
private let takenOpacity = 0.45

private struct ShelfCard: View {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel
    let result: SearchResult

    var body: some View {
        let mark = app.lists.offered ? Categories.mark(result, lists: app.lists.lists) : nil
        VStack(alignment: .leading, spacing: 4) {
            VStack(alignment: .leading, spacing: 2) {
                CoverView(url: result.coverUrl, title: result.title, targetWidth: 124)
                    .overlay(alignment: .bottomLeading) {
                        if let mark { CategoryBadge(mark: mark).padding(4) }
                    }
                Text(result.title).font(.ui(.footnote, .semibold)).lineLimit(2)
                if let author = result.author, !author.isEmpty {
                    Text(author).font(.ui(.caption)).foregroundStyle(.secondary).lineLimit(1)
                }
                if let series = result.seriesLabel {
                    Text(series).font(.ui(.caption)).foregroundStyle(.secondary).lineLimit(1)
                }
            }
            .opacity(mark != nil ? takenOpacity : 1)
            .accessibilityElement(children: .combine)
            CardActionButton(model: model, result: result, fromShelf: true, compact: true)
        }
        .frame(width: 124)
        .categoryMenu(enabled: app.lists.offered && app.isReachable) { model.openCategorySheet(result, fromShelf: true) }
    }
}

private struct ResultCard: View {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel
    let result: SearchResult
    let fromShelf: Bool

    var body: some View {
        let mark = app.lists.offered ? Categories.mark(result, lists: app.lists.lists) : nil
        HStack(alignment: .top, spacing: 14) {
            CoverView(url: result.coverUrl, title: result.title, targetWidth: 84)
                .frame(width: 84)
                .opacity(mark != nil ? takenOpacity : 1)
            VStack(alignment: .leading, spacing: 4) {
                if let mark { CategoryBadge(mark: mark) }
                VStack(alignment: .leading, spacing: 2) {
                    Text(result.title).font(.literata(.headline, .semibold)).lineLimit(3)
                    if let author = result.author, !author.isEmpty {
                        Text(author).font(.ui(.subheadline)).foregroundStyle(.secondary).lineLimit(1)
                    }
                    if let series = result.seriesLabel {
                        Text(series).font(.ui(.footnote)).foregroundStyle(.secondary).lineLimit(1)
                    }
                    let sources = Requests.sourcesLabel(result.sources)
                    if !sources.isEmpty {
                        Text(sources).font(.ui(.footnote)).foregroundStyle(.secondary).lineLimit(2)
                    }
                }
                .opacity(mark != nil ? takenOpacity : 1)
                .accessibilityElement(children: .combine)
                RequestSynopsis(text: result.description)
                CardActionButton(model: model, result: result, fromShelf: fromShelf, compact: false)
                    .padding(.top, 2)
            }
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .padding(12)
        .background(Color.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .categoryMenu(enabled: app.lists.offered && app.isReachable) { model.openCategorySheet(result, fromShelf: fromShelf) }
    }
}

/// The card's one action: Request, Me too, In library, or Requested.
private struct CardActionButton: View {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel
    let result: SearchResult
    let fromShelf: Bool
    let compact: Bool

    var body: some View {
        let action = Requests.cardAction(result, canJoin: model.canJoin)
        let label = Requests.cardActionLabel(action)
        let online = app.isReachable
        switch action {
        case .request:
            Button { model.openRequestSheet(result, fromShelf: fromShelf) } label: {
                if compact { Text(label) } else { Label(label, systemImage: "text.badge.plus") }
            }
            .buttonStyle(.borderedProminent)
            .controlSize(compact ? .small : .regular)
            .disabled(!online)
            .accessibilityLabel("Request \(result.title)")
        case .meToo:
            HStack(spacing: 8) {
                Button(label) { model.meToo(result, fromShelf: fromShelf) }
                    .buttonStyle(.bordered)
                    .controlSize(compact ? .small : .regular)
                    .disabled(!online || model.joining.contains(result.key))
                    .accessibilityLabel("Me too: someone already asked for \(result.title). Join their request.")
                if model.joining.contains(result.key) { ProgressView().controlSize(.small) }
            }
        case .inLibrary(let novelId):
            if let novelId {
                NavigationLink(value: NovelRoute(id: novelId, summary: nil)) { Text(label) }
                    .buttonStyle(.bordered)
                    .controlSize(compact ? .small : .regular)
                    .accessibilityLabel("In library: open \(result.title)")
            } else {
                Button(label) {}.buttonStyle(.bordered).controlSize(compact ? .small : .regular).disabled(true)
            }
        case .requested:
            if compact {
                Text(label).font(.ui(.caption, .semibold)).foregroundStyle(Color.brandAccent).lineLimit(2)
            } else {
                Button(label) {}.buttonStyle(.bordered).disabled(true)
            }
        }
    }
}

/// "In Action" / "Requested · Action": where a card went.
private struct CategoryBadge: View {
    let mark: Categories.Mark

    var body: some View {
        Text(mark.badge)
            .font(.ui(.caption2, .semibold))
            .lineLimit(1)
            .padding(.horizontal, 6)
            .padding(.vertical, 2)
            .background(.thinMaterial, in: RoundedRectangle(cornerRadius: 6, style: .continuous))
    }
}

private extension View {
    /// Hold a card (or the screen reader's actions): "Add to category".
    @ViewBuilder
    func categoryMenu(enabled: Bool, open: @escaping () -> Void) -> some View {
        if enabled {
            contextMenu {
                Button { open() } label: { Label("Add to category", systemImage: "folder.badge.plus") }
            }
            .accessibilityAction(named: "Add to category", open)
        } else {
            self
        }
    }
}

/// A synopsis clamped to a few lines, with More / Less.
struct RequestSynopsis: View {
    let text: String?
    var lines = 3
    @State private var expanded = false

    var body: some View {
        let body = text?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
        if !body.isEmpty {
            VStack(alignment: .leading, spacing: 2) {
                Text(body)
                    .font(.literata(.footnote))
                    .lineLimit(expanded ? nil : lines)
                if body.count > lines * 45 {
                    Button(expanded ? "Less" : "More") { withAnimation { expanded.toggle() } }
                        .font(.ui(.footnote, .semibold))
                        .accessibilityValue(expanded ? "Expanded" : "Collapsed")
                }
            }
        }
    }
}

// MARK: - My requests

private struct RequestsMinePart: View {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel

    var body: some View {
        // A scroll view, not a List: a List turns each row's Open link into a whole-row link.
        ScrollView {
            LazyVStack(alignment: .leading, spacing: 0) {
                if !model.listsLoaded {
                    ProgressView().frame(maxWidth: .infinity).padding(.top, 40)
                } else if model.mine.isEmpty, let failure = model.listsFailure {
                    LoadFailedView(title: "Can't reach your server", message: failure) { await model.reloadLists() }
                } else {
                    // Audiobooks uploaded from this device belong here too (android 1.15); the
                    // audiobooks area provides that list (see the 2.0 hand-off notes).
                    if let label = Requests.pendingLabel(model.mine, limit: model.pendingLimit) {
                        Text(label).font(.ui(.subheadline, .medium)).foregroundStyle(.secondary).padding(.vertical, 6)
                    }
                    if model.mine.isEmpty {
                        ContentUnavailableView("No requests yet", systemImage: "tray",
                                               description: Text("Search for a book, web novel or manga and request it. You'll hear when the owner decides."))
                    }
                    ForEach(model.mine) { request in
                        MineRow(model: model, request: request)
                        Divider().padding(.leading, 70)
                    }
                }
            }
            .frame(maxWidth: 720)
            .padding(.horizontal, 16)
            .frame(maxWidth: .infinity)
        }
        .refreshable { await model.reloadLists() }
    }
}

private struct MineRow: View {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel
    let request: ReaderRequest

    var body: some View {
        let status = Requests.status(request)
        let action = Requests.mineAction(request)
        HStack(alignment: .top, spacing: 14) {
            CoverView(url: request.coverUrl, title: request.title, cornerRadius: 4, targetWidth: 56).frame(width: 56)
            VStack(alignment: .leading, spacing: 4) {
                VStack(alignment: .leading, spacing: 4) {
                    Text(request.title).font(.literata(.headline, .semibold)).lineLimit(2)
                    StatusChip(status: status)
                    let caption = [Requests.mediumLabel(request.medium), Requests.date(request.createdAt).map { "requested \($0)" }]
                        .compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · ")
                    Text(caption).font(.ui(.footnote)).foregroundStyle(.secondary)
                    if Requests.showsWanted(request) {
                        Text(Requests.wantedLine).font(.ui(.footnote)).foregroundStyle(Color.brandAmber)
                    }
                    if request.isFollower {
                        Text("You joined this request").font(.ui(.footnote)).foregroundStyle(.secondary)
                    }
                    if status == .declined, let reason = request.declineReason, !reason.isEmpty {
                        Text("Reason: \(reason)").font(.ui(.footnote))
                    }
                    // A joined request's note is the requester's, not this account's.
                    if let note = request.note, !note.isEmpty, !request.isFollower {
                        Text("Your note: \(note)").font(.ui(.footnote)).italic().foregroundStyle(.secondary).lineLimit(4)
                    }
                }
                .accessibilityElement(children: .combine)
                HStack(spacing: 8) {
                    if let novelId = Requests.openableNovel(request) {
                        NavigationLink(value: NovelRoute(id: novelId, summary: nil)) { Text("Open") }
                            .buttonStyle(.borderedProminent)
                            .accessibilityLabel("Open \(request.title)")
                    }
                    if action != .none {
                        if model.busy.contains(request.id) {
                            ProgressView()
                        } else {
                            let leave = action == .leave
                            Button(leave ? "Leave" : "Cancel", role: .destructive) {
                                if leave { model.confirmLeave = request } else { model.confirmCancel = request }
                            }
                            .buttonStyle(.borderless)
                            .disabled(!app.isReachable)
                            .accessibilityLabel(leave ? "Leave the request for \(request.title)" : "Cancel the request for \(request.title)")
                        }
                    }
                }
            }
        }
        .padding(.vertical, 10)
    }
}

private struct StatusChip: View {
    let status: Requests.Status

    var body: some View {
        Text(status.label)
            .font(.ui(.caption, .semibold))
            .foregroundStyle(status == .pending ? Color.primary : color)
            .padding(.horizontal, 10)
            .padding(.vertical, 3)
            .background(color.opacity(0.14), in: Capsule())
            .accessibilityLabel("Status: \(status.label)")
    }

    private var color: Color {
        switch status {
        case .pending: .brandAmber
        case .approved: .brandAccent
        case .available: .green
        case .declined: .red
        case .unknown: .secondary
        }
    }
}

// MARK: - To review (the owner)

private struct RequestsReviewPart: View {
    @Bindable var model: RequestsModel

    var body: some View {
        List {
            if model.decidesElsewhere {
                // The owner on the public address: pull to refresh once home again.
                ContentUnavailableView("Review at home", systemImage: "checkmark.circle",
                                       description: Text("Approving requests works on your home network."))
                    .listRowBackground(Color.clear)
            } else if model.review.isEmpty, let failure = model.listsFailure {
                LoadFailedView(title: "Can't reach your server", message: failure) { await model.reloadLists() }
                    .listRowBackground(Color.clear)
            } else if model.review.isEmpty {
                ContentUnavailableView("Nothing to review", systemImage: "checkmark.circle",
                                       description: Text("New requests from your readers show up here, oldest first."))
                    .listRowBackground(Color.clear)
            } else {
                ForEach(model.review) { request in
                    ReviewCard(model: model, request: request)
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                }
            }
        }
        .listStyle(.plain)
        .refreshable { await model.reloadLists() }
    }
}

private struct ReviewCard: View {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel
    let request: ReaderRequest

    var body: some View {
        let busy = model.busy.contains(request.id)
        let online = app.isReachable
        let shortlist = Requests.shortlist(request.candidates)
        let needsURL = Requests.needsURL(request)
        let selected = Requests.selectedCandidate(request.candidates, chosen: model.choices[request.id])
        let url = model.urls[request.id] ?? ""
        VStack(alignment: .leading, spacing: 10) {
            HStack(alignment: .top, spacing: 14) {
                CoverView(url: request.coverUrl, title: request.title, targetWidth: 72).frame(width: 72)
                VStack(alignment: .leading, spacing: 3) {
                    let who = request.requestedBy?.name.flatMap { $0.isEmpty ? nil : $0 } ?? "A reader"
                    Text(["\(who) requested", Requests.date(request.createdAt)].compactMap { $0 }.joined(separator: " · "))
                        .font(.ui(.caption, .medium)).foregroundStyle(.secondary)
                    Text(request.title).font(.literata(.headline, .semibold)).lineLimit(3)
                        .accessibilityAddTraits(.isHeader)
                    let byline = [request.author, Requests.mediumLabel(request.medium)]
                        .compactMap { $0 }.filter { !$0.isEmpty }.joined(separator: " · ")
                    Text(byline).font(.ui(.footnote)).foregroundStyle(.secondary)
                    if let also = Requests.alsoWantedLabel(request) {
                        Text(also).font(.ui(.footnote)).foregroundStyle(Color.brandAccent)
                    }
                }
                .accessibilityElement(children: .combine)
            }
            RequestSynopsis(text: request.description)
            if let note = request.note, !note.isEmpty {
                Text("Note: \(note)").font(.ui(.subheadline)).italic()
            }
            if let link = request.link, !link.isEmpty {
                HStack {
                    Text(link).font(.uiMono(.caption)).foregroundStyle(.secondary).textSelection(.enabled).lineLimit(2)
                    Spacer()
                    if let target = URL(string: link), target.scheme?.hasPrefix("http") == true {
                        Link(destination: target) { Label("Open link", systemImage: "arrow.up.right.square") }
                            .font(.ui(.footnote, .semibold))
                    }
                }
            }
            if !shortlist.isEmpty {
                HStack {
                    Text("Add from").font(.ui(.subheadline, .semibold)).accessibilityAddTraits(.isHeader)
                    Spacer()
                    if request.isManga {
                        Button("Refresh ranking") { model.rerank(request) }
                            .font(.ui(.footnote, .semibold))
                            .buttonStyle(.borderless)
                            .disabled(!online || busy)
                    }
                }
                if Requests.rankingPending(request) {
                    HStack(spacing: 8) {
                        ProgressView().controlSize(.mini)
                        Text("Ranking…").font(.ui(.footnote)).foregroundStyle(.secondary)
                    }
                    .accessibilityElement(children: .combine)
                }
                VStack(alignment: .leading, spacing: 0) {
                    ForEach(shortlist, id: \.self) { candidate in
                        CandidateRow(candidate: candidate, selected: candidate.index == selected && !needsURL) {
                            if let index = candidate.index { model.choices[request.id] = index }
                        }
                    }
                }
            }
            if needsURL {
                VStack(alignment: .leading, spacing: 4) {
                    TextField("Series page URL", text: Binding(
                        get: { model.urls[request.id] ?? "" },
                        set: { model.urls[request.id] = $0.trimmingCharacters(in: .whitespaces) }))
                        .keyboardType(.URL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                        .textFieldStyle(.roundedBorder)
                    Text("The series' page on a source Novarr can add from.").font(.ui(.caption)).foregroundStyle(.secondary)
                }
            }
            HStack(spacing: 10) {
                Button("Approve") { model.approve(request) }
                    .buttonStyle(.borderedProminent)
                    .disabled(!online || busy || (needsURL ? url.isEmpty : selected == nil))
                    .accessibilityLabel("Approve \(request.title)")
                Button("Decline") { model.openDecline(request) }
                    .buttonStyle(.bordered)
                    .disabled(!online || busy)
                    .accessibilityLabel("Decline \(request.title)")
                if busy { ProgressView() }
            }
        }
        .padding(14)
        .background(Color.surface, in: RoundedRectangle(cornerRadius: 14, style: .continuous))
        .padding(.vertical, 6)
    }
}

/// One shortlist entry as a radio row; one that failed to check is shown last and can't be chosen.
private struct CandidateRow: View {
    let candidate: RequestCandidate
    let selected: Bool
    let select: () -> Void

    var body: some View {
        let enabled = Requests.selectable(candidate)
        Button(action: select) {
            HStack(alignment: .top, spacing: 10) {
                Image(systemName: selected ? "largecircle.fill.circle" : "circle")
                    .foregroundStyle(selected ? Color.brandAccent : .secondary)
                    .font(.system(size: 20))
                VStack(alignment: .leading, spacing: 2) {
                    Text(candidate.sourceName.isEmpty ? candidate.source : candidate.sourceName)
                        .font(.ui(.body))
                        .foregroundStyle(enabled ? Color.primary : .secondary)
                    if let reason = candidate.reason, !reason.isEmpty {
                        Text(reason).font(.ui(.footnote)).foregroundStyle(Color.primary)
                    }
                    if let error = candidate.error, !error.isEmpty {
                        Text("Couldn't check: \(error)").font(.ui(.footnote)).foregroundStyle(.red)
                    }
                    let details = Requests.candidateDetails(candidate)
                    if !details.isEmpty { Text(details).font(.ui(.footnote)).foregroundStyle(.secondary) }
                }
                Spacer(minLength: 0)
            }
            .padding(.vertical, 6)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .disabled(!enabled)
        .accessibilityAddTraits(selected ? .isSelected : [])
    }
}

// MARK: - Sheets and dialogs

private struct RequestsSheets: ViewModifier {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel

    func body(content: Content) -> some View {
        content
            .sheet(item: $model.requestSheet) { result in
                RequestFormSheet(title: "Request \(result.title)", confirm: "Send request",
                          canConfirm: app.isReachable && !model.sending, sending: model.sending,
                          error: model.sheetError, onConfirm: model.sendRequest) {
                    let sources = Requests.sourcesLabel(result.sources)
                    if !sources.isEmpty { Text(sources).font(.ui(.footnote)).foregroundStyle(.secondary) }
                    RequestNoteField(text: $model.requestNote, label: "Note for the owner (optional)")
                }
            }
            .sheet(isPresented: Binding(get: { model.freeText != nil }, set: { if !$0 { model.freeText = nil } })) {
                FreeTextSheet(model: model)
            }
            .sheet(item: $model.declineSheet) { request in
                RequestFormSheet(title: "Decline \(request.title)", confirm: "Decline",
                          canConfirm: app.isReachable && !model.sending, sending: model.sending,
                          error: model.sheetError, destructive: true, onConfirm: model.decline) {
                    let who = request.requestedBy?.name.flatMap { $0.isEmpty ? nil : $0 } ?? "The reader"
                    Text("\(who) will see the reason.").font(.ui(.footnote)).foregroundStyle(.secondary)
                    RequestNoteField(text: $model.declineReason, label: "Reason (optional)")
                }
            }
            .sheet(isPresented: Binding(get: { model.categorySheet != nil }, set: { if !$0 { model.closeCategorySheet() } })) {
                if let result = model.categorySheet {
                    BrowseCategorySheet(result: result, sending: model.categorySending,
                                        onCancel: model.closeCategorySheet, onConfirm: model.confirmCategories)
                }
            }
            .alert("Cancel this request?", isPresented: present(\.confirmCancel), presenting: model.confirmCancel) { request in
                Button("Cancel request", role: .destructive) { model.cancel(request) }
                Button("Keep", role: .cancel) { model.confirmCancel = nil }
            } message: { request in
                Text("\(request.title) will no longer be waiting for the owner.")
            }
            .alert("Leave this request?", isPresented: present(\.confirmLeave), presenting: model.confirmLeave) { request in
                Button("Leave", role: .destructive) { model.leave(request) }
                Button("Keep", role: .cancel) { model.confirmLeave = nil }
            } message: { request in
                Text("You won't be told when \(request.title) is ready. The request stays for the reader who made it.")
            }
            .alert("Already in the library?", isPresented: present(\.duplicate), presenting: model.duplicate) { prompt in
                Button("Add anyway") { model.approve(prompt.request, force: true) }
                Button("Cancel", role: .cancel) { model.duplicate = nil }
            } message: { prompt in
                Text(prompt.message)
            }
            .alert("Requests", isPresented: present(\.errorDialog), presenting: model.errorDialog) { _ in
                Button("OK", role: .cancel) { model.errorDialog = nil }
            } message: { message in
                Text(message)
            }
    }

    private func present<T>(_ keyPath: ReferenceWritableKeyPath<RequestsModel, T?>) -> Binding<Bool> {
        Binding(get: { model[keyPath: keyPath] != nil }, set: { if !$0 { model[keyPath: keyPath] = nil } })
    }
}

private struct FreeTextSheet: View {
    @Environment(AppModel.self) private var app
    @Bindable var model: RequestsModel

    var body: some View {
        let draft = model.freeText ?? FreeTextDraft()
        RequestFormSheet(title: "Request it anyway", confirm: "Send request",
                  canConfirm: app.isReachable && !model.sending && !draft.title.trimmingCharacters(in: .whitespaces).isEmpty,
                  sending: model.sending, error: model.sheetError, onConfirm: model.sendFreeText) {
            Text("The owner will look for it by hand.").font(.ui(.footnote)).foregroundStyle(.secondary)
            TextField("Title", text: field(\.title, limit: 255))
            Picker("Medium", selection: Binding(get: { draft.medium }, set: { model.freeText?.medium = $0 })) {
                ForEach(model.mediums) { Text($0.title).tag($0) }
            }
            .pickerStyle(.segmented)
            TextField("Link (optional)", text: field(\.link, limit: Requests.linkMax), prompt: Text("https://…"))
                .keyboardType(.URL)
                .textInputAutocapitalization(.never)
                .autocorrectionDisabled()
            RequestNoteField(text: field(\.note, limit: Requests.noteMax), label: "Note for the owner (optional)")
        }
    }

    private func field(_ keyPath: WritableKeyPath<FreeTextDraft, String>, limit: Int) -> Binding<String> {
        Binding(get: { model.freeText?[keyPath: keyPath] ?? "" },
                set: { model.freeText?[keyPath: keyPath] = String($0.prefix(limit)) })
    }
}

/// A short form in a sheet: its fields, the error, Cancel and the confirm button.
struct RequestFormSheet<Content: View>: View {
    let title: String
    let confirm: String
    let canConfirm: Bool
    let sending: Bool
    let error: String?
    var destructive = false
    let onConfirm: () -> Void
    @ViewBuilder let content: Content
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            Form {
                Section { content }
                if let error {
                    Section { Text(error).foregroundStyle(.red) }
                }
            }
            .navigationTitle(title)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button("Cancel") { dismiss() } }
                ToolbarItem(placement: .confirmationAction) {
                    if sending {
                        ProgressView()
                    } else {
                        Button(confirm, role: destructive ? .destructive : nil, action: onConfirm)
                            .disabled(!canConfirm)
                    }
                }
            }
        }
        .presentationDetents([.medium, .large])
    }
}

/// A multi-line note with its count against the server's limit.
struct RequestNoteField: View {
    @Binding var text: String
    let label: String
    var limit = Requests.noteMax

    var body: some View {
        VStack(alignment: .trailing, spacing: 4) {
            TextField(label, text: Binding(get: { text }, set: { text = String($0.prefix(limit)) }), axis: .vertical)
                .lineLimit(3...8)
            Text("\(text.count) / \(limit)").font(.ui(.caption2)).foregroundStyle(.secondary)
        }
    }
}

// MARK: - Toast

private struct NoticeToast: ViewModifier {
    @Binding var message: String?

    func body(content: Content) -> some View {
        content.overlay(alignment: .bottom) {
            if let message {
                Text(message)
                    .font(.ui(.subheadline))
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 16)
                    .padding(.vertical, 10)
                    .background(.regularMaterial, in: Capsule())
                    .padding(.horizontal, 24)
                    .padding(.bottom, 16)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                    .onTapGesture { self.message = nil }
                    .accessibilityAddTraits(.updatesFrequently)
                    .task(id: message) {
                        UIAccessibility.post(notification: .announcement, argument: message)
                        try? await Task.sleep(for: .seconds(3.5))
                        withAnimation { self.message = nil }
                    }
            }
        }
        .animation(.easeOut(duration: 0.2), value: message)
    }
}

extension View {
    /// A one-line message at the bottom that goes away by itself (Android's snackbar).
    func noticeToast(_ message: Binding<String?>) -> some View { modifier(NoticeToast(message: message)) }
}
