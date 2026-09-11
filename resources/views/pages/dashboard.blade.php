@extends('layouts.app')

@section('title', $appTitle.' – '.__('dashboard'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('dashboard') }}</h1>
            <p class="muted">
                @php
                    // Pluralise reports and topics independently — a single
                    // trans_choice can only agree with one of the two counts.
                    $reportsStr = trans_choice('reports_count', $reports->total(), ['count' => $reports->total()]);
                    $topicsStr = trans_choice('topics_count', $topics->count(), ['count' => $topics->count()]);
                @endphp
                {{ __('reports_across_topics', ['reports' => $reportsStr, 'topics' => $topicsStr]) }}
            </p>
            {{-- $overdueCount is computed in the controller across ALL manageable
                 reports (not just the current page), so it stays accurate under
                 pagination. --}}
            @if ($overdueCount > 0 || $staleCount > 0)
                <p class="intro-signals">
                    @if ($overdueCount > 0)
                        <span class="unread-badge overdue">{{ trans_choice('overdue_summary', $overdueCount, ['count' => $overdueCount]) }}</span>
                    @endif
                    {{-- $staleCount, like $overdueCount, spans all manageable reports. --}}
                    @if ($staleCount > 0)
                        <a class="unread-badge stale" href="{{ route('dashboard', ['filters' => '1', 'only_stale' => '1']) }}">{{ trans_choice('stale_summary', $staleCount, ['count' => $staleCount, 'days' => $staleDays]) }}</a>
                    @endif
                </p>
            @endif
        </div>
    </section>
@endsection

@section('content')
    <form method="GET" action="{{ route('dashboard') }}" class="toolbar">
        {{-- Marks a deliberate filter submit so the controller can tell an
             unchecked box apart from a fresh visit (which uses the defaults). --}}
        <input type="hidden" name="filters" value="1">
        <label>
            {{ __('topic') }}:
            <select name="topic">
                <option value="">{{ __('dashboard_filter_all_topics') }}</option>
                @foreach ($topics as $t)
                    <option value="{{ $t->id }}" @selected($selectedTopic === $t->id)>{{ $t->name($lang) }}</option>
                @endforeach
            </select>
        </label>
        <label>
            <input type="checkbox" name="hide_closed" value="1" @checked($hideClosed)>
            {{ __('hide_closed') }}
        </label>
        <label>
            <input type="checkbox" name="hide_spam" value="1" @checked($hideSpam)>
            {{ __('hide_spam') }}
        </label>
        @if ($staleDays !== null)
            <label>
                <input type="checkbox" name="only_stale" value="1" @checked($onlyStale)>
                {{ __('only_stale', ['days' => $staleDays]) }}
            </label>
        @endif
        <button type="submit" class="button button-small">{{ __('apply_filters') }}</button>
        <div class="toolbar-end">
            {{-- Export mirrors the current filter selection. --}}
            <a class="button button-small button-ghost"
               href="{{ route('dashboard.export', array_filter([
                   'filters' => '1',
                   'topic' => $selectedTopic ?: null,
                   'hide_closed' => $hideClosed ? '1' : null,
                   'hide_spam' => $hideSpam ? '1' : null,
                   'only_stale' => $onlyStale ? '1' : null,
               ])) }}">{{ __('export_csv') }}</a>
        </div>
    </form>

    @if ($reports->isEmpty())
        <div class="alert alert-info">{{ $topics->isEmpty() ? __('no_reports_yet') : __('dashboard_no_matches') }}</div>
    @else
        <table class="table-cards">
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
                    <tr class="dashboard-row">
                        <td class="cell-title">#{{ $r->id }}</td>
                        <td class="cell-title">{{ $r->topic->name($lang) }}</td>
                        <td data-label="{{ __('date') }}">{{ $r->dateFmt() }}</td>
                        <td data-label="{{ __('contact') }}">{{ $r->creator ?: __('anonymous') }}</td>
                        <td class="cell-status">
                            <details class="status-menu">
                                <summary class="status-pill {{ $r->state->value }}" title="{{ __('change_status') }}">
                                    {{ $r->statusLabel() }}
                                </summary>
                                <div class="status-menu-options" role="menu">
                                    @unless ($r->state === \App\Enums\ReportState::Open)
                                        <button type="button" role="menuitem"
                                                data-status-url="{{ $statusUrl }}" data-status="open">{{ __('reopen') }}</button>
                                    @endunless
                                    @unless ($r->state === \App\Enums\ReportState::InProgress || $r->isClosed() || $r->isSpam())
                                        <button type="button" role="menuitem"
                                                data-status-url="{{ $statusUrl }}" data-status="progress">{{ __('mark_in_progress') }}</button>
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
                               href="{{ route('admin.report.show', ['topic' => $r->topic_id, 'report' => $r->id]) }}">{{ __('open') }} →</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $reports->links() }}
    @endif
@endsection
