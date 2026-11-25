@extends('layouts.app')

@section('title', $reader->name)

@section('breadcrumb')
    <li><a href="{{ route('readers.index') }}">Readers</a></li>
    <li aria-current="page">{{ $reader->name }}</li>
@endsection

@php
    $isOwner = (int) $reader->id === \App\Support\CurrentReader::ownerId();
    $tz = config('app.timezone');
@endphp

@section('content')
<div class="page-head">
    <div class="page-head-titles">
        <span class="page-head-kicker">Reading activity</span>
        <h1 class="page-title mb-0">{{ $reader->name }}</h1>
        <p class="page-head-sub mb-0 reader-head-meta">
            <span class="mono-muted">{{ $reader->username }}</span>
            <span class="badge {{ $reader->isAdmin() ? 'badge-accent' : 'badge-muted' }}">{{ $isOwner ? 'Owner' : ($reader->isAdmin() ? 'Admin' : 'Reader') }}</span>
            @if($reader->isDisabled())
                <span class="badge badge-muted">Turned off</span>
            @endif
            <span class="mono-muted">
                @if($reader->last_seen_at)
                    Seen <time datetime="{{ $reader->last_seen_at->toIso8601String() }}">{{ $reader->last_seen_at->diffForHumans() }}</time>
                @else
                    Never seen
                @endif
            </span>
        </p>
    </div>
    <div class="page-head-actions">
        <a href="{{ route('settings.users.history', ['user' => $reader->id]) }}" class="btn btn-secondary">Sign-in history</a>
        <a href="{{ route('settings.users') }}#user-{{ $reader->id }}" class="btn btn-secondary">Account settings</a>
    </div>
</div>

@if($now->isNotEmpty())
    <section class="dash-panel mb-4" id="reading-now-section" aria-labelledby="nowTitle">
        @include('readers._now', ['now' => $now, 'names' => $names])
    </section>
@endif

@include('stats._body')

{{-- Where they are in each series (their reading positions). --}}
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">In progress</h2>
        <span class="chart-note">Newest {{ $inProgress->count() }}</span>
    </div>
    @if($inProgress->isEmpty())
        <div class="card-body"><p class="text-muted mb-0">No reading positions yet.</p></div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle table-responsive-cards" id="inProgressTable">
                <caption class="visually-hidden">{{ $reader->name }}'s reading positions, newest first</caption>
                <thead>
                    <tr>
                        <th>Series</th>
                        <th>Chapter</th>
                        <th class="text-end">Progress</th>
                        <th>Device</th>
                        <th class="text-end">When</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($inProgress as $p)
                        <tr>
                            <td class="cell-primary text-truncate" style="max-width: 300px;"><a href="{{ route('novels.show', $p->novel_id) }}">{{ $p->novel_name }}</a></td>
                            <td data-label="Chapter" @if($p->label) title="{{ $p->label }}" @endif>{{ $p->number !== '' ? 'Chapter ' . $p->number : ($p->label ?: '—') }}</td>
                            <td class="text-end" data-label="Progress"><span class="mono-figure">{{ $p->progress === null ? '—' : $p->progress . '%' }}</span></td>
                            <td class="mono-muted" data-label="Device">{{ $p->device_name ?? '—' }}</td>
                            <td class="text-end mono-muted" data-label="When">
                                @if($p->at)
                                    <time datetime="{{ $p->at->toIso8601String() }}">{{ $p->at->diffForHumans() }}</time>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Devices: read-only here (sign out / revoke live on Account and Settings → Users). No addresses. --}}
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Devices</h2>
        <span class="chart-note">{{ $devices['apps']->count() }} {{ Str::plural('app', $devices['apps']->count()) }} · {{ $devices['browsers']->count() }} {{ Str::plural('browser', $devices['browsers']->count()) }}</span>
    </div>
    @if($devices['apps']->isEmpty() && $devices['browsers']->isEmpty())
        <div class="card-body"><p class="text-muted mb-0">No signed-in apps or browsers.</p></div>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle table-responsive-cards" id="devicesTable">
                <caption class="visually-hidden">{{ $reader->name }}'s signed-in apps and browsers</caption>
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>Kind</th>
                        <th class="text-end">Last active</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($devices['apps'] as $token)
                        <tr>
                            <td class="cell-primary">{{ $token->name }}</td>
                            <td data-label="Kind">App</td>
                            <td class="text-end mono-muted" data-label="Last active">
                                @if($token->last_used_at)
                                    <time datetime="{{ $token->last_used_at->toIso8601String() }}">{{ $token->last_used_at->diffForHumans() }}</time>
                                @else
                                    Never used
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @foreach($devices['browsers'] as $browser)
                        <tr>
                            <td class="cell-primary">{{ $browser->device }}</td>
                            <td data-label="Kind">Browser · {{ $browser->public ? 'public address' : 'home network' }}</td>
                            <td class="text-end mono-muted" data-label="Last active">
                                @if($browser->last_active_at)
                                    <time datetime="{{ $browser->last_active_at->toIso8601String() }}">{{ $browser->last_active_at->diffForHumans() }}</time>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

{{-- Their newest sessions. --}}
<div class="card mb-4">
    <div class="panel-head">
        <h2 class="panel-title">Recent history</h2>
        <a href="{{ route('readers.history', ['user' => $reader->id]) }}" class="chart-note">Full history</a>
    </div>
    @if($recent === [])
        <div class="card-body"><p class="text-muted mb-0">Nothing read yet.</p></div>
    @else
        @include('readers._sessions', ['sessions' => $recent, 'names' => $names, 'caption' => $reader->name . "'s recent reading sessions, newest first", 'showReader' => false])
    @endif
</div>
@endsection
