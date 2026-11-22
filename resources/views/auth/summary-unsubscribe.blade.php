@extends('layouts.app')

@section('title', 'Daily summary')

{{-- "Stop these emails" from a reader's daily summary
     (App\Http\Controllers\SummaryUnsubscribeController): opening the link
     changes nothing; the button does. --}}
@section('content')
<div class="auth-page">
    <div class="card auth-card">
        <div class="card-body">
            <div class="auth-brand" aria-hidden="true">
                <x-brand-mark :size="40" />
            </div>
            @if($done)
                <h1 class="auth-title" id="summaryStopped">Done</h1>
                <p class="auth-lead">{{ $user->name }} no longer gets the daily summary email. It can be switched back on in Account.</p>
                <div class="auth-actions">
                    <a href="{{ route('account') }}" class="btn btn-secondary">Account</a>
                </div>
            @else
                <h1 class="auth-title" id="summaryStop">Stop the daily summary?</h1>
                <p class="auth-lead">The daily email to {{ $user->name }} with the new chapters and completed series across the library.</p>
                <form method="POST" action="{{ $action }}" class="auth-actions">
                    @csrf
                    <button type="submit" class="btn btn-primary">Stop the daily summary</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
