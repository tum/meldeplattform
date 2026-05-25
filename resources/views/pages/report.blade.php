@extends('layouts.app')

@section('title', $appTitle.' – '.__('report'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            @if ($isAdministrator)
                <a href="{{ route('topic.reports', $report->topic_id) }}" class="crumb">{{ __('reports') }}</a>
            @else
                <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            @endif
            <h1>
                {{ __('report') }} #{{ $report->id }}
                <span class="muted" style="font-weight: 400; font-size: 0.55em; margin-left: 0.5rem;">·
                    {{ $report->topic->name($lang) }}</span>
            </h1>
            <p class="muted">
                {{ $isAdministrator ? __('report_view_admin') : __('report_view_reporter') }}
                · {{ $report->dateFmt() }}
            </p>
        </div>
    </section>
@endsection

@section('content')
    @if (! $isAdministrator)
        <div class="alert alert-warning">
            <div>{{ __('reportOpened') }}</div>
            <div class="copy-link" style="margin-top: 0.75rem; display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                <input type="text" readonly value="{{ url()->current().'?reporterToken='.$report->reporter_token }}"
                       data-copy-link aria-label="{{ __('report_link_label') }}"
                       style="flex: 1; min-width: 18rem; font-family: monospace; font-size: 0.9rem;">
                <button type="button" class="button button-small" data-copy-button>{{ __('copy_link') }}</button>
            </div>
        </div>
        <script src="{{ asset('js/copy-link.js') }}" defer></script>
    @endif

    @if ($report->creator)
        <p class="muted">{{ $report->creator }}</p>
    @endif

    <div class="thread">
        @foreach ($report->messages as $m)
            @php
                $isMine = ($isAdministrator === $m->is_admin);
                $cls = $isMine ? 'bubble-mine' : 'bubble-theirs';
                $roleLabel = $m->is_admin ? __('role_admin') : __('role_reporter');
            @endphp
            <div class="bubble {{ $cls }}">
                <div class="bubble-meta">
                    {{ $isMine ? __('you').' · '.$roleLabel : $roleLabel }}
                    · {{ $m->created_at?->format('d.m.Y H:i') }}
                </div>
                <div class="message-body">
                    {!! $m->renderedBody() !!}
                </div>
            </div>
        @endforeach
    </div>

    <form method="post" class="card mt-4">
        @csrf
        <label for="reply">{{ __('reply') }}</label>
        <textarea id="reply" name="reply" required placeholder="{{ __('reply_placeholder') }}">{{ old('reply') }}</textarea>
        @error('reply')
            <span class="field-error">{{ $message }}</span>
        @enderror
        <div class="text-right mt-3">
            <button type="submit">{{ __('send') }}</button>
        </div>
    </form>

    @if ($isAdministrator)
        <section class="mt-5">
            <div class="section-header">
                <h2>{{ __('status') }}</h2>
            </div>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                @if ($report->isClosed())
                    <button class="button button-success"
                            data-status-url="{{ route('report.status', ['topic' => $report->topic_id, 'report' => $report->id]) }}"
                            data-status="open">
                        {{ __('reopen') }}
                    </button>
                @else
                    <button class="button button-success"
                            data-status-url="{{ route('report.status', ['topic' => $report->topic_id, 'report' => $report->id]) }}"
                            data-status="close"
                            data-status-confirm="{{ __('confirm_close') }}">
                        {{ __('close') }}
                    </button>
                @endif
                @if (! $report->isSpam())
                    <button class="button button-danger"
                            data-status-url="{{ route('report.status', ['topic' => $report->topic_id, 'report' => $report->id]) }}"
                            data-status="spam"
                            data-status-confirm="{{ __('confirm_spam') }}">
                        {{ __('spam') }}
                    </button>
                @endif
            </div>
        </section>
    @endif
@endsection
