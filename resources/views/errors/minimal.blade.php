{{-- 500 / 503: no app shell — the shell reads settings and the signed-in
     account, and those may be what failed. Styles only if the build exists. --}}
@php
    try { $styles = Vite::withEntryPoints(['resources/css/app.scss'])->toHtml(); } catch (\Throwable $e) { $styles = ''; }
@endphp
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>@yield('heading') · {{ config('app.name', 'Novarr') }}</title>
    {!! $styles !!}
</head>
<body class="is-guest">
    <main id="main-content">
        <div class="container">
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
        </div>
    </main>
</body>
</html>
