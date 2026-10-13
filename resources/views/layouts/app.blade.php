<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <title>@yield('title', $appTitle)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ $metaDescription ?? __('meta_description') }}">
    {{-- Public pages answer in both languages via ?lang=; announce both so
         search engines index the German page too (crawlers send no
         Accept-Language and would otherwise only ever see English). Only
         for routes whose identity is the path alone — token-bearing and
         admin pages must not advertise alternates. --}}
    @if (request()->routeIs('home', 'process', 'support', 'imprint', 'privacy'))
        <link rel="alternate" hreflang="de" href="{{ url()->current() }}?lang=de">
        <link rel="alternate" hreflang="en" href="{{ url()->current() }}?lang=en">
        <link rel="alternate" hreflang="x-default" href="{{ url()->current() }}">
    @endif
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="stylesheet" href="@asset('css/app.css')">
    <script src="@asset('js/app.js')" defer></script>
</head>
<body>

<a class="skip-link" href="#main-content">{{ __('skip_to_content') }}</a>

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
