(function () {
    const bootstrapEl = document.getElementById('new-topic-bootstrap');
    if (!bootstrapEl) return;

    const bootstrap = JSON.parse(bootstrapEl.textContent || '{}');
    const topicID = bootstrap.topicID | 0;
    const tr = bootstrap.tr || {};
    const fieldTypes = Array.isArray(bootstrap.fieldTypes) && bootstrap.fieldTypes.length > 0
        ? bootstrap.fieldTypes
        : [{ value: 'text', label: 'text' }];

    const defaultField = () => ({
        ID: 0,
        Name: { de: '', en: '' },
        Description: { de: '', en: '' },
        Type: 'text',
        Required: false,
        Choices: [],
    });

    let topic = null;
    const body = document.getElementById('topic-form-body');
    const statusEl = document.getElementById('save-status');

    function el(tag, props = {}, ...children) {
        const n = document.createElement(tag);
        Object.assign(n, props);
        if (props.attrs) Object.entries(props.attrs).forEach(([k, v]) => n.setAttribute(k, v));
        children.flat().forEach((c) => n.append(c));
        return n;
    }

    function input(value, onInput, placeholder = '', type = 'text') {
        const i = el('input', { type, value, placeholder });
        i.addEventListener('input', (e) => onInput(e.target.value));
        return i;
    }

    function textarea(value, onInput, placeholder = '') {
        const t = el('textarea', { value, placeholder, rows: 5 });
        t.addEventListener('input', (e) => onInput(e.target.value));
        return t;
    }

    function debounce(fn, ms) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), ms);
        };
    }

    // Render `text` through the server-side summary sanitiser and drop the
    // resulting (already-sanitised) HTML into `target`. Empty input clears the
    // box without a round-trip.
    function fetchPreview(text, target) {
        if (!text.trim()) {
            target.innerHTML = '';
            return;
        }
        fetch('/api/topic/summary-preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ text }),
        })
            .then((r) => (r.ok ? r.json() : { html: '' }))
            .then((j) => { target.innerHTML = j.html || ''; })
            .catch(() => {});
    }

    // Summary editor: a de/en textarea pair, each with a live preview that
    // mirrors exactly what the public page renders (markdown + brand colours).
    function summaryField() {
        const group = el('div', { className: 'form-group' }, el('label', { textContent: tr.summary }));

        const makeLang = (langLabel, getVal, setVal) => {
            const preview = el('div', { className: 'summary-preview' });
            const update = debounce((text) => fetchPreview(text, preview), 250);
            const ta = textarea(getVal(), (v) => { setVal(v); update(v); });
            fetchPreview(getVal(), preview);
            return el(
                'label',
                { style: 'display:block' },
                el('span', { className: 'desc', textContent: langLabel }),
                ta,
                el('span', { className: 'desc', textContent: tr.preview }),
                preview,
            );
        };

        group.append(
            makeLang(tr.de, () => topic.Summary?.de ?? '', (v) => (topic.Summary.de = v)),
            makeLang(tr.en, () => topic.Summary?.en ?? '', (v) => (topic.Summary.en = v)),
        );
        if (tr.summaryHint) {
            group.append(el('span', { className: 'desc', textContent: tr.summaryHint }));
        }
        return group;
    }

    function langRow(labelText, deVal, enVal, onDe, onEn, placeholder = {}) {
        return el(
            'div',
            { className: 'form-group' },
            el('label', { textContent: labelText }),
            el(
                'label',
                { style: 'display:block' },
                el('span', { className: 'desc', textContent: tr.de }),
                input(deVal, onDe, placeholder.de ?? ''),
            ),
            el(
                'label',
                { style: 'display:block' },
                el('span', { className: 'desc', textContent: tr.en }),
                input(enVal, onEn, placeholder.en ?? ''),
            ),
        );
    }

    function render() {
        body.innerHTML = '';
        if (!topic) {
            body.append(el('p', { className: 'muted', textContent: 'Loading…' }));
            return;
        }

        body.append(el('h3', { textContent: tr.general }));
        body.append(
            langRow(
                tr.name,
                topic.Name?.de ?? '',
                topic.Name?.en ?? '',
                (v) => (topic.Name.de = v),
                (v) => (topic.Name.en = v),
                { de: 'IT-Sicherheit', en: 'IT-Security' },
            ),
        );
        body.append(summaryField());

        const reqLoginLabel = el('label', { className: 'form-group', style: 'display:block;' });
        const reqLoginCb = el('input', { type: 'checkbox', checked: !!topic.RequireLogin });
        reqLoginCb.addEventListener('change', (e) => (topic.RequireLogin = e.target.checked));
        reqLoginLabel.append(reqLoginCb, document.createTextNode(' ' + tr.requireLogin));
        body.append(reqLoginLabel);
        if (tr.requireLoginDesc) {
            body.append(el('p', { className: 'muted', style: 'margin-top:-.5rem;font-size:.875rem;', textContent: tr.requireLoginDesc }));
        }

        const retGroup = el('div', { className: 'form-group' }, el('label', { textContent: tr.retention }));
        retGroup.append(
            input(
                topic.RetentionDays ?? '',
                (v) => {
                    const n = parseInt(v, 10);
                    topic.RetentionDays = Number.isFinite(n) && n > 0 ? n : null;
                },
                '',
                'number',
            ),
        );
        if (tr.retentionDesc) {
            retGroup.append(el('span', { className: 'desc', textContent: tr.retentionDesc }));
        }
        body.append(retGroup);

        body.append(el('hr'));
        body.append(el('h3', { textContent: tr.questions }));

        topic.Fields = topic.Fields || [];
        topic.Fields.forEach((f, i) => {
            const card = el('div', { className: 'card' });

            const typeLabel = el('label', { textContent: tr.type });
            const sel = el('select', {});
            fieldTypes.forEach((t) => {
                const o = el('option', { value: t.value, textContent: t.label });
                if (f.Type === t.value) o.selected = true;
                sel.append(o);
            });
            sel.addEventListener('change', (e) => {
                f.Type = e.target.value;
                render();
            });
            card.append(typeLabel, sel);

            card.append(
                langRow(
                    tr.name,
                    f.Name.de,
                    f.Name.en,
                    (v) => (f.Name.de = v),
                    (v) => (f.Name.en = v),
                ),
            );
            card.append(
                langRow(
                    tr.description,
                    f.Description?.de ?? '',
                    f.Description?.en ?? '',
                    (v) => ((f.Description ??= {}).de = v),
                    (v) => ((f.Description ??= {}).en = v),
                ),
            );

            if (f.Type === 'select') {
                const group = el('div', { className: 'form-group' }, el('label', { textContent: tr.selectOpts }));
                (f.Choices ?? []).forEach((c, ci) => {
                    const row = el(
                        'div',
                        { style: 'display:flex;gap:.4rem;margin-bottom:.35rem;' },
                        input(c, (v) => (f.Choices[ci] = v)),
                        el('button', {
                            type: 'button',
                            className: 'button button-small button-danger',
                            textContent: '×',
                            onclick: () => {
                                f.Choices.splice(ci, 1);
                                render();
                            },
                        }),
                    );
                    group.append(row);
                });
                group.append(
                    el('button', {
                        type: 'button',
                        className: 'button button-small button-ghost',
                        textContent: tr.addOption,
                        onclick: () => {
                            (f.Choices ??= []).push('');
                            render();
                        },
                    }),
                );
                card.append(group);
            }

            const reqLabel = el('label', {});
            const cb = el('input', { type: 'checkbox', checked: !!f.Required });
            cb.addEventListener('change', (e) => (f.Required = e.target.checked));
            reqLabel.append(cb, document.createTextNode(' ' + tr.required));
            card.append(reqLabel);

            card.append(
                el(
                    'div',
                    { className: 'mt-2' },
                    el('button', {
                        type: 'button',
                        className: 'button button-small button-danger',
                        textContent: tr.delete,
                        onclick: () => {
                            topic.Fields.splice(i, 1);
                            render();
                        },
                    }),
                ),
            );

            body.append(card);
        });

        body.append(
            el('button', {
                type: 'button',
                className: 'button button-ghost',
                textContent: tr.addField,
                onclick: () => {
                    topic.Fields.push(defaultField());
                    render();
                },
            }),
        );

        body.append(el('hr'));
        body.append(el('h3', { textContent: tr.admins }));
        body.append(el('p', { className: 'muted', textContent: tr.admins_desc }));
        topic.Admins = topic.Admins || [];
        topic.Admins.forEach((a, i) => {
            const row = el(
                'div',
                { style: 'display:flex;gap:.4rem;margin-bottom:.4rem;' },
                input(a.UserID ?? '', (v) => (a.UserID = v), 'ge42tum'),
                el('button', {
                    type: 'button',
                    className: 'button button-small button-danger',
                    textContent: '×',
                    onclick: () => {
                        topic.Admins.splice(i, 1);
                        render();
                    },
                }),
            );
            body.append(row);
        });
        body.append(
            el('button', {
                type: 'button',
                className: 'button button-ghost',
                textContent: tr.addAdmin,
                onclick: () => {
                    topic.Admins.push({ ID: 0, UserID: '' });
                    render();
                },
            }),
        );

        body.append(el('hr'));
        body.append(el('label', { textContent: tr.contactEmail }));
        body.append(input(topic.Email ?? '', (v) => (topic.Email = v), 'it-sec@tum.de', 'email'));
    }

    const loadUrl = topicID > 0 ? `/api/topic/${topicID}` : '/api/topic/new';
    fetch(loadUrl, { credentials: 'same-origin' })
        .then((r) => r.json())
        .then((t) => {
            topic = t;
            topic.Name ??= { de: '', en: '' };
            topic.Summary ??= { de: '', en: '' };
            topic.RequireLogin ??= false;
            topic.RetentionDays ??= null;
            topic.Fields ??= [];
            topic.Admins ??= [];
            render();
        });

    document.getElementById('topic-form').addEventListener('submit', (e) => {
        e.preventDefault();
        statusEl.textContent = '…';
        const targetId = topic.ID || topicID;
        const saveUrl = targetId > 0 ? `/api/topic/${targetId}` : '/api/topic';
        fetch(saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
            },
            credentials: 'same-origin',
            body: JSON.stringify(topic),
        })
            .then((r) => {
                statusEl.textContent = r.ok ? tr.savedOk : tr.savedErr;
                statusEl.style.color = r.ok ? 'var(--tum-green)' : 'var(--tum-red)';
                if (r.ok) return r.json().then((j) => {
                    if (j.ID) topic.ID = j.ID;
                });
            })
            .catch(() => {
                statusEl.textContent = tr.savedErr;
                statusEl.style.color = 'var(--tum-red)';
            });
    });
})();
