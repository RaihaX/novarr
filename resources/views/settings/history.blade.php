@extends('layouts.app')

@section('title', 'Sign-in history')

@section('breadcrumb')
    <li><a href="{{ route('settings.index') }}">Settings</a></li>
    <li><a href="{{ route('settings.users') }}">Users</a></li>
    <li aria-current="page">Sign-in history</li>
@endsection

@php
    use App\AuthEvent;
    $tz = config('app.timezone');
    /** The account an event names: its display name, or "Deleted account" when it is gone. */
    $who = function (?int $id) use ($names) {
        if ($id === null) {
            return null;
        }
        $u = $names[$id] ?? null;

        return $u ? $u->name . ' (' . $u->username . ')' : 'Deleted account';
    };
@endphp

@section('content')
<div class="acct-shell history-page">
    <header class="acct-head">
        <h1 class="page-title">Sign-in history</h1>
        <p class="acct-text">Sign-ins, failed attempts and changes to accounts, newest first. Kept for {{ ($days = (int) config('novarr.auth_log_days', 180)) > 0 ? $days . ' days' : 'ever' }}.</p>
    </header>

    <section class="card form-panel acct-card" aria-labelledby="historyTitle">
        <div class="card-body">
            <h2 class="acct-card-title visually-hidden" id="historyTitle">Events</h2>
            <form method="GET" action="{{ route('settings.users.history') }}" class="history-filter" id="historyFilter">
                <div class="form-row">
                    <label class="form-label" for="history-user">Account</label>
                    <select name="user" id="history-user" class="form-select">
                        <option value="">All accounts</option>
                        @foreach($users as $u)
                            <option value="{{ $u->id }}" @selected($userId === (int) $u->id)>{{ $u->name }} ({{ $u->username }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label class="form-label" for="history-group">Show</label>
                    <select name="group" id="history-group" class="form-select">
                        <option value="">Everything</option>
                        @foreach(AuthEvent::GROUP_LABELS as $key => $label)
                            <option value="{{ $key }}" @selected($group === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="acct-actions">
                    <button type="submit" class="btn btn-secondary">Show</button>
                </div>
            </form>

            @if($events->isEmpty())
                <div class="acct-empty">
                    <x-icon name="users" :size="18" />
                    <p>Nothing here yet.</p>
                </div>
            @else
                <ul class="account-devices history-list" id="historyList">
                    @foreach($events as $event)
                        <li class="account-device history-row {{ $event->isFailure() ? 'is-failure' : '' }}">
                            <span class="account-device-main">
                                <span class="account-device-name">{{ $event->label() }}</span>
                                <span class="account-device-meta">
                                    <time datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->timezone($tz)->format('j M Y, H:i:s') }}</time>
                                    · {{ $who($event->user_id) ?? (!empty($event->detail['unknown_name']) ? 'No such account' : 'No account') }}
                                    @if($event->actor_user_id)
                                        · by {{ $who($event->actor_user_id) }}
                                    @endif
                                </span>
                                <span class="account-device-meta">
                                    @if($event->ip)
                                        {{ $event->device() }} · {{ $event->ip }}
                                        @if($event->public)
                                            <span class="badge badge-warning">Public</span>
                                        @endif
                                    @else
                                        On the server
                                    @endif
                                </span>
                            </span>
                        </li>
                    @endforeach
                </ul>
                <div class="history-pages">{{ $events->links() }}</div>
            @endif
        </div>
    </section>
</div>
@endsection
