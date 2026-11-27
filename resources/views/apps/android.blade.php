@extends('layouts.app')

@section('title', 'Android app')

{{-- /app/android/whats-new (App\Http\Controllers\AndroidAppController::page):
     the published version's release notes and the APK download. --}}
@section('content')
<div class="auth-page">
    <div class="card auth-card auth-card-wide">
        <div class="card-body">
            <div class="auth-brand" aria-hidden="true">
                <x-brand-mark :size="40" />
            </div>
            <h1 class="auth-title" id="androidApp">Novarr for Android{{ $version ? ' ' . $version : '' }}</h1>
            <p class="auth-lead">Already have the app? Open Novarr and tap Update. Otherwise download it here and open the file to install it.</p>
            @if($notes !== [])
                <section aria-labelledby="androidWhatsNew" class="android-notes">
                    <h2 class="acct-card-title" id="androidWhatsNew">What's new{{ $version ? ' in ' . $version : '' }}</h2>
                    <ul id="androidNotes">
                        @foreach($notes as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                </section>
            @endif
            <div class="auth-actions">
                <a href="{{ $downloadUrl }}" class="btn btn-primary" data-turbo="false" download>{{ $linkText }}</a>
            </div>
        </div>
    </div>
</div>
@endsection
