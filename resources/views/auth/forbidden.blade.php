@extends('layouts.app')

@section('title', 'Not available')

@section('content')
<div class="auth-page">
    <div class="card auth-card">
        <div class="card-body">
            <h1 class="auth-title">Not available to your account</h1>
            <p>{{ $message ?? 'Only the admin can do that.' }}</p>
            <a href="{{ route('home') }}" class="btn btn-primary">Go to Home</a>
        </div>
    </div>
</div>
@endsection
