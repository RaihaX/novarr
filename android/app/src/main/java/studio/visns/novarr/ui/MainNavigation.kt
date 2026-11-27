package studio.visns.novarr.ui

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.EnterTransition
import androidx.compose.animation.ExitTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandIn
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkOut
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.consumeWindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.MenuBook
import androidx.compose.material.icons.outlined.CollectionsBookmark
import androidx.compose.material.icons.outlined.Headphones
import androidx.compose.material.icons.outlined.Settings
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationRail
import androidx.compose.material3.NavigationRailItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.SideEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.navigation.NavDestination.Companion.hasRoute
import androidx.navigation.NavDestination.Companion.hierarchy
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.NavHostController
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.toRoute
import kotlinx.serialization.Serializable
import studio.visns.novarr.ui.audiobooks.AudiobooksScreen
import studio.visns.novarr.ui.audiobooks.MiniPlayer
import studio.visns.novarr.ui.audiobooks.PlayerScreen
import studio.visns.novarr.ui.components.LocalApp
import studio.visns.novarr.ui.components.isWide
import studio.visns.novarr.ui.connect.ConnectScreen
import studio.visns.novarr.ui.highlights.HighlightsScreen
import studio.visns.novarr.ui.home.HomeScreen
import studio.visns.novarr.ui.library.LibraryScreen
import studio.visns.novarr.ui.lists.WantToReadScreen
import studio.visns.novarr.ui.novel.NovelScreen
import studio.visns.novarr.ui.reader.MangaReaderScreen
import studio.visns.novarr.ui.reader.ReaderSettingsScreen
import studio.visns.novarr.ui.reader.TextReaderScreen
import studio.visns.novarr.ui.components.RequestsIcon
import studio.visns.novarr.ui.requests.RequestsScreen
import studio.visns.novarr.ui.settings.DownloadsScreen
import studio.visns.novarr.ui.settings.SettingsScreen
import studio.visns.novarr.ui.theme.LocalEink

// Type-safe routes.
@Serializable data object HomeRoute
@Serializable data object LibraryRoute
@Serializable data object SettingsRoute
@Serializable data class NovelRoute(val id: Int)

/**
 * A chapter to read. [offerLatest]: opened by choosing a chapter (continue,
 * resume, chapter list), so check whether another device read this series
 * more recently and offer to go there. Off for highlights.
 * [lookupExcerpt]: opened at a highlight (its excerpt): the reader starts at
 * that passage instead of the saved position, and treats the chapter as a
 * look-up, sending no reading position for it (BaseReaderViewModel).
 * [chapterId] stays the chapter the reader was opened on; the one being read
 * is in the reader's saved state.
 */
@Serializable data class ReaderRoute(
    val novelId: Int,
    val chapterId: Int,
    val manga: Boolean = false,
    val offerLatest: Boolean = false,
    val lookupExcerpt: String = "",
)

/** All highlights, or one series' when [novelId] > 0. */
@Serializable data class HighlightsRoute(val novelId: Int = 0, val novelName: String = "")
@Serializable data object DownloadsRoute

/** Reader requests, opened on [section] (Requests.Section's name: SEARCH, MINE, REVIEW). */
@Serializable data class RequestsRoute(val section: String = "SEARCH")
@Serializable data object ReaderSettingsRoute
@Serializable data object ChangeServerRoute

/** The reader's whole "Want to read" (Home's shelf "See all"). */
@Serializable data object WantToReadRoute

/** Every audiobook (a tab when the server has them). */
@Serializable data object AudiobooksRoute

/** The full-screen audiobook player, opening audiobook [id] if another is loaded. */
@Serializable data class PlayerRoute(val id: Int)

private data class Tab(val route: Any, val label: String, val icon: ImageVector)

private val homeTab = Tab(HomeRoute, "Home", Icons.AutoMirrored.Outlined.MenuBook)
private val libraryTab = Tab(LibraryRoute, "Library", Icons.Outlined.CollectionsBookmark)
private val audiobooksTab = Tab(AudiobooksRoute, "Listen", Icons.Outlined.Headphones)
private val settingsTab = Tab(SettingsRoute, "Settings", Icons.Outlined.Settings)

/** The tabs; "Listen" only on servers with audiobooks (meta `audiobooks`). */
private fun tabsFor(audiobooks: Boolean): List<Tab> =
    if (audiobooks) listOf(homeTab, libraryTab, audiobooksTab, settingsTab) else listOf(homeTab, libraryTab, settingsTab)

/** Navigation for a signed-in (or sign-in-off) session. No Discover: this app only reads. */
@Composable
fun MainNavigation() {
    val nav = rememberNavController()
    val entry by nav.currentBackStackEntryAsState()
    val destination = entry?.destination
    // The reader and the player are full-screen: no tab chrome.
    val showTabs = destination?.hasRoute(ReaderRoute::class) != true && destination?.hasRoute(PlayerRoute::class) != true
    val app = LocalApp.current
    val audiobooksOffered by app.audiobooksOffered.collectAsStateWithLifecycle()
    val tabs = tabsFor(audiobooksOffered)
    val nowPlaying by app.listening.nowPlaying.collectAsStateWithLifecycle()
    val wide = isWide()
    val eink = LocalEink.current

    // Pushed screens (a series, highlights) keep the tab they were opened from lit.
    var lastTab by rememberSaveable { mutableIntStateOf(0) }

    fun select(tab: Tab) {
        // The tab's saved stack may come back with a pushed screen on top (a series opened from the
        // Library): that screen belongs to this tab, not to the one being left.
        lastTab = tabs.indexOf(tab)
        nav.navigate(tab.route) {
            popUpTo(nav.graph.findStartDestination().id) { saveState = true }
            launchSingleTop = true
            restoreState = true
        }
    }

    // The playback notification: the full-screen player, over whatever was on screen.
    val openPlayer by app.openPlayerRequest.collectAsStateWithLifecycle()
    LaunchedEffect(openPlayer, nowPlaying?.id) {
        if (!openPlayer) return@LaunchedEffect
        val id = nowPlaying?.id ?: return@LaunchedEffect
        if (destination?.hasRoute(PlayerRoute::class) != true) nav.navigate(PlayerRoute(id)) { launchSingleTop = true }
        app.playerOpened()
    }

    // A tapped notification: Home, from wherever the app was (Home's banner offers the update).
    val openHome by app.openHomeRequest.collectAsStateWithLifecycle()
    LaunchedEffect(openHome) {
        if (!openHome) return@LaunchedEffect
        nav.navigate(HomeRoute) {
            popUpTo(nav.graph.findStartDestination().id)
            launchSingleTop = true
        }
        app.homeOpened()
    }

    // A tapped request notification: the Requests screen on its part (over Home), and the series for an available one.
    val openRequests by app.openRequestsRequest.collectAsStateWithLifecycle()
    LaunchedEffect(openRequests) {
        val target = openRequests ?: return@LaunchedEffect
        nav.navigate(RequestsRoute(target.section.name)) {
            popUpTo(nav.graph.findStartDestination().id)
        }
        target.novelId?.let { nav.navigate(NovelRoute(it)) }
        app.requestsOpened()
    }

    // A tapped new-chapters notification for one series: that series, over Home.
    val openSeries by app.openSeriesRequest.collectAsStateWithLifecycle()
    LaunchedEffect(openSeries) {
        val novelId = openSeries ?: return@LaunchedEffect
        nav.navigate(HomeRoute) {
            popUpTo(nav.graph.findStartDestination().id)
            launchSingleTop = true
        }
        lastTab = 0
        nav.navigate(NovelRoute(novelId))
        app.seriesOpened()
    }

    // Requests: in the rail on tablets (only when the server offers them); on phones Home's top bar has it.
    val requestsOffered by app.requestsOffered.collectAsStateWithLifecycle()
    val unseenRequests by app.requestsUnseen.collectAsStateWithLifecycle(0)
    val onRequests = destination?.hasRoute(RequestsRoute::class) == true

    val current = tabs.indexOfFirst { tab -> destination?.hierarchy?.any { it.hasRoute(tab.route::class) } == true }
    val selected = if (current >= 0) current else lastTab
    SideEffect { lastTab = selected }
    // In the rail, Requests is lit itself instead of the tab it was opened from.
    fun isSelected(tab: Tab) = tabs.indexOf(tab) == selected && !(wide && onRequests)

    Scaffold(
        contentWindowInsets = WindowInsets(0),
        bottomBar = {
            // An audiobook loaded: the mini-player sits above the tabs (on tablets, at the bottom of the content).
            Column {
                val playing = nowPlaying
                if (playing != null && showTabs) {
                    MiniPlayer(
                        playing,
                        onOpen = { nav.navigate(PlayerRoute(playing.id)) { launchSingleTop = true } },
                        // Without the tab bar below it (tablets), it keeps clear of the system navigation itself.
                        modifier = if (wide) Modifier.navigationBarsPadding() else Modifier,
                    )
                }
                if (!wide) {
                    AnimatedVisibility(
                        showTabs,
                        // AnimatedVisibility's defaults, or none on e-ink.
                        enter = if (eink) EnterTransition.None else fadeIn() + expandIn(),
                        exit = if (eink) ExitTransition.None else shrinkOut() + fadeOut(),
                    ) {
                        NavigationBar {
                            tabs.forEach { tab ->
                                NavigationBarItem(
                                    selected = isSelected(tab),
                                    onClick = { select(tab) },
                                    icon = { Icon(tab.icon, contentDescription = null) },
                                    label = { Text(tab.label) },
                                )
                            }
                        }
                    }
                }
            }
        },
    ) { padding ->
        Row(Modifier.fillMaxSize().padding(padding).consumeWindowInsets(padding)) {
            // Tablets and landscape: a rail, so content keeps its height and lines stay short.
            if (wide && showTabs) {
                NavigationRail {
                    tabs.forEach { tab ->
                        NavigationRailItem(
                            selected = isSelected(tab),
                            onClick = { select(tab) },
                            icon = { Icon(tab.icon, contentDescription = null) },
                            label = { Text(tab.label) },
                        )
                    }
                    if (requestsOffered) {
                        NavigationRailItem(
                            selected = onRequests,
                            onClick = {
                                nav.navigate(RequestsRoute()) {
                                    popUpTo(nav.graph.findStartDestination().id)
                                    launchSingleTop = true
                                }
                            },
                            icon = { RequestsIcon(unseenRequests) },
                            label = { Text("Requests") },
                        )
                    }
                }
            }
            NovarrNavHost(nav)
        }
    }
}

@Composable
private fun NovarrNavHost(nav: NavHostController) {
    val eink = LocalEink.current
    // NavHost's default cross-fade, or an instant switch on e-ink.
    NavHost(
        nav,
        startDestination = HomeRoute,
        enterTransition = { if (eink) EnterTransition.None else fadeIn(animationSpec = tween(700)) },
        exitTransition = { if (eink) ExitTransition.None else fadeOut(animationSpec = tween(700)) },
        popEnterTransition = { if (eink) EnterTransition.None else fadeIn(animationSpec = tween(700)) },
        popExitTransition = { if (eink) ExitTransition.None else fadeOut(animationSpec = tween(700)) },
    ) {
        composable<HomeRoute> { HomeScreen(nav) }
        composable<LibraryRoute> { LibraryScreen(nav) }
        composable<SettingsRoute> { SettingsScreen(nav) }
        composable<NovelRoute> { NovelScreen(nav, it.toRoute<NovelRoute>().id) }
        composable<ReaderRoute> {
            val route = it.toRoute<ReaderRoute>()
            if (route.manga) MangaReaderScreen(nav, route) else TextReaderScreen(nav, route)
        }
        composable<HighlightsRoute> { HighlightsScreen(nav, it.toRoute()) }
        composable<DownloadsRoute> { DownloadsScreen(nav) }
        composable<WantToReadRoute> { WantToReadScreen(nav) }
        composable<AudiobooksRoute> { AudiobooksScreen(nav) }
        composable<PlayerRoute> { PlayerScreen(nav, it.toRoute<PlayerRoute>().id) }
        composable<RequestsRoute> { RequestsScreen(nav, it.toRoute()) }
        composable<ReaderSettingsRoute> { ReaderSettingsScreen(nav) }
        composable<ChangeServerRoute> { ConnectScreen(initialSetup = false, onDone = { nav.popBackStack() }) }
    }
}
