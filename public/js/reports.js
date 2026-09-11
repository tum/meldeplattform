(function () {
    const wrap = document.querySelector('[data-reports-filter]');
    const closedCb = document.getElementById('hide-closed');
    const spamCb = document.getElementById('hide-spam');
    const rows = document.querySelectorAll('.report-row');
    const totalEl = document.querySelector('[data-report-count]');
    if (!wrap || !closedCb || !spamCb) return;

    // The intro line is server-rendered with the topic's total. When the
    // filters hide rows it would read "7 reports" above a two-row table, so
    // rewrite it to "2 of 7 reports" from the data-showing template.
    const totalAllText = totalEl ? totalEl.textContent : '';
    function syncTotal(visible) {
        if (!totalEl) return;
        const total = Number(totalEl.dataset.total);
        totalEl.textContent = visible === total
            ? totalAllText
            : (totalEl.dataset.showing || '').replace(':visible', String(visible));
    }

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
        let visible = 0;
        rows.forEach((r) => {
            const closed = r.dataset.closed === '1';
            const spam = r.dataset.spam === '1';
            const hide = (closedCb.checked && closed) || (spamCb.checked && spam);
            r.style.display = hide ? 'none' : '';
            if (!hide) visible++;
        });
        syncTotal(visible);
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

    // --- Bulk actions -----------------------------------------------------
    const bulkBar = document.querySelector('[data-bulk-bar]');
    const selectAll = document.querySelector('[data-bulk-select-all]');
    const rowChecks = document.querySelectorAll('[data-bulk-row]');
    if (!bulkBar || !selectAll || rowChecks.length === 0) return;

    const bulkUrl = bulkBar.dataset.bulkUrl;
    const countEl = bulkBar.querySelector('[data-bulk-count]');
    const baseCountText = countEl ? countEl.textContent.replace(/^\d+\s*/, '') : '';

    function selected() {
        return Array.from(rowChecks).filter((c) => c.checked && c.closest('.report-row').style.display !== 'none');
    }

    function syncBar() {
        const n = selected().length;
        bulkBar.hidden = n === 0;
        if (countEl) countEl.textContent = n + ' ' + baseCountText;
        // Reflect indeterminate state on the header checkbox.
        const total = Array.from(rowChecks).filter((c) => c.closest('.report-row').style.display !== 'none').length;
        selectAll.checked = n > 0 && n === total;
        selectAll.indeterminate = n > 0 && n < total;
    }

    selectAll.addEventListener('change', () => {
        rowChecks.forEach((c) => {
            if (c.closest('.report-row').style.display === 'none') return;
            c.checked = selectAll.checked;
        });
        syncBar();
    });
    rowChecks.forEach((c) => c.addEventListener('change', syncBar));
    // Re-sync when filters hide/show rows so the count tracks visible state.
    closedCb.addEventListener('change', syncBar);
    spamCb.addEventListener('change', syncBar);

    bulkBar.querySelectorAll('[data-bulk-status]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const status = btn.dataset.bulkStatus;
            const ids = selected().map((c) => Number(c.value));
            if (ids.length === 0) return;

            const confirmText = status === 'close' ? bulkBar.dataset.confirmClose
                : status === 'spam' ? bulkBar.dataset.confirmSpam
                : null;
            if (confirmText && !window.confirm(confirmText)) return;

            window.md.postJson(bulkUrl, { ids, s: status }, { reload: true }).catch((err) => {
                alert('Request failed: ' + err.message);
            });
        });
    });

    syncBar();
})();
