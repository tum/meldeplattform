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
    let otrsQueues = [];
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

    // A de/en markdown editor: each language gets a textarea with a live
    // preview that mirrors exactly what the public page renders (markdown +
    // brand colours). Shared by the topic summary and Info display fields.
    // `get(lang)` / `set(lang, value)` read and write the bound model.
    function markdownField(labelText, get, set, hint) {
        const group = el('div', { className: 'form-group' }, el('label', { textContent: labelText }));

        const makeLang = (langLabel, lang) => {
            const preview = el('div', { className: 'summary-preview' });
            const update = debounce((text) => fetchPreview(text, preview), 250);
            const ta = textarea(get(lang), (v) => { set(lang, v); update(v); });
            fetchPreview(get(lang), preview);
            return el(
                'label',
                { style: 'display:block' },
                el('span', { className: 'desc', textContent: langLabel }),
                ta,
                el('span', { className: 'desc', textContent: tr.preview }),
                preview,
            );
        };

        group.append(makeLang(tr.de, 'de'), makeLang(tr.en, 'en'));
        if (hint) {
            group.append(el('span', { className: 'desc', textContent: hint }));
        }
        return group;
    }

    // Topic summary editor (markdown + brand colours, de/en with preview).
    function summaryField() {
        return markdownField(
            tr.summary,
            (lang) => topic.Summary?.[lang] ?? '',
            (lang, v) => { (topic.Summary ??= {})[lang] = v; },
            tr.summaryHint,
        );
    }

    // Info field content editor: the display-only block's formatted text,
    // stored in the field's Description and rendered exactly like a summary.
    function infoContentField(f) {
        return markdownField(
            tr.description,
            (lang) => f.Description?.[lang] ?? '',
            (lang, v) => { (f.Description ??= {})[lang] = v; },
            tr.summaryHint,
        );
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

    // OTRS queue picker: a dropdown of the live queue list when one is
    // available (avoids typos that make every TicketCreate fail), otherwise a
    // free-text field. An empty value always means "use the global default
    // queue". An existing custom value not in the fetched list is preserved as
    // its own option so editing an unrelated setting never drops it.
    function otrsQueueControl() {
        const cur = topic.Contacts.Otrs.Queue ?? '';
        if (otrsQueues.length === 0) {
            return input(cur, (v) => (topic.Contacts.Otrs.Queue = v), 'Raw');
        }
        const sel = el('select', {});
        const values = [''].concat(otrsQueues);
        if (cur && !otrsQueues.includes(cur)) values.push(cur);
        values.forEach((q) => {
            const o = el('option', { value: q, textContent: q === '' ? (tr.otrsQueueDefault || '—') : q });
            if (q === cur) o.selected = true;
            sel.append(o);
        });
        sel.addEventListener('change', (e) => (topic.Contacts.Otrs.Queue = e.target.value));
        return sel;
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

            if (f.Type === 'info') {
                // Display-only block: just the formatted de/en content. No
                // label, choices, or required flag — it collects no answer.
                card.append(infoContentField(f));
            } else {
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
            }

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
        body.append(el('h3', { textContent: tr.channels }));
        if (tr.channelsDesc) {
            body.append(el('p', { className: 'muted', textContent: tr.channelsDesc }));
        }

        // Email channel (legacy `topics.email` column).
        const emailGroup = el('div', { className: 'form-group' }, el('label', { textContent: tr.contactEmail }));
        emailGroup.append(input(topic.Email ?? '', (v) => (topic.Email = v), 'it-sec@tum.de', 'email'));
        body.append(emailGroup);

        // Webhook channel (contacts.webhook.target).
        const webhookGroup = el('div', { className: 'form-group' }, el('label', { textContent: tr.webhookUrl }));
        webhookGroup.append(input(topic.Contacts.Webhook ?? '', (v) => (topic.Contacts.Webhook = v), 'https://…', 'url'));
        if (tr.webhookDesc) {
            webhookGroup.append(el('span', { className: 'desc', textContent: tr.webhookDesc }));
        }
        body.append(webhookGroup);

        // OTRS channel (contacts.otrs). The queue input only matters when
        // enabled, so it is hidden until the box is checked; an empty queue
        // means "use the global default".
        const otrsLabel = el('label', { className: 'form-group', style: 'display:block;' });
        const otrsCb = el('input', { type: 'checkbox', checked: !!topic.Contacts.Otrs.Enabled });
        otrsLabel.append(otrsCb, document.createTextNode(' ' + tr.otrsEnable));
        body.append(otrsLabel);

        const otrsQueueGroup = el('div', { className: 'form-group' }, el('label', { textContent: tr.otrsQueue }));
        otrsQueueGroup.append(otrsQueueControl());
        if (tr.otrsQueueDesc) {
            otrsQueueGroup.append(el('span', { className: 'desc', textContent: tr.otrsQueueDesc }));
        }
        body.append(otrsQueueGroup);

        const syncOtrs = () => { otrsQueueGroup.style.display = topic.Contacts.Otrs.Enabled ? '' : 'none'; };
        otrsCb.addEventListener('change', (e) => {
            topic.Contacts.Otrs.Enabled = e.target.checked;
            syncOtrs();
        });
        syncOtrs();
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
            topic.Contacts ??= {};
            topic.Contacts.Otrs ??= { Enabled: false, Queue: '' };
            topic.Fields ??= [];
            topic.Admins ??= [];
            render();
        });

    // Pull the live OTRS queue list (if the backend has it configured) so the
    // OTRS queue field can be a dropdown. Independent of the topic load; when it
    // arrives we re-render so the picker upgrades from free text in place.
    fetch('/api/otrs/queues', { credentials: 'same-origin' })
        .then((r) => (r.ok ? r.json() : { queues: [] }))
        .then((j) => {
            otrsQueues = Array.isArray(j.queues) ? j.queues : [];
            if (topic && otrsQueues.length > 0) render();
        })
        .catch(() => {});

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
