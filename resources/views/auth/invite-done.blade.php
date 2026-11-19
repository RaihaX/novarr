@extends('layouts.app')

@section('title', 'Account ready')

{{-- Accepted while "Require sign-in" is off: nothing to sign in to yet. --}}
@section('content')
<div class="auth-page">
    <div class="card auth-card">
        <div class="card-body">
            <div class="auth-brand" aria-hidden="true">
                <x-brand-mark :size="40" />
            </div>
            <h1 class="auth-title" id="inviteDone">Your account is ready, {{ $user->name }}</h1>
            <p class="auth-lead">You sign in as <strong>{{ $user->username }}</strong> (or with your email). Sign-in is not required on this Novarr yet, so you can start reading now. Once it is, sign in with that name and your password.</p>
            <div class="auth-actions">
                <a href="{{ route('home') }}" class="btn btn-primary">Go to Home</a>
            </div>
            @if(\App\Support\AndroidApp::available())
                <p class="form-text auth-note"><a href="{{ \App\Support\AndroidApp::url(request()) }}" data-turbo="false" download>{{ \App\Support\AndroidApp::linkText() }}</a></p>
            @endif
        </div>
    </div>
</div>
@endsection
