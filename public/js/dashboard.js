// Client-side filter for the admin dashboard at /dashboard.
// Filters are persisted in localStorage so they survive reloads
// (mirrors the per-topic /reports/{topic} list behavior).
(function () {
    const wrap = document.querySelector('[data-dashboard-filter]');
    const summary = document.querySelector('[data-dashboard-summary]');
    const topicSel = document.getElementById('filter-topic');
    const closedCb = document.getElementById('filter-hide-closed');
    const spamCb = document.getElementById('filter-hide-spam');
    const rows = document.querySelectorAll('.dashboard-row');
    const empty = document.getElementById('dashboard-empty');
    if (!wrap || !topicSel || !closedCb || !spamCb) return;

    const storageKey = 'dashboard-filter';
    const totalTpl = summary?.dataset.templateTotal ?? '';
    const shownTpl = summary?.dataset.templateShown ?? '';
    const total = rows.length;

    try {
        const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
        if (saved && typeof saved === 'object') {
            if (typeof saved.topic === 'string') topicSel.value = saved.topic;
            if (typeof saved.hideClosed === 'boolean') closedCb.checked = saved.hideClosed;
            if (typeof saved.hideSpam === 'boolean') spamCb.checked = saved.hideSpam;
        }
    } catch {
        // localStorage may be disabled (private mode, quota); fall back to defaults.
    }

    function apply() {
        const topicFilter = topicSel.value;
        let shown = 0;
        rows.forEach((r) => {
            const closed = r.dataset.closed === '1';
            const spam = r.dataset.spam === '1';
            const topicMatch = !topicFilter || r.dataset.topicId === topicFilter;
            const statusHide = (closedCb.checked && closed) || (spamCb.checked && spam);
            const hide = !topicMatch || statusHide;
            r.style.display = hide ? 'none' : '';
            if (!hide) shown++;
        });
        if (empty) empty.hidden = shown !== 0;
        if (summary) {
            // When a filter narrows the set, swap the total caption for a
            // "X of Y shown" caption so the user knows the table is filtered.
            if (shown === total) {
                summary.textContent = totalTpl.replace(':total', total);
            } else {
                summary.textContent = shownTpl.replace(':shown', shown).replace(':total', total);
            }
        }
        try {
            localStorage.setItem(storageKey, JSON.stringify({
                topic: topicSel.value,
                hideClosed: closedCb.checked,
                hideSpam: spamCb.checked,
            }));
        } catch {
            // ignore quota / disabled-storage
        }
    }

    topicSel.addEventListener('change', apply);
    closedCb.addEventListener('change', apply);
    spamCb.addEventListener('change', apply);
    apply();
})();
