<footer class="site-footer">
    <div class="container">
        <div class="footer-links">
            <a href="/imprint">{{ __('imprint') }}</a>
            <a href="/privacy">{{ __('privacy') }}</a>
            <a href="https://github.com/tum/meldeplattform" target="_blank" rel="noopener">{{ __('source') }}</a>
        </div>
        <div class="footer-meta">
            <div>© {{ now()->year }} Technische Universität München</div>
            <div class="footer-thanks">
                {{ __('thanks_prefix') }}
                <a href="https://www.tum.dev/" target="_blank" rel="noopener">TUM DEV</a>
                {{ __('thanks_suffix') }}
            </div>
        </div>
    </div>
</footer>
