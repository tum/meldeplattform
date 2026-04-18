@extends('layouts.app')

@section('title', $appTitle.' – '.__('report'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <h1>{{ __('report') }} #{{ $report->id }}</h1>
            <p class="muted">
                @if ($isAdministrator)
                    {{ $lang === 'de' ? 'Bearbeitung als Administrator*in.' : 'Administrator view.' }}
                @else
                    {{ $lang === 'de' ? 'Ihre eingereichte Meldung.' : 'Your submitted report.' }}
                @endif
                · {{ $report->dateFmt() }}
            </p>
        </div>
    </section>
@endsection

@section('content')
    @if (! $isAdministrator)
        <div class="alert alert-warning">{{ __('reportOpened') }}</div>
    @endif

    @if ($report->creator)
        <p class="muted">{{ $report->creator }}</p>
    @endif

    <div class="thread">
        @foreach ($report->messages as $m)
            @php $cls = ($isAdministrator === $m->is_admin) ? 'bubble-admin' : 'bubble-user'; @endphp
            <div class="bubble {{ $cls }}">
                <div class="bubble-meta">
                    {{ $m->is_admin
                        ? ($lang === 'de' ? 'Administration' : 'Administrator')
                        : ($lang === 'de' ? 'Melder*in' : 'Reporter') }}
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
        <textarea id="reply" name="reply" required placeholder="{{ $lang === 'de' ? 'Ihre Antwort…' : 'Your reply…' }}"></textarea>
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
                            data-status-url="/api/topic/{{ $report->topic_id }}/report/{{ $report->id }}/status"
                            data-status="open">
                        {{ __('reopen') }}
                    </button>
                @else
                    <button class="button button-success"
                            data-status-url="/api/topic/{{ $report->topic_id }}/report/{{ $report->id }}/status"
                            data-status="close">
                        {{ __('close') }}
                    </button>
                @endif
                @if (! $report->isSpam())
                    <button class="button button-danger"
                            data-status-url="/api/topic/{{ $report->topic_id }}/report/{{ $report->id }}/status"
                            data-status="spam">
                        {{ __('spam') }}
                    </button>
                @endif
            </div>
        </section>
    @endif
@endsection
