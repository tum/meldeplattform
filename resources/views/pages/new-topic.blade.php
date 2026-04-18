@extends('layouts.app')

@section('title', $appTitle.' – '.__('create_topic'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="/" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('create_topic') }}</h1>
            <p>{{ __('create_topic_summary') }}</p>
        </div>
    </section>
@endsection

@section('content')
    <form id="topic-form" class="card">
        @csrf
        <div id="topic-form-body">
            <p class="muted">Loading…</p>
        </div>

        <hr>
        <div class="flex-between">
            <a class="button button-ghost" href="/">{{ __('back') }}</a>
            <div>
                <span id="save-status" class="muted" style="margin-right: 1rem;"></span>
                <button type="submit">{{ __('create_topic') }}</button>
            </div>
        </div>
    </form>

    <script>
        const topicID = {{ (int) $topicID }};
        const tr = {
            general: @json(__('general')),
            questions: @json(__('questions')),
            admins: @json(__('admins')),
            admins_desc: @json(__('admins_desc')),
            contactEmail: @json(__('contactEmail')),
            summary: @json(__('summary')),
            de: @json(__('german')),
            en: @json(__('english')),
            type: @json(__('type')),
            description: @json(__('description')),
            required: @json(__('required')),
            delete: @json(__('delete')),
            addField: @json(__('add_field')),
            addAdmin: @json(__('add_admin')),
            addOption: @json(__('add_option')),
            selectOpts: @json(__('select_options_label')),
            savedOk: @json(__('topic_saved')),
            savedErr: @json(__('topic_saved_error')),
            name: 'Name',
        };

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
            children.flat().forEach(c => n.append(c));
            return n;
        }
        function input(value, onInput, placeholder = '', type = 'text') {
            const i = el('input', { type, value, placeholder });
            i.addEventListener('input', e => onInput(e.target.value));
            return i;
        }
        function langRow(labelText, deVal, enVal, onDe, onEn, placeholder = {}) {
            return el('div', { className: 'form-group' },
                el('label', { textContent: labelText }),
                el('label', { style: 'display:block' },
                    el('span', { className: 'desc', textContent: tr.de }),
                    input(deVal, onDe, placeholder.de ?? '')),
                el('label', { style: 'display:block' },
                    el('span', { className: 'desc', textContent: tr.en }),
                    input(enVal, onEn, placeholder.en ?? '')),
            );
        }

        function render() {
            body.innerHTML = '';
            if (!topic) {
                body.append(el('p', { className: 'muted', textContent: 'Loading…' }));
                return;
            }

            body.append(el('h3', { textContent: tr.general }));
            body.append(langRow(tr.name,
                topic.Name?.de ?? '', topic.Name?.en ?? '',
                v => topic.Name.de = v, v => topic.Name.en = v,
                { de: 'IT-Sicherheit', en: 'IT-Security' }));
            body.append(langRow(tr.summary,
                topic.Summary?.de ?? '', topic.Summary?.en ?? '',
                v => topic.Summary.de = v, v => topic.Summary.en = v));

            body.append(el('hr'));
            body.append(el('h3', { textContent: tr.questions }));

            topic.Fields = topic.Fields || [];
            topic.Fields.forEach((f, i) => {
                const card = el('div', { className: 'card' });

                // Type selector
                const typeLabel = el('label', { textContent: tr.type });
                const sel = el('select', {});
                ['text', 'textarea', 'file', 'files', 'select', 'email', 'date'].forEach(t => {
                    const o = el('option', { value: t, textContent: t });
                    if (f.Type === t) o.selected = true;
                    sel.append(o);
                });
                sel.addEventListener('change', e => { f.Type = e.target.value; render(); });
                card.append(typeLabel, sel);

                card.append(langRow(tr.name,
                    f.Name.de, f.Name.en,
                    v => f.Name.de = v, v => f.Name.en = v));
                card.append(langRow(tr.description,
                    f.Description?.de ?? '', f.Description?.en ?? '',
                    v => (f.Description ??= {}).de = v,
                    v => (f.Description ??= {}).en = v));

                if (f.Type === 'select') {
                    const group = el('div', { className: 'form-group' },
                        el('label', { textContent: tr.selectOpts }));
                    (f.Choices ?? []).forEach((c, ci) => {
                        const row = el('div', { style: 'display:flex;gap:.4rem;margin-bottom:.35rem;' },
                            input(c, v => f.Choices[ci] = v),
                            el('button', {
                                type: 'button',
                                className: 'button button-small button-danger',
                                textContent: '×',
                                onclick: () => { f.Choices.splice(ci, 1); render(); },
                            }));
                        group.append(row);
                    });
                    group.append(el('button', {
                        type: 'button',
                        className: 'button button-small button-ghost',
                        textContent: tr.addOption,
                        onclick: () => { (f.Choices ??= []).push(''); render(); },
                    }));
                    card.append(group);
                }

                const reqLabel = el('label', {});
                const cb = el('input', { type: 'checkbox', checked: !!f.Required });
                cb.addEventListener('change', e => f.Required = e.target.checked);
                reqLabel.append(cb, document.createTextNode(' ' + tr.required));
                card.append(reqLabel);

                card.append(el('div', { className: 'mt-2' },
                    el('button', {
                        type: 'button',
                        className: 'button button-small button-danger',
                        textContent: tr.delete,
                        onclick: () => { topic.Fields.splice(i, 1); render(); },
                    })));

                body.append(card);
            });

            body.append(el('button', {
                type: 'button',
                className: 'button button-ghost',
                textContent: tr.addField,
                onclick: () => { topic.Fields.push(defaultField()); render(); },
            }));

            body.append(el('hr'));
            body.append(el('h3', { textContent: tr.admins }));
            body.append(el('p', { className: 'muted', textContent: tr.admins_desc }));
            topic.Admins = topic.Admins || [];
            topic.Admins.forEach((a, i) => {
                const row = el('div', { style: 'display:flex;gap:.4rem;margin-bottom:.4rem;' },
                    input(a.UserID ?? '', v => a.UserID = v, 'ge42tum'),
                    el('button', {
                        type: 'button',
                        className: 'button button-small button-danger',
                        textContent: '×',
                        onclick: () => { topic.Admins.splice(i, 1); render(); },
                    }));
                body.append(row);
            });
            body.append(el('button', {
                type: 'button',
                className: 'button button-ghost',
                textContent: tr.addAdmin,
                onclick: () => { topic.Admins.push({ ID: 0, UserID: '' }); render(); },
            }));

            body.append(el('hr'));
            body.append(el('label', { textContent: tr.contactEmail }));
            body.append(input(topic.Email ?? '', v => topic.Email = v, 'it-sec@tum.de', 'email'));
        }

        fetch(`/api/topic/${topicID}`, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(t => {
                topic = t;
                topic.Name ??= { de: '', en: '' };
                topic.Summary ??= { de: '', en: '' };
                topic.Fields ??= [];
                topic.Admins ??= [];
                render();
            });

        document.getElementById('topic-form').addEventListener('submit', (e) => {
            e.preventDefault();
            statusEl.textContent = '…';
            fetch(`/api/topic/${topic.ID || topicID}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                credentials: 'same-origin',
                body: JSON.stringify(topic),
            }).then(r => {
                statusEl.textContent = r.ok ? tr.savedOk : tr.savedErr;
                statusEl.style.color = r.ok ? 'var(--tum-green)' : 'var(--tum-red)';
                if (r.ok) return r.json().then(j => { if (j.ID) topic.ID = j.ID; });
            }).catch(() => {
                statusEl.textContent = tr.savedErr;
                statusEl.style.color = 'var(--tum-red)';
            });
        });
    </script>
@endsection
