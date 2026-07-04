// Progressive enhancement for the topic administration table's bulk bar.
// Bulk deactivate/reactivate already works without JS (the row checkboxes are
// tied to the bulk form via the HTML `form=` attribute); this only adds a
// select-all toggle, a live selected-count, and disables the bulk buttons
// while nothing is selected.
(function () {
    const bar = document.querySelector('[data-bulk-bar]');
    if (!bar) return;

    const selectAll = document.querySelector('[data-bulk-select-all]');
    const rowChecks = Array.from(document.querySelectorAll('[data-bulk-row]'));
    const countEl = bar.querySelector('[data-bulk-count]');
    const actionButtons = Array.from(bar.querySelectorAll('button[name="action"]'));
    if (rowChecks.length === 0) return;

    function selectedCount() {
        return rowChecks.filter((c) => c.checked).length;
    }

    function sync() {
        const n = selectedCount();
        if (countEl) countEl.textContent = String(n);
        actionButtons.forEach((b) => { b.disabled = n === 0; });
        if (selectAll) {
            selectAll.checked = n > 0 && n === rowChecks.length;
            selectAll.indeterminate = n > 0 && n < rowChecks.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            rowChecks.forEach((c) => { c.checked = selectAll.checked; });
            sync();
        });
    }
    rowChecks.forEach((c) => c.addEventListener('change', sync));

    sync();
})();
