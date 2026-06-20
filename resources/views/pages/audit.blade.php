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
    <section class="card">
        <table>
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
                        <td>{{ $entry->created_at?->format('d.m.Y H:i:s') ?? '—' }}</td>
                        <td>{{ $entry->actor ?? 'system' }}</td>
                        <td><code>{{ $entry->action }}</code></td>
                        <td>
                            @if ($entry->subject_type !== null)
                                {{ $entry->subject_type }}#{{ $entry->subject_id }}
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            @if ($entry->metadata !== null && $entry->metadata !== [])
                                <code>{{ json_encode($entry->metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted text-center" style="padding: 2rem;">{{ __('audit_none') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top: 1rem;">
            {{ $entries->links() }}
        </div>
    </section>
@endsection
