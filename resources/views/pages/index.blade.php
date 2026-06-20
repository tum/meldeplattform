@extends('layouts.app')

@section('intro')
    <section class="page-intro">
        <div class="container">
            <h1>{{ __('welcome_heading') }}</h1>
            <p>{{ __('about_text') }}</p>
            <p>{{ __('intro_text') }}</p>
        </div>
    </section>
@endsection

@section('content')
    <div class="stack">
        <h2>{{ __('select_topic_prompt') }}</h2>
        @foreach ($topicsAll as $t)
            <article class="card topic-card">
                <div class="topic-body">
                    <h3><a href="{{ route('form.show', $t) }}">{{ $t->name($lang) }}</a></h3>
                    <p class="muted">{{ $t->summary($lang) }}</p>
                    @if ($t->require_login)
                        <p class="muted"><small>{{ __('login_required_badge') }}</small></p>
                    @endif
                </div>
                <div class="actions">
                    <a class="button button-small" href="{{ route('form.show', $t) }}">{{ __('report') }}</a>
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

        @if ($topicsAll->isEmpty())
            <div class="alert alert-info">{{ __('no_topics_configured') }}</div>
        @endif
    </div>

    @can('create', App\Models\Topic::class)
        <div class="mt-5">
            <a class="button" href="{{ route('topic.create') }}">{{ __('new_topic') }}</a>
        </div>
    @endcan
@endsection
