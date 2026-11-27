{{-- One row of Health → Storage's "Where it goes" ($b: a report() bucket; $total: what Share is a part of, null = no share). --}}
@php
    $h = fn ($bytes) => \App\Support\Bytes::human($bytes);
    $fmtPct = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
    $text = $text ?? null;
@endphp
<tr data-bucket="{{ $b['key'] }}">
    <td class="cell-primary">
        <span class="stg-swatch" style="background: {{ $colors[$b['key']] ?? 'var(--nv-muted)' }}"></span><strong>{{ $b['label'] }}</strong>
        @if($b['key'] === 'database')
            <span class="stg-meta">
                {{ $b['meta']['driver'] ?? '' }}{{ isset($b['meta']['source']) ? ', ' . $b['meta']['source'] : '' }}
                @if($text)
                    · novel chapter text {{ $text['meta']['estimated'] ?? false ? '≈ ' : '' }}{{ $h($text['bytes']) }} in {{ number_format($text['meta']['chapters'] ?? 0) }} chapters
                @endif
                @if(!empty($b['meta']['chapter_texts_bytes']))
                    · chapter_texts table with indexes {{ $h($b['meta']['chapter_texts_bytes']) }}
                @endif
            </span>
        @elseif($b['key'] === 'manga_pages' && !empty($b['meta']['unattributed_bytes']))
            <span class="stg-meta">{{ $h($b['meta']['unattributed_bytes']) }} in folders of series no longer in the library</span>
        @elseif($b['key'] === 'backups' && !empty($b['meta']['remote']))
            <span class="stg-meta">Not counted: backups on {{ implode(', ', $b['meta']['remote']) }} (not this server)</span>
        @elseif($b['key'] === 'audiobooks')
            <span class="stg-meta">{{ number_format($b['meta']['count'] ?? 0) }} {{ \Illuminate\Support\Str::plural('audiobook', (int) ($b['meta']['count'] ?? 0)) }} · uploads waiting are counted on their own</span>
        @elseif($b['key'] === 'audiobook_uploads')
            <span class="stg-meta">Readers' uploads waiting for your approval, and uploads still arriving</span>
        @elseif($b['key'] === 'download_inboxes')
            @php $seeding = $b['meta']['seeding'] ?? ['status' => 'off']; @endphp
            <span class="stg-meta" data-seeding="{{ $seeding['status'] }}">
                @if(!empty($b['meta']['external']))
                    On the {{ \App\Services\StorageUsage::SHARE_LABEL }} (NFS), not Novarr's disks ·
                @endif
                @if($seeding['status'] === 'ok')
                    @if($seeding['bytes'] > 0)
                        <strong>{{ $h($seeding['bytes']) }}</strong> kept for seeding — a second copy of imported audiobooks and books
                        ({{ number_format($seeding['entries']) }} {{ \Illuminate\Support\Str::plural('torrent', $seeding['entries']) }})
                        · {{ $h(max(0, $b['bytes'] - $seeding['bytes'])) }} not seeding
                    @else
                        Nothing kept for seeding
                    @endif
                @elseif($seeding['status'] === 'unknown')
                    <span class="tone-warning">Seeding share unknown: Transmission could not be asked</span>
                @else
                    Transmission is not set up: no seeding copies
                @endif
            </span>
            @if(!empty($b['meta']['partial']))
                <span class="stg-meta tone-warning" data-partial="1">Partial: the walk stopped after {{ (int) ($b['meta']['time_limit_s'] ?? 0) }} s (the share is slow); the real size is larger</span>
            @endif
            @foreach((array) ($b['meta']['inboxes'] ?? []) as $inbox)
                <span class="stg-meta mono-muted">{{ $inbox['path'] }} — {{ !empty($inbox['missing']) ? 'not there (not mounted?)' : $h($inbox['bytes']) }}</span>
            @endforeach
        @endif
        @if(!empty($b['meta']['paths']) && !in_array($b['key'], ['database', 'download_inboxes'], true))
            <span class="stg-meta mono-muted">{{ implode(' · ', $b['meta']['paths']) }}</span>
        @endif
        @if(!empty($b['meta']['errors']))
            <span class="stg-meta tone-warning">{{ number_format($b['meta']['errors']) }} unreadable {{ \Illuminate\Support\Str::plural('entry', $b['meta']['errors']) }} skipped</span>
        @endif
    </td>
    <td class="stg-num mono-figure" data-label="Size">{{ $h($b['bytes']) }}</td>
    <td class="stg-num mono-muted" data-label="Share">{{ $total ? $fmtPct(100 * $b['bytes'] / $total) : '—' }}</td>
    <td class="stg-num mono-muted" data-label="Files">{{ $b['key'] === 'database' ? '—' : number_format($b['files']) }}</td>
</tr>
