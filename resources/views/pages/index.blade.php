@extends('layouts.app')

@section('intro')
    <section class="page-intro">
        <div class="container">
            <h1>{{ __('select_topic_prompt') }}</h1>
            <p>
                {{ $lang === 'de'
                    ? 'Melden Sie Probleme anonym und sicher an die zuständigen Stellen der TUM. Ihre Meldung wird pseudonym verarbeitet, ohne Registrierung.'
                    : 'Report issues anonymously and securely to the responsible teams at TUM. Your report is processed pseudonymously, without an account.' }}
            </p>
        </div>
    </section>
@endsection

@section('content')
    <div class="stack">
        @foreach ($topicsAll as $t)
            <article class="card topic-card">
                <div class="topic-body">
                    <h3><a href="/form/{{ $t->id }}">{{ $t->name($lang) }}</a></h3>
                    <p class="muted">{{ $t->summary($lang) }}</p>
                </div>
                <div class="actions">
                    <a class="button button-small" href="/form/{{ $t->id }}">{{ __('report') }}</a>
                    @if ($isGlobalAdmin || ($authUid && $t->isAdmin($authUid)))
                        <a class="button button-small button-ghost" href="/newTopic/{{ $t->id }}">{{ __('edit') }}</a>
                        <a class="button button-small button-ghost" href="/reports/{{ $t->id }}">{{ __('reports') }}</a>
                    @endif
                </div>
            </article>
        @endforeach

        @if ($topicsAll->isEmpty())
            <div class="alert alert-info">
                {{ $lang === 'de'
                    ? 'Aktuell sind noch keine Meldethemen konfiguriert.'
                    : 'No reporting topics have been configured yet.' }}
            </div>
        @endif
    </div>

    @if ($isGlobalAdmin)
        <div class="mt-5">
            <a class="button" href="/newTopic/0">+ {{ __('create_topic') }}</a>
        </div>
    @endif
@endsection
