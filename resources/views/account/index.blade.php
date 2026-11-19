@extends('layouts.app')

@section('title', 'Account')

@php
    use App\DevicePairing;
@endphp

@section('content')
<h1 class="page-title mb-4">Account</h1>

<div class="row justify-content-center">
    <div class="col-lg-8 account-page">

        {{-- Name --}}
        <section class="card form-panel mb-4" aria-labelledby="profileTitle">
            <div class="card-body">
                <h2 class="panel-title" id="profileTitle">You</h2>
                <p class="form-text mt-0">You sign in as <strong>{{ $user->username }}</strong>{{ $user->isAdmin() ? ' · admin' : '' }}.</p>
                <form method="POST" action="{{ route('account.profile') }}">
                    @csrf
                    <div class="form-row">
                        <label for="name" class="form-label">Display name</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" maxlength="80" required autocomplete="name">
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-secondary">Save name</button>
                </form>
            </div>
        </section>

        {{-- Password --}}
        <section class="card form-panel mb-4" aria-labelledby="passwordTitle">
            <div class="card-body">
                <h2 class="panel-title" id="passwordTitle">Password</h2>
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
                        <input type="password" name="password" id="new_password" class="form-control @error('password', 'password') is-invalid @enderror" autocomplete="new-password" minlength="8" required>
                        @error('password', 'password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">At least 8 characters.</div>
                    </div>
                    <div class="form-row">
                        <label for="password_confirmation" class="form-label">New password again</label>
                        <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" autocomplete="new-password" required>
                    </div>
                    <button type="submit" class="btn btn-secondary">Change password</button>
                </form>
            </div>
        </section>

        {{-- Link a device (Kindle / KOReader): code → which device → confirm --}}
        <section class="card form-panel mb-4" aria-labelledby="linkTitle" id="link">
            <div class="card-body">
                <h2 class="panel-title" id="linkTitle">Link a device</h2>
                @if($pairing && $pairing->user_id === null && $pairing->expires_at->isFuture())
                    <p>Link <strong>{{ $pairing->displayName() }}</strong> (code {{ DevicePairing::formatCode($pairing->code) }}) to your account? It will read and sync as {{ $user->name }}.</p>
                    <form method="POST" action="{{ route('account.link.confirm') }}" class="d-flex gap-2 flex-wrap">
                        @csrf
                        <input type="hidden" name="pairing" value="{{ $pairing->id }}">
                        <input type="hidden" name="code" value="{{ $pairing->code }}">
                        <button type="submit" class="btn btn-primary">Link this device</button>
                        <a href="{{ route('account') }}#link" class="btn btn-ghost">Cancel</a>
                    </form>
                @else
                    <p class="form-text mt-0">On the Kindle, open Novarr in KOReader and choose to sign in: it shows a 6-character code. Type it here.</p>
                    <form method="POST" action="{{ route('account.link') }}" class="link-form">
                        @csrf
                        <div class="form-row">
                            <label for="code" class="form-label">Code</label>
                            <input type="text" name="code" id="code" class="form-control link-code-input @error('code', 'link') is-invalid @enderror"
                                   value="{{ old('code') }}" placeholder="K7M 4QX" maxlength="9" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" required>
                            @error('code', 'link')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button type="submit" class="btn btn-secondary">Next</button>
                    </form>
                @endif
            </div>
        </section>

        {{-- Signed-in devices (tokens) --}}
        <section class="card form-panel mb-4" aria-labelledby="devicesTitle" id="devices">
            <div class="card-body">
                <h2 class="panel-title" id="devicesTitle">Signed-in apps and devices</h2>
                @if($tokens->isEmpty())
                    <p class="form-text mt-0">No apps or devices are signed in to your account. The iOS app signs in with your name and password; a Kindle is linked with a code above.</p>
                @else
                    <ul class="account-devices">
                        @foreach($tokens as $token)
                            <li class="account-device">
                                <span class="account-device-main">
                                    <strong>{{ $token->name }}</strong>
                                    <span class="form-text d-block mt-0">
                                        {{ $token->last_used_at ? 'Last used ' . $token->last_used_at->diffForHumans() : 'Not used yet' }}
                                        · signed in {{ $token->created_at?->format('j M Y') }}
                                    </span>
                                </span>
                                <form method="POST" action="{{ route('account.tokens.revoke', $token->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm" aria-label="Revoke {{ $token->name }} (signs it out)" title="Signs this app or device out">Revoke</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        <form method="POST" action="{{ route('logout') }}" data-turbo="false" class="mb-4">
            @csrf
            <button type="submit" class="btn btn-ghost"><x-icon name="log-out" :size="14" /> Sign out of this browser</button>
        </form>
    </div>
</div>
@endsection
