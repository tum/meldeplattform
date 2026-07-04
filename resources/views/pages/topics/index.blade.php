@extends('layouts.app')

@section('title', $appTitle.' – '.__('topics'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('topics') }}</h1>
            <p class="muted">{{ __('topics_admin_intro') }}</p>
        </div>
    </section>
@endsection

@section('content')
    <div class="flex-between mb-4">
        <form method="GET" action="{{ route('topics.index') }}" class="filter-bar">
            <label for="topic-search">{{ __('search') }}</label>
            <input id="topic-search" type="search" name="q" value="{{ $q }}" autocomplete="off"
                   placeholder="{{ __('topics') }}…">
            <label for="topic-status">{{ __('topic_status') }}</label>
            <select id="topic-status" name="status">
                <option value="all" @selected($status === 'all')>{{ __('filter_status_all') }}</option>
                <option value="active" @selected($status === 'active')>{{ __('status_active') }}</option>
                <option value="deactivated" @selected($status === 'deactivated')>{{ __('status_deactivated') }}</option>
            </select>
            <button type="submit" class="button button-small">{{ __('apply_filters') }}</button>
        </form>
        @can('create', App\Models\Topic::class)
            <a class="button" href="{{ route('topic.create') }}">{{ __('new_topic') }}</a>
        @endcan
    </div>

    @if ($topics->isEmpty())
        <div class="alert alert-info">{{ __('topics_none') }}</div>
    @else
        {{-- Bulk-action bar. The row checkboxes below belong to this form via the
             HTML `form=` attribute, so bulk deactivate/reactivate submits natively
             even without JS; topics-admin.js only adds select-all + a live count
             and disables the buttons while nothing is selected. --}}
        <form method="post" action="{{ route('topics.bulk') }}" id="topics-bulk-form"
              class="bulk-bar" data-bulk-bar data-confirm-submit="{{ __('confirm_bulk') }}">
            @csrf
            <span class="bulk-bar-count"><span data-bulk-count>0</span> {{ __('selected') }}</span>
            <span class="bulk-bar-actions">
                <button type="submit" name="action" value="deactivate" class="button button-small">{{ __('bulk_deactivate') }}</button>
                <button type="submit" name="action" value="activate" class="button button-small button-ghost">{{ __('bulk_reactivate') }}</button>
            </span>
        </form>

        <table>
            <thead>
                <tr>
                    <th style="width: 1%;"><input type="checkbox" data-bulk-select-all aria-label="{{ __('select_all') }}"></th>
                    <th>{{ __('topic') }}</th>
                    <th>{{ __('topic_status') }}</th>
                    <th>{{ __('reports') }}</th>
                    <th>{{ __('admins') }}</th>
                    <th>{{ __('retention_days_label') }}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($topics as $t)
                    <tr class="topic-row">
                        <td>
                            <input type="checkbox" name="ids[]" value="{{ $t->id }}" form="topics-bulk-form"
                                   data-bulk-row aria-label="{{ $t->name($lang) }}">
                        </td>
                        <td>
                            <strong>{{ $t->name($lang) }}</strong>
                            @if ($t->require_login)
                                <span class="muted" style="font-size: 0.8rem;">· {{ __('login_required_badge') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($t->isActive())
                                <span class="status-pill active">{{ __('status_active') }}</span>
                            @else
                                <span class="status-pill deactivated">{{ __('status_deactivated') }}</span>
                            @endif
                        </td>
                        <td>{{ $t->reports_count }}</td>
                        <td>
                            @forelse ($t->admins as $a)
                                <span class="topic-chip">{{ $a->user_id }}</span>
                            @empty
                                <span class="muted">—</span>
                            @endforelse
                        </td>
                        <td>{{ $t->retention_days ?? '—' }}</td>
                        <td class="text-right" style="white-space: nowrap;">
                            <a class="button button-small button-ghost" href="{{ route('topic.edit', $t) }}">{{ __('edit') }}</a>
                            @if ($t->isActive())
                                <form method="post" action="{{ route('topic.deactivate', $t) }}" style="display: inline;"
                                      data-confirm-submit="{{ __('topic_confirm_deactivate', ['name' => $t->name($lang)]) }}">
                                    @csrf
                                    <button type="submit" class="button button-small">{{ __('topic_deactivate') }}</button>
                                </form>
                            @else
                                <form method="post" action="{{ route('topic.activate', $t) }}" style="display: inline;"
                                      data-confirm-submit="{{ __('topic_confirm_reactivate', ['name' => $t->name($lang)]) }}">
                                    @csrf
                                    <button type="submit" class="button button-small">{{ __('topic_reactivate') }}</button>
                                </form>
                            @endif
                            @can('delete', $t)
                                @if ($t->reports_count > 0)
                                    {{-- A topic with reports cannot be deleted (retention duty); the
                                         disabled button surfaces the rule, the controller enforces it. --}}
                                    <button type="button" class="button button-small button-danger" disabled
                                            title="{{ __('topic_delete_disabled_hint') }}">{{ __('topic_delete') }}</button>
                                @else
                                    <form method="post" action="{{ route('topic.destroy', $t) }}" style="display: inline;"
                                          data-confirm-submit="{{ __('topic_confirm_delete', ['name' => $t->name($lang)]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="button button-small button-danger">{{ __('topic_delete') }}</button>
                                    </form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <script src="{{ asset('js/topics-admin.js') }}" defer></script>
@endsection
