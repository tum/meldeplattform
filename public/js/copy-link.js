// "Copy report link" button on the reporter view of /report. Uses the
// Clipboard API when available, falls back to <input>.select() +
// execCommand for older browsers and for contexts where writeText is
// blocked (non-HTTPS, missing permission, embedded iframe).
(function () {
    const button = document.querySelector('[data-copy-button]');
    const input = document.querySelector('[data-copy-link]');
    if (!button || !input) return;

    const originalLabel = button.textContent;
    const okLabel = button.dataset.okLabel || '✓ ' + originalLabel;

    function selectInput() {
        input.focus();
        input.select();
        input.setSelectionRange(0, input.value.length);
    }

    function feedback(label) {
        button.textContent = label;
        setTimeout(() => { button.textContent = originalLabel; }, 1800);
    }

    button.addEventListener('click', async () => {
        // Best path: async Clipboard API. Falls through to execCommand on
        // browsers that block writeText (no perm / insecure context).
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(input.value);
                feedback(okLabel);
                return;
            } catch {
                /* fall through */
            }
        }
        // Legacy fallback: select the input and let the user Cmd/Ctrl+C,
        // and try execCommand('copy') which works in most older engines.
        selectInput();
        let copied = false;
        try { copied = document.execCommand('copy'); } catch { /* ignore */ }
        feedback(copied ? okLabel : originalLabel);
        // Leave the value selected so the user can finish with a keyboard shortcut.
    });
})();
