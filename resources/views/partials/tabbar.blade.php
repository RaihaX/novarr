{{--
    Phone tab bar (<768px; hidden above by CSS) + its "More" bottom sheet.
    Rendered by layouts/app.blade.php on every page except the chromeless
    reader. The sheet uses window.Novarr.openSheet (resources/js/modal.js):
    focus trap, inert page, Esc / backdrop to close, focus back to "More".
--}}
@php
    $tabHome = request()->routeIs('home', 'continue');
    $tabDiscover = request()->routeIs('novels.discover*');
    $tabLibrary = request()->routeIs('novels.*') && !$tabDiscover;
    $tabSearch = request()->routeIs('search.*');
    $moreLinks = [
        ['route' => 'library', 'match' => 'library', 'label' => 'Downloads', 'icon' => 'download', 'meta' => 'Offline'],
        ['route' => 'bookmarks.index', 'match' => 'bookmarks.*', 'label' => 'Highlights', 'icon' => 'highlighter'],
        ['route' => 'stats.index', 'match' => 'stats.*', 'label' => 'Stats', 'icon' => 'chart-column'],
    ];
    $systemLinks = [
        ['route' => 'activity.index', 'match' => 'activity.*', 'label' => 'Activity', 'icon' => 'activity'],
        ['route' => 'commands.index', 'match' => 'commands.*', 'label' => 'Commands', 'icon' => 'terminal'],
        ['route' => 'logs.index', 'match' => 'logs.*', 'label' => 'Logs', 'icon' => 'scroll-text'],
        ['route' => 'health.index', 'match' => 'health.*', 'label' => 'Health', 'icon' => 'heart-pulse'],
        ['route' => 'settings.index', 'match' => 'settings.*', 'label' => 'Settings', 'icon' => 'settings'],
    ];
    $tabMore = collect($moreLinks)->merge($systemLinks)->contains(fn($l) => request()->routeIs($l['match']));
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
    <a href="{{ route('novels.discover') }}" class="tabbar-item {{ $tabDiscover ? 'is-active' : '' }}" @if($tabDiscover) aria-current="page" @endif>
        <x-icon name="compass" :size="22" />
        <span>Discover</span>
    </a>
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
                    <a href="{{ route($l['route']) }}" class="nv-sheet-item {{ $on ? 'is-active' : '' }}" data-sheet-close @if($on) aria-current="page" @endif>
                        <x-icon :name="$l['icon']" :size="20" />
                        <span>{{ $l['label'] }}</span>
                        @isset($l['meta'])<span class="nv-sheet-item-meta">{{ $l['meta'] }}</span>@endisset
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="nv-sheet-sep" role="presentation"></div>
        <h3 class="nv-sheet-title" id="moreSheetSystem">System</h3>
        <ul class="nv-sheet-list" aria-labelledby="moreSheetSystem">
            @foreach($systemLinks as $l)
                @php $on = request()->routeIs($l['match']); @endphp
                <li>
                    <a href="{{ route($l['route']) }}" class="nv-sheet-item {{ $on ? 'is-active' : '' }}" data-sheet-close @if($on) aria-current="page" @endif>
                        <x-icon :name="$l['icon']" :size="20" />
                        <span>{{ $l['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
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
        </ul>
    </div>
</div>
