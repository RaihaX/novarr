{{--
    The desktop nav's links (layouts/app.blade.php, inside #navbarNav):
    Home · Library ▾ · Requests · Discover · Manage ▾ · System ▾.
    Readers (sign-in on, not the admin; and the admin on the public address)
    get Home, Library and Requests only: Gate 'admin' decides the rest.
    Groups are Bootstrap dropdowns (resources/js/bootstrap.js: keyboard, Esc,
    click outside; delegated, so they survive Turbo visits). Below xl the
    collapsed menu shows each group's links in the flow under its toggle.
    A group's toggle is marked active (and aria-current) while one of its
    pages is shown; a group with a count shows it on the toggle too.
--}}
@php
    $r = request();
    $navAdmin = Gate::allows('admin');
    // Pages that belong to Manage (the owner's intake), not the shelves.
    $manageMatch = ['downloads.*', 'books.review*', 'audiobooks.manage*', 'books.calibre*', 'books.upload*', 'novels.create'];
    // Three libraries on one route: the item of the medium this page belongs
    // to (LibraryMedium::current()) is active; book pages belong to Books.
    $navMedium = $r->routeIs('novels.*') && !$r->routeIs('novels.discover*', 'novels.create')
        ? \App\Http\Helpers\LibraryMedium::current()
        : ($r->routeIs('books.*') && !$r->routeIs('books.review*', 'books.calibre*', 'books.upload*') ? 'book' : null);

    $libraryLinks = [];
    foreach (['novel' => 'Web Novels', 'manga' => 'Manga', 'book' => 'Books'] as $navKey => $navLabel) {
        $libraryLinks[] = ['href' => route('novels.index', ['medium' => $navKey]), 'label' => $navLabel, 'on' => $navMedium === $navKey, 'medium' => $navKey];
    }
    $libraryLinks[] = ['href' => route('audiobooks.index'), 'label' => 'Audiobooks', 'on' => $r->routeIs('audiobooks.index', 'audiobooks.show')];
    $libraryLinks[] = ['href' => route('want_to_read'), 'label' => 'Want to read', 'on' => $r->routeIs('want_to_read')];
    $libraryLinks[] = ['divider' => true];
    $libraryLinks[] = ['href' => route('bookmarks.index'), 'label' => 'Highlights', 'on' => $r->routeIs('bookmarks.*')];
    $libraryLinks[] = ['href' => route('stats.index'), 'label' => 'Stats', 'on' => $r->routeIs('stats.*')];
    $libraryLinks[] = ['href' => route('library'), 'label' => 'Offline', 'on' => $r->routeIs('library'), 'title' => 'Novels saved on this device for offline reading'];
    // Readers send the owner a book; the owner's own upload is under Manage.
    if (!$navAdmin) {
        $libraryLinks[] = ['href' => route('books.upload'), 'label' => 'Upload a book', 'on' => $r->routeIs('books.upload*')];
    }

    $manageLinks = $systemLinks = [];
    if ($navAdmin) {
        $manageLinks = [
            ['href' => route('downloads.index'), 'label' => 'Downloads', 'on' => $r->routeIs('downloads.*', 'books.review*'), 'count' => $downloadsAttention ?? null, 'countLabel' => 'need you', 'id' => 'navDownloads'],
            ['href' => route('audiobooks.manage'), 'label' => 'Audiobook inbox', 'on' => $r->routeIs('audiobooks.manage*')],
            ['href' => route('books.calibre'), 'label' => 'Calibre import', 'on' => $r->routeIs('books.calibre*')],
            ['href' => route('books.upload'), 'label' => 'Upload a book', 'on' => $r->routeIs('books.upload*')],
            ['href' => route('novels.create'), 'label' => 'Add a series', 'on' => $r->routeIs('novels.create')],
        ];
        $systemLinks = [
            ['href' => route('health.index'), 'label' => 'Health', 'on' => $r->routeIs('health.index')],
            ['href' => route('health.storage'), 'label' => 'Storage', 'on' => $r->routeIs('health.storage')],
            ['href' => route('health.sources'), 'label' => 'Sources', 'on' => $r->routeIs('health.sources')],
            ['href' => route('readers.index'), 'label' => 'Readers', 'on' => $r->routeIs('readers.*')],
            ['href' => route('activity.index'), 'label' => 'Activity', 'on' => $r->routeIs('activity.*')],
            ['href' => route('logs.index'), 'label' => 'Logs', 'on' => $r->routeIs('logs.*')],
            ['href' => route('commands.index'), 'label' => 'Commands', 'on' => $r->routeIs('commands.*')],
            ['divider' => true],
            ['href' => route('settings.index'), 'label' => 'Settings', 'on' => $r->routeIs('settings.*')],
        ];
    }

    $navGroups = array_filter([
        ['id' => 'libraryDropdown', 'label' => 'Library', 'links' => $libraryLinks],
        $navAdmin ? ['id' => 'manageDropdown', 'label' => 'Manage', 'links' => $manageLinks] : null,
        $navAdmin ? ['id' => 'systemDropdown', 'label' => 'System', 'links' => $systemLinks] : null,
    ]);
    foreach ($navGroups as &$g) {
        $g['on'] = collect($g['links'])->contains(fn($l) => !empty($l['on']));
        $g['count'] = (int) collect($g['links'])->sum(fn($l) => (int) ($l['count'] ?? 0));
    }
    unset($g);
    $navGroups = collect($navGroups)->keyBy('id');
    $homeOn = $r->routeIs('home');
    $requestsNavOn = $r->routeIs('requests.index');
    $discoverOn = $r->routeIs('novels.discover*');
@endphp
<ul class="navbar-nav me-auto">
    <li class="nav-item">
        <a class="nav-link {{ $homeOn ? 'active' : '' }}" @if($homeOn) aria-current="page" @endif href="{{ route('home') }}">Home</a>
    </li>
    @include('partials.navbar-group', ['g' => $navGroups['libraryDropdown']])
    {{-- Reader requests, for everyone while the feature is on (the readers'
         main action, so never in a group); the admin at home sees how many
         wait for a decision. --}}
    @if($requestsOn ?? false)
    <li class="nav-item">
        <a class="nav-link {{ $requestsNavOn ? 'active' : '' }}" @if($requestsNavOn) aria-current="page" @endif href="{{ route('requests.index') }}" id="navRequests"><span>Requests</span>@if(!empty($requestsPending))<span class="nav-count chip-mono chip-mono-pending" data-requests-badge><span class="visually-hidden">, </span>{{ $requestsPending }}<span class="visually-hidden"> pending</span></span>@endif</a>
    </li>
    @endif
    @if($navAdmin)
    <li class="nav-item">
        <a class="nav-link {{ $discoverOn ? 'active' : '' }}" @if($discoverOn) aria-current="page" @endif href="{{ route('novels.discover') }}" id="navDiscover">Discover</a>
    </li>
    @include('partials.navbar-group', ['g' => $navGroups['manageDropdown']])
    @include('partials.navbar-group', ['g' => $navGroups['systemDropdown']])
    @endif
</ul>
