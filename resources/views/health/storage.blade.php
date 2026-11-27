@extends('layouts.app')

@section('title', 'Storage · Health')
@section('breadcrumb')
    @include('partials.breadcrumb', ['trail' => [['System'], ['Health', route('health.index')], ['Storage']]])
@endsection

@push('styles')
<style>
    /* Health → Storage: CSS bars, no chart library. Colours are theme tokens. */
    .stg-bar { display: flex; height: 14px; border-radius: 3px; overflow: hidden; background: var(--nv-surface-alt); }
    .stg-bar > span { display: block; height: 100%; min-width: 2px; }
    .stg-bar-used { background: var(--nv-amber); }
    .stg-bar.is-low .stg-bar-used { background: var(--nv-danger); }
    .stg-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 2px; margin-right: 6px; vertical-align: baseline; }
    .stg-figures { display: flex; flex-wrap: wrap; gap: 4px 20px; margin: 8px 0 0; font-size: .875rem; }
    .stg-figures dt { display: inline; font-weight: 500; color: var(--nv-text-muted); margin-right: 4px; }
    .stg-figures dd { display: inline; margin: 0; }
    .stg-meta { display: block; font-size: .8125rem; color: var(--nv-text-muted); overflow-wrap: anywhere; }
    .stg-num { text-align: right; white-space: nowrap; }
    .stg-volume + .stg-volume { margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--nv-border); }
    @media (max-width: 767.98px) { .stg-num { text-align: right; } }
</style>
@endpush

@section('content')
@php
    $h = fn ($bytes) => \App\Support\Bytes::human($bytes);
    $colors = [
        'manga_pages' => 'var(--nv-amber)',
        'database' => 'var(--nv-accent)',
        'covers' => 'var(--nv-success)',
        'epubs' => 'var(--nv-pending)',
        'backups' => 'var(--nv-link)',
        'logs' => 'var(--nv-warning)',
        'snapshots' => 'var(--nv-danger)',
        'cache' => 'var(--nv-text-muted)',
        'other' => 'var(--nv-muted)',
        'books' => 'var(--nv-amber-ink)',
        'audiobooks' => 'var(--nv-series-3)',
    ];
    $mediumLabel = [\App\Novel::MEDIUM_NOVEL => 'Web Novel', \App\Novel::MEDIUM_MANGA => 'Manga', \App\Novel::MEDIUM_BOOK => 'Book'];
    $fmtPct = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
@endphp

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4">
    <h1 class="page-title mb-0">Storage</h1>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary" id="measureNow">
            <span class="cmd-label">Measure now</span>
            <span class="cmd-spinner d-none"><span class="spinner-border spinner-border-sm me-1"></span>Measuring</span>
        </button>
        <a href="{{ route('health.index') }}" class="btn btn-secondary">Back to Health</a>
    </div>
</div>

<p class="text-muted mb-4" id="measuredAt">
    @if($report)
        Last measured <span title="{{ $report['measured_at']->format('Y-m-d H:i') }}">{{ $report['measured_at']->diffForHumans() }}</span>
        (took {{ number_format($report['duration_ms'] / 1000, 1) }} s{{ $report['exact'] ? ', exact text sizes' : '' }}).
    @else
        Never measured.
    @endif
    Measured daily at 05:10 — walking every manga page and sizing every series takes a while, so this page shows the last measurement.
    Soft-deleted series and chapters are included: their files and text stay on disk until they are deleted for good.
</p>

@if(!$report)
    <div class="card mb-4" id="neverMeasured">
        <div class="card-body">
            <p class="mb-0">No measurement yet. Use <strong>Measure now</strong> or wait for tonight's run.</p>
        </div>
    </div>
@else
    {{-- Disk volumes --}}
    @php $lowCount = count(\App\Services\StorageUsage::lowDisks($report['disks'])); @endphp
    <div class="card mb-4" id="storageDisks">
        <div class="panel-head">
            <h2 class="panel-title">
                Disk
                @if($lowCount > 0)
                    <span class="count-chip is-danger">low space</span>
                @endif
            </h2>
            <span class="panel-note">Warning under {{ $fmtPct($lowFreePercent) }} free</span>
        </div>
        <div class="card-body">
            @forelse($report['disks'] as $disk)
                @php
                    $total = (int) ($disk['total'] ?? 0);
                    $usedPct = $total > 0 ? min(100, round(100 * $disk['used'] / $total, 1)) : 0;
                @endphp
                <div class="stg-volume" data-disk="{{ $disk['key'] }}" data-low="{{ !empty($disk['low']) ? 1 : 0 }}">
                    <div class="d-flex flex-wrap justify-content-between align-items-baseline gap-2">
                        <strong>{{ implode(' + ', (array) ($disk['labels'] ?? [])) }}</strong>
                        @if(!empty($disk['low']))
                            <x-status state="failed">Low space</x-status>
                        @endif
                    </div>
                    <span class="stg-meta mono-muted">{{ implode(' · ', (array) ($disk['paths'] ?? [])) }}</span>
                    @if($total > 0)
                        <div class="stg-bar mt-2 {{ !empty($disk['low']) ? 'is-low' : '' }}" role="img"
                             aria-label="{{ $h($disk['used']) }} used of {{ $h($total) }}, {{ $h($disk['free']) }} free">
                            <span class="stg-bar-used" style="width: {{ $usedPct }}%"></span>
                        </div>
                        <dl class="stg-figures">
                            <div><dt>Used</dt><dd class="mono-figure">{{ $h($disk['used']) }}</dd></div>
                            <div><dt>Free</dt><dd class="mono-figure {{ !empty($disk['low']) ? 'tone-danger' : '' }}">{{ $h($disk['free']) }} ({{ $fmtPct($disk['free_percent'] ?? 0) }})</dd></div>
                            <div><dt>Total</dt><dd class="mono-figure">{{ $h($total) }}</dd></div>
                        </dl>
                    @else
                        <p class="text-muted mb-0 mt-2">The size of this disk could not be read.</p>
                    @endif
                </div>
            @empty
                <p class="text-muted mb-0">No disk could be read.</p>
            @endforelse
        </div>
    </div>

    {{-- Where it goes: physical buckets, which add up --}}
    @php
        $bucketTotal = max(1, array_sum(array_column($report['buckets'], 'bytes')));
        $text = $report['novel_text'];
    @endphp
    <div class="card mb-4" id="storageBuckets">
        <div class="panel-head">
            <h2 class="panel-title">Where it goes <span class="count-chip">{{ $h(array_sum(array_column($report['buckets'], 'bytes'))) }}</span></h2>
        </div>
        <div class="card-body pb-0">
            <div class="stg-bar" role="img" aria-label="{{ collect($report['buckets'])->map(fn ($b) => $b['label'] . ' ' . $h($b['bytes']))->implode('; ') }}">
                @foreach($report['buckets'] as $b)
                    @if($b['bytes'] > 0)
                        <span style="width: {{ round(100 * $b['bytes'] / $bucketTotal, 2) }}%; background: {{ $colors[$b['key']] ?? 'var(--nv-muted)' }}" title="{{ $b['label'] }}: {{ $h($b['bytes']) }}"></span>
                    @endif
                @endforeach
            </div>
        </div>
        <div class="table-responsive">
            <table class="table align-middle table-responsive-cards mb-0">
                <caption class="visually-hidden">Storage by kind</caption>
                <thead>
                    <tr><th>Kind</th><th class="stg-num">Size</th><th class="stg-num">Share</th><th class="stg-num">Files</th></tr>
                </thead>
                <tbody>
                    @foreach($report['buckets'] as $b)
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
                                @endif
                                @if(!empty($b['meta']['paths']) && $b['key'] !== 'database')
                                    <span class="stg-meta mono-muted">{{ implode(' · ', $b['meta']['paths']) }}</span>
                                @endif
                                @if(!empty($b['meta']['errors']))
                                    <span class="stg-meta tone-warning">{{ number_format($b['meta']['errors']) }} unreadable {{ \Illuminate\Support\Str::plural('entry', $b['meta']['errors']) }} skipped</span>
                                @endif
                            </td>
                            <td class="stg-num mono-figure" data-label="Size">{{ $h($b['bytes']) }}</td>
                            <td class="stg-num mono-muted" data-label="Share">{{ $fmtPct(100 * $b['bytes'] / $bucketTotal) }}</td>
                            <td class="stg-num mono-muted" data-label="Files">{{ $b['key'] === 'database' ? '—' : number_format($b['files']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Novels vs Manga --}}
    <div class="card mb-4" id="storageMediums">
        <div class="panel-head">
            <h2 class="panel-title">Web novels, manga and books</h2>
            <span class="panel-note">Per series: text or pages, cover, ePub</span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle table-responsive-cards mb-0">
                <caption class="visually-hidden">Storage by medium</caption>
                <thead>
                    <tr>
                        <th>Medium</th><th class="stg-num">Size</th><th class="stg-num">Series</th><th class="stg-num">Chapters</th>
                        <th class="stg-num">Text / pages</th><th class="stg-num">Covers</th><th class="stg-num">ePubs</th><th class="stg-num">Per series</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($report['mediums'] as $medium => $m)
                        <tr data-medium="{{ $medium }}">
                            <td class="cell-primary"><strong>{{ ['manga' => 'Manga', 'book' => 'Books'][$medium] ?? 'Web Novels' }}</strong>
                                @if($medium === \App\Novel::MEDIUM_BOOK)
                                    <span class="stg-meta">ePubs: the original files</span>
                                @endif
                                @if($medium === \App\Novel::MEDIUM_NOVEL && ($text['meta']['estimated'] ?? false))
                                    <span class="stg-meta">Text sizes estimated from stored word counts</span>
                                @endif
                            </td>
                            <td class="stg-num mono-figure" data-label="Size">{{ $h($m['bytes']) }}</td>
                            <td class="stg-num" data-label="Series">{{ number_format($m['series']) }}</td>
                            <td class="stg-num" data-label="Chapters">{{ number_format($m['chapters']) }}</td>
                            <td class="stg-num mono-muted" data-label="Text / pages">{{ $h($m['content_bytes']) }}</td>
                            <td class="stg-num mono-muted" data-label="Covers">{{ $h($m['cover_bytes']) }}</td>
                            <td class="stg-num mono-muted" data-label="ePubs">{{ $h($m['epub_bytes']) }}</td>
                            <td class="stg-num mono-muted" data-label="Per series">{{ $h($m['average']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Biggest series --}}
    <div class="card mb-4" id="storageTop">
        <div class="panel-head flex-wrap gap-2">
            <h2 class="panel-title">Biggest series</h2>
            <div class="btn-group segmented" role="group" aria-label="Show" id="stgTopTabs">
                <button type="button" class="btn btn-secondary active" data-top="all" aria-pressed="true">All</button>
                <button type="button" class="btn btn-secondary" data-top="novel" aria-pressed="false">Web Novels</button>
                <button type="button" class="btn btn-secondary" data-top="manga" aria-pressed="false">Manga</button>
                <button type="button" class="btn btn-secondary" data-top="book" aria-pressed="false">Books</button>
            </div>
        </div>
        @foreach(['all' => 'All series', 'novel' => 'Web Novels', 'manga' => 'Manga', 'book' => 'Books'] as $which => $caption)
            <div class="table-responsive" data-top-table="{{ $which }}" @if($which !== 'all') hidden @endif>
                @if(count($report['top'][$which]))
                    <table class="table align-middle table-responsive-cards mb-0">
                        <caption class="visually-hidden">{{ $caption }}, biggest first</caption>
                        <thead>
                            <tr><th class="stg-num">#</th><th>Series</th><th>Medium</th><th class="stg-num">Chapters</th><th class="stg-num">Size</th><th class="stg-num">Per chapter</th></tr>
                        </thead>
                        <tbody>
                            @foreach($report['top'][$which] as $i => $s)
                                <tr data-series="{{ $s['novel_id'] }}">
                                    <td class="stg-num mono-muted" data-label="#">{{ $i + 1 }}</td>
                                    <td class="cell-primary">
                                        @if($s['deleted'])
                                            <strong>{{ $s['name'] }}</strong> <span class="stg-meta">deleted (files still on disk)</span>
                                        @else
                                            <a href="{{ route('novels.show', $s['novel_id']) }}"><strong>{{ $s['name'] }}</strong></a>
                                        @endif
                                        <span class="stg-meta mono-muted">
                                            {{ $s['medium'] === \App\Novel::MEDIUM_MANGA ? 'pages ' . $h($s['pages_bytes']) : 'text ' . ($s['text_estimated'] ? '≈ ' : '') . $h($s['text_bytes']) }}
                                            · cover {{ $h($s['cover_bytes']) }} · ePub {{ $h($s['epub_bytes']) }}
                                        </span>
                                    </td>
                                    <td data-label="Medium">{{ $mediumLabel[$s['medium']] ?? $s['medium'] }}</td>
                                    <td class="stg-num" data-label="Chapters">{{ number_format($s['chapters']) }}</td>
                                    <td class="stg-num mono-figure" data-label="Size">{{ $h($s['bytes']) }}</td>
                                    <td class="stg-num mono-muted" data-label="Per chapter">{{ $s['per_chapter'] !== null ? $h($s['per_chapter']) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="card-body"><p class="text-muted mb-0">Nothing stored yet.</p></div>
                @endif
            </div>
        @endforeach
    </div>
@endif
@endsection

@push('scripts')
<script>
(function () {
    // Biggest series: All / Novels / Manga.
    const tabs = document.getElementById('stgTopTabs');
    if (tabs) {
        tabs.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-top]');
            if (!btn) return;
            tabs.querySelectorAll('button').forEach(b => {
                const on = b === btn;
                b.classList.toggle('active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            document.querySelectorAll('[data-top-table]').forEach(t => { t.hidden = t.dataset.topTable !== btn.dataset.top; });
        });
    }

    // Measure now: queued through the Commands runner, then reload.
    const btn = document.getElementById('measureNow');
    btn?.addEventListener('click', async () => {
        btn.disabled = true;
        btn.querySelector('.cmd-label').classList.add('d-none');
        btn.querySelector('.cmd-spinner').classList.remove('d-none');
        let result;
        try {
            result = await Novarr.executeCommand({ command: 'storage_measure' });
        } catch (err) {
            result = { success: false, message: err.message };
        }
        if (result && result.success) {
            const note = (result.output || '').includes('already running') ? 'A measurement is already running.' : 'Storage measured.';
            Novarr.showToast(note, 'success');
            window.location.reload();
            return;
        }
        Novarr.showToast('Measuring failed: ' + (result?.error || result?.message || 'unknown error'), 'danger');
        btn.disabled = false;
        btn.querySelector('.cmd-label').classList.remove('d-none');
        btn.querySelector('.cmd-spinner').classList.add('d-none');
    });
})();
</script>
@endpush
