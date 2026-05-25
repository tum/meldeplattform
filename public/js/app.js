// Tiny helpers for progressive enhancement. No build step required.
(function () {
    // Generic POST to JSON endpoint + optional reload.
    window.md = {
        async postJson(url, body, opts = {}) {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.getAttribute('content') ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(body ?? {}),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            if (opts.redirect) {
                window.location.href = opts.redirect;
            } else if (opts.reload) {
                window.location.reload();
            }
            return res.json().catch(() => ({}));
        },
        async getJson(url) {
            const res = await fetch(url, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        },
    };

    // Hook buttons with data-status (report open/close/spam).
    //  - data-status-confirm (optional): gates the request behind confirm()
    //    so misclicks on destructive actions don't fire.
    //  - data-status-redirect (optional): navigate here on success instead
    //    of reloading the current page. Used on the single-report view to
    //    auto-advance the admin back to the reports list after Close/Spam,
    //    so they don't get stranded on a freshly-closed report.
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-status-url]');
        if (!el) return;
        e.preventDefault();
        const confirmText = el.getAttribute('data-status-confirm');
        if (confirmText && !window.confirm(confirmText)) return;
        const url = el.getAttribute('data-status-url');
        const s = el.getAttribute('data-status');
        const redirect = el.getAttribute('data-status-redirect');
        const opts = redirect ? { redirect } : { reload: true };
        window.md.postJson(url, { s }, opts).catch((err) => {
            alert('Request failed: ' + err.message);
        });
    });

    // Generic form-submit confirmation via `data-confirm-submit="…"`. Used
    // instead of inline `onsubmit="return confirm(…)"` because the strict
    // CSP (`script-src 'self'`) blocks inline event handlers — the inline
    // version would silently submit without ever asking.
    document.addEventListener('submit', (e) => {
        const form = e.target.closest('form[data-confirm-submit]');
        if (!form) return;
        const message = form.getAttribute('data-confirm-submit');
        if (message && !window.confirm(message)) {
            e.preventDefault();
        }
    });
})();
