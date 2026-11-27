@extends('layouts.app')

@section('title', 'App update emails')

{{-- "Stop app update emails" from the new Android version email
     (App\Http\Controllers\AppUpdateUnsubscribeController): opening the link
     changes nothing; the button does. --}}
@section('content')
<div class="auth-page">
    <div class="card auth-card">
        <div class="card-body">
            <div class="auth-brand" aria-hidden="true">
                <x-brand-mark :size="40" />
            </div>
            @if($done)
                <h1 class="auth-title" id="appUpdatesStopped">Done</h1>
                <p class="auth-lead">{{ $user->name }} no longer gets an email when a new version of the Android app is out. It can be switched back on in Account.</p>
                <div class="auth-actions">
                    <a href="{{ route('account') }}" class="btn btn-secondary">Account</a>
                </div>
            @else
                <h1 class="auth-title" id="appUpdatesStop">Stop app update emails?</h1>
                <p class="auth-lead">The email to {{ $user->name }} when a new version of the Novarr app for Android is out. The app still offers each update when you open it.</p>
                <form method="POST" action="{{ $action }}" class="auth-actions">
                    @csrf
                    <button type="submit" class="btn btn-primary">Stop app update emails</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
