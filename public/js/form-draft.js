// Drafts the in-progress reporter form to localStorage so an accidental
// tab close / language switch / network blip doesn't wipe a long write-up.
//
// Limitations: file inputs are intentionally NOT drafted (the browser
// security model doesn't let JS rebuild a `File` object from a string), so
// a closed tab still loses any picked uploads.
(function () {
    const form = document.querySelector('form[data-draft-key]');
    if (!form) return;

    const key = form.dataset.draftKey;
    // The "Draft restored" hint lives in the surrounding page, not inside
    // the form, so search the whole document.
    const note = document.querySelector('[data-draft-note]');
    const SAVE_DEBOUNCE_MS = 400;

    function fieldsToSerialize() {
        return Array.from(form.querySelectorAll('input, textarea, select'))
            .filter((el) => el.name && el.type !== 'hidden' && el.type !== 'file');
    }

    function collect() {
        const out = {};
        fieldsToSerialize().forEach((el) => {
            if (el.type === 'checkbox') {
                out[el.name] = el.checked;
            } else if (el.type === 'radio') {
                if (el.checked) out[el.name] = el.value;
            } else {
                out[el.name] = el.value;
            }
        });
        return out;
    }

    function restore() {
        let raw;
        try {
            raw = localStorage.getItem(key);
        } catch {
            return false;
        }
        if (!raw) return false;
        let saved;
        try {
            saved = JSON.parse(raw);
        } catch {
            return false;
        }
        if (!saved || typeof saved !== 'object') return false;

        let restored = false;
        fieldsToSerialize().forEach((el) => {
            if (!(el.name in saved)) return;
            const v = saved[el.name];
            if (el.type === 'checkbox') {
                if (typeof v === 'boolean' && el.checked !== v) {
                    el.checked = v;
                    restored = true;
                }
            } else if (el.type === 'radio') {
                if (typeof v === 'string' && el.value === v && !el.checked) {
                    el.checked = true;
                    restored = true;
                }
            } else if (typeof v === 'string' && el.value === '' && v !== '') {
                el.value = v;
                restored = true;
            }
        });
        return restored;
    }

    function saveNow() {
        try {
            localStorage.setItem(key, JSON.stringify(collect()));
        } catch {
            // Quota or disabled storage — silently give up.
        }
    }

    let saveTimer = null;
    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveNow, SAVE_DEBOUNCE_MS);
    }

    function clearDraft() {
        try {
            localStorage.removeItem(key);
        } catch {
            // ignore
        }
    }

    if (restore() && note) {
        note.hidden = false;
    }

    form.addEventListener('input', scheduleSave);
    form.addEventListener('change', scheduleSave);
    form.addEventListener('submit', () => {
        // Form posts → server redirects on success. Clear pre-emptively;
        // on a validation failure the user lands back here and old() repopulates,
        // so losing the draft is fine.
        clearDraft();
    });

    // Flush any pending debounced save before the page goes away — otherwise
    // a fast close/reload within the 400ms window wipes the draft. Use
    // `pagehide` over `beforeunload` because it fires reliably on bfcache
    // and on iOS/Safari.
    function flush() {
        if (saveTimer !== null) {
            clearTimeout(saveTimer);
            saveTimer = null;
            saveNow();
        }
    }
    window.addEventListener('pagehide', flush);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') flush();
    });
})();
