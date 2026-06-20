@extends('layouts.app')

@section('title', $appTitle.' – '.__('dashboard'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('dashboard') }}</h1>
            <p class="muted">
                {{ trans_choice('reports_across_topics', $topics->count(), ['topics' => $topics->count(), 'reports' => $reports->total()]) }}
            </p>
        </div>
    </section>
@endsection

@section('content')
    <form method="GET" action="{{ route('dashboard') }}" class="card card-soft mb-4"
          style="display: flex; gap: 1.25rem; flex-wrap: wrap; align-items: center;">
        {{-- Marks a deliberate filter submit so the controller can tell an
             unchecked box apart from a fresh visit (which uses the defaults). --}}
        <input type="hidden" name="filters" value="1">
        <label style="font-weight: 500; margin: 0; display: flex; gap: 0.4rem; align-items: center;">
            {{ __('topic') }}:
            <select name="topic" style="width: auto;">
                <option value="">{{ __('dashboard_filter_all_topics') }}</option>
                @foreach ($topics as $t)
                    <option value="{{ $t->id }}" @selected($selectedTopic === $t->id)>{{ $t->name($lang) }}</option>
                @endforeach
            </select>
        </label>
        <label style="font-weight: 500; margin: 0;">
            <input type="checkbox" name="hide_closed" value="1" @checked($hideClosed)>
            {{ __('hide_closed') }}
        </label>
        <label style="font-weight: 500; margin: 0;">
            <input type="checkbox" name="hide_spam" value="1" @checked($hideSpam)>
            {{ __('hide_spam') }}
        </label>
        <button type="submit" class="button button-small">{{ __('apply_filters') }}</button>
    </form>

    @if ($reports->isEmpty())
        <div class="alert alert-info">{{ $topics->isEmpty() ? __('no_reports_yet') : __('dashboard_no_matches') }}</div>
    @else
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
                    <tr class="dashboard-row">
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
            </tbody>
        </table>

        {{ $reports->links() }}
    @endif
@endsection
