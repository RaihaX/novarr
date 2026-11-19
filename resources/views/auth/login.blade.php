@extends('layouts.app')

@section('title', 'Sign in')

{{-- Always tells the page JS that nobody is signed in on this browser any
     more: the service worker's cached pages (another account's read flags)
     are emptied before anyone signs in (layouts.app → novarr-signed). --}}
@section('signed-out', '1')

@section('content')
<div class="auth-page">
    <div class="card auth-card">
        <div class="card-body">
            <div class="auth-brand" aria-hidden="true">
                <x-brand-mark :size="40" />
            </div>
            <h1 class="auth-title">Sign in to Novarr</h1>

            {{-- Plain form: works without JavaScript; Turbo is kept out so a
                 sign-in always loads the next page in full. --}}
            <form method="POST" action="{{ route('login.store') }}" class="auth-form" data-turbo="false" novalidate>
                @csrf
                @error('username')
                    <div class="alert alert-danger" role="alert" id="loginError">{{ $message }}</div>
                @enderror
                @error('password')
                    <div class="alert alert-danger" role="alert">{{ $message }}</div>
                @enderror

                <div class="form-row">
                    <label for="username" class="form-label">Name or email</label>
                    <input type="text" name="username" id="username" class="form-control @error('username') is-invalid @enderror"
                           value="{{ $username }}" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false"
                           required autofocus @error('username') aria-describedby="loginError" @enderror>
                </div>
                <div class="form-row">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror"
                           autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Sign in</button>
                <p class="form-text auth-note">You stay signed in on this device until you sign out.</p>
                <p class="form-text auth-note">Forgot your password? Ask the person who runs this server to set a new one.</p>
            </form>
        </div>
    </div>
    <p class="auth-help">No account? Ask the person who runs this Novarr to invite you.</p>
</div>
@endsection
