@extends('layouts.app')

@section('title', $appTitle.' – '.__('stats_title'))

@section('intro')
    <section class="page-intro">
        <div class="container">
            <a href="{{ route('home') }}" class="crumb">{{ __('back') }}</a>
            <h1>{{ __('stats_title') }}</h1>
            <p class="muted">{{ __('stats_intro') }} · {{ __('stats_as_of', ['time' => $generatedAt->format('d.m.Y H:i')]) }}</p>
        </div>
    </section>
@endsection

@section('content')
@php
    use App\Services\SystemStats;

    $de = $lang === 'de';
    $n = static fn (int|float $v, int $dec = 0): string => number_format($v, $dec, $de ? ',' : '.', $de ? '.' : ',');
    $pct = static fn (int $part, int $whole): ?int => $whole > 0 ? (int) round($part / $whole * 100) : null;
    $pctText = static fn (int $part, int $whole): string => $whole > 0 ? $n((int) round($part / $whole * 100)).' %' : '—';
    // Median durations: hours under two days, days beyond.
    $duration = static function (?float $hours) use ($n, $de): string {
        if ($hours === null) {
            return '—';
        }
        if ($hours < 48) {
            return $n($hours, 1).' '.($de ? 'Std.' : 'h');
        }

        return $n($hours / 24, 1).' '.($de ? 'Tage' : 'days');
    };
    $tname = static fn (array $t): string => $de ? ($t['name_de'] !== '' ? $t['name_de'] : $t['name_en']) : ($t['name_en'] !== '' ? $t['name_en'] : $t['name_de']);
    $bytes = static fn (?int $b): string => SystemStats::bytes($b, $lang);
    $ago = static fn (?\Carbon\CarbonInterface $t): string => $t === null ? '—' : $t->locale($lang)->diffForHumans();

    // Column path with a 4px rounded top and a square baseline.
    $column = static function (float $x, float $y, float $w, float $h): string {
        if ($h < 4) {
            return sprintf('M%.1f,%.1f h%.1f v%.1f h-%.1f Z', $x, $y + $h, $w, -$h, $w);
        }
        $r = 4;

        return sprintf('M%.1f,%.1f V%.1f Q%.1f,%.1f %.1f,%.1f H%.1f Q%.1f,%.1f %.1f,%.1f V%.1f Z',
            $x, $y + $h, $y + $r, $x, $y, $x + $r, $y, $x + $w - $r, $x + $w, $y, $x + $w, $y + $r, $y + $h);
    };
    // Axis ceiling = four round steps (1, 2, 2.5, 5 × 10^k), so every tick is
    // a clean number: 32 → 0/10/20/30/40, 120 → 0/50/100/150/200.
    $niceStep = static function (float $raw): float {
        if ($raw <= 1) {
            return 1;
        }
        $mag = 10 ** floor(log10($raw));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            $step = $m * $mag;
            if ($raw <= $step && ($step >= 10 || floor($step) == $step)) {
                return $step;
            }
        }

        return 10 * $mag;
    };

    $states = $reports['states'];
    $stateMeta = [
        'open' => ['label' => __('status_open'), 'class' => 'seg-open'],
        'in_progress' => ['label' => __('status_in_progress'), 'class' => 'seg-progress'],
        'done' => ['label' => __('status_done'), 'class' => 'seg-done'],
        'spam' => ['label' => __('spam'), 'class' => 'seg-spam'],
    ];
    $overdue = $reports['ack_overdue'] + $reports['feedback_overdue'];
    $delta = $reports['last_30'] - $reports['prev_30'];

    // Health checks: [label, ok?, detail]. Warnings, not errors — an operator
    // reads this list and decides.
    $heartbeat = $tech['scheduler_heartbeat'];
    $heartbeatOk = $heartbeat !== null && $heartbeat->gte(now()->subMinutes(15));
    $phpUploadBytes = SystemStats::iniBytes($tech['upload_max']);
    $phpPostBytes = SystemStats::iniBytes($tech['post_max']);
    $uploadLimitOk = ($phpUploadBytes === null || $phpUploadBytes >= $tech['configured_upload_mb'] * 1024 * 1024)
        && ($phpPostBytes === null || $phpPostBytes >= $tech['configured_upload_mb'] * 1024 * 1024);
    $isProd = $tech['app_env'] === 'production';
    $checks = [
        [__('stats_check_scheduler'), $heartbeatOk, $heartbeat === null ? __('stats_never') : $ago($heartbeat)],
        [__('stats_check_uploads_writable'), $storage['uploads_writable'], null],
        [__('stats_check_logs_writable'), $storage['logs_writable'], null],
        [__('stats_check_app_key'), $tech['app_key_set'], null],
        [__('stats_check_debug_off'), ! ($isProd && $tech['app_debug']), $tech['app_debug'] ? 'APP_DEBUG=true' : 'APP_DEBUG=false'],
        [__('stats_check_https'), str_starts_with($tech['app_url'], 'https://'), $tech['app_url']],
        [__('stats_check_secure_cookie'), $tech['session_secure'] || ! $isProd, 'SESSION_SECURE_COOKIE='.($tech['session_secure'] ? 'true' : 'false')],
        [__('stats_check_dev_login'), ! $tech['dev_login'] || ! $isProd, $tech['dev_login'] ? __('stats_enabled') : __('stats_disabled')],
        [__('stats_check_saml'), $tech['saml_configured'] || ! $isProd, $tech['saml_configured'] ? __('stats_configured') : __('stats_not_configured')],
        [__('stats_check_upload_limit'), $uploadLimitOk, sprintf('upload_max_filesize=%s · post_max_size=%s · MELDE_MAX_UPLOAD_MB=%d', $tech['upload_max'], $tech['post_max'], $tech['configured_upload_mb'])],
        [__('stats_check_log_rotation'), str_contains($tech['log_stack'], 'daily'), 'LOG_STACK='.$tech['log_stack'].' · '.$tech['log_days'].' '.($de ? 'Tage' : 'days')],
        [__('stats_check_queue_sync'), $tech['queue'] === 'sync', 'QUEUE_CONNECTION='.$tech['queue']],
    ];
    $failing = count(array_filter($checks, static fn (array $c): bool => ! $c[1]));
@endphp

{{-- ================= Reports ================= --}}
<section class="stats-section">
    <div class="section-header"><h2>{{ __('reports') }}</h2></div>

    <ul class="kpi-grid">
        <li class="kpi kpi-hero">
            <span class="kpi-label">{{ __('stats_active_reports') }}</span>
            <span class="kpi-value">{{ $n($reports['active']) }}</span>
            <span class="kpi-hint">
                {{ __('stats_of_which_in_progress', ['count' => $n($states['in_progress'])]) }}
                @if ($reports['stale_days'] !== null)
                    · <a href="{{ route('dashboard', ['filters' => '1', 'only_stale' => '1']) }}">{{ __('stats_stale', ['count' => $n($reports['stale']), 'days' => $reports['stale_days']]) }}</a>
                @endif
            </span>
        </li>
        <li class="kpi">
            <span class="kpi-label">{{ __('stats_overdue') }}</span>
            <span class="kpi-value">{{ $n($overdue) }}</span>
            <span class="kpi-hint">
                @if ($overdue > 0)<span class="dot dot-bad" aria-hidden="true"></span>@else<span class="dot dot-ok" aria-hidden="true"></span>@endif
                {{ __('ack_overdue') }} {{ $n($reports['ack_overdue']) }} · {{ __('feedback_overdue') }} {{ $n($reports['feedback_overdue']) }}
            </span>
        </li>
        <li class="kpi">
            <span class="kpi-label">{{ __('stats_new_30') }}</span>
            <span class="kpi-value">{{ $n($reports['last_30']) }}</span>
            <span class="kpi-hint kpi-delta">{{ $delta > 0 ? '+' : '' }}{{ $n($delta) }} {{ __('stats_vs_previous_30') }}</span>
        </li>
        <li class="kpi">
            <span class="kpi-label">{{ __('stats_median_ack') }}</span>
            <span class="kpi-value">{{ $duration($reports['median_ack_hours']) }}</span>
            <span class="kpi-hint">{{ __('stats_last_12_months') }}</span>
        </li>
        <li class="kpi">
            <span class="kpi-label">{{ __('stats_ack_on_time') }}</span>
            <span class="kpi-value">{{ $pctText($reports['ack_on_time'], $reports['ack_eligible']) }}</span>
            <span class="kpi-hint">{{ __('stats_x_of_y', ['x' => $n($reports['ack_on_time']), 'y' => $n($reports['ack_eligible'])]) }} · 7 {{ $de ? 'Tage' : 'days' }}</span>
        </li>
        <li class="kpi">
            <span class="kpi-label">{{ __('stats_feedback_on_time') }}</span>
            <span class="kpi-value">{{ $pctText($reports['feedback_on_time'], $reports['feedback_eligible']) }}</span>
            <span class="kpi-hint">{{ __('stats_x_of_y', ['x' => $n($reports['feedback_on_time']), 'y' => $n($reports['feedback_eligible'])]) }} · 3 {{ $de ? 'Monate' : 'months' }}</span>
        </li>
    </ul>

    <div class="chart-grid">
        {{-- Monthly intake: single series, one hue, columns. --}}
        @php
            $months = $reports['months'];
            $maxMonth = max(array_column($months, 'count') ?: [0]);
            $step = $niceStep($maxMonth / 4);
            $ceil = (int) ($step * 4);
            $W = 640; $H = 220; $padL = 36; $padR = 12; $padT = 16; $padB = 28;
            $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;
            $slot = $plotW / count($months);
            $barW = min(24, $slot * 0.6);
            $ticks = [0, $step, 2 * $step, 3 * $step, $ceil];
            $currentKey = now()->format('Y-m');
        @endphp
        <figure class="chart">
            <figcaption>
                <h3>{{ __('stats_reports_per_month') }}</h3>
                <span class="muted">{{ __('stats_last_12_months') }} · {{ __('stats_total') }} {{ $n(array_sum(array_column($months, 'count'))) }}</span>
            </figcaption>
            <svg class="viz" viewBox="0 0 {{ $W }} {{ $H }}" role="img" aria-label="{{ __('stats_reports_per_month') }}">
                @foreach ($ticks as $t)
                    @php $y = $padT + $plotH - ($ceil > 0 ? $t / $ceil * $plotH : 0); @endphp
                    <line class="viz-grid" x1="{{ $padL }}" x2="{{ $W - $padR }}" y1="{{ $y }}" y2="{{ $y }}"/>
                    <text class="viz-tick" x="{{ $padL - 8 }}" y="{{ $y + 4 }}" text-anchor="end">{{ $n((int) $t) }}</text>
                @endforeach
                @foreach ($months as $i => $m)
                    @php
                        $h = $ceil > 0 ? $m['count'] / $ceil * $plotH : 0;
                        $x = $padL + $i * $slot + ($slot - $barW) / 2;
                        $y = $padT + $plotH - $h;
                        $labelIt = $m['count'] === $maxMonth && $maxMonth > 0;
                    @endphp
                    <g class="viz-bar" tabindex="0">
                        <title>{{ $m['label'] }} {{ $m['year'] }}: {{ $n($m['count']) }}</title>
                        <rect class="viz-hit" x="{{ $padL + $i * $slot }}" y="{{ $padT }}" width="{{ $slot }}" height="{{ $plotH }}"/>
                        <path class="viz-fill" d="{{ $column($x, $y, $barW, $h) }}"/>
                        @if ($labelIt)
                            <text class="viz-value" x="{{ $x + $barW / 2 }}" y="{{ $y - 6 }}" text-anchor="middle">{{ $n($m['count']) }}</text>
                        @endif
                        <text class="viz-tick" x="{{ $x + $barW / 2 }}" y="{{ $H - 8 }}" text-anchor="middle">{{ $m['label'] }}</text>
                    </g>
                @endforeach
            </svg>
            <details class="chart-table">
                <summary>{{ __('stats_as_table') }}</summary>
                <table>
                    <thead><tr><th>{{ __('stats_month') }}</th><th class="text-right">{{ __('reports') }}</th></tr></thead>
                    <tbody>
                        @foreach ($months as $m)
                            <tr><td>{{ $m['label'] }} {{ $m['year'] }}</td><td class="text-right num">{{ $n($m['count']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
        </figure>

        {{-- State distribution: one horizontal stacked bar, 2px surface gaps, legend + labels. --}}
        <figure class="chart">
            <figcaption>
                <h3>{{ __('status') }}</h3>
                <span class="muted">{{ __('stats_total') }} {{ $n($reports['total']) }}</span>
            </figcaption>
            @if ($reports['total'] > 0)
                <div class="stack" role="img" aria-label="{{ __('status') }}">
                    @foreach ($stateMeta as $key => $meta)
                        @php $w = $states[$key] / $reports['total'] * 100; @endphp
                        @if ($states[$key] > 0)
                            <span class="stack-seg {{ $meta['class'] }}" style="flex-basis: {{ $w }}%" title="{{ $meta['label'] }}: {{ $n($states[$key]) }} ({{ $pctText($states[$key], $reports['total']) }})" tabindex="0">
                                @if ($w >= 12)<span class="stack-label">{{ $n($states[$key]) }}</span>@endif
                            </span>
                        @endif
                    @endforeach
                </div>
            @else
                <p class="muted">{{ __('stats_no_data') }}</p>
            @endif
            <ul class="legend">
                @foreach ($stateMeta as $key => $meta)
                    <li><span class="swatch {{ $meta['class'] }}" aria-hidden="true"></span>{{ $meta['label'] }} <strong class="num">{{ $n($states[$key]) }}</strong> <span class="muted">{{ $pctText($states[$key], $reports['total']) }}</span></li>
                @endforeach
            </ul>
        </figure>

        {{-- Per topic: horizontal bars, one hue, value at the tip, open count as text. --}}
        @php $maxTopic = max(array_column($reports['by_topic'], 'count') ?: [0]); @endphp
        <figure class="chart chart-wide">
            <figcaption>
                <h3>{{ __('stats_by_topic') }}</h3>
                <span class="muted">{{ __('stats_all_time') }}</span>
            </figcaption>
            @if ($reports['by_topic'] === [])
                <p class="muted">{{ __('stats_no_data') }}</p>
            @else
                <ol class="hbars">
                    @foreach ($reports['by_topic'] as $t)
                        <li class="hbar" tabindex="0" title="{{ $tname($t) }}: {{ $n($t['count']) }} · {{ __('status_open') }} {{ $n($t['open']) }}">
                            <span class="hbar-label">{{ $tname($t) }}</span>
                            <span class="hbar-track"><span class="hbar-fill" style="width: {{ $maxTopic > 0 ? $t['count'] / $maxTopic * 100 : 0 }}%"></span></span>
                            <span class="hbar-value num">{{ $n($t['count']) }}<span class="muted"> · {{ $n($t['open']) }} {{ mb_strtolower(__('status_open')) }}</span></span>
                        </li>
                    @endforeach
                    @if ($reports['by_topic_other'] > 0)
                        <li class="hbar hbar-other">
                            <span class="hbar-label muted">{{ __('stats_other_topics') }}</span>
                            <span class="hbar-track"><span class="hbar-fill hbar-fill-muted" style="width: {{ $maxTopic > 0 ? min(100, $reports['by_topic_other'] / $maxTopic * 100) : 0 }}%"></span></span>
                            <span class="hbar-value num">{{ $n($reports['by_topic_other']) }}</span>
                        </li>
                    @endif
                </ol>
            @endif
        </figure>
    </div>
</section>

{{-- ================= Communication ================= --}}
<section class="stats-section">
    <div class="section-header"><h2>{{ __('stats_communication') }}</h2></div>
    <ul class="kpi-grid kpi-grid-compact">
        <li class="kpi"><span class="kpi-label">{{ __('messages') }}</span><span class="kpi-value">{{ $n($communication['messages']) }}</span><span class="kpi-hint">{{ __('role_reporter') }} {{ $n($communication['reporter_messages']) }} · {{ __('role_admin') }} {{ $n($communication['admin_replies']) }}</span></li>
        <li class="kpi"><span class="kpi-label">{{ __('stats_reports_with_reply') }}</span><span class="kpi-value">{{ $pctText($communication['reports_with_reply'], $reports['total']) }}</span><span class="kpi-hint">{{ __('stats_x_of_y', ['x' => $n($communication['reports_with_reply']), 'y' => $n($reports['total'])]) }}</span></li>
        <li class="kpi"><span class="kpi-label">{{ __('stats_anonymous') }}</span><span class="kpi-value">{{ $pctText($reports['anonymous'], $reports['total']) }}</span><span class="kpi-hint">{{ __('stats_x_of_y', ['x' => $n($reports['anonymous']), 'y' => $n($reports['total'])]) }}</span></li>
        <li class="kpi"><span class="kpi-label">{{ __('stats_with_attachments') }}</span><span class="kpi-value">{{ $n($communication['with_attachments']) }}</span><span class="kpi-hint">{{ $n($storage['files']) }} {{ __('stats_files') }}</span></li>
        <li class="kpi"><span class="kpi-label">{{ __('stats_messages_30') }}</span><span class="kpi-value">{{ $n($communication['messages_30']) }}</span><span class="kpi-hint">{{ __('stats_otrs_mirrored') }} {{ $n($communication['otrs_mirrored']) }}</span></li>
    </ul>
</section>

{{-- ================= People & access ================= --}}
<section class="stats-section">
    <div class="section-header"><h2>{{ __('stats_people') }}</h2></div>
    <ul class="kpi-grid kpi-grid-compact">
        <li class="kpi"><span class="kpi-label">{{ __('role_global_admin') }}</span><span class="kpi-value">{{ $n($people['global']) }}</span></li>
        <li class="kpi"><span class="kpi-label">{{ __('role_topic_admin') }}</span><span class="kpi-value">{{ $n($people['topic']) }}</span><span class="kpi-hint">{{ __('role_pending') }} {{ $n($people['pending']) }}</span></li>
        <li class="kpi"><span class="kpi-label">{{ __('role_none') }}</span><span class="kpi-value">{{ $n($people['regular']) }}</span></li>
        <li class="kpi"><span class="kpi-label">{{ __('stats_logins_30') }}</span><span class="kpi-value">{{ $n($people['logins_30']) }}</span><span class="kpi-hint">7 {{ $de ? 'Tage' : 'days' }}: {{ $n($people['logins_7']) }} · {{ __('stats_last_login') }} {{ $ago($people['last_login']) }}</span></li>
        <li class="kpi"><span class="kpi-label">{{ __('topics') }}</span><span class="kpi-value">{{ $n($topics['active']) }}</span><span class="kpi-hint">{{ __('status_deactivated') }} {{ $n($topics['deactivated']) }} · {{ __('login_required_badge') }} {{ $n($topics['require_login']) }}</span></li>
        <li class="kpi"><span class="kpi-label">{{ __('stats_integrations') }}</span><span class="kpi-value">{{ $n($topics['otrs'] + $topics['webhook']) }}</span><span class="kpi-hint">OTRS {{ $n($topics['otrs']) }} · Webhook {{ $n($topics['webhook']) }}</span></li>
    </ul>

    <div class="chart-grid">
        <figure class="chart">
            <figcaption>
                <h3>{{ __('audit_title') }}</h3>
                <span class="muted">{{ __('stats_last_30_days') }} · {{ $n($audit['last_30d']) }} · {{ __('stats_total') }} {{ $n($audit['total']) }}</span>
            </figcaption>
            @php $maxDomain = max($audit['by_domain_30d'] ?: [0]); @endphp
            @if ($audit['by_domain_30d'] === [])
                <p class="muted">{{ __('stats_no_data') }}</p>
            @else
                <ol class="hbars">
                    @foreach ($audit['by_domain_30d'] as $domain => $count)
                        <li class="hbar" tabindex="0" title="{{ $domain }}: {{ $n($count) }}">
                            <span class="hbar-label"><code>{{ $domain }}</code></span>
                            <span class="hbar-track"><span class="hbar-fill" style="width: {{ $maxDomain > 0 ? $count / $maxDomain * 100 : 0 }}%"></span></span>
                            <span class="hbar-value num">{{ $n($count) }}</span>
                        </li>
                    @endforeach
                </ol>
            @endif
            <p class="muted chart-foot">{{ __('stats_last_24h') }} {{ $n($audit['last_24h']) }} · {{ __('stats_last_7d') }} {{ $n($audit['last_7d']) }}</p>
        </figure>
    </div>
</section>

{{-- ================= Retention ================= --}}
<section class="stats-section">
    <div class="section-header"><h2>{{ __('stats_retention') }}</h2><span class="muted">{{ __('stats_retention_hint') }}</span></div>
    <div class="table-wrap">
        <table class="table-cards">
            <thead>
                <tr>
                    <th>{{ __('stats_category') }}</th>
                    <th>{{ __('stats_window') }}</th>
                    <th class="text-right">{{ __('stats_due_next_run') }}</th>
                    <th>{{ __('stats_last_deletion') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($retention as $row)
                    <tr>
                        <td class="cell-title">{{ __('stats_retention_'.$row['key']) }}</td>
                        <td data-label="{{ __('stats_window') }}">{{ $row['window'] === null ? __('stats_disabled') : $n($row['window']).' '.($de ? 'Tage' : 'days') }}</td>
                        <td data-label="{{ __('stats_due_next_run') }}" class="text-right num">{{ $row['window'] === null ? '—' : $n($row['due']) }}</td>
                        <td data-label="{{ __('stats_last_deletion') }}">{{ $row['last_run'] === null ? __('stats_never') : $row['last_run']->format('d.m.Y').' · '.$ago($row['last_run']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

{{-- ================= System ================= --}}
<section class="stats-section">
    <div class="section-header">
        <h2>{{ __('stats_system') }}</h2>
        <span class="muted">
            @if ($failing === 0)<span class="dot dot-ok" aria-hidden="true"></span>{{ __('stats_all_checks_pass') }}
            @else<span class="dot dot-warn" aria-hidden="true"></span>{{ trans_choice('stats_checks_failing', $failing, ['count' => $failing]) }}@endif
        </span>
    </div>

    <div class="spec-grid">
        <div class="spec spec-checks">
            <h3>{{ __('stats_checks') }}</h3>
            <ul class="health">
                @foreach ($checks as [$label, $ok, $detail])
                    <li>
                        <span class="dot {{ $ok ? 'dot-ok' : 'dot-warn' }}" aria-hidden="true"></span>
                        <span class="health-label">{{ $label }}</span>
                        <span class="health-state">{{ $ok ? __('stats_ok') : __('stats_attention') }}</span>
                        @if ($detail !== null)<span class="health-detail">{{ $detail }}</span>@endif
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="spec">
            <h3>{{ __('stats_application') }}</h3>
            <dl class="spec-list">
                <dt>{{ __('stats_environment') }}</dt><dd><code>{{ $tech['app_env'] }}</code>@if ($tech['app_debug']) · <code>debug</code>@endif</dd>
                <dt>Laravel</dt><dd>{{ $tech['laravel'] }}</dd>
                <dt>PHP</dt><dd>{{ $tech['php'] }} · OPcache {{ $tech['opcache'] ? __('stats_enabled') : __('stats_disabled') }}</dd>
                <dt>{{ __('stats_timezone') }}</dt><dd>{{ $tech['timezone'] }} · {{ $tech['locale'] }}</dd>
                <dt>URL</dt><dd>{{ $tech['app_url'] }}</dd>
                <dt>{{ __('stats_scheduler') }}</dt><dd>{{ $heartbeat === null ? __('stats_never') : $ago($heartbeat) }}</dd>
            </dl>
        </div>

        <div class="spec">
            <h3>{{ __('stats_database') }}</h3>
            <dl class="spec-list">
                <dt>{{ __('stats_driver') }}</dt><dd>{{ $tech['db_driver'] }} {{ $tech['db_version'] }}</dd>
                @foreach ($tech['tables'] as $table => $count)
                    <dt><code>{{ $table }}</code></dt><dd class="num">{{ $n($count) }}</dd>
                @endforeach
            </dl>
        </div>

        <div class="spec">
            <h3>{{ __('stats_storage') }}</h3>
            <dl class="spec-list">
                <dt>{{ __('stats_uploads') }}</dt><dd>{{ $n($storage['files']) }} {{ __('stats_files') }} · {{ $bytes($storage['upload_bytes']) }}</dd>
                <dt>{{ __('stats_logs') }}</dt><dd>{{ $bytes($storage['log_bytes']) }}</dd>
                @if ($storage['disk_total'] !== null && $storage['disk_free'] !== null)
                    @php $used = $storage['disk_total'] - $storage['disk_free']; $usedPct = (int) round($used / max(1, $storage['disk_total']) * 100); @endphp
                    <dt>{{ __('stats_disk') }}</dt>
                    <dd>
                        {{ $bytes((int) $storage['disk_free']) }} {{ __('stats_free_of') }} {{ $bytes((int) $storage['disk_total']) }}
                        <span class="meter {{ $usedPct >= 90 ? 'meter-danger' : ($usedPct >= 75 ? 'meter-warning' : '') }}" role="meter" aria-valuenow="{{ $usedPct }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('stats_disk') }}"><span class="meter-fill" style="width: {{ $usedPct }}%"></span></span>
                    </dd>
                @endif
            </dl>
        </div>

        <div class="spec">
            <h3>{{ __('stats_services') }}</h3>
            <dl class="spec-list">
                <dt>Queue</dt><dd><code>{{ $tech['queue'] }}</code></dd>
                <dt>Cache</dt><dd><code>{{ $tech['cache'] }}</code></dd>
                <dt>Session</dt><dd><code>{{ $tech['session'] }}</code> · {{ $n($tech['session_lifetime']) }} min</dd>
                <dt>Mail</dt><dd><code>{{ $tech['mail'] }}</code>@if ($tech['mail'] === 'smtp' && $tech['mail_host'] !== '') · {{ $tech['mail_host'] }}@endif</dd>
                <dt>Log</dt><dd><code>{{ $tech['log_stack'] }}</code> · {{ $n($tech['log_days']) }} {{ $de ? 'Tage' : 'days' }}</dd>
                <dt>PHP</dt><dd>memory {{ $tech['memory_limit'] }} · upload {{ $tech['upload_max'] }} · post {{ $tech['post_max'] }} · exec {{ $tech['max_execution'] }}s</dd>
            </dl>
        </div>

        <div class="spec">
            <h3>{{ __('stats_integrations') }}</h3>
            <dl class="spec-list">
                <dt>SAML</dt><dd>{{ $tech['saml_configured'] ? __('stats_configured') : __('stats_not_configured') }}</dd>
                <dt>OTRS</dt><dd>{{ $tech['otrs_configured'] ? __('stats_configured') : __('stats_not_configured') }}@if ($tech['otrs_configured']) · Inbound {{ $tech['otrs_inbound'] ? __('stats_enabled') : __('stats_disabled') }}@endif</dd>
                <dt>Webhooks</dt><dd>{{ $tech['webhook_signed'] ? __('stats_signed') : __('stats_unsigned') }}</dd>
                <dt>Dev-Login</dt><dd>{{ $tech['dev_login'] ? __('stats_enabled') : __('stats_disabled') }}</dd>
            </dl>
        </div>
    </div>
</section>
@endsection
