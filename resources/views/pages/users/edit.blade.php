@extends('layouts.app')

@section('title', $appTitle.' – '.__('users').' – '.$uid)

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('users.index') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ $uid }}</h1>
            <p class="muted">
                @if ($user === null)
                    {{ __('users_pending_login') }}
                @else
                    {{ $user->name ?: '—' }} · {{ $user->email ?: '—' }}
                @endif
            </p>
        </div>
    </section>
@endsection

@section('content')
    @php
        $isEnvGlobal = $user?->isGlobalAdminViaEnv() ?? false;
        $currentGlobal = $user?->is_global_admin ?? false;
        $isSelf = auth()->user()?->uid === $uid;
    @endphp

    <form method="post" action="{{ route('users.update', ['uid' => $uid]) }}" class="card" style="max-width: 720px;">
        @csrf

        <div class="form-group">
            <label>
                <input type="checkbox" name="is_global_admin" value="1"
                       @checked(old('is_global_admin', $currentGlobal))
                       @disabled($isEnvGlobal || ($isSelf && $currentGlobal))>
                {{ __('users_is_global_admin') }}
            </label>
            @if ($isEnvGlobal)
                <span class="desc">{{ __('users_global_env_hint') }}</span>
            @elseif ($isSelf && $currentGlobal)
                <span class="desc">{{ __('users_cannot_self_demote') }}</span>
            @endif
            @error('is_global_admin')
                <span class="field-error">{{ $message }}</span>
            @enderror
        </div>

        <fieldset style="border: none; padding: 0;">
            <legend>{{ __('users_topic_access') }}</legend>
            @if ($topics->isEmpty())
                <p class="muted">{{ __('no_topics_configured') }}</p>
            @else
                @foreach ($topics as $t)
                    @php $checked = in_array($t->id, old('topic_ids', $assignedTopicIds), false); @endphp
                    <label style="display: flex; gap: 0.4rem; align-items: center; padding: 0.25rem 0;">
                        <input type="checkbox" name="topic_ids[]" value="{{ $t->id }}" @checked($checked)>
                        {{ $t->name($lang) }}
                    </label>
                @endforeach
            @endif
        </fieldset>

        <div class="flex-between">
            <a class="button button-ghost" href="{{ route('users.index') }}">{{ __('back') }}</a>
            <button type="submit">{{ __('save') }}</button>
        </div>
    </form>
@endsection
