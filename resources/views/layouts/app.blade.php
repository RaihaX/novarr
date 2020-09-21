<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Styles -->
    <link href="{{ mix('css/app.css') }}" rel="stylesheet">
    <link href="{{ mix('css/vendor.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/font-awesome/4.7.0/css/font-awesome.min.css" integrity="sha384-wvfXpqpZZVQGK6TAh5PVlGOfQNHSoD2xbE+QkPxCAFlNEevoEH3Sl0sibVcOQVnN" crossorigin="anonymous">
</head>
<body>
    <div id="app">
        <nav style="height:7vh;" class="navbar navbar-expand-md navbar-light navbar-laravel bg-dark">
            <div class="container">
                <a class="navbar-brand text-light" href="{{ url('/novels') }}">
                    {{ config('app.name', 'Laravel') }}
                </a>
                <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
                    <span class="navbar-toggler-icon"></span>
                </button>

                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                    <!-- Left Side Of Navbar -->
                    <ul class="navbar-nav mr-auto">
                        <li class="text-light">@yield('title')</li>
                    </ul>

                    <!-- Right Side Of Navbar -->
                    <ul class="navbar-nav ml-auto">
                        <!-- Authentication Links -->
                        @guest
                            <li><a class="nav-link text-light" href="{{ route('login') }}">{{ __('Login') }}</a></li>
                            <li><a class="nav-link text-light" href="{{ route('register') }}">{{ __('Register') }}</a></li>
                        @else
                            <li><a class="nav-link text-light" href="{{ route('novels.index') }}">{{ __('Novel') }}</a></li>
                            <li><a class="nav-link text-light" href="{{ route('groups.index') }}">{{ __('Group') }}</a></li>
                            <li><a class="nav-link text-light" href="{{ route('languages.index') }}">{{ __('Language') }}</a></li>
                            <li><a class="nav-link text-light" href="{{ route('mangas.index') }}">{{ __('Manga') }}</a></li>
                            <li class="nav-item dropdown">
                                <a id="navbarDropdown" class="nav-link dropdown-toggle text-light" href="#" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" v-pre>
                                    {{ Auth::user()->name }} <span class="caret"></span>
                                </a>

                                <div class="dropdown-menu" aria-labelledby="navbarDropdown">
                                    <a class="dropdown-item" href="{{ route('logout') }}"
                                       onclick="event.preventDefault();
                                                     document.getElementById('logout-form').submit();">
                                        {{ __('Logout') }}
                                    </a>

                                    <form id="logout-form" action="{{ route('logout') }}" method="POST" style="display: none;">
                                        @csrf
                                    </form>
                                </div>
                            </li>
                        @endguest
                    </ul>
                </div>
            </div>
        </nav>

        <div class="container-fluid">
            <div class="row">
                <div class="col-1 bg-secondary">
                    <div class="d-flex flex-column">
                        <div class="p-2">
                            @yield('subnav')
                        </div>
                    </div>
                </div>
                <div class="col-11" style="overflow-y: scroll; height:93vh;">
                    @yield('content')
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="{{ mix('js/app.js') }}"></script>
    <script src="{{ mix('js/vendor.js') }}"></script>
    @yield('javascript')
</body>
</html>
