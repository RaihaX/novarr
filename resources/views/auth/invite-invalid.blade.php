@extends('layouts.app')

@section('title', 'Invite not valid')

{{-- One page for a wrong, expired, cancelled or used link: it tells nobody
     which, so nothing is learned about an email address. --}}
@section('content')
<div class="auth-page">
    <div class="card auth-card">
        <div class="card-body">
            <div class="auth-brand" aria-hidden="true">
                <x-brand-mark :size="40" />
            </div>
            <h1 class="auth-title" id="inviteInvalid">This invite link does not work</h1>
            <p class="auth-lead">It may have expired, been replaced by a newer one, been cancelled, or been used already. Ask the person who runs this Novarr to send you a new invite.</p>
            <div class="auth-actions">
                <a href="{{ route('login') }}" class="btn btn-secondary">I already have an account</a>
            </div>
        </div>
    </div>
</div>
@endsection
