@extends('layouts.app')

@section('title', $appTitle.' – '.__('audit_title'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('audit_title') }}</h1>
            <p class="muted">{{ __('audit_intro') }}</p>
        </div>
    </section>
@endsection

@section('content')
    <div class="table-wrap">
        <table class="table-cards">
            <thead>
                <tr>
                    <th>{{ __('audit_date') }}</th>
                    <th>{{ __('audit_actor') }}</th>
                    <th>{{ __('audit_action') }}</th>
                    <th>{{ __('audit_subject') }}</th>
                    <th>{{ __('audit_metadata') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr>
                        <td class="cell-time cell-head-end">
                            @if ($entry->created_at !== null)
                                {{ $entry->created_at->format('d.m.Y') }}
                                <small>{{ $entry->created_at->format('H:i:s') }}</small>
                            @else
                                —
                            @endif
                        </td>
                        <td data-label="{{ __('audit_actor') }}">
                            @if ($entry->actor !== null)
                                <strong>{{ $entry->actor }}</strong>
                            @else
                                <span class="tag">system</span>
                            @endif
                        </td>
                        <td class="cell-title">
                            {{-- The domain prefix (report./topic./admin.) drives the colour dot. --}}
                            <code class="audit-action" data-domain="{{ strstr($entry->action, '.', true) ?: $entry->action }}">{{ $entry->action }}</code>
                        </td>
                        <td data-label="{{ __('audit_subject') }}">
                            @if ($entry->subject_type !== null)
                                <span class="audit-subject"><span class="muted">{{ $entry->subject_type }}</span>#{{ $entry->subject_id }}</span>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                        <td data-label="{{ __('audit_metadata') }}">
                            @if ($entry->metadata !== null && $entry->metadata !== [])
                                {{-- Metadata is a flat map (scalars or id lists); render it as
                                     key/value chips rather than a JSON blob. Non-scalars fall
                                     back to compact JSON so nothing is silently dropped. --}}
                                <div class="kv-list">
                                    @foreach ($entry->metadata as $key => $value)
                                        <span class="kv">
                                            <span class="kv-key">{{ $key }}</span>
                                            <span class="kv-value">{{ is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</span>
                                        </span>
                                    @endforeach
                                </div>
                            @else
                                <span class="muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="table-empty">{{ __('audit_none') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $entries->links() }}
@endsection
