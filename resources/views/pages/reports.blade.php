@extends('layouts.app')

@section('title', $appTitle.' – '.__('reports'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('reports') }}: {{ $topic->name($lang) }}</h1>
            <p class="muted">{{ $reports->count() }} {{ __('reports') }}</p>
        </div>
    </section>
@endsection

@section('content')
    <div class="card card-soft mb-4" data-reports-filter data-topic-id="{{ $topic->id }}"
         style="display: flex; gap: 1.25rem; flex-wrap: wrap; align-items: center;">
        <label style="font-weight: 500; margin: 0;">
            <input type="checkbox" id="hide-closed" checked>
            {{ __('hide_closed') }}
        </label>
        <label style="font-weight: 500; margin: 0;">
            <input type="checkbox" id="hide-spam" checked>
            {{ __('hide_spam') }}
        </label>
    </div>

    <div class="bulk-bar" data-bulk-bar hidden
         data-bulk-url="{{ route('report.status.bulk', ['topic' => $topic->id]) }}"
         data-confirm-close="{{ __('confirm_close_bulk') }}"
         data-confirm-spam="{{ __('confirm_spam_bulk') }}">
        <span class="bulk-bar-count" data-bulk-count>0 {{ __('selected') }}</span>
        <div class="bulk-bar-actions">
            <button type="button" class="button button-small" data-bulk-status="open">{{ __('reopen') }}</button>
            <button type="button" class="button button-small" data-bulk-status="close">{{ __('close') }}</button>
            <button type="button" class="button button-small button-danger" data-bulk-status="spam">{{ __('spam') }}</button>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th><input type="checkbox" data-bulk-select-all aria-label="{{ __('select_all') }}"></th>
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
                    <td><input type="checkbox" data-bulk-row value="{{ $r->id }}" aria-label="{{ __('select_row') }}"></td>
                    <td>#{{ $r->id }}</td>
                    <td>{{ $r->dateFmt() }}</td>
                    <td>{{ $r->creator ?: __('anonymous') }}</td>
                    <td>
                        @php
                            $statusUrl = route('report.status', ['topic' => $topic->id, 'report' => $r->id]);
                        @endphp
                        <details class="status-menu">
                            <summary class="status-pill {{ $r->state->value }}" title="{{ __('change_status') }}">
                                {{ $r->statusLabel() }}
                            </summary>
                            <div class="status-menu-options" role="menu">
                                @unless ($r->state === \App\Enums\ReportState::Open)
                                    <button type="button" role="menuitem"
                                            data-status-url="{{ $statusUrl }}" data-status="open">
                                        {{ __('reopen') }}
                                    </button>
                                @endunless
                                @unless ($r->state === \App\Enums\ReportState::InProgress || $r->isClosed() || $r->isSpam())
                                    <button type="button" role="menuitem"
                                            data-status-url="{{ $statusUrl }}" data-status="progress">
                                        {{ __('mark_in_progress') }}
                                    </button>
                                @endunless
                                @unless ($r->isClosed())
                                    <button type="button" role="menuitem"
                                            data-status-url="{{ $statusUrl }}" data-status="close"
                                            data-status-confirm="{{ __('confirm_close') }}">
                                        {{ __('close') }}
                                    </button>
                                @endunless
                                @unless ($r->isSpam())
                                    <button type="button" role="menuitem"
                                            data-status-url="{{ $statusUrl }}" data-status="spam"
                                            data-status-confirm="{{ __('confirm_spam') }}">
                                        {{ __('spam') }}
                                    </button>
                                @endunless
                            </div>
                        </details>
                        @if ($r->isAcknowledgementOverdue())
                            <span class="unread-badge overdue" title="{{ __('ack_overdue') }}">{{ __('ack_overdue') }}</span>
                        @endif
                        @if ($r->isFeedbackOverdue())
                            <span class="unread-badge overdue" title="{{ __('feedback_overdue') }}">{{ __('feedback_overdue') }}</span>
                        @endif
                    </td>
                    <td>{{ $r->messages->count() }}</td>
                    <td class="text-right">
                        <a class="button button-small button-ghost"
                           href="{{ route('report.show', ['administratorToken' => $r->administrator_token]) }}">{{ __('open') }} →</a>
                    </td>
                </tr>
            @endforeach
            @if ($reports->isEmpty())
                <tr><td colspan="7" class="muted text-center" style="padding: 2rem;">—</td></tr>
            @endif
        </tbody>
    </table>

    <script src="{{ asset('js/reports.js') }}" defer></script>
@endsection
