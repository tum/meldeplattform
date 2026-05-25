@extends('layouts.app')

@section('intro')
    <section class="page-intro">
        <div class="container">
            <h1>{{ __('select_topic_prompt') }}</h1>
            <p>{{ __('intro_text') }}</p>
        </div>
    </section>
@endsection

@section('content')
    <div class="stack">
        @foreach ($topicsAll as $t)
            <article class="card topic-card">
                <div class="topic-body">
                    <h3><a href="{{ route('form.show', $t) }}">{{ $t->name($lang) }}</a></h3>
                    <p class="muted">{{ $t->summary($lang) }}</p>
                </div>
                <div class="actions">
                    <a class="button button-small" href="{{ route('form.show', $t) }}">{{ __('report') }}</a>
                    @can('update', $t)
                        <a class="button button-small button-ghost" href="{{ route('topic.edit', $t) }}">{{ __('edit') }}</a>
                        <a class="button button-small button-ghost" href="{{ route('topic.reports', $t) }}">{{ __('reports') }}</a>
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
