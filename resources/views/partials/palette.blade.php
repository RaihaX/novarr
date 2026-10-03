{{--
    Command palette — ⌘K / Ctrl-K, "/", the navbar search field or the phone
    Search tab. Behaviour: resources/js/palette.js; styles: _palette.scss.
    Hosted in an .nv-sheet so the focus trap / inert / Esc plumbing is shared
    (resources/js/modal.js).
--}}
@php
    $paletteNav = [
        ['title' => 'Home', 'url' => route('home'), 'icon' => 'house', 'keywords' => 'dashboard start continue reading'],
        ['title' => 'Library', 'url' => route('novels.index'), 'icon' => 'library', 'keywords' => 'novels books all'],
        ['title' => 'Discover', 'url' => route('novels.discover'), 'icon' => 'compass', 'keywords' => 'browse find new add'],
        ['title' => 'Add a novel', 'url' => route('novels.create'), 'icon' => 'plus', 'keywords' => 'new create add novel'],
        ['title' => 'Downloads', 'url' => route('library'), 'icon' => 'download', 'meta' => 'Saved for offline reading', 'keywords' => 'offline saved device'],
        ['title' => 'Highlights', 'url' => route('bookmarks.index'), 'icon' => 'highlighter', 'keywords' => 'bookmarks notes quotes'],
        ['title' => 'Stats', 'url' => route('stats.index'), 'icon' => 'chart-column', 'keywords' => 'statistics reading streak charts'],
        ['title' => 'Activity', 'url' => route('activity.index'), 'icon' => 'activity', 'meta' => 'System', 'keywords' => 'recent downloads missing chapters'],
        ['title' => 'Commands', 'url' => route('commands.index'), 'icon' => 'terminal', 'meta' => 'System', 'keywords' => 'artisan run scrape jobs'],
        ['title' => 'Logs', 'url' => route('logs.index'), 'icon' => 'scroll-text', 'meta' => 'System', 'keywords' => 'errors log viewer'],
        ['title' => 'Health', 'url' => route('health.index'), 'icon' => 'heart-pulse', 'meta' => 'System', 'keywords' => 'status sources failing attention'],
        ['title' => 'Settings', 'url' => route('settings.index'), 'icon' => 'settings', 'keywords' => 'preferences config kindle tailscale'],
        ['title' => 'Search page', 'url' => route('search.index'), 'icon' => 'search', 'keywords' => 'full text search chapters'],
    ];
    $paletteIcons = ['book-open', 'file-text', 'arrow-right', 'search', 'refresh-cw', 'download', 'sun', 'moon', 'sun-moon',
        'house', 'library', 'compass', 'plus', 'highlighter', 'chart-column', 'activity', 'terminal', 'scroll-text', 'heart-pulse', 'settings'];
@endphp
<div class="nv-sheet palette" id="palette" role="dialog" aria-modal="true" aria-label="Command palette" hidden>
    <div class="nv-sheet-backdrop" data-sheet-close></div>
    <div class="nv-sheet-panel palette-panel">
        <div class="palette-field">
            <x-icon name="search" :size="18" />
            <input type="text" id="paletteInput" class="palette-input"
                   role="combobox" aria-expanded="false" aria-controls="paletteList" aria-autocomplete="list"
                   aria-label="Search novels, chapters, commands"
                   placeholder="Novel, “ascending 142”, or a command…"
                   autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" enterkeyhint="go">
            <span class="palette-spinner" aria-hidden="true"></span>
            <kbd class="kbd-hint" aria-hidden="true">esc</kbd>
        </div>
        <div class="palette-list" id="paletteList" role="listbox" aria-label="Results"></div>
        <div class="palette-foot">
            <span class="palette-foot-keys"><kbd class="kbd-hint">↑</kbd><kbd class="kbd-hint">↓</kbd> move</span>
            <span><kbd class="kbd-hint">↵</kbd> open</span>
            <span class="palette-foot-keys"><kbd class="kbd-hint">tab</kbd> next group</span>
            <a class="palette-foot-all" href="{{ route('search.index') }}" data-sheet-close>Full search</a>
        </div>
    </div>
    <script type="application/json" id="paletteNav">@json(['nav' => $paletteNav, 'searchUrl' => route('search.index')])</script>
    <template id="paletteIcons">
        @foreach($paletteIcons as $ico)
            <span data-icon="{{ $ico }}"><x-icon :name="$ico" :size="16" /></span>
        @endforeach
    </template>
</div>
