@extends('layouts.app')

@section('title', $appTitle.' – '.__('users'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('users') }}</h1>
            <p class="muted">{{ __('users_intro') }}</p>
            @if ($dormantDays !== null)
                <p class="muted"><small>{{ __('users_dormant_policy', ['days' => $dormantDays]) }}</small></p>
            @endif
            {{-- Platform-wide tallies; unaffected by the search/role filter below. --}}
            <ul class="stat-row" aria-label="{{ __('users_role_column') }}">
                <li class="stat"><strong>{{ $counts['global'] }}</strong> {{ __('role_global_admin') }}</li>
                <li class="stat"><strong>{{ $counts['topic'] }}</strong> {{ __('role_topic_admin') }}</li>
                @if ($counts['pending'] > 0)
                    <li class="stat"><strong>{{ $counts['pending'] }}</strong> {{ __('role_pending') }}</li>
                @endif
                <li class="stat stat-muted"><strong>{{ $counts['none'] }}</strong> {{ __('role_none') }}</li>
            </ul>
        </div>
    </section>
@endsection

@section('content')
    <section class="card mb-4">
        <h2 style="margin-top:0;">{{ __('users_add_heading') }}</h2>
        <form method="post" action="{{ route('users.store') }}">
            @csrf
            <div class="form-inline" style="margin-bottom: 1rem;">
                <div class="form-group" style="flex: 1; min-width: 12rem;">
                    <label for="new-uid">{{ __('users_uid_label') }}</label>
                    <input id="new-uid" name="uid" type="text" required autocomplete="off"
                           placeholder="ge42tum" value="{{ old('uid') }}">
                    @error('uid')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>
                <label style="margin: 0;">
                    <input type="checkbox" name="is_global_admin" value="1" @checked(old('is_global_admin'))>
                    {{ __('users_is_global_admin') }}
                </label>
                @error('is_global_admin')
                    <span class="field-error">{{ $message }}</span>
                @enderror
            </div>
            <fieldset style="margin: 0 0 1rem; border: none; padding: 0;">
                <legend class="desc" style="margin-bottom: 0.4rem;">{{ __('users_topic_access') }}</legend>
                @if ($topics->isEmpty())
                    <p class="muted" style="margin: 0;">{{ __('no_topics_configured') }}</p>
                @else
                    <div class="topic-checkbox-list">
                        @foreach ($topics as $t)
                            <label>
                                <input type="checkbox" name="topic_ids[]" value="{{ $t->id }}"
                                       @checked(in_array($t->id, old('topic_ids', []), false))>
                                <span>{{ $t->name($lang) }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </fieldset>
            <div class="text-right">
                <button type="submit">{{ __('users_add_button') }}</button>
            </div>
        </form>
    </section>

    <div class="flex-between mb-4">
        <form method="GET" action="{{ route('users.index') }}" class="filter-bar">
            <label for="user-search">{{ __('search') }}</label>
            <input id="user-search" type="search" name="q" value="{{ $q }}" autocomplete="off"
                   placeholder="{{ __('users_search_placeholder') }}">
            <label for="user-role">{{ __('users_role_column') }}</label>
            <select id="user-role" name="role">
                <option value="all" @selected($role === 'all')>{{ __('filter_status_all') }}</option>
                <option value="global" @selected($role === 'global')>{{ __('role_global_admin') }}</option>
                <option value="topic" @selected($role === 'topic')>{{ __('role_topic_admin') }}</option>
                <option value="pending" @selected($role === 'pending')>{{ __('role_pending') }}</option>
                <option value="none" @selected($role === 'none')>{{ __('role_none') }}</option>
            </select>
            <button type="submit" class="button button-small">{{ __('apply_filters') }}</button>
        </form>
    </div>

    @if ($role !== 'none')
        <section class="mb-5">
            <div class="section-header">
                <h2>{{ __('users_section_admins') }} <span class="muted">({{ count($admins) }})</span></h2>
            </div>
            <div class="table-wrap">
                <table class="table-cards">
                    <thead>
                        <tr>
                            <th>{{ __('users_person_column') }}</th>
                            <th>{{ __('users_role_column') }}</th>
                            <th>{{ __('users_topics_column') }}</th>
                            <th>{{ __('users_last_login') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($admins as $row)
                            @include('pages.users._row', ['row' => $row, 'dormantWarnBefore' => $dormantWarnBefore])
                        @empty
                            <tr><td colspan="5" class="table-empty">{{ __('users_none_admins') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    @if ($role === 'all' || $role === 'none')
        {{-- Collapsed by default: this list grows with every login-required
             report and rarely needs attention. A search or the role filter
             opens it, since then the admin is looking for someone in here. --}}
        <details class="section-collapsible"{!! $q !== '' || $role === 'none' ? ' open' : '' !!}>
            <summary>
                <h2>{{ __('users_section_regular') }} <span class="muted">({{ count($regular) }})</span></h2>
                <span class="desc">{{ __('users_regular_hint') }}</span>
            </summary>
            <div class="table-wrap">
                <table class="table-cards">
                    <thead>
                        <tr>
                            <th>{{ __('users_person_column') }}</th>
                            <th>{{ __('users_role_column') }}</th>
                            <th>{{ __('users_topics_column') }}</th>
                            <th>{{ __('users_last_login') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($regular as $row)
                            @include('pages.users._row', ['row' => $row, 'dormantWarnBefore' => null])
                        @empty
                            <tr><td colspan="5" class="table-empty">{{ __('users_none_regular') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </details>
    @endif
@endsection
