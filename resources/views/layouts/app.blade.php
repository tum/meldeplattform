<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title', $appTitle)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ $appTitle }} – {{ $appSubtitle }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <script src="{{ asset('js/app.js') }}" defer></script>
</head>
<body>

<a class="skip-link" href="#main-content">{{ $lang === 'de' ? 'Zum Inhalt springen' : 'Skip to content' }}</a>

@include('partials.header')

<main id="main-content">
    @hasSection('intro')
        @yield('intro')
    @endif

    <div class="container" style="padding-top: 1.75rem; padding-bottom: 3rem;">
        @if (session('flash.success'))
            <div class="alert alert-success">{{ session('flash.success') }}</div>
        @endif
        @if (session('flash.error'))
            <div class="alert alert-error">{{ session('flash.error') }}</div>
        @endif

        @yield('content')
    </div>
</main>

@include('partials.footer')

</body>
</html>
