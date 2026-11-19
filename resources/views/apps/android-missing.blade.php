@extends('layouts.app')

@section('title', 'Android app')

@section('content')
<div class="auth-page">
    <div class="card auth-card">
        <div class="card-body">
            <div class="auth-brand" aria-hidden="true">
                <x-brand-mark :size="40" />
            </div>
            <h1 class="auth-title" id="androidMissing">The Android app is not available</h1>
            <p class="auth-lead">This Novarr does not offer the Android app for download right now. You can still read in your phone's browser. Ask the person who runs this Novarr if you need the app.</p>
            <div class="auth-actions">
                <a href="{{ route('home') }}" class="btn btn-secondary">Go to Home</a>
            </div>
        </div>
    </div>
</div>
@endsection
