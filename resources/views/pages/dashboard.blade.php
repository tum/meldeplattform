@extends('layouts.app')

@section('title', $appTitle.' – '.__('dashboard'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="/" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('dashboard') }}</h1>
            <p class="muted">
                {{ trans_choice('reports_across_topics', $topics->count(), ['topics' => $topics->count(), 'reports' => $reports->count()]) }}
            </p>
        </div>
    </section>
@endsection

@section('content')
    @if ($reports->isEmpty())
        <div class="alert alert-info">{{ __('no_reports_yet') }}</div>
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
                    <tr>
                        <td>#{{ $r->id }}</td>
                        <td>{{ $r->topic->name($lang) }}</td>
                        <td>{{ $r->dateFmt() }}</td>
                        <td>{{ $r->creator ?: __('anonymous') }}</td>
                        <td>
                            <span class="status-pill {{ $r->state->value }}">{{ $r->statusLabel() }}</span>
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
    @endif
@endsection
