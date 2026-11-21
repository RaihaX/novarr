@extends('layouts.app')

@section('title', 'Account')

@php
    use App\DevicePairing;
    $android = \App\Support\AndroidApp::available();
@endphp

@section('content')
<div class="acct-shell account-page">

    {{-- Who you are, compactly: the title and the content share one left edge. --}}
    <header class="acct-head">
        <h1 class="page-title">Account</h1>
        <p class="acct-identity" id="accountIdentity">
            <span class="acct-identity-name">{{ $user->name }}</span>
            <span class="acct-identity-username" title="Your sign-in name">{{ $user->username }}</span>
            <span class="badge {{ $user->isAdmin() ? 'badge-accent' : 'badge-muted' }}">{{ $user->isAdmin() ? 'Admin' : 'Reader' }}</span>
            @if($user->email)
                <span class="acct-identity-email">{{ $user->email }}</span>
            @endif
        </p>
    </header>

    <div class="acct-grid">
        {{-- ============ Left: you ============ --}}
        <div class="acct-col">
            <section class="card form-panel acct-card" aria-labelledby="profileTitle">
                <div class="card-body">
                    <h2 class="acct-card-title" id="profileTitle">You</h2>
                    <form method="POST" action="{{ route('account.profile') }}">
                        @csrf
                        <div class="form-row">
                            <label for="name" class="form-label">Display name</label>
                            <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" maxlength="80" required autocomplete="name">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Shown in the menu and to the admin. You sign in as <strong>{{ $user->username }}</strong>{{ $user->email ? ' or with your email' : '' }}.</div>
                        </div>
                        <div class="acct-actions">
                            <button type="submit" class="btn btn-primary">Save name</button>
                        </div>
                    </form>
                </div>
            </section>

            <section class="card form-panel acct-card" aria-labelledby="passwordTitle">
                <div class="card-body">
                    <h2 class="acct-card-title" id="passwordTitle">Password</h2>
                    <form method="POST" action="{{ route('account.password') }}">
                        @csrf
                        {{-- For password managers: which account this is. --}}
                        <input type="text" name="username" value="{{ $user->username }}" autocomplete="username" hidden readonly>
                        <div class="form-row">
                            <label for="current_password" class="form-label">Current password</label>
                            <input type="password" name="current_password" id="current_password" class="form-control @error('current_password', 'password') is-invalid @enderror" autocomplete="current-password" required>
                            @error('current_password', 'password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-row">
                            <label for="new_password" class="form-label">New password</label>
                            <input type="password" name="password" id="new_password" class="form-control @error('password', 'password') is-invalid @enderror" autocomplete="new-password" minlength="12" required aria-describedby="new_password_help">
                            @error('password', 'password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text" id="new_password_help">At least 12 characters, and not your name or email.</div>
                        </div>
                        <div class="form-row">
                            <label for="password_confirmation" class="form-label">New password again</label>
                            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" autocomplete="new-password" required>
                        </div>
                        <div class="acct-actions">
                            <button type="submit" class="btn btn-primary">Change password</button>
                        </div>
                    </form>
                </div>
            </section>

            {{-- Own sign-ins and failed attempts (App\AuthEvent): spot one that was not you. --}}
            <section class="card form-panel acct-card" aria-labelledby="signInsTitle" id="sign-ins">
                <div class="card-body">
                    <h2 class="acct-card-title" id="signInsTitle">Recent sign-ins</h2>
                    @if($signIns->isEmpty())
                        <div class="acct-empty">
                            <x-icon name="users" :size="18" />
                            <p>No sign-ins recorded yet.</p>
                        </div>
                    @else
                        <ul class="account-devices history-list" id="signInsList">
                            @foreach($signIns as $event)
                                <li class="account-device history-row {{ $event->isFailure() ? 'is-failure' : '' }}">
                                    <span class="account-device-main">
                                        <span class="account-device-name">{{ $event->event === \App\AuthEvent::SIGN_IN ? 'Signed in' : 'Failed attempt' }} · {{ $event->device() }}</span>
                                        <span class="account-device-meta">
                                            <time datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->timezone(config('app.timezone'))->format('j M Y, H:i') }}</time>
                                            · {{ $event->ip }} · {{ $event->public ? 'public address' : 'home network' }}
                                        </span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="form-text acct-footnote">If one of these was not you, change your password above.</p>
                    @endif
                </div>
            </section>
        </div>

        {{-- ============ Right: devices ============ --}}
        <div class="acct-col">
            {{-- Link a device (Kindle / KOReader): code → which device → confirm --}}
            <section class="card form-panel acct-card" aria-labelledby="linkTitle" id="link">
                <div class="card-body">
                    <h2 class="acct-card-title" id="linkTitle">Link a device</h2>
                    @if($pairing && $pairing->user_id === null && $pairing->expires_at->isFuture())
                        <p class="acct-text">Link <strong>{{ $pairing->displayName() }}</strong> (code <span class="acct-code">{{ DevicePairing::formatCode($pairing->code) }}</span>) to your account? It will read and sync as {{ $user->name }}.</p>
                        <form method="POST" action="{{ route('account.link.confirm') }}" class="acct-actions">
                            @csrf
                            <input type="hidden" name="pairing" value="{{ $pairing->id }}">
                            <input type="hidden" name="code" value="{{ $pairing->code }}">
                            <button type="submit" class="btn btn-primary">Link this device</button>
                            <a href="{{ route('account') }}#link" class="btn btn-ghost">Cancel</a>
                        </form>
                    @else
                        <p class="acct-text">On the Kindle, open Novarr in KOReader and choose to sign in: it shows a 6-character code. Type it here.</p>
                        <form method="POST" action="{{ route('account.link') }}" class="link-form">
                            @csrf
                            <label for="code" class="form-label">Code</label>
                            <div class="link-form-row">
                                <input type="text" name="code" id="code" class="form-control link-code-input @error('code', 'link') is-invalid @enderror"
                                       value="{{ old('code') }}" placeholder="K7M 4QX" maxlength="9" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" required
                                       @error('code', 'link') aria-describedby="code-error" @enderror>
                                <button type="submit" class="btn btn-primary">Next</button>
                            </div>
                            @error('code', 'link')<div class="invalid-feedback d-block" id="code-error">{{ $message }}</div>@enderror
                        </form>
                    @endif
                </div>
            </section>

            {{-- Web browsers signed in to this account (App\Services\BrowserSessions) --}}
            <section class="card form-panel acct-card" aria-labelledby="browsersTitle" id="browsers">
                <div class="card-body">
                    <h2 class="acct-card-title" id="browsersTitle">Signed-in browsers</h2>
                    <ul class="account-devices" id="browsersList">
                        @foreach($browsers as $browser)
                            <li class="account-device {{ $browser->current ? 'is-current' : '' }}">
                                <span class="account-device-main">
                                    <span class="account-device-name">
                                        {{ $browser->device }}
                                        @if($browser->current)
                                            <span class="badge badge-accent">This browser</span>
                                        @endif
                                    </span>
                                    <span class="account-device-meta">
                                        {{ $browser->ip }} · {{ $browser->public ? 'public address' : 'home network' }}
                                    </span>
                                    <span class="account-device-meta">
                                        Signed in {{ $browser->signed_in_at?->timezone(config('app.timezone'))->format('j M Y') }}
                                        · last active {{ $browser->last_active_at?->diffForHumans() }}
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if($browsers->where('current', false)->isNotEmpty())
                        <form method="POST" action="{{ route('account.browsers.sign_out_others') }}" class="acct-actions" data-turbo="false"
                              data-confirm="Sign out every other browser signed in to your account? This browser and your apps and devices stay signed in." data-confirm-title="Sign out other browsers?" data-confirm-text="Sign out">
                            @csrf
                            <button type="submit" class="btn btn-outline-danger btn-sm" id="signOutOthers">Sign out all other browsers</button>
                        </form>
                    @endif
                </div>
            </section>

            {{-- Signed-in devices (tokens) --}}
            <section class="card form-panel acct-card" aria-labelledby="devicesTitle" id="devices">
                <div class="card-body">
                    <h2 class="acct-card-title" id="devicesTitle">Signed-in apps and devices</h2>
                    @if($tokens->isEmpty())
                        <div class="acct-empty">
                            <x-icon name="book-open" :size="18" />
                            <p>No apps or devices are signed in yet. The Android and iOS apps sign in with your name and password; a Kindle is linked with a code.</p>
                        </div>
                    @else
                        <ul class="account-devices">
                            @foreach($tokens as $token)
                                <li class="account-device">
                                    <span class="account-device-main">
                                        <span class="account-device-name">{{ $token->name }}</span>
                                        <span class="account-device-meta">
                                            {{ $token->last_used_at ? 'Last used ' . $token->last_used_at->diffForHumans() : 'Not used yet' }}
                                            · signed in {{ $token->created_at?->format('j M Y') }}
                                        </span>
                                    </span>
                                    <form method="POST" action="{{ route('account.tokens.revoke', $token->id) }}" class="account-device-action"
                                          data-confirm="Sign this device out? A Kindle will need a new code to link again." data-confirm-title="Sign out {{ $token->name }}?" data-confirm-text="Sign out">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Revoke {{ $token->name }} (signs it out)" title="Signs this app or device out">Revoke</button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if($android)
                        <p class="acct-android" id="accountAndroid">
                            <a href="{{ \App\Support\AndroidApp::url(request()) }}" data-turbo="false" download>{{ \App\Support\AndroidApp::linkText() }}</a>
                        </p>
                    @endif
                </div>
            </section>
        </div>
    </div>

    {{-- Page footer: a secondary action, below both columns at every width. --}}
    <footer class="acct-foot">
        <form method="POST" action="{{ route('logout') }}" data-turbo="false">
            @csrf
            <button type="submit" class="btn btn-secondary"><x-icon name="log-out" :size="14" /> Sign out of this browser</button>
        </form>
    </footer>
</div>
@endsection
