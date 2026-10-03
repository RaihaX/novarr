<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Inline page scripts bind listeners; cached snapshot restores would revive dead DOM --}}
    <meta name="turbo-cache-control" content="no-cache">

    {{-- Views set @section('title', 'Library') → "Library · Novarr" --}}
    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name', 'Novarr') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    {{-- PWA --}}
    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#0F1216">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Novarr">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

    {{-- Theme lives in resources/css/app.scss on top of Bootstrap's native dark mode --}}
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
    @stack('styles')
</head>
{{-- Views that @section('chromeless') (the reader) render without the global
     navbar: the reader's own bar carries the way back. --}}
<body @hasSection('chromeless') class="is-chromeless" @endif>
    <a href="#main-content" class="skip-link">Skip to content</a>
    @unless(View::hasSection('chromeless'))
    <nav class="navbar navbar-expand-lg">
        <div class="container d-flex align-items-center">
            {{-- Brand lockup: 28px mark + 15px wordmark (handoff §4) --}}
            <a class="navbar-brand" href="{{ url('/') }}">
                <x-brand-mark :size="28" />
                <span class="brand-wordmark">NOVARR<span class="brand-dot">.</span></span>
                <span class="visually-hidden">Novarr home</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Menu">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('home') ? 'active' : '' }}" @if(request()->routeIs('home')) aria-current="page" @endif href="{{ route('home') }}">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('novels.*') ? 'active' : '' }}" @if(request()->routeIs('novels.*')) aria-current="page" @endif href="{{ route('novels.index') }}">Library</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('library') ? 'active' : '' }}" @if(request()->routeIs('library')) aria-current="page" @endif href="{{ route('library') }}" title="Novels saved on this device for offline reading">Downloads</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('stats.*') ? 'active' : '' }}" @if(request()->routeIs('stats.*')) aria-current="page" @endif href="{{ route('stats.index') }}">Stats</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('bookmarks.*') ? 'active' : '' }}" @if(request()->routeIs('bookmarks.*')) aria-current="page" @endif href="{{ route('bookmarks.index') }}">Highlights</a>
                    </li>
                    @php $systemActive = request()->routeIs('commands.*') || request()->routeIs('logs.*') || request()->routeIs('health.*'); @endphp
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle {{ $systemActive ? 'active' : '' }}" href="#" id="systemDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false" @if($systemActive) aria-current="true" @endif>System</a>
                        <ul class="dropdown-menu" aria-labelledby="systemDropdown">
                            @foreach(['commands' => 'Commands', 'logs' => 'Logs', 'health' => 'Health'] as $sysRoute => $sysLabel)
                                @php $sysOn = request()->routeIs($sysRoute . '.*'); @endphp
                                <li><a class="dropdown-item {{ $sysOn ? 'active' : '' }}" @if($sysOn) aria-current="page" @endif href="{{ route($sysRoute . '.index') }}">{{ $sysLabel }}</a></li>
                            @endforeach
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('settings.*') ? 'active' : '' }}" @if(request()->routeIs('settings.*')) aria-current="page" @endif href="{{ route('settings.index') }}">Settings</a>
                    </li>
                </ul>

                {{-- 240px search field; JS hooks in resources/js/navsearch.js --}}
                <form class="nav-search" role="search" action="{{ route('search.index') }}" method="GET" id="navSearchForm">
                    <x-icon name="search" :size="14" class="nav-search-icon" />
                    <input type="search" name="q" id="navSearch" class="form-control" placeholder="Search library" autocomplete="off" aria-label="Search novels">
                    <div id="navSearchResults" class="dropdown-menu dropdown-menu-end p-0 w-100 d-none" style="position: absolute; top: 100%;"></div>
                </form>
            </div>
        </div>
    </nav>
    @endunless

    {{-- Shown by resources/js/funnel.js while Tailscale Funnel exposes this
         instance to the public internet. Amber = warning (status triad). --}}
    <div id="funnelBanner" class="funnel-banner d-none" role="status">
        <div class="container funnel-banner-inner">
            <x-icon name="triangle-alert" :size="14" class="icon" />
            <span class="funnel-banner-text"><strong>Funnel is on.</strong> Novarr is reachable from the public internet, with no login in front of it.</span>
            <a href="{{ route('settings.index') }}#tailscaleCard" class="funnel-banner-link">Turn off in Settings</a>
        </div>
    </div>

    <main class="py-4" id="main-content" tabindex="-1">
        <div class="container">
            {{-- Global flash surface — controllers redirect with status/error --}}
            @if(session('status') || session('success'))
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    {{ session('status') ?? session('success') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    {{ session('error') }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            @endif
            {{-- System pages pass a trail: @section('breadcrumb') … --}}
            @hasSection('breadcrumb')
                <nav class="page-breadcrumb" aria-label="Breadcrumb">
                    <ol>@yield('breadcrumb')</ol>
                </nav>
            @endif
            @yield('content')
        </div>
    </main>

    <!-- Scripts -->
    @stack('scripts')
</body>
</html>
