@extends('layouts.app')

@section('title', 'Your invite')

@php($turnstileKey = \App\Services\Turnstile::siteKeyFor(request()))

@if($turnstileKey)
    {{-- Full page loads here (Turbo would run the widget's script twice). --}}
    @push('styles')
        <meta name="turbo-visit-control" content="reload">
    @endpush
    @push('scripts')
        <script src="{{ \App\Services\Turnstile::SCRIPT_URL }}" async defer></script>
    @endpush
@endif

@section('content')
<div class="auth-page">
    <div class="card auth-card auth-card-wide">
        <div class="card-body">
            <div class="auth-brand" aria-hidden="true">
                <x-brand-mark :size="40" />
            </div>
            <h1 class="auth-title">Welcome, {{ $invite->name }}</h1>

            @if($signedIn)
                {{-- Never switch accounts silently: say who is signed in here. --}}
                <p class="auth-lead" id="inviteSignedIn">
                    You are already signed in on this browser as <strong>{{ $signedIn->name }}</strong> ({{ $signedIn->username }}).
                    To make your own account with this invite, sign out first.
                </p>
                <div class="auth-actions">
                    <form method="POST" action="{{ route('logout') }}" data-turbo="false">
                        @csrf
                        <input type="hidden" name="next" value="{{ route('invite.show', ['token' => $token], false) }}">
                        <button type="submit" class="btn btn-primary">Sign out and continue</button>
                    </form>
                    <a href="{{ route('home') }}" class="btn btn-ghost">Stay signed in</a>
                </div>
            @else
                <p class="auth-lead">Choose how you sign in. Your reading progress and highlights are your own.</p>
                @unless($authOn)
                    <p class="form-text auth-note-top" id="inviteSignInOff">This Novarr does not ask people to sign in yet. Your account is ready for when it does.</p>
                @endunless

                {{-- Plain form: works without JavaScript; Turbo kept out so a sign-in loads the next page in full. --}}
                <form method="POST" action="{{ route('invite.accept', ['token' => $token]) }}" class="auth-form" data-turbo="false" novalidate>
                    @csrf
                    @error('turnstile')
                        <div class="alert alert-danger" role="alert" id="humanCheckError">{{ $message }}</div>
                    @enderror
                    <input type="text" value="{{ $invite->email }}" autocomplete="email" hidden readonly>

                    <div class="form-row">
                        <label for="invite-name" class="form-label">Your name</label>
                        <input type="text" name="name" id="invite-name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $invite->name) }}" maxlength="80" autocomplete="name" required
                               @error('name') aria-describedby="invite-name-error" @enderror>
                        @error('name')<div class="invalid-feedback" id="invite-name-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-row">
                        <label for="invite-username" class="form-label">Sign-in name</label>
                        <input type="text" name="username" id="invite-username" class="form-control @error('username') is-invalid @enderror"
                               value="{{ old('username', $suggested) }}" maxlength="60" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" required
                               aria-describedby="invite-username-help @error('username') invite-username-error @enderror">
                        @error('username')<div class="invalid-feedback" id="invite-username-error">{{ $message }}</div>@enderror
                        <div class="form-text" id="invite-username-help">What you type to sign in. Not case-sensitive. You can also sign in with {{ $invite->email }}.</div>
                    </div>
                    <div class="form-row">
                        <label for="invite-password" class="form-label">Password</label>
                        <input type="password" name="password" id="invite-password" class="form-control @error('password') is-invalid @enderror"
                               autocomplete="new-password" minlength="12" required
                               aria-describedby="invite-password-help @error('password') invite-password-error @enderror">
                        @error('password')<div class="invalid-feedback" id="invite-password-error">{{ $message }}</div>@enderror
                        <div class="form-text" id="invite-password-help">At least 12 characters, and not your name or email.</div>
                    </div>
                    <div class="form-row">
                        <label for="invite-password2" class="form-label">Password again</label>
                        <input type="password" name="password_confirmation" id="invite-password2" class="form-control"
                               autocomplete="new-password" required>
                    </div>
    {{-- The human check: public address only, when configured (App\Services\Turnstile). --}}
                    @if($turnstileKey)
                        <div class="form-row auth-check" id="humanCheck">
                            <div class="cf-turnstile" data-sitekey="{{ $turnstileKey }}" data-theme="auto" data-response-field-name="{{ \App\Services\Turnstile::FIELD }}"></div>
                        </div>
                    @endif
                    <button type="submit" class="btn btn-primary w-100">Create my account</button>
                    <p class="form-text auth-note">This invite works until {{ $invite->expires_at->timezone(config('app.timezone'))->format('j F Y') }}.</p>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
