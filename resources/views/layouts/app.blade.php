<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Novarr') }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

    <!-- Styles -->
    @vite(['resources/css/app.scss', 'resources/js/app.js'])
    <style>
        body {
            background-color: #1a1d21;
            color: #e1e1e1;
        }
        .navbar {
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .navbar-brand {
            font-weight: bold;
            letter-spacing: 1px;
        }
        .card {
            background-color: #212529;
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .card-header {
            background-color: rgba(255,255,255,0.05);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .card-footer {
            background-color: rgba(255,255,255,0.03);
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        .table {
            --bs-table-color: #e1e1e1;
            --bs-table-bg: transparent;
            color: #e1e1e1;
        }
        .table > :not(caption) > * > * {
            color: #e1e1e1;
            --bs-table-color-state: #e1e1e1;
            --bs-table-color-type: #e1e1e1;
        }
        .table-striped > tbody > tr:nth-of-type(odd) > * {
            --bs-table-bg-type: rgba(255,255,255,0.03);
        }
        .table-hover > tbody > tr:hover > * {
            --bs-table-bg-state: rgba(255,255,255,0.06);
        }
        .table-hover > tbody > tr:hover a {
            color: #9ec5fe;
        }
        .table-dark {
            --bs-table-bg: #2c3034;
            --bs-table-border-color: rgba(255,255,255,0.1);
        }
        .table > thead {
            border-bottom: 2px solid rgba(255,255,255,0.15);
        }
        .form-control, .form-select {
            background-color: #2c3034;
            border-color: rgba(255,255,255,0.15);
            color: #e1e1e1;
        }
        .form-control:focus, .form-select:focus {
            background-color: #343a40;
            border-color: #0d6efd;
            color: #fff;
        }
        .form-control::placeholder {
            color: #8b929a;
        }
        a {
            color: #6ea8fe;
        }
        a:hover {
            color: #9ec5fe;
        }
        h1, h2, h3, h4, h5, h6 {
            color: #fff;
        }
        .text-muted {
            color: #8b929a !important;
        }
        code {
            color: #e685b5;
            background-color: rgba(255,255,255,0.05);
            padding: 2px 5px;
            border-radius: 3px;
        }
        pre {
            color: #e1e1e1;
        }
        .progress {
            background-color: #2c3034;
        }
        .badge.bg-success { background-color: #198754 !important; }
        .badge.bg-info { background-color: #0d6efd !important; color: #fff !important; }
        .badge.bg-warning { background-color: #ffc107 !important; }
        .badge.bg-danger { background-color: #dc3545 !important; }
        .badge.bg-secondary { background-color: #6c757d !important; }
        .btn-outline-primary { color: #6ea8fe; border-color: #6ea8fe; }
        .btn-outline-primary:hover { background-color: #0d6efd; color: #fff; border-color: #0d6efd; }
        .btn-outline-secondary { color: #adb5bd; border-color: #6c757d; }
        .btn-outline-secondary:hover { background-color: #6c757d; color: #fff; }
        .alert-danger {
            background-color: rgba(220,53,69,0.15);
            border-color: rgba(220,53,69,0.3);
            color: #ea868f;
        }
        .page-link {
            background-color: #2c3034;
            border-color: rgba(255,255,255,0.1);
            color: #6ea8fe;
        }
        .page-link:hover {
            background-color: #343a40;
            border-color: rgba(255,255,255,0.15);
            color: #9ec5fe;
        }
        .page-item.active .page-link {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
        .page-item.disabled .page-link {
            background-color: #212529;
            border-color: rgba(255,255,255,0.05);
            color: #6c757d;
        }
    </style>
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
                        <a class="nav-link {{ request()->is('commands*') ? 'active' : '' }}" href="{{ route('commands.index') }}">Commands</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->is('logs*') ? 'active' : '' }}" href="{{ route('logs.index') }}">Logs</a>
                    </li>
                </ul>
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
