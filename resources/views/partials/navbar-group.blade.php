{{-- One nav group (partials/navbar-menu): $g = id, label, links, on, count. --}}
<li class="nav-item dropdown nav-group" data-nav-group="{{ Str::before($g['id'], 'Dropdown') }}">
    <button type="button" class="nav-link dropdown-toggle{{ $g['on'] ? ' active' : '' }}" id="{{ $g['id'] }}" data-bs-toggle="dropdown" aria-expanded="false" @if($g['on']) aria-current="true" @endif>{{ $g['label'] }}@if($g['count'] > 0)<span class="nav-count chip-mono chip-mono-pending"><span class="visually-hidden">, </span>{{ $g['count'] }}<span class="visually-hidden"> need you</span></span>@endif</button>
    <ul class="dropdown-menu" aria-labelledby="{{ $g['id'] }}">
        @foreach($g['links'] as $l)
            @if(!empty($l['divider']))
                <li><hr class="dropdown-divider"></li>
            @else
                @php
                    // Optional attributes, each with its leading space (none: nothing).
                    $extra = collect(['id' => $l['id'] ?? null, 'data-nav-medium' => $l['medium'] ?? null, 'title' => $l['title'] ?? null])
                        ->filter()->map(fn($v, $k) => ' ' . $k . '="' . e($v) . '"')->implode('');
                @endphp
                <li><a class="dropdown-item{{ $l['on'] ? ' active' : '' }}" @if($l['on']) aria-current="page" @endif href="{{ $l['href'] }}"{!! $extra !!}>{{ $l['label'] }}@if(!empty($l['count']))<span class="nav-count chip-mono chip-mono-pending"><span class="visually-hidden">, </span>{{ $l['count'] }}<span class="visually-hidden"> {{ $l['countLabel'] ?? '' }}</span></span>@endif</a></li>
            @endif
        @endforeach
    </ul>
</li>
