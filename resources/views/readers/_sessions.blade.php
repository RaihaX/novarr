{{-- Reading sessions (App\Services\ReaderActivity::sessions()) as a table:
     Reader, Series, Chapter(s), Device (and address), When. Used by the Readers dashboard, the
     history and a reader's page.
       $sessions  list from sessions()
       $names     accounts keyed by id
       $caption   the table's (visually hidden) caption
       $showReader  false on one reader's page (default true)
       $absolute  true: the date and time, then how long ago (the history)
       $ipFilter  query added to the address links (e.g. ['user' => 3]) --}}
@php
    use App\Services\ReaderActivity;
    $showReader = $showReader ?? true;
    $absolute = $absolute ?? false;
    $ipFilter = $ipFilter ?? [];
    $tz = config('app.timezone');
@endphp
<div class="table-responsive">
    <table class="table table-hover align-middle table-responsive-cards reader-sessions">
        <caption class="visually-hidden">{{ $caption }}</caption>
        <thead>
            <tr>
                @if($showReader)<th>Reader</th>@endif
                <th>Series</th>
                <th>Chapters</th>
                <th>Device</th>
                <th class="text-end">When</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sessions as $s)
                @php $who = $names[$s['user_id']] ?? null; @endphp
                <tr data-session-user="{{ $s['user_id'] }}">
                    @if($showReader)
                        <td class="cell-primary" data-label="Reader">
                            @if($who)
                                <a href="{{ route('readers.show', $who->id) }}">{{ $who->name }}</a>
                            @else
                                <span class="text-muted">Deleted account</span>
                            @endif
                        </td>
                    @endif
                    <td class="text-truncate" style="max-width: 300px;" data-label="Series">
                        <a href="{{ route('novels.show', $s['novel_id']) }}">{{ $s['novel_name'] }}</a>
                    </td>
                    <td data-label="Chapters">
                        <span class="reader-session-chapters">{{ ReaderActivity::sessionChapters($s) }}</span>
                        @if($s['manual'] && !$s['bulk'])
                            <span class="chip-mono reader-chip" title="Marked read by hand">Marked by hand</span>
                        @endif
                        @if($s['bulk'])
                            <span class="chip-mono reader-chip" title="Marked read in bulk: not reading activity">Bulk</span>
                        @endif
                    </td>
                    <td data-label="Device">
                        @if($s['label'] === null && $s['ip'] === null)
                            <span class="text-muted">—</span>
                        @else
                            {{-- One child: the phone card layout puts it beside the label. --}}
                            <span class="reader-device">
                                @if($s['label'] !== null)
                                    <span class="reader-device-name" @if($s['device'] && $s['client'] && $s['client'] !== $s['device']) title="{{ $s['client'] }}" @endif>{{ $s['label'] }}</span>
                                @endif
                                @if($s['ip'] !== null)
                                    @php $others = array_values(array_diff($s['ips'], [$s['ip']])); @endphp
                                    <span class="reader-device-ip mono-muted">
                                        <a href="{{ route('readers.history', $ipFilter + ['ip' => $s['ip']]) }}" class="reader-ip">{{ $s['ip'] }}</a>@if($others !== []) <span title="Also {{ implode(', ', $others) }}">+{{ count($others) }}</span>@endif
                                    </span>
                                @endif
                                @if($s['public'])
                                    <span class="chip-mono chip-mono-pending reader-chip-public">Public address</span>
                                @endif
                            </span>
                        @endif
                    </td>
                    <td class="text-end mono-muted" data-label="When">
                        @if($absolute)
                            <time datetime="{{ $s['ended_at']->toIso8601String() }}">{{ $s['ended_at']->copy()->timezone($tz)->format('j M Y, H:i') }}</time>
                            <span class="reader-when-rel">{{ $s['ended_at']->diffForHumans() }}</span>
                        @else
                            <time datetime="{{ $s['ended_at']->toIso8601String() }}" title="{{ $s['ended_at']->copy()->timezone($tz)->format('j M Y, H:i') }}">{{ $s['ended_at']->diffForHumans() }}</time>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
