(function () {
    const wrap = document.querySelector('[data-reports-filter]');
    const closedCb = document.getElementById('hide-closed');
    const spamCb = document.getElementById('hide-spam');
    const rows = document.querySelectorAll('.report-row');
    if (!wrap || !closedCb || !spamCb) return;

    // Sticky per-topic filter state so an admin's "show closed" choice
    // survives a reload instead of resetting every time.
    const storageKey = 'reports-filter:' + (wrap.dataset.topicId || '0');
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
        if (saved && typeof saved === 'object') {
            if (typeof saved.hideClosed === 'boolean') closedCb.checked = saved.hideClosed;
            if (typeof saved.hideSpam === 'boolean') spamCb.checked = saved.hideSpam;
        }
    } catch {
        // localStorage may be disabled (private mode, quota); fall back to defaults.
    }

    function apply() {
        rows.forEach((r) => {
            const closed = r.dataset.closed === '1';
            const spam = r.dataset.spam === '1';
            const hide = (closedCb.checked && closed) || (spamCb.checked && spam);
            r.style.display = hide ? 'none' : '';
        });
        try {
            localStorage.setItem(storageKey, JSON.stringify({
                hideClosed: closedCb.checked,
                hideSpam: spamCb.checked,
            }));
        } catch {
            // ignore quota / disabled-storage errors
        }
    }

    closedCb.addEventListener('change', apply);
    spamCb.addEventListener('change', apply);
    apply();
})();
