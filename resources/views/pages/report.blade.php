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
    @if (! $isAdministrator && session('receipt_code'))
        @php
            $receiptCode = (string) session('receipt_code');
            $receiptGroups = implode(' ', str_split($receiptCode, 4));
        @endphp
        <div class="alert alert-warning">
            <p style="margin: 0 0 0.75rem;">{{ __('reportOpened') }}</p>
            <strong>{{ __('receipt_heading') }}</strong>
            <p style="margin-top: 0.5rem;">{{ __('receipt_instructions') }}</p>
            <p style="font-family: monospace; font-size: 1.4rem; letter-spacing: 0.1em; margin: 0.75rem 0;">{{ $receiptGroups }}</p>
            <p class="muted"><small>{{ __('receipt_track_hint') }}</small></p>
        </div>
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

    @php
        // Admins can always reply; reporters only while the report is Open.
        // Mirrors the server-side guard in ReportController::reply() so we
        // never render a form that is guaranteed to 403 on submit.
        $canReply = $isAdministrator || $report->state->allowsReply();
    @endphp
    @if ($canReply)
        @php
            // Admin and reporter replies are handled by different routes, and
            // the admin view's URL (/reports/{topic}/{report}) has no POST
            // handler of its own — without an explicit action the form would
            // submit back to that GET-only URL and get a 405.
            $replyAction = $isAdministrator
                ? route('admin.report.reply', ['topic' => $report->topic_id, 'report' => $report->id])
                : route('report.reply', ['reporterToken' => $report->reporter_token]);
        @endphp
        <form method="post" action="{{ $replyAction }}" class="card mt-4">
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
    @else
        <div class="alert alert-info mt-4">{{ __('report_closed_no_reply') }}</div>
    @endif

    @if ($isAdministrator)
        <section class="mt-5">
            <div class="section-header">
                <h2>{{ __('status') }}</h2>
                <span class="status-pill {{ $report->state->value }}">{{ $report->statusLabel() }}</span>
            </div>

            {{-- EU Whistleblowing Directive deadlines: acknowledgement (7d) and feedback (3mo). --}}
            <div class="card card-soft mb-4">
                <p style="margin: 0 0 0.5rem;">
                    @if ($report->isAcknowledged())
                        <span class="status-pill done">{{ __('acknowledged') }}</span>
                        <span class="muted">· {{ $report->acknowledged_at?->format('d.m.Y H:i') }}</span>
                    @else
                        <span class="status-pill open">{{ __('not_acknowledged') }}</span>
                    @endif
                    @if ($report->isAcknowledgementOverdue())
                        <span class="unread-badge overdue">{{ __('ack_overdue') }}</span>
                    @endif
                    @if ($report->isFeedbackOverdue())
                        <span class="unread-badge overdue">{{ __('feedback_overdue') }}</span>
                    @endif
                </p>
                <p class="muted" style="margin: 0;">
                    {{ __('ack_due') }}: {{ $report->acknowledgementDueAt()?->format('d.m.Y') }}
                    · {{ __('feedback_due') }}: {{ $report->feedbackDueAt()?->format('d.m.Y') }}
                </p>
            </div>

            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                @php
                    // Auto-advance to the reports list after Close/Spam so
                    // the admin lands somewhere actionable instead of being
                    // stranded on the just-triaged report. Reopen omits the
                    // redirect — the admin probably wants to verify the
                    // state change before moving on.
                    $statusUrl = route('report.status', ['topic' => $report->topic_id, 'report' => $report->id]);
                    $ackUrl = route('report.acknowledge', ['topic' => $report->topic_id, 'report' => $report->id]);
                    $listUrl = route('topic.reports', $report->topic_id);
                @endphp
                @unless ($report->isAcknowledged())
                    <button class="button"
                            data-acknowledge-url="{{ $ackUrl }}"
                            data-acknowledge-confirm="{{ __('confirm_acknowledge') }}">
                        {{ __('acknowledge') }}
                    </button>
                @endunless
                @unless ($report->state === \App\Enums\ReportState::Open)
                    <button class="button button-success"
                            data-status-url="{{ $statusUrl }}"
                            data-status="open">
                        {{ __('reopen') }}
                    </button>
                @endunless
                @unless ($report->state === \App\Enums\ReportState::InProgress || $report->isClosed() || $report->isSpam())
                    <button class="button"
                            data-status-url="{{ $statusUrl }}"
                            data-status="progress">
                        {{ __('mark_in_progress') }}
                    </button>
                @endunless
                @unless ($report->isClosed())
                    <button class="button button-success"
                            data-status-url="{{ $statusUrl }}"
                            data-status="close"
                            data-status-redirect="{{ $listUrl }}"
                            data-status-confirm="{{ __('confirm_close') }}">
                        {{ __('close') }}
                    </button>
                @endunless
                @unless ($report->isSpam())
                    <button class="button button-danger"
                            data-status-url="{{ $statusUrl }}"
                            data-status="spam"
                            data-status-redirect="{{ $listUrl }}"
                            data-status-confirm="{{ __('confirm_spam') }}">
                        {{ __('spam') }}
                    </button>
                @endunless
            </div>
        </section>
    @endif
@endsection
