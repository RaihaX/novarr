{{--
    Phone tab bar (<768px; hidden above by CSS) + its "More" bottom sheet.
    Rendered by layouts/app.blade.php on every page except the chromeless
    reader. The sheet uses window.Novarr.openSheet (resources/js/modal.js):
    focus trap, inert page, Esc / backdrop to close, focus back to "More".
    Readers (sign-in on, not the admin) get no Discover tab and no Manage or System
    links; with sign-in on the sheet also has Account and Sign out.
--}}
@php
    $tabAdmin = Gate::allows('admin');
    $tabHome = request()->routeIs('home', 'continue');
    $tabDiscover = request()->routeIs('novels.discover*');
    $tabLibrary = request()->routeIs('novels.*') && !$tabDiscover && !request()->routeIs('novels.create');
    $tabSearch = request()->routeIs('search.*');
    // The desktop nav's Library group (partials/navbar-menu); Novels, Manga
    // and Books are the Library tab's own switch.
    $moreLinks = [
        ['route' => 'audiobooks.index', 'match' => ['audiobooks.index', 'audiobooks.show'], 'label' => 'Audiobooks', 'icon' => 'headphones'],
        ['route' => 'want_to_read', 'match' => 'want_to_read', 'label' => 'Want to read', 'icon' => 'bookmark'],
        ['route' => 'favourites', 'match' => 'favourites', 'label' => 'Favourites', 'icon' => 'heart'],
        ['route' => 'library', 'match' => 'library', 'label' => 'Offline', 'icon' => 'wifi-off', 'meta' => 'Saved on this device'],
        ['route' => 'bookmarks.index', 'match' => 'bookmarks.*', 'label' => 'Highlights', 'icon' => 'highlighter'],
        ['route' => 'stats.index', 'match' => 'stats.*', 'label' => 'Stats', 'icon' => 'chart-column'],
    ];
    // Reader requests (feature on): for everyone; the admin at home sees the pending count.
    if ($requestsOn ?? false) {
        $moreLinks[] = ['route' => 'requests.index', 'match' => 'requests.index', 'label' => 'Requests', 'icon' => 'inbox',
            'meta' => !empty($requestsPending) ? $requestsPending . ' pending' : null, 'badge' => !empty($requestsPending)];
    }
    // The Android app, for everyone, once an APK is published (with sign-in
    // off there is no Account page to find it on). A file, not a page.
    if (\App\Support\AndroidApp::available()) {
        $androidVersion = \App\Support\AndroidApp::version();
        $moreLinks[] = ['route' => 'app.android', 'match' => 'app.android', 'label' => 'Apps', 'icon' => 'download',
            'meta' => 'Android' . ($androidVersion !== null ? ' ' . Str::before($androidVersion, ',') : ''),
            'href' => \App\Support\AndroidApp::url(request()), 'download' => true];
    }
    // Readers send the owner a book (the owner's own upload is under Manage).
    if (!$tabAdmin) {
        $moreLinks[] = ['route' => 'books.upload', 'match' => 'books.upload*', 'label' => 'Upload a book', 'icon' => 'plus'];
    }
    if (!empty($navUser)) {
        $moreLinks[] = ['route' => 'account', 'match' => 'account', 'label' => 'Account', 'icon' => 'user', 'meta' => $navUser->username];
    }
    $manageLinks = $tabAdmin ? [
        ['route' => 'downloads.index', 'match' => ['downloads.*', 'books.review*'], 'label' => 'Downloads', 'icon' => 'download',
            'meta' => !empty($downloadsAttention) ? $downloadsAttention . ' need you' : null],
        ['route' => 'audiobooks.manage', 'match' => 'audiobooks.manage*', 'label' => 'Audiobook inbox', 'icon' => 'headphones'],
        ['route' => 'books.calibre', 'match' => 'books.calibre*', 'label' => 'Calibre import', 'icon' => 'library'],
        ['route' => 'books.upload', 'match' => 'books.upload*', 'label' => 'Upload a book', 'icon' => 'file-text'],
        ['route' => 'novels.create', 'match' => 'novels.create', 'label' => 'Add a series', 'icon' => 'plus'],
    ] : [];
    $systemLinks = $tabAdmin ? [
        ['route' => 'health.index', 'match' => 'health.index', 'label' => 'Health', 'icon' => 'heart-pulse'],
        ['route' => 'health.storage', 'match' => 'health.storage', 'label' => 'Storage', 'icon' => 'layout-grid'],
        ['route' => 'health.sources', 'match' => 'health.sources', 'label' => 'Sources', 'icon' => 'refresh-cw'],
        ['route' => 'activity.index', 'match' => 'activity.*', 'label' => 'Activity', 'icon' => 'activity'],
        ['route' => 'readers.index', 'match' => 'readers.*', 'label' => 'Readers', 'icon' => 'users'],
        ['route' => 'commands.index', 'match' => 'commands.*', 'label' => 'Commands', 'icon' => 'terminal'],
        ['route' => 'logs.index', 'match' => 'logs.*', 'label' => 'Logs', 'icon' => 'scroll-text'],
        ['route' => 'settings.index', 'match' => 'settings.*', 'label' => 'Settings', 'icon' => 'settings'],
    ] : [];
    $tabMore = collect($moreLinks)->merge($manageLinks)->merge($systemLinks)->contains(fn($l) => request()->routeIs($l['match']));
@endphp
<nav class="tabbar" aria-label="Primary">
    <a href="{{ route('home') }}" class="tabbar-item {{ $tabHome ? 'is-active' : '' }}" @if($tabHome) aria-current="page" @endif>
        <x-icon name="house" :size="22" />
        <span>Home</span>
    </a>
    <a href="{{ route('novels.index') }}" class="tabbar-item {{ $tabLibrary ? 'is-active' : '' }}" @if($tabLibrary) aria-current="page" @endif>
        <x-icon name="library" :size="22" />
        <span>Library</span>
    </a>
    @can('admin')
    <a href="{{ route('novels.discover') }}" class="tabbar-item {{ $tabDiscover ? 'is-active' : '' }}" @if($tabDiscover) aria-current="page" @endif>
        <x-icon name="compass" :size="22" />
        <span>Discover</span>
    </a>
    @endcan
    {{-- Opens the command palette; plain link to /search without JS. --}}
    <a href="{{ route('search.index') }}" class="tabbar-item {{ $tabSearch ? 'is-active' : '' }}" data-palette-open aria-haspopup="dialog" @if($tabSearch) aria-current="page" @endif>
        <x-icon name="search" :size="22" />
        <span>Search</span>
    </a>
    <button type="button" class="tabbar-item {{ $tabMore ? 'is-active' : '' }}" data-sheet-open="#moreSheet" aria-haspopup="dialog" aria-controls="moreSheet" aria-expanded="false">
        <x-icon name="more-horizontal" :size="22" />
        <span>More</span>
    </button>
</nav>

<div class="nv-sheet" id="moreSheet" role="dialog" aria-modal="true" aria-labelledby="moreSheetTitle" hidden>
    <button type="button" class="nv-sheet-backdrop" data-sheet-close tabindex="-1" aria-label="Close"></button>
    <div class="nv-sheet-panel">
        <div class="nv-sheet-handle" aria-hidden="true"></div>
        <h2 class="nv-sheet-title" id="moreSheetTitle">More</h2>
        <ul class="nv-sheet-list">
            @foreach($moreLinks as $l)
                @php $on = request()->routeIs($l['match']); @endphp
                <li>
                    <a href="{{ $l['href'] ?? route($l['route']) }}" class="nv-sheet-item {{ $on ? 'is-active' : '' }}" data-sheet-close @if($on) aria-current="page" @endif @if(!empty($l['download'])) data-turbo="false" download title="{{ \App\Support\AndroidApp::linkText() }}" @endif>
                        <x-icon :name="$l['icon']" :size="20" />
                        <span>{{ $l['label'] }}</span>
                        @isset($l['meta'])<span class="nv-sheet-item-meta" @if(!empty($l['badge'])) data-requests-badge @endif>{{ $l['meta'] }}</span>@endisset
                    </a>
                </li>
            @endforeach
        </ul>
        @can('admin')
        @foreach(['Manage' => $manageLinks, 'System' => $systemLinks] as $sectionTitle => $sectionLinks)
        <div class="nv-sheet-sep" role="presentation"></div>
        <h3 class="nv-sheet-title" id="moreSheet{{ $sectionTitle }}">{{ $sectionTitle }}</h3>
        <ul class="nv-sheet-list" aria-labelledby="moreSheet{{ $sectionTitle }}">
            @foreach($sectionLinks as $l)
                @php $on = request()->routeIs($l['match']); @endphp
                <li>
                    <a href="{{ route($l['route']) }}" class="nv-sheet-item {{ $on ? 'is-active' : '' }}" data-sheet-close @if($on) aria-current="page" @endif>
                        <x-icon :name="$l['icon']" :size="20" />
                        <span>{{ $l['label'] }}</span>
                        @isset($l['meta'])<span class="nv-sheet-item-meta">{{ $l['meta'] }}</span>@endisset
                    </a>
                </li>
            @endforeach
        </ul>
        @endforeach
        @endcan
        <div class="nv-sheet-sep" role="presentation"></div>
        <ul class="nv-sheet-list">
            <li>
                {{-- Cycles system → light → dark (resources/js/theme.js). --}}
                <button type="button" class="nv-sheet-item theme-toggle" data-theme-toggle aria-label="Theme: System. Switch to Light">
                    <x-icon name="sun-moon" :size="20" class="theme-icon theme-icon-system" />
                    <x-icon name="sun" :size="20" class="theme-icon theme-icon-light" />
                    <x-icon name="moon" :size="20" class="theme-icon theme-icon-dark" />
                    <span>Theme</span>
                    <span class="nv-sheet-item-meta" data-theme-label>System</span>
                </button>
            </li>
            @if(!empty($navUser))
            <li>
                <form method="POST" action="{{ route('logout') }}" data-turbo="false">
                    @csrf
                    <button type="submit" class="nv-sheet-item">
                        <x-icon name="log-out" :size="20" />
                        <span>Sign out</span>
                    </button>
                </form>
            </li>
            @endif
        </ul>
    </div>
</div>
