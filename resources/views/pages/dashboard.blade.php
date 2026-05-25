@extends('layouts.app')

@section('title', $appTitle.' – '.__('dashboard'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('dashboard') }}</h1>
            <p class="muted" data-dashboard-summary
               data-template-shown="{{ __('dashboard_count_shown') }}"
               data-template-total="{{ trans_choice('reports_across_topics', $topics->count(), ['topics' => $topics->count(), 'reports' => ':total']) }}">
                {{ trans_choice('reports_across_topics', $topics->count(), ['topics' => $topics->count(), 'reports' => $reports->count()]) }}
            </p>
        </div>
    </section>
@endsection

@section('content')
    @if ($reports->isEmpty())
        <div class="alert alert-info">{{ __('no_reports_yet') }}</div>
    @else
        <div class="card card-soft mb-4" data-dashboard-filter
             style="display: flex; gap: 1.25rem; flex-wrap: wrap; align-items: center;">
            <label style="font-weight: 500; margin: 0; display: flex; gap: 0.4rem; align-items: center;">
                {{ __('topic') }}:
                <select id="filter-topic" style="width: auto;">
                    <option value="">{{ __('dashboard_filter_all_topics') }}</option>
                    @foreach ($topics as $t)
                        <option value="{{ $t->id }}">{{ $t->name($lang) }}</option>
                    @endforeach
                </select>
            </label>
            <label style="font-weight: 500; margin: 0;">
                <input type="checkbox" id="filter-hide-closed" checked>
                {{ __('hide_closed') }}
            </label>
            <label style="font-weight: 500; margin: 0;">
                <input type="checkbox" id="filter-hide-spam" checked>
                {{ __('hide_spam') }}
            </label>
        </div>

        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>{{ __('topic') }}</th>
                    <th>{{ __('date') }}</th>
                    <th>{{ __('contact') }}</th>
                    <th>{{ __('status') }}</th>
                    <th>{{ __('messages') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($reports as $r)
                    @php
                        $statusUrl = route('report.status', ['topic' => $r->topic_id, 'report' => $r->id]);
                    @endphp
                    <tr class="dashboard-row"
                        data-topic-id="{{ $r->topic_id }}"
                        data-closed="{{ $r->isClosed() ? '1' : '0' }}"
                        data-spam="{{ $r->isSpam() ? '1' : '0' }}">
                        <td>#{{ $r->id }}</td>
                        <td>{{ $r->topic->name($lang) }}</td>
                        <td>{{ $r->dateFmt() }}</td>
                        <td>{{ $r->creator ?: __('anonymous') }}</td>
                        <td>
                            <details class="status-menu">
                                <summary class="status-pill {{ $r->state->value }}" title="{{ __('change_status') }}">
                                    {{ $r->statusLabel() }}
                                </summary>
                                <div class="status-menu-options" role="menu">
                                    @unless ($r->state === \App\Enums\ReportState::Open)
                                        <button type="button" role="menuitem"
                                                data-status-url="{{ $statusUrl }}" data-status="open">{{ __('reopen') }}</button>
                                    @endunless
                                    @unless ($r->isClosed())
                                        <button type="button" role="menuitem"
                                                data-status-url="{{ $statusUrl }}" data-status="close"
                                                data-status-confirm="{{ __('confirm_close') }}">{{ __('close') }}</button>
                                    @endunless
                                    @unless ($r->isSpam())
                                        <button type="button" role="menuitem"
                                                data-status-url="{{ $statusUrl }}" data-status="spam"
                                                data-status-confirm="{{ __('confirm_spam') }}">{{ __('spam') }}</button>
                                    @endunless
                                </div>
                            </details>
                        </td>
                        <td>{{ $r->messages->count() }}</td>
                        <td class="text-right">
                            <a class="button button-small button-ghost"
                               href="{{ route('report.show', ['administratorToken' => $r->administrator_token]) }}">{{ __('open') }} →</a>
                        </td>
                    </tr>
                @endforeach
                <tr id="dashboard-empty" hidden>
                    <td colspan="7" class="muted text-center" style="padding: 1.5rem;">{{ __('dashboard_no_matches') }}</td>
                </tr>
            </tbody>
        </table>

        <script src="{{ asset('js/dashboard.js') }}" defer></script>
    @endif
@endsection
