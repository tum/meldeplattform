@extends('layouts.app')

@section('title', $appTitle.' – '.__('reports'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="/" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('reports') }}: {{ $topic->name($lang) }}</h1>
            <p class="muted">{{ $reports->count() }} {{ __('reports') }}</p>
        </div>
    </section>
@endsection

@section('content')
    <div class="card card-soft mb-4" style="display: flex; gap: 1.25rem; flex-wrap: wrap; align-items: center;">
        <label style="font-weight: 500; margin: 0;">
            <input type="checkbox" id="hide-closed" checked>
            {{ __('hide_closed') }}
        </label>
        <label style="font-weight: 500; margin: 0;">
            <input type="checkbox" id="hide-spam" checked>
            {{ __('hide_spam') }}
        </label>
    </div>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>{{ __('date') }}</th>
                <th>{{ __('contact') }}</th>
                <th>{{ __('status') }}</th>
                <th>{{ __('messages') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @foreach ($reports as $r)
                <tr class="report-row"
                    data-closed="{{ $r->isClosed() ? '1' : '0' }}"
                    data-spam="{{ $r->isSpam() ? '1' : '0' }}">
                    <td>#{{ $r->id }}</td>
                    <td>{{ $r->dateFmt() }}</td>
                    <td>{{ $r->creator ?: __('anonymous') }}</td>
                    <td>
                        <span class="status-pill {{ strtolower($r->statusLabel()) }}">
                            {{ $r->statusLabel() }}
                        </span>
                    </td>
                    <td>{{ $r->messages->count() }}</td>
                    <td class="text-right">
                        <a class="button button-small button-ghost"
                           href="/report?administratorToken={{ $r->administrator_token }}">{{ __('open') }} →</a>
                    </td>
                </tr>
            @endforeach
            @if ($reports->isEmpty())
                <tr><td colspan="6" class="muted text-center" style="padding: 2rem;">—</td></tr>
            @endif
        </tbody>
    </table>

    <script>
        (function () {
            const closedCb = document.getElementById('hide-closed');
            const spamCb = document.getElementById('hide-spam');
            const rows = document.querySelectorAll('.report-row');
            function apply() {
                rows.forEach(r => {
                    const closed = r.dataset.closed === '1';
                    const spam = r.dataset.spam === '1';
                    const hide = (closedCb.checked && closed) || (spamCb.checked && spam);
                    r.style.display = hide ? 'none' : '';
                });
            }
            closedCb.addEventListener('change', apply);
            spamCb.addEventListener('change', apply);
            apply();
        })();
    </script>
@endsection
