{{-- data-menu="closed" collapses the nav behind the toggle on small screens;
     app.js flips it. The <noscript> block below keeps the menu reachable
     when scripts are off. On wide screens the CSS ignores the attribute. --}}
<div class="topbar" data-topbar data-menu="closed">
    <noscript><style>.topbar-menu { display: flex !important; }</style></noscript>
    <div class="container">
        @auth
            <span class="topbar-user" title="{{ auth()->user()->name ?: auth()->user()->uid }}">
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 12c2.76 0 5-2.24 5-5S14.76 2 12 2 7 4.24 7 7s2.24 5 5 5zm0 2c-3.33 0-10 1.67-10 5v3h20v-3c0-3.33-6.67-5-10-5z"/>
                </svg>
                <span class="topbar-user-name">{{ auth()->user()->name ?: auth()->user()->uid }}</span>
            </span>
        @endauth

        <button type="button" class="topbar-toggle" data-topbar-toggle
                aria-expanded="false" aria-controls="topbar-menu" aria-label="{{ __('menu') }}">
            <svg class="icon-menu" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M4 7h16M4 12h16M4 17h16"/>
            </svg>
            <svg class="icon-close" aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M6 6l12 12M18 6L6 18"/>
            </svg>
        </button>

        <div class="topbar-menu" id="topbar-menu">
            @auth
                @php
                    // Section highlight for the current page. Topic editing and
                    // per-topic report lists count as "Topics"; a single report can
                    // be reached from either section, so it highlights neither.
                    // A regular signed-in user (no topic to administer) gets no
                    // section links at all — only logout and the language toggle.
                    $nav = [];
                    if (auth()->user()->can('viewAny', App\Models\Topic::class)) {
                        $nav[] = ['route' => 'dashboard', 'label' => __('dashboard'), 'active' => request()->routeIs('dashboard', 'dashboard.*')];
                        $nav[] = ['route' => 'topics.index', 'label' => __('topics'), 'active' => request()->routeIs('topics.*', 'topic.*')];
                    }
                    if (auth()->user()->can('manage', App\Models\User::class)) {
                        $nav[] = ['route' => 'users.index', 'label' => __('users'), 'active' => request()->routeIs('users.*')];
                        $nav[] = ['route' => 'stats.index', 'label' => __('stats_title'), 'active' => request()->routeIs('stats.*')];
                        $nav[] = ['route' => 'audit.index', 'label' => __('audit_title'), 'active' => request()->routeIs('audit.*')];
                    }
                @endphp
                <nav class="topbar-nav" aria-label="{{ __('main_navigation') }}">
                    @foreach ($nav as $item)
                        <a href="{{ route($item['route']) }}" @class(['is-active' => $item['active']]){!! $item['active'] ? ' aria-current="page"' : '' !!}>{{ $item['label'] }}</a>
                    @endforeach
                    {{-- Logout is a POST so it cannot be triggered cross-origin. --}}
                    <form method="POST" action="{{ Route::has('dev.logout') ? route('dev.logout') : route('saml.logout') }}" style="display:contents">
                        @csrf
                        <button type="submit" class="linkish topbar-logout">{{ __('logout') }}</button>
                    </form>
                </nav>
            @else
                <nav class="topbar-nav" aria-label="{{ __('main_navigation') }}">
                    <a href="{{ route('saml.login') }}">{{ __('login') }}</a>
                    @if (Route::has('dev.login'))
                        <a href="{{ route('dev.login') }}" class="topbar-nav-muted">Dev-Login</a>
                    @endif
                </nav>
            @endauth
            {{-- Segmented toggle; the active language reads as the pressed segment. --}}
            <form method="POST" action="{{ route('lang.set') }}" class="lang-switch" aria-label="{{ __('language') }}">
                @csrf
                <span class="lang-switch-label" aria-hidden="true">{{ __('language') }}</span>
                <span class="lang-switch-options">
                    <button type="submit" name="lang" value="de" class="lang-btn {{ $lang === 'de' ? 'active' : '' }}" aria-pressed="{{ $lang === 'de' ? 'true' : 'false' }}"><abbr lang="de" title="Deutsch">de</abbr></button>
                    <button type="submit" name="lang" value="en" class="lang-btn {{ $lang === 'en' ? 'active' : '' }}" aria-pressed="{{ $lang === 'en' ? 'true' : 'false' }}"><abbr lang="en" title="English">en</abbr></button>
                </span>
            </form>
        </div>
    </div>
</div>

<header class="masthead">
    <div class="container">
        <a href="{{ route('home') }}" class="brand" aria-label="{{ $appTitle }} – {{ $appSubtitle }}">
            <span class="app-eyebrow">{{ config('meldeplattform.subtitle.'.$lang) }}</span>
            <span class="app-name">{{ config('meldeplattform.title.'.$lang) }}</span>
        </a>
        <span class="tum-logo" aria-hidden="true">
            {{-- TUM word mark, rendered in white on the navy masthead --}}
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="-16 -31 408.16 212.46">
                <path fill="#ffffff" d="m 140.54052,-31 v 173.32822 h 44.72985 V -31 H 392.146 V 181.46685 H 353.00738 V 8.138629 H 308.2775 V 181.46685 H 269.13887 V 8.138629 H 224.40902 V 181.46685 H 101.4019 V 8.138629 H 62.26327 V 181.46685 H 23.12462 V 8.138629 H -16.014 V -31 Z"/>
            </svg>
        </span>
    </div>
</header>
