package studio.visns.novarr.ui.requests

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.os.SystemClock
import android.widget.Toast
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.OpenInNew
import androidx.compose.material.icons.automirrored.outlined.PlaylistAdd
import androidx.compose.material.icons.outlined.Clear
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.outlined.TaskAlt
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilledTonalButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.PrimaryTabRow
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Tab
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontStyle
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.LifecycleStartEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.NavController
import kotlinx.coroutines.delay
import studio.visns.novarr.api.ReaderRequest
import studio.visns.novarr.api.RequestCandidate
import studio.visns.novarr.api.SearchResult
import studio.visns.novarr.domain.Requests
import studio.visns.novarr.domain.Requests.CardAction
import studio.visns.novarr.domain.Requests.Medium
import studio.visns.novarr.domain.Requests.Section
import studio.visns.novarr.domain.Requests.Status
import studio.visns.novarr.domain.RetryAfter
import studio.visns.novarr.ui.NovelRoute
import studio.visns.novarr.ui.RequestsRoute
import studio.visns.novarr.ui.components.CenteredProgress
import studio.visns.novarr.ui.components.Cover
import studio.visns.novarr.ui.components.EmptyState
import studio.visns.novarr.ui.components.LoadFailed
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.OfflinePill
import studio.visns.novarr.ui.components.isWide
import studio.visns.novarr.ui.components.readableWidth
import studio.visns.novarr.ui.theme.BrandFonts
import studio.visns.novarr.ui.theme.success

/**
 * Reader requests: search every source for a novel or manga and request it,
 * follow your requests, and (the owner, on the home network) review
 * everyone's. Only offered when the server's meta says `requests: true`.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RequestsScreen(nav: NavController, route: RequestsRoute) {
    val app = LocalApp.current
    val model = viewModel { RequestsViewModel(app, Section.from(route.section)) }
    val offered by app.requestsOffered.collectAsStateWithLifecycle()
    val reachable by app.isReachable.collectAsStateWithLifecycle()
    val feedRevision by app.requestFeed.revision.collectAsStateWithLifecycle()
    val snackbar = remember { SnackbarHostState() }

    // Polling (the search, a shortlist being ranked) runs only while this screen is started.
    LifecycleStartEffect(Unit) {
        model.setVisible(true)
        app.requestsScreenVisible(true)
        onStopOrDispose {
            model.setVisible(false)
            app.requestsScreenVisible(false)
        }
    }
    // First load, and again whenever the feed brought news (a decision, a new request).
    LaunchedEffect(feedRevision) { model.loadLists() }
    LaunchedEffect(model.notice) {
        model.notice?.let { snackbar.showSnackbar(it) }
        model.notice = null
    }

    val decidesElsewhere = model.listsLoaded && Requests.decidesElsewhere(app.isAdmin, model.canDecide)
    val sections = buildList {
        add(Section.SEARCH)
        add(Section.MINE)
        if (model.canDecide || decidesElsewhere) add(Section.REVIEW)
    }
    val current = model.section.takeIf { it in sections } ?: if (model.section == Section.REVIEW && !model.listsLoaded) Section.MINE else Section.SEARCH

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Requests") },
                navigationIcon = {
                    if (nav.previousBackStackEntry != null) {
                        IconButton(onClick = { nav.popBackStack() }) { Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Back") }
                    }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbar) },
    ) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (!offered) {
                EmptyState("Requests are off", "This server doesn't take requests right now.", Icons.Outlined.Inbox, Modifier.padding(top = 40.dp))
                return@Column
            }
            PrimaryTabRow(selectedTabIndex = sections.indexOf(current).coerceAtLeast(0)) {
                sections.forEach { section ->
                    Tab(
                        selected = current == section,
                        onClick = { model.section = section },
                        unselectedContentColor = MaterialTheme.colorScheme.onSurfaceVariant,
                        text = { Text(tabTitle(section, model)) },
                    )
                }
            }
            if (!reachable) {
                OfflinePill(Modifier.align(Alignment.CenterHorizontally).padding(top = 10.dp), text = "Offline · requests need a connection")
            }
            when (current) {
                Section.SEARCH -> SearchPart(model, reachable, nav)
                Section.MINE -> MinePart(model, reachable, nav)
                Section.REVIEW -> ReviewPart(model, reachable, decidesElsewhere)
            }
        }
    }

    Sheets(model, reachable)
    Dialogs(model)
}

private fun tabTitle(section: Section, model: RequestsViewModel): String = when (section) {
    Section.SEARCH -> "Search"
    Section.MINE -> "My requests"
    Section.REVIEW -> if (model.review.isEmpty()) "To review" else "To review (${model.review.size})"
}

// Search

@Composable
private fun SearchPart(model: RequestsViewModel, online: Boolean, nav: NavController) {
    val wide = isWide()
    // Counts down Retry-After; re-enables the button when it has passed.
    var now by remember { mutableLongStateOf(SystemClock.elapsedRealtime()) }
    LaunchedEffect(model.blockedUntil) {
        while (model.blockedUntil != null) {
            now = SystemClock.elapsedRealtime()
            if (!model.isBlocked(now)) {
                model.unblock()
                break
            }
            delay(500)
        }
    }
    val wait = RetryAfter.remaining(model.blockedUntil, now)
    val canSearch = model.query.isNotBlank() && !model.searching && wait == 0L
    val focus = LocalFocusManager.current
    fun search() {
        // The keyboard goes so the results can be seen.
        focus.clearFocus()
        model.startSearch()
    }

    LazyColumn(
        Modifier.fillMaxSize(),
        contentPadding = PaddingValues(horizontal = if (wide) 28.dp else 16.dp, vertical = 12.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        item(key = "form") {
            Column(Modifier.readableWidth(), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                OutlinedTextField(
                    value = model.query,
                    onValueChange = { model.query = it.take(Requests.QUERY_MAX) },
                    label = { Text("Title") },
                    placeholder = { Text("A novel or manga to request") },
                    leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null) },
                    trailingIcon = {
                        if (model.query.isNotEmpty()) {
                            IconButton(onClick = { model.query = "" }) { Icon(Icons.Outlined.Clear, contentDescription = "Clear title") }
                        }
                    },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                    keyboardActions = KeyboardActions(onSearch = { if (canSearch) search() }),
                    modifier = Modifier.fillMaxWidth(),
                )
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    MediumSwitch(model.medium, Modifier.weight(1f)) { model.medium = it }
                    Button(
                        onClick = ::search,
                        enabled = canSearch,
                        modifier = Modifier.semantics { if (wait > 0) stateDescription = "Available in $wait seconds" },
                    ) {
                        Text(if (wait > 0) "Wait ${wait}s" else "Search")
                    }
                }
            }
        }

        val search = model.search
        when {
            model.searchError != null -> item(key = "error") {
                Text(
                    model.searchError.orEmpty(),
                    color = MaterialTheme.colorScheme.error,
                    style = MaterialTheme.typography.bodyMedium,
                    modifier = Modifier.readableWidth().semantics { liveRegion = LiveRegionMode.Polite },
                )
            }
            model.searching -> item(key = "running") {
                Column(Modifier.readableWidth().semantics(mergeDescendants = true) { liveRegion = LiveRegionMode.Polite }, verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    LinearProgressIndicator(Modifier.fillMaxWidth())
                    Text(
                        search?.query?.let { "Searching every source for “$it”…" } ?: "Starting the search…",
                        style = MaterialTheme.typography.bodyMedium,
                    )
                    if (model.slow) {
                        Text(
                            "Some sources are slow. A search can take up to a minute and a half.",
                            style = MaterialTheme.typography.bodySmall,
                            color = MaterialTheme.colorScheme.onSurfaceVariant,
                        )
                    }
                }
            }
            search?.isFailed == true -> item(key = "failed") {
                Text(
                    search.message ?: "The search failed.",
                    color = MaterialTheme.colorScheme.error,
                    modifier = Modifier.readableWidth(),
                )
            }
        }

        // A refused new search (such as "Search is busy") keeps the last results under its message.
        if (search != null && search.isDone) {
            Requests.skippedLabel(search.skippedSources.map { it.sourceName.ifBlank { it.source } })?.let { skipped ->
                item(key = "skipped") {
                    Text(skipped, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.readableWidth())
                }
            }
            if (search.results.isEmpty()) {
                item(key = "empty") {
                    Column(Modifier.readableWidth(), horizontalAlignment = Alignment.CenterHorizontally) {
                        EmptyState(
                            "Nothing found for “${search.query}”",
                            "No source has it under that title. You can still ask the owner to find it.",
                            Icons.Outlined.Search,
                        )
                        Button(onClick = model::openFreeText, enabled = online) { Text("Request it anyway") }
                    }
                }
            } else {
                items(search.results, key = { "result-${it.key}" }) { result ->
                    ResultCard(
                        result,
                        action = Requests.cardAction(result, model.canJoin),
                        online = online,
                        joining = result.key in model.joining,
                        modifier = Modifier.readableWidth(),
                        onRequest = { model.openRequestSheet(result) },
                        onMeToo = { model.meToo(result) },
                        onOpen = { id -> nav.navigate(NovelRoute(id)) },
                    )
                }
                item(key = "anyway") {
                    TextButton(onClick = model::openFreeText, enabled = online, modifier = Modifier.readableWidth()) {
                        Text("Not here? Request it anyway")
                    }
                }
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun MediumSwitch(selected: Medium, modifier: Modifier = Modifier, onSelect: (Medium) -> Unit) {
    SingleChoiceSegmentedButtonRow(modifier) {
        Medium.entries.forEachIndexed { index, medium ->
            SegmentedButton(
                selected = selected == medium,
                onClick = { onSelect(medium) },
                shape = SegmentedButtonDefaults.itemShape(index, Medium.entries.size),
            ) { Text(medium.title) }
        }
    }
}

@Composable
private fun ResultCard(
    result: SearchResult,
    action: CardAction,
    online: Boolean,
    joining: Boolean,
    modifier: Modifier,
    onRequest: () -> Unit,
    onMeToo: () -> Unit,
    onOpen: (Int) -> Unit,
) {
    Surface(shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.surfaceContainerLow, modifier = modifier) {
        Row(Modifier.padding(12.dp), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            Cover(result.coverUrl, result.title, Modifier.width(84.dp), corner = 6.dp)
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Column(Modifier.semantics(mergeDescendants = true) {}) {
                    Text(result.title, style = titleStyle(), maxLines = 3, overflow = TextOverflow.Ellipsis)
                    result.author?.takeIf { it.isNotBlank() }?.let {
                        Text(it, style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    Requests.sourcesLabel(result.sources).takeIf { it.isNotEmpty() }?.let {
                        Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    }
                }
                Synopsis(result.description, key = "result-${result.key}")
                Spacer(Modifier.size(2.dp))
                when (action) {
                    CardAction.Request -> Button(onClick = onRequest, enabled = online, modifier = Modifier.semantics { contentDescription = "Request ${result.title}" }) {
                        Icon(Icons.AutoMirrored.Outlined.PlaylistAdd, contentDescription = null, modifier = Modifier.size(18.dp))
                        Text("  Request")
                    }
                    // No note sheet: it joins the existing request straight away.
                    CardAction.MeToo -> Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        FilledTonalButton(
                            onClick = onMeToo,
                            enabled = online && !joining,
                            modifier = Modifier.semantics { contentDescription = "Me too: someone already asked for ${result.title}. Join their request." },
                        ) { Text(Requests.cardActionLabel(action)) }
                        if (joining) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                    }
                    is CardAction.InLibrary -> OutlinedButton(
                        onClick = { action.novelId?.let(onOpen) },
                        enabled = action.novelId != null,
                        modifier = Modifier.semantics { contentDescription = "In library: open ${result.title}" },
                    ) { Text(Requests.cardActionLabel(action)) }
                    is CardAction.Requested -> OutlinedButton(onClick = {}, enabled = false) { Text(Requests.cardActionLabel(action)) }
                }
            }
        }
    }
}

/** A synopsis clamped to a few lines, with More / Less. */
@Composable
private fun Synopsis(text: String?, key: String, lines: Int = 3) {
    val body = text?.trim().orEmpty()
    if (body.isEmpty()) return
    var expanded by rememberSaveable(key) { mutableStateOf(false) }
    var overflows by remember(key) { mutableStateOf(false) }
    Column {
        Text(
            body,
            style = MaterialTheme.typography.bodySmall.copy(fontFamily = BrandFonts.Literata),
            maxLines = if (expanded) Int.MAX_VALUE else lines,
            overflow = TextOverflow.Ellipsis,
            onTextLayout = { if (!expanded) overflows = it.hasVisualOverflow },
        )
        if (overflows || expanded) {
            TextButton(
                onClick = { expanded = !expanded },
                contentPadding = PaddingValues(horizontal = 0.dp),
                modifier = Modifier.heightIn(min = 36.dp).semantics { stateDescription = if (expanded) "Expanded" else "Collapsed" },
            ) { Text(if (expanded) "Less" else "More") }
        }
    }
}

// My requests

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun MinePart(model: RequestsViewModel, online: Boolean, nav: NavController) {
    PullToRefreshBox(model.refreshing, onRefresh = { model.loadLists(pull = true) }, modifier = Modifier.fillMaxSize()) {
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(vertical = 8.dp)) {
            when {
                !model.listsLoaded -> item { CenteredProgress() }
                model.mine.isEmpty() && model.listsFailure != null -> item {
                    LoadFailed("Can't reach your server", model.listsFailure.orEmpty()) { model.loadLists() }
                }
                else -> {
                    Requests.pendingLabel(model.mine, model.pendingLimit)?.let { label ->
                        item(key = "limit") {
                            Text(label, style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.onSurfaceVariant, modifier = Modifier.readableWidth().padding(horizontal = 16.dp, vertical = 6.dp))
                        }
                    }
                    if (model.mine.isEmpty()) {
                        item {
                            EmptyState(
                                "No requests yet",
                                "Search for a novel or manga and request it. You'll hear when the owner decides.",
                                Icons.Outlined.Inbox,
                            )
                        }
                    }
                    items(model.mine, key = { "mine-${it.id}" }) { request ->
                        MineRow(
                            request,
                            busy = request.id in model.busy,
                            online = online,
                            modifier = Modifier.readableWidth(),
                            onOpen = { id -> nav.navigate(NovelRoute(id)) },
                            onCancel = { model.confirmCancel = request },
                            onLeave = { model.confirmLeave = request },
                        )
                        HorizontalDivider(Modifier.readableWidth().padding(horizontal = 16.dp))
                    }
                }
            }
        }
    }
}

@Composable
private fun MineRow(
    request: ReaderRequest,
    busy: Boolean,
    online: Boolean,
    modifier: Modifier,
    onOpen: (Int) -> Unit,
    onCancel: () -> Unit,
    onLeave: () -> Unit,
) {
    val status = Requests.status(request)
    val action = Requests.mineAction(request)
    Row(modifier.padding(horizontal = 16.dp, vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(14.dp)) {
        Cover(request.coverUrl, request.title, Modifier.width(56.dp), corner = 4.dp)
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Column(Modifier.semantics(mergeDescendants = true) {}, verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(request.title, style = titleStyle(), maxLines = 2, overflow = TextOverflow.Ellipsis)
                StatusChip(status)
                val caption = listOfNotNull(
                    Requests.Medium.from(request.medium).singular,
                    Requests.date(request.createdAt)?.let { "requested $it" },
                ).joinToString(" · ")
                Text(caption, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                if (request.isFollower) {
                    Text("You joined this request", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                }
                if (status == Status.DECLINED) {
                    request.declineReason?.takeIf { it.isNotBlank() }?.let {
                        Text("Reason: $it", style = MaterialTheme.typography.bodySmall)
                    }
                }
                // A joined request's note is the requester's, not this account's.
                request.note?.takeIf { it.isNotBlank() && !request.isFollower }?.let {
                    Text("Your note: $it", style = MaterialTheme.typography.bodySmall.copy(fontStyle = FontStyle.Italic), color = MaterialTheme.colorScheme.onSurfaceVariant, maxLines = 4, overflow = TextOverflow.Ellipsis)
                }
            }
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                Requests.openableNovel(request)?.let { id ->
                    Button(onClick = { onOpen(id) }, modifier = Modifier.semantics { contentDescription = "Open ${request.title}" }) { Text("Open") }
                }
                if (action != Requests.MineAction.NONE) {
                    if (busy) {
                        CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
                    } else {
                        val leave = action == Requests.MineAction.LEAVE
                        TextButton(
                            onClick = if (leave) onLeave else onCancel,
                            enabled = online,
                            modifier = Modifier.semantics {
                                contentDescription = if (leave) "Leave the request for ${request.title}" else "Cancel the request for ${request.title}"
                            },
                        ) { Text(if (leave) "Leave" else "Cancel", color = if (online) MaterialTheme.colorScheme.error else Color.Unspecified) }
                    }
                }
            }
        }
    }
}

@Composable
private fun StatusChip(status: Status) {
    val scheme = MaterialTheme.colorScheme
    val color = when (status) {
        Status.PENDING -> scheme.tertiary
        Status.APPROVED -> scheme.primary
        Status.AVAILABLE -> scheme.success
        Status.DECLINED -> scheme.error
        Status.UNKNOWN -> scheme.onSurfaceVariant
    }
    Surface(shape = CircleShape, color = color.copy(alpha = 0.14f)) {
        Text(
            status.label,
            style = MaterialTheme.typography.labelMedium,
            color = if (status == Status.PENDING) scheme.onSurface else color,
            modifier = Modifier
                .padding(horizontal = 10.dp, vertical = 3.dp)
                .semantics { contentDescription = "Status: ${status.label}" },
        )
    }
}

// To review (the owner)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun ReviewPart(model: RequestsViewModel, online: Boolean, decidesElsewhere: Boolean) {
    PullToRefreshBox(model.refreshing, onRefresh = { model.loadLists(pull = true) }, modifier = Modifier.fillMaxSize()) {
        LazyColumn(
            Modifier.fillMaxSize(),
            contentPadding = PaddingValues(horizontal = 16.dp, vertical = 12.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
        ) {
            when {
                // The owner on the public address: pull to refresh once home again.
                decidesElsewhere -> item {
                    EmptyState("Review at home", "Approving requests works on your home network.", Icons.Outlined.TaskAlt, Modifier.padding(top = 12.dp))
                }
                model.review.isEmpty() && model.listsFailure != null -> item {
                    LoadFailed("Can't reach your server", model.listsFailure.orEmpty()) { model.loadLists() }
                }
                model.review.isEmpty() -> item {
                    EmptyState("Nothing to review", "New requests from your readers show up here, oldest first.", Icons.Outlined.TaskAlt)
                }
                else -> items(model.review, key = { "review-${it.id}" }) { request ->
                    ReviewCard(request, model, online, Modifier.readableWidth())
                }
            }
        }
    }
}

@Composable
private fun ReviewCard(request: ReaderRequest, model: RequestsViewModel, online: Boolean, modifier: Modifier) {
    val context = LocalContext.current
    val busy = request.id in model.busy
    val shortlist = Requests.shortlist(request.candidates)
    val needsUrl = Requests.needsUrl(request)
    val selected = Requests.selectedCandidate(request.candidates, model.choices[request.id])
    val url = model.urls[request.id].orEmpty()

    Surface(shape = RoundedCornerShape(14.dp), color = MaterialTheme.colorScheme.surfaceContainerLow, modifier = modifier) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Row(horizontalArrangement = Arrangement.spacedBy(14.dp)) {
                Cover(request.coverUrl, request.title, Modifier.width(72.dp), corner = 6.dp)
                Column(Modifier.weight(1f).semantics(mergeDescendants = true) {}, verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    val who = request.requestedBy?.name?.takeIf { it.isNotBlank() } ?: "A reader"
                    Text(
                        listOfNotNull("$who requested", Requests.date(request.createdAt)).joinToString(" · "),
                        style = MaterialTheme.typography.labelMedium,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                    Text(request.title, style = titleStyle(), maxLines = 3, overflow = TextOverflow.Ellipsis, modifier = Modifier.semantics { heading() })
                    val byline = listOfNotNull(request.author?.takeIf { it.isNotBlank() }, Medium.from(request.medium).singular).joinToString(" · ")
                    Text(byline, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    Requests.alsoWantedLabel(request)?.let {
                        Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.primary)
                    }
                }
            }
            Synopsis(request.description, key = "review-${request.id}")
            request.note?.takeIf { it.isNotBlank() }?.let {
                Text("Note: $it", style = MaterialTheme.typography.bodyMedium.copy(fontStyle = FontStyle.Italic))
            }
            request.link?.takeIf { it.isNotBlank() }?.let { link ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    SelectionContainer(Modifier.weight(1f)) {
                        Text(link, style = MaterialTheme.typography.bodySmall.copy(fontFamily = BrandFonts.GeistMono), color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                    TextButton(onClick = {
                        try {
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(link)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                        } catch (_: ActivityNotFoundException) {
                            Toast.makeText(context, "No app can open this link.", Toast.LENGTH_SHORT).show()
                        }
                    }) {
                        Icon(Icons.AutoMirrored.Outlined.OpenInNew, contentDescription = null, modifier = Modifier.size(16.dp))
                        Text(" Open link")
                    }
                }
            }

            if (shortlist.isNotEmpty()) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text("Add from", style = MaterialTheme.typography.titleSmall, modifier = Modifier.weight(1f).semantics { heading() })
                    if (request.isManga) {
                        TextButton(onClick = { model.rerank(request) }, enabled = online && !busy) { Text("Refresh ranking") }
                    }
                }
                if (Requests.rankingPending(request)) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.semantics(mergeDescendants = true) {}) {
                        CircularProgressIndicator(Modifier.size(14.dp), strokeWidth = 2.dp)
                        Text("Ranking…", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
                    }
                }
                Column(Modifier.selectableGroup()) {
                    shortlist.forEach { candidate ->
                        CandidateRow(candidate, selected = candidate.index == selected && !needsUrl) {
                            candidate.index?.let { model.choices[request.id] = it }
                        }
                    }
                }
            }
            if (needsUrl) {
                OutlinedTextField(
                    value = url,
                    onValueChange = { model.urls[request.id] = it.trim() },
                    label = { Text("Series page URL") },
                    supportingText = { Text("The series' page on a source Novarr can add from.") },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, imeAction = ImeAction.Done),
                    modifier = Modifier.fillMaxWidth(),
                )
            }

            Row(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalAlignment = Alignment.CenterVertically) {
                Button(
                    onClick = { model.approve(request) },
                    enabled = online && !busy && (if (needsUrl) url.isNotBlank() else selected != null),
                    modifier = Modifier.semantics { contentDescription = "Approve ${request.title}" },
                ) { Text("Approve") }
                OutlinedButton(
                    onClick = { model.openDecline(request) },
                    enabled = online && !busy,
                    modifier = Modifier.semantics { contentDescription = "Decline ${request.title}" },
                ) { Text("Decline") }
                if (busy) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp)
            }
        }
    }
}

/** One shortlist entry as a radio row; a candidate that failed to check is shown last and can't be chosen. */
@Composable
private fun CandidateRow(candidate: RequestCandidate, selected: Boolean, onSelect: () -> Unit) {
    val enabled = Requests.selectable(candidate)
    val muted = MaterialTheme.colorScheme.onSurfaceVariant
    Row(
        Modifier
            .fillMaxWidth()
            .selectable(selected = selected, enabled = enabled, role = Role.RadioButton, onClick = onSelect)
            .padding(vertical = 6.dp),
        verticalAlignment = Alignment.Top,
    ) {
        RadioButton(selected = selected, onClick = null, enabled = enabled)
        Column(Modifier.padding(start = 8.dp).weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
            Text(
                candidate.sourceName.ifBlank { candidate.source },
                style = MaterialTheme.typography.bodyLarge,
                color = if (enabled) Color.Unspecified else muted,
            )
            candidate.reason?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
            candidate.error?.takeIf { it.isNotBlank() }?.let { Text("Couldn't check: $it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error) }
            Requests.candidateDetails(candidate).takeIf { it.isNotEmpty() }?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = muted) }
        }
    }
}

// Sheets and dialogs

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun Sheets(model: RequestsViewModel, online: Boolean) {
    model.requestSheet?.let { result ->
        FormSheet(
            title = "Request ${result.title}",
            confirm = "Send request",
            canConfirm = online && !model.sending,
            sending = model.sending,
            error = model.sheetError,
            onConfirm = model::sendRequest,
            onDismiss = { model.requestSheet = null },
        ) {
            Requests.sourcesLabel(result.sources).takeIf { it.isNotEmpty() }?.let {
                Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            }
            NoteField(model.requestNote, "Note for the owner (optional)") { model.requestNote = it }
        }
    }

    model.freeText?.let { draft ->
        FormSheet(
            title = "Request it anyway",
            confirm = "Send request",
            canConfirm = online && !model.sending && draft.title.isNotBlank(),
            sending = model.sending,
            error = model.sheetError,
            onConfirm = model::sendFreeText,
            onDismiss = { model.freeText = null },
        ) {
            Text(
                "The owner will look for it by hand.",
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            OutlinedTextField(
                value = draft.title,
                onValueChange = { model.freeText = draft.copy(title = it.take(255)) },
                label = { Text("Title") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
            )
            MediumSwitch(draft.medium, Modifier.fillMaxWidth()) { model.freeText = draft.copy(medium = it) }
            OutlinedTextField(
                value = draft.link,
                onValueChange = { model.freeText = draft.copy(link = it.trim().take(2048)) },
                label = { Text("Link (optional)") },
                placeholder = { Text("https://…") },
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri),
                modifier = Modifier.fillMaxWidth(),
            )
            NoteField(draft.note, "Note for the owner (optional)") { model.freeText = draft.copy(note = it) }
        }
    }

    model.declineSheet?.let { request ->
        FormSheet(
            title = "Decline ${request.title}",
            confirm = "Decline",
            canConfirm = online && !model.sending,
            sending = model.sending,
            error = model.sheetError,
            destructive = true,
            onConfirm = model::decline,
            onDismiss = { model.declineSheet = null },
        ) {
            val who = request.requestedBy?.name?.takeIf { it.isNotBlank() } ?: "The reader"
            Text("$who will see the reason.", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
            NoteField(model.declineReason, "Reason (optional)") { model.declineReason = it }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun FormSheet(
    title: String,
    confirm: String,
    canConfirm: Boolean,
    sending: Boolean,
    error: String?,
    destructive: Boolean = false,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
    content: @Composable () -> Unit,
) {
    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)) {
        Column(
            Modifier
                .readableWidth(640.dp)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 20.dp)
                .padding(bottom = 16.dp)
                .navigationBarsPadding()
                .imePadding(),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(title, style = titleStyle(), modifier = Modifier.semantics { heading() })
            content()
            error?.let {
                Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite })
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
                if (sending) CircularProgressIndicator(Modifier.size(20.dp).padding(end = 4.dp), strokeWidth = 2.dp)
                TextButton(onClick = onDismiss) { Text("Cancel") }
                Button(
                    onClick = onConfirm,
                    enabled = canConfirm,
                    colors = if (destructive) androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = MaterialTheme.colorScheme.error, contentColor = MaterialTheme.colorScheme.onError) else androidx.compose.material3.ButtonDefaults.buttonColors(),
                ) { Text(confirm) }
            }
        }
    }
}

@Composable
private fun NoteField(value: String, label: String, onChange: (String) -> Unit) {
    OutlinedTextField(
        value = value,
        onValueChange = { onChange(it.take(Requests.NOTE_MAX)) },
        label = { Text(label) },
        supportingText = { Text("${value.length} / ${Requests.NOTE_MAX}") },
        minLines = 3,
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun Dialogs(model: RequestsViewModel) {
    model.confirmCancel?.let { request ->
        AlertDialog(
            onDismissRequest = { model.confirmCancel = null },
            title = { Text("Cancel this request?") },
            text = { Text("${request.title} will no longer be waiting for the owner.") },
            confirmButton = { TextButton(onClick = { model.cancel(request) }) { Text("Cancel request", color = MaterialTheme.colorScheme.error) } },
            dismissButton = { TextButton(onClick = { model.confirmCancel = null }) { Text("Keep") } },
        )
    }
    model.confirmLeave?.let { request ->
        AlertDialog(
            onDismissRequest = { model.confirmLeave = null },
            title = { Text("Leave this request?") },
            text = { Text("You won't be told when ${request.title} is ready. The request stays for the reader who made it.") },
            confirmButton = { TextButton(onClick = { model.leave(request) }) { Text("Leave", color = MaterialTheme.colorScheme.error) } },
            dismissButton = { TextButton(onClick = { model.confirmLeave = null }) { Text("Keep") } },
        )
    }
    model.duplicate?.let { prompt ->
        AlertDialog(
            onDismissRequest = { model.duplicate = null },
            title = { Text("Already in the library?") },
            text = { Text(prompt.message) },
            confirmButton = { TextButton(onClick = { model.approve(prompt.request, force = true) }) { Text("Add anyway") } },
            dismissButton = { TextButton(onClick = { model.duplicate = null }) { Text("Cancel") } },
        )
    }
    model.errorDialog?.let { message ->
        AlertDialog(
            onDismissRequest = { model.errorDialog = null },
            title = { Text("Requests") },
            text = { Text(message) },
            confirmButton = { TextButton(onClick = { model.errorDialog = null }) { Text("OK") } },
        )
    }
}

/** Series titles in Literata, as elsewhere. */
@Composable
private fun titleStyle() = MaterialTheme.typography.titleMedium.copy(fontFamily = BrandFonts.LiterataDisplay)
