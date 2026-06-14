<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Novarr') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    {{-- PWA --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#16181d">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Novarr">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    {{-- Theme lives in resources/css/app.scss on top of Bootstrap's native dark mode --}}
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="{{ url('/') }}">
                <img src="{{ asset('logo.svg') }}" alt="Novarr" height="28">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('/') ? 'active' : '' }}" href="{{ route('home') }}">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('novels*') ? 'active' : '' }}" href="{{ route('novels.index') }}">Novels</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('library') ? 'active' : '' }}" href="{{ route('library') }}">Library</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('commands*') ? 'active' : '' }}" href="{{ route('commands.index') }}">Commands</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('logs*') ? 'active' : '' }}" href="{{ route('logs.index') }}">Logs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('health*') ? 'active' : '' }}" href="{{ route('health.index') }}">Health</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('settings*') ? 'active' : '' }}" href="{{ route('settings.index') }}">Settings</a>
                    </li>
                </ul>

                <form class="d-flex position-relative" role="search" action="{{ route('search.index') }}" method="GET" id="navSearchForm">
                    <input type="search" name="q" id="navSearch" class="form-control form-control-sm" placeholder="Search…" autocomplete="off" aria-label="Search novels" style="min-width: 200px;">
                    <div id="navSearchResults" class="dropdown-menu dropdown-menu-end p-0 w-100 d-none" style="position: absolute; top: 100%;"></div>
                </form>
            </div>
        </div>
    </nav>

    <main class="py-4">
        <div class="container">
            @yield('content')
        </div>
    </main>

    <!-- Scripts -->
    @stack('scripts')
</body>
</html>
