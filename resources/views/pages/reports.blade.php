@extends('layouts.app')

@section('title', $appTitle.' – '.__('reports'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('reports') }}: {{ $topic->name($lang) }}</h1>
            @php $total = $reports->count(); @endphp
            {{-- reports.js hides closed/spam rows client-side and rewrites this
                 line to "X of Y" whenever the visible count differs from the
                 total. The template keeps the paginator-style :visible token
                 for JS; only :total is filled in here. --}}
            <p class="muted" data-report-count data-total="{{ $total }}"
               data-showing="{{ __('reports_showing', ['total' => trans_choice('reports_count', $total, ['count' => $total])]) }}">{{ trans_choice('reports_count', $total, ['count' => $total]) }}</p>
        </div>
    </section>
@endsection

@section('content')
    <div class="toolbar" data-reports-filter data-topic-id="{{ $topic->id }}">
        <label>
            <input type="checkbox" id="hide-closed" checked>
            {{ __('hide_closed') }}
        </label>
        <label>
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

    <table class="table-cards">
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
                    <td class="cell-select"><input type="checkbox" data-bulk-row value="{{ $r->id }}" aria-label="{{ __('select_row') }}"></td>
                    <td class="cell-title">#{{ $r->id }}</td>
                    <td data-label="{{ __('date') }}">{{ $r->dateFmt() }}</td>
                    <td data-label="{{ __('contact') }}">{{ $r->creator ?: __('anonymous') }}</td>
                    <td class="cell-status">
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
                        @if (($staleFor = $r->staleForDays()) !== null)
                            <span class="unread-badge stale" title="{{ __('stale_hint') }}">{{ __('stale_badge', ['days' => $staleFor]) }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('messages') }}">{{ $r->messages->count() }}</td>
                    <td class="text-right cell-actions">
                        <a class="button button-small button-ghost"
                           href="{{ route('admin.report.show', ['topic' => $topic->id, 'report' => $r->id]) }}">{{ __('open') }} →</a>
                    </td>
                </tr>
            @endforeach
            @if ($reports->isEmpty())
                <tr><td colspan="7" class="table-empty">{{ __('reports_none') }}</td></tr>
            @endif
        </tbody>
    </table>

    <script src="@asset('js/reports.js')" defer></script>
@endsection
