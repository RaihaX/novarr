{{-- Error pages in the app's shell (works signed out, like the sign-in
     page). Each code's view sets title / heading / text; the exception's own
     message replaces the text when the app set one (abort(404, '…')), never a
     framework default such as "The route … could not be found." --}}
@extends('layouts.app')

@section('title', View::getSection('heading'))

@section('content')
<div class="auth-page">
    <div class="card auth-card">
        <div class="card-body error-page">
            <p class="error-code">@yield('code')</p>
            <h1 class="auth-title">@yield('heading')</h1>
            <p class="auth-lead">{{ \App\Support\ErrorPage::message($exception ?? null) ?? View::getSection('text') }}</p>
            <div class="auth-actions">
                <a href="{{ url('/') }}" class="btn btn-primary">Home</a>
            </div>
        </div>
    </div>
</div>
@endsection
