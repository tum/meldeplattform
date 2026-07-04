@extends('layouts.app')

@section('intro')
    <section class="page-intro">
        <div class="container">
            <h1>{{ __('welcome_heading') }}</h1>
            <p>{{ __('about_text') }}</p>
            <p>{{ __('intro_text') }}</p>
            <p><a href="{{ route('report.track') }}">{{ __('track_link') }}</a></p>
        </div>
    </section>
@endsection

@section('content')
    @php
        // Reporters only ever see active topics. A managing admin also sees the
        // topics they can edit even when deactivated, so they can still reach
        // Edit/Reports from here — but the public "Report" action is withheld.
        $canManageTopic = static fn ($t): bool => auth()->user()?->can('update', $t) ?? false;
        $visibleTopics = $topicsAll->filter(static fn ($t): bool => $t->isActive() || $canManageTopic($t));
    @endphp
    <div class="stack">
        <h2>{{ __('select_topic_prompt') }}</h2>
        @foreach ($visibleTopics as $t)
            <article class="card topic-card">
                <div class="topic-body">
                    <h3>
                        @if ($t->isActive())
                            <a href="{{ route('form.show', $t) }}">{{ $t->name($lang) }}</a>
                        @else
                            {{ $t->name($lang) }}
                            <span class="status-pill deactivated">{{ __('topic_deactivated_badge') }}</span>
                        @endif
                    </h3>
                    <div class="muted topic-summary">{!! $t->renderedSummary($lang) !!}</div>
                    @if ($t->require_login)
                        <p class="muted"><small>{{ __('login_required_badge') }}</small></p>
                    @endif
                </div>
                <div class="actions">
                    @if ($t->isActive())
                        <a class="button button-small" href="{{ route('form.show', $t) }}">{{ __('report') }}</a>
                    @endif
                    @can('update', $t)
                        <a class="button button-small button-ghost" href="{{ route('topic.edit', $t) }}">{{ __('edit') }}</a>
                        @php $unread = $unreadByTopic[$t->id] ?? 0; @endphp
                        <a class="button button-small button-ghost" href="{{ route('topic.reports', $t) }}">
                            {{ __('reports') }}
                            @if ($unread > 0)
                                <span class="unread-badge" aria-label="{{ trans_choice('unread_reports', $unread, ['count' => $unread]) }}">{{ $unread }}</span>
                            @endif
                        </a>
                    @endcan
                </div>
            </article>
        @endforeach

        @if ($visibleTopics->isEmpty())
            <div class="alert alert-info">{{ __('no_topics_configured') }}</div>
        @endif
    </div>

    @can('create', App\Models\Topic::class)
        <div class="mt-5">
            <a class="button" href="{{ route('topic.create') }}">{{ __('new_topic') }}</a>
        </div>
    @endcan
@endsection
