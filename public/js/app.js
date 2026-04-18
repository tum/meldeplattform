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
            if (opts.reload) window.location.reload();
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
    document.addEventListener('click', (e) => {
        const el = e.target.closest('[data-status-url]');
        if (!el) return;
        e.preventDefault();
        const url = el.getAttribute('data-status-url');
        const s = el.getAttribute('data-status');
        window.md.postJson(url, { s }, { reload: true }).catch((err) => {
            alert('Request failed: ' + err.message);
        });
    });
})();
