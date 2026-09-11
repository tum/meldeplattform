@php
    /** @var array{uid: string, user: \App\Models\User|null, topics: \Illuminate\Support\Collection<int, \App\Models\Topic>, role: string} $row */
    $user = $row['user'];
    $isEnvGlobal = $user?->isGlobalAdminViaEnv() ?? false;
    $isSelf = auth()->user()?->uid === $row['uid'];
@endphp
<tr>
    <td class="cell-title">
        <span class="person">
            @if ($user?->name)
                <span class="person-name">{{ $user->name }}</span>
                <span class="person-meta"><code>{{ $row['uid'] }}</code>@if ($user->email) · {{ $user->email }}@endif</span>
            @else
                <span class="person-name"><code>{{ $row['uid'] }}</code></span>
                <span class="person-meta">{{ __('users_pending_login') }}</span>
            @endif
        </span>
    </td>
    <td class="cell-head-end">
        @switch ($row['role'])
            @case('global')
                <span class="status-pill role-global" @if ($isEnvGlobal) title="{{ __('users_global_env_hint') }}" @endif>{{ __('role_global_admin') }}@if ($isEnvGlobal) · {{ __('users_global_env') }}@endif</span>
                @break
            @case('topic')
                <span class="status-pill role-topic">{{ __('role_topic_admin') }}</span>
                @break
            @case('pending')
                <span class="status-pill role-pending">{{ __('role_pending') }}</span>
                @break
            @default
                <span class="status-pill role-none">{{ __('role_none') }}</span>
        @endswitch
    </td>
    <td data-label="{{ __('users_topics_column') }}">
        @if ($row['topics']->isEmpty())
            <span class="muted">—</span>
        @else
            <span class="chip-list">
                @foreach ($row['topics'] as $t)
                    <span class="topic-chip">{{ $t->name($lang) }}</span>
                @endforeach
            </span>
        @endif
    </td>
    <td data-label="{{ __('users_last_login') }}">{{ $user?->last_login_at?->format('d.m.Y') ?? '—' }}</td>
    <td class="text-right cell-actions">
        @if ($row['role'] === 'none')
            <a class="button button-small button-ghost"
               href="{{ route('users.edit', ['uid' => $row['uid']]) }}">{{ __('users_grant_access') }}</a>
        @else
            <a class="button button-small button-ghost"
               href="{{ route('users.edit', ['uid' => $row['uid']]) }}">{{ __('edit') }}</a>
            @unless ($isSelf)
                <form method="post" action="{{ route('users.destroy', ['uid' => $row['uid']]) }}"
                      style="display: inline;"
                      data-confirm-submit="{{ __('users_confirm_revoke', ['uid' => $row['uid']]) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="button button-small button-danger">{{ __('users_revoke') }}</button>
                </form>
            @endunless
        @endif
    </td>
</tr>
