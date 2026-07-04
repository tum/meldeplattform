@extends('layouts.app')

@section('title', $appTitle.' – '.__('users'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('users') }}</h1>
            <p class="muted">{{ __('users_intro') }}</p>
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
                   placeholder="{{ __('users_uid_label') }}…">
            <label for="user-role">{{ __('users_role_column') }}</label>
            <select id="user-role" name="role">
                <option value="all" @selected($role === 'all')>{{ __('filter_status_all') }}</option>
                <option value="global" @selected($role === 'global')>{{ __('role_global_admin') }}</option>
                <option value="topic" @selected($role === 'topic')>{{ __('role_topic_admin') }}</option>
                <option value="none" @selected($role === 'none')>{{ __('role_none') }}</option>
                <option value="pending" @selected($role === 'pending')>{{ __('role_pending') }}</option>
            </select>
            <button type="submit" class="button button-small">{{ __('apply_filters') }}</button>
        </form>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('users_uid_label') }}</th>
                <th>Name</th>
                <th>{{ __('contact') }}</th>
                <th>{{ __('users_last_login') }}</th>
                <th>{{ __('users_role_column') }}</th>
                <th>{{ __('users_topics_column') }}</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                @php
                    $user = $row['user'];
                    $isEnvGlobal = $user?->isGlobalAdminViaEnv() ?? false;
                    $isDbGlobal = $user?->is_global_admin ?? false;
                    $hasTopics = $row['topics']->isNotEmpty();
                @endphp
                <tr>
                    <td><code>{{ $row['uid'] }}</code></td>
                    <td>{{ $user?->name ?: '—' }}</td>
                    <td>{{ $user?->email ?: '—' }}</td>
                    <td>{{ $user?->last_login_at?->format('d.m.Y') ?? '—' }}</td>
                    <td>
                        @if ($isEnvGlobal)
                            <span class="status-pill open" title="{{ __('users_global_env_hint') }}">{{ __('role_global_admin') }} · {{ __('users_global_env') }}</span>
                        @elseif ($isDbGlobal)
                            <span class="status-pill done">{{ __('role_global_admin') }}</span>
                        @elseif ($hasTopics)
                            <span class="status-pill open">{{ __('role_topic_admin') }}</span>
                        @else
                            <span class="muted">—</span>
                        @endif
                        @if ($user === null)
                            <span class="status-pill deactivated">{{ __('role_pending') }}</span>
                        @endif
                    </td>
                    <td>
                        @if ($row['topics']->isEmpty())
                            <span class="muted">—</span>
                        @else
                            @foreach ($row['topics'] as $t)
                                <span class="topic-chip">{{ $t->name($lang) }}</span>
                            @endforeach
                        @endif
                    </td>
                    <td class="text-right">
                        <a class="button button-small button-ghost"
                           href="{{ route('users.edit', ['uid' => $row['uid']]) }}">{{ __('edit') }}</a>
                        @if (auth()->user()?->uid !== $row['uid'])
                            <form method="post" action="{{ route('users.destroy', ['uid' => $row['uid']]) }}"
                                  style="display: inline;"
                                  data-confirm-submit="{{ __('users_confirm_revoke', ['uid' => $row['uid']]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="button button-small button-danger">{{ __('users_revoke') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted text-center" style="padding: 2rem;">{{ __('users_none') }}</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
