// "Copy report link" button on the reporter view of /report. Uses the
// Clipboard API when available, falls back to <input>.select() + execCommand
// for older browsers.
(function () {
    const button = document.querySelector('[data-copy-button]');
    const input = document.querySelector('[data-copy-link]');
    if (!button || !input) return;

    const originalLabel = button.textContent;
    const okLabel = button.dataset.okLabel || '✓ ' + originalLabel;

    button.addEventListener('click', async () => {
        try {
            if (navigator.clipboard) {
                await navigator.clipboard.writeText(input.value);
            } else {
                input.select();
                document.execCommand('copy');
                input.setSelectionRange(0, 0);
            }
            button.textContent = okLabel;
            setTimeout(() => { button.textContent = originalLabel; }, 1800);
        } catch {
            input.select();
        }
    });
})();
