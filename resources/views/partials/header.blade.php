<div class="topbar">
    <div class="container">
        <div class="left">
            @if ($authLoggedIn)
                <span>
                    <svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-2px;margin-right:.25rem;">
                        <path d="M12 12c2.76 0 5-2.24 5-5S14.76 2 12 2 7 4.24 7 7s2.24 5 5 5zm0 2c-3.33 0-10 1.67-10 5v3h20v-3c0-3.33-6.67-5-10-5z"/>
                    </svg>{{ $authName ?: $authUid }}
                </span>
                @if (! app()->environment('production'))
                    <a href="/dev/logout">{{ __('logout') }}</a>
                @else
                    <a href="/saml/logout">{{ __('logout') }}</a>
                @endif
            @else
                <a href="/saml/out">{{ __('login') }}</a>
                @if (! app()->environment('production'))
                    <a href="/dev/login" style="opacity:.8;">Dev-Login</a>
                @endif
            @endif
        </div>
        <div class="right lang-switch">
            <a href="/setLang?lang=de" class="{{ $lang === 'de' ? 'active' : '' }}"><abbr lang="de" title="Deutsch">de</abbr></a>
            <span class="sep">|</span>
            <a href="/setLang?lang=en" class="{{ $lang === 'en' ? 'active' : '' }}"><abbr lang="en" title="English">en</abbr></a>
        </div>
    </div>
</div>

<header class="masthead">
    <div class="container">
        <a href="/" class="brand" aria-label="{{ $appTitle }} – {{ $appSubtitle }}">
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
