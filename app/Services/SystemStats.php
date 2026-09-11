<?php

namespace App\Services;

use App\Enums\ReportState;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use App\Support\Retention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Everything the /stats page shows, computed on request. Aggregation is
 * done in PHP over narrow column sets rather than in SQL date functions so
 * the same code runs on MySQL (production) and SQLite (tests). The report
 * volume of a whistleblowing platform is small enough that a full scan of a
 * few columns is cheap; only the upload-size walk is cached.
 */
final class SystemStats
{
    private const TREND_MONTHS = 12;

    /**
     * Run a `select key, count(*)` grouping and return [key => int].
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     * @return array<int|string, int>
     */
    private static function countsBy(Builder $query, string $column): array
    {
        $out = [];
        foreach ($query->select($column, DB::raw('count(*) as n'))->groupBy($column)->get() as $row) {
            /** @var object{n: int|string} $row */
            $key = $row->{$column};
            // Cast attributes (e.g. `state` → ReportState) arrive as enums.
            if ($key instanceof \BackedEnum) {
                $key = $key->value;
            }
            $out[is_int($key) ? $key : (string) $key] = (int) $row->n;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function reports(): array
    {
        $now = Carbon::now();
        $trendStart = $now->copy()->startOfMonth()->subMonths(self::TREND_MONTHS - 1);

        $byState = self::countsBy(Report::query(), 'state');
        $total = array_sum($byState);
        $states = [];
        foreach (ReportState::cases() as $state) {
            $states[$state->value] = $byState[$state->value] ?? 0;
        }

        // Active reports (open / in progress) carry the deadline signals.
        $active = Report::query()
            ->whereIn('state', [ReportState::Open->value, ReportState::InProgress->value])
            ->get(['id', 'created_at', 'acknowledged_at', 'state', 'closed_at']);
        $ackOverdue = $active->filter(fn (Report $r): bool => $r->isAcknowledgementOverdue())->count();
        $feedbackOverdue = $active->filter(fn (Report $r): bool => $r->isFeedbackOverdue())->count();
        $stale = Report::staleDays() === null ? 0 : Report::query()->staleNow()->count();

        // Last 12 months: monthly intake, response times and deadline adherence.
        $recent = Report::query()
            ->where('created_at', '>=', $trendStart)
            ->get(['id', 'topic_id', 'state', 'created_at', 'acknowledged_at', 'closed_at', 'creator']);

        $months = [];
        for ($i = 0; $i < self::TREND_MONTHS; $i++) {
            $m = $trendStart->copy()->addMonths($i);
            $months[$m->format('Y-m')] = ['label' => $m->translatedFormat('M'), 'year' => $m->format('Y'), 'count' => 0];
        }
        foreach ($recent as $r) {
            $key = $r->created_at?->format('Y-m');
            if ($key !== null && isset($months[$key])) {
                $months[$key]['count']++;
            }
        }

        $last30 = $recent->filter(fn (Report $r): bool => $r->created_at !== null && $r->created_at->gte($now->copy()->subDays(30)))->count();
        $prev30 = $recent->filter(fn (Report $r): bool => $r->created_at !== null
            && $r->created_at->lt($now->copy()->subDays(30))
            && $r->created_at->gte($now->copy()->subDays(60)))->count();

        /** @var list<float> $ackHours */
        $ackHours = [];
        /** @var list<float> $closeDays */
        $closeDays = [];
        foreach ($recent as $r) {
            if ($r->created_at === null) {
                continue;
            }
            if ($r->acknowledged_at !== null) {
                $ackHours[] = $r->created_at->diffInMinutes($r->acknowledged_at) / 60;
            }
            if ($r->closed_at !== null && $r->state === ReportState::Done) {
                $closeDays[] = $r->created_at->diffInMinutes($r->closed_at) / 1440;
            }
        }

        $ackDeadline = is_numeric($v = config('meldeplattform.acknowledgement_deadline_days')) ? (int) $v : 7;
        $feedbackDeadline = is_numeric($v = config('meldeplattform.feedback_deadline_days')) ? (int) $v : 90;
        // Deadline adherence over reports old enough to have had the full window
        // (or already acknowledged/closed), excluding spam.
        $ackEligible = 0;
        $ackOnTime = 0;
        $feedbackEligible = 0;
        $feedbackOnTime = 0;
        foreach ($recent as $r) {
            if ($r->state === ReportState::Spam || $r->created_at === null) {
                continue;
            }
            if ($r->acknowledged_at !== null || $r->created_at->lt($now->copy()->subDays($ackDeadline))) {
                $ackEligible++;
                if ($r->acknowledged_at !== null && $r->acknowledged_at->lte($r->created_at->copy()->addDays($ackDeadline))) {
                    $ackOnTime++;
                }
            }
            if ($r->closed_at !== null || $r->created_at->lt($now->copy()->subDays($feedbackDeadline))) {
                $feedbackEligible++;
                if ($r->closed_at !== null && $r->closed_at->lte($r->created_at->copy()->addDays($feedbackDeadline))) {
                    $feedbackOnTime++;
                }
            }
        }

        $anonymous = Report::query()->where(function ($q): void {
            $q->whereNull('creator')->orWhere('creator', '');
        })->count();

        // Per topic (all time), top 8 + "other".
        $topicNames = Topic::query()->get(['id', 'name_de', 'name_en'])->keyBy('id');
        $byTopic = self::countsBy(Report::query(), 'topic_id');
        arsort($byTopic);
        $openByTopic = self::countsBy(
            Report::query()->whereIn('state', [ReportState::Open->value, ReportState::InProgress->value]),
            'topic_id',
        );
        $topics = [];
        $other = 0;
        $rank = 0;
        foreach ($byTopic as $topicId => $n) {
            if ($rank++ < 8) {
                $topics[] = [
                    'id' => (int) $topicId,
                    'name_de' => $topicNames[$topicId]->name_de ?? '#'.$topicId,
                    'name_en' => $topicNames[$topicId]->name_en ?? '#'.$topicId,
                    'count' => $n,
                    'open' => $openByTopic[$topicId] ?? 0,
                ];
            } else {
                $other += $n;
            }
        }

        return [
            'total' => $total,
            'states' => $states,
            'active' => $states['open'] + $states['in_progress'],
            'ack_overdue' => $ackOverdue,
            'feedback_overdue' => $feedbackOverdue,
            'stale' => $stale,
            'stale_days' => Report::staleDays(),
            'last_30' => $last30,
            'prev_30' => $prev30,
            'months' => array_values($months),
            'median_ack_hours' => self::median($ackHours),
            'median_close_days' => self::median($closeDays),
            'ack_on_time' => $ackOnTime,
            'ack_eligible' => $ackEligible,
            'feedback_on_time' => $feedbackOnTime,
            'feedback_eligible' => $feedbackEligible,
            'anonymous' => $anonymous,
            'by_topic' => $topics,
            'by_topic_other' => $other,
        ];
    }

    /** @return array<string, int|float> */
    public function communication(): array
    {
        $messages = Message::query()->count();
        $adminReplies = Message::query()->where('is_admin', true)->count();
        $reportsWithReply = Message::query()->where('is_admin', true)->distinct()->count('report_id');
        $messages30 = Message::query()->where('created_at', '>=', Carbon::now()->subDays(30))->count();
        $withAttachments = Report::query()->whereHas('messages.files')->count();
        $otrsMirrored = Message::query()->where('source', 'otrs')->count();

        return [
            'messages' => $messages,
            'admin_replies' => $adminReplies,
            'reporter_messages' => $messages - $adminReplies,
            'reports_with_reply' => $reportsWithReply,
            'messages_30' => $messages30,
            'with_attachments' => $withAttachments,
            'otrs_mirrored' => $otrsMirrored,
        ];
    }

    /** @return array<string, mixed> */
    public function people(): array
    {
        $envAdmins = Retention::envAdmins();
        $users = User::query()->get(['uid', 'is_global_admin', 'last_login_at']);
        /** @var list<string> $topicAdminUids */
        $topicAdminUids = Admin::query()->whereHas('topics')->pluck('user_id')->all();
        /** @var list<string> $knownUids */
        $knownUids = $users->map(fn (User $u): string => $u->uid)->values()->all();

        $global = $users->filter(fn (User $u): bool => $u->is_global_admin || in_array($u->uid, $envAdmins, true))->count();
        $topicAdmins = $users->filter(fn (User $u): bool => ! $u->is_global_admin && ! in_array($u->uid, $envAdmins, true) && in_array($u->uid, $topicAdminUids, true))->count();
        $pending = count(array_diff($topicAdminUids, $knownUids));
        $regular = $users->count() - $global - $topicAdmins;
        $now = Carbon::now();

        return [
            'total' => $users->count(),
            'global' => $global,
            'topic' => $topicAdmins,
            'pending' => $pending,
            'regular' => $regular,
            'logins_30' => $users->filter(fn (User $u): bool => $u->last_login_at !== null && $u->last_login_at->gte($now->copy()->subDays(30)))->count(),
            'logins_7' => $users->filter(fn (User $u): bool => $u->last_login_at !== null && $u->last_login_at->gte($now->copy()->subDays(7)))->count(),
            'last_login' => $users->max('last_login_at'),
        ];
    }

    /** @return array<string, mixed> */
    public function topics(): array
    {
        $topics = Topic::query()->get(['id', 'deactivated_at', 'require_login', 'retention_days', 'contacts']);

        return [
            'total' => $topics->count(),
            'active' => $topics->whereNull('deactivated_at')->count(),
            'deactivated' => $topics->whereNotNull('deactivated_at')->count(),
            'require_login' => $topics->filter(fn (Topic $t): bool => (bool) $t->require_login)->count(),
            'own_retention' => $topics->whereNotNull('retention_days')->count(),
            'otrs' => $topics->filter(fn (Topic $t): bool => TopicContacts::fromTopic($t)->otrsQueue !== null)->count(),
            'webhook' => $topics->filter(fn (Topic $t): bool => TopicContacts::fromTopic($t)->webhookTarget !== null)->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function audit(): array
    {
        $now = Carbon::now();
        $rows = AuditLog::query()
            ->where('created_at', '>=', $now->copy()->subDays(30))
            ->get(['action', 'created_at']);

        /** @var array<string, int> $byDomain */
        $byDomain = [];
        foreach ($rows as $row) {
            $domain = strstr($row->action, '.', true) ?: $row->action;
            $byDomain[$domain] = ($byDomain[$domain] ?? 0) + 1;
        }
        arsort($byDomain);

        return [
            'total' => AuditLog::query()->count(),
            'last_24h' => $rows->filter(fn (AuditLog $l): bool => $l->created_at !== null && $l->created_at->gte($now->copy()->subDay()))->count(),
            'last_7d' => $rows->filter(fn (AuditLog $l): bool => $l->created_at !== null && $l->created_at->gte($now->copy()->subDays(7)))->count(),
            'last_30d' => $rows->count(),
            'by_domain_30d' => $byDomain,
            'oldest' => AuditLog::query()->min('created_at'),
        ];
    }

    /**
     * What the next prune runs will delete, per category, next to the window
     * that drives it and the last run that actually deleted something.
     *
     * @return list<array{key: string, window: int|null, due: int, last_run: Carbon|null}>
     */
    public function retention(): array
    {
        $now = Carbon::now();

        $reportsDue = 0;
        $spamDue = 0;
        foreach (Topic::query()->get() as $topic) {
            $days = $topic->effectiveRetentionDays();
            $spamDays = Retention::spamWindowFor($days);
            if ($days !== null) {
                $reportsDue += Retention::reportsDue($topic, $now->copy()->subDays($days), spamOnly: false, excludeSpam: $spamDays !== null)->count();
            }
            if ($spamDays !== null) {
                $spamDue += Retention::reportsDue($topic, $now->copy()->subDays($spamDays), spamOnly: true, excludeSpam: false)->count();
            }
        }

        $inactiveDays = Retention::window('inactive_user_days');
        $dormantDays = Retention::window('dormant_admin_days');
        $auditDays = Retention::window('audit_retention_days');

        $lastRun = static function (string $action): ?Carbon {
            $raw = AuditLog::query()->where('action', $action)->max('created_at');

            return is_string($raw) && $raw !== '' ? Carbon::parse($raw) : null;
        };

        return [
            ['key' => 'reports', 'window' => Retention::window('default_retention_days'), 'due' => $reportsDue, 'last_run' => $lastRun('reports.pruned')],
            ['key' => 'spam', 'window' => Retention::window('spam_retention_days'), 'due' => $spamDue, 'last_run' => $lastRun('reports.pruned')],
            ['key' => 'users', 'window' => $inactiveDays, 'due' => $inactiveDays === null ? 0 : Retention::inactiveUsers($now->copy()->subDays($inactiveDays))->count(), 'last_run' => $lastRun('users.pruned')],
            ['key' => 'admins', 'window' => $dormantDays, 'due' => $dormantDays === null ? 0
                : Retention::dormantAdministrators($now->copy()->subDays($dormantDays))->count() + Retention::expiredPreAssignments($now->copy()->subDays($dormantDays))->count(),
                'last_run' => $lastRun('admin.revoked')],
            ['key' => 'audit', 'window' => $auditDays, 'due' => $auditDays === null ? 0 : Retention::auditEntriesDue($now->copy()->subDays($auditDays))->count(), 'last_run' => $lastRun('audit.pruned')],
        ];
    }

    /** @return array<string, mixed> */
    public function storage(): array
    {
        $root = config('filesystems.disks.uploads.root');
        $uploadsRoot = is_string($root) && $root !== '' ? $root : storage_path('app/uploads');

        // Walking the uploads tree costs one stat() per file; cache the sum.
        $uploadBytes = Cache::remember('stats.upload_bytes', 600, function (): int {
            $disk = Storage::disk('uploads');
            $bytes = 0;
            foreach ($disk->allFiles() as $path) {
                $bytes += (int) $disk->size($path);
            }

            return $bytes;
        });

        $logBytes = 0;
        foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
            $logBytes += (int) filesize($log);
        }

        return [
            'files' => File::query()->count(),
            'upload_bytes' => $uploadBytes,
            'log_bytes' => $logBytes,
            'disk_free' => @disk_free_space($uploadsRoot) ?: null,
            'disk_total' => @disk_total_space($uploadsRoot) ?: null,
            'uploads_writable' => is_dir($uploadsRoot) && is_writable($uploadsRoot),
            'logs_writable' => is_writable(storage_path('logs')),
        ];
    }

    /** @return array<string, mixed> */
    public function technical(): array
    {
        $connection = DB::connection();
        try {
            $attr = $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $dbVersion = is_scalar($attr) ? (string) $attr : '';
        } catch (\Throwable) {
            $dbVersion = '';
        }

        /** @var array<string, int> $tables */
        $tables = [];
        foreach (['reports', 'messages', 'files', 'topics', 'users', 'admins', 'audit_logs', 'topic_views'] as $table) {
            $tables[$table] = (int) DB::table($table)->count();
        }

        $opcache = function_exists('opcache_get_status') ? (opcache_get_status(false)['opcache_enabled'] ?? false) : false;

        // Plain config() + casts, not the typed accessors: an env-backed key
        // such as session.secure is *present but null* when the variable is
        // unset, and Config::boolean() throws on null instead of defaulting.
        $str = static fn (string $key, string $default = ''): string => is_scalar($v = config($key)) ? (string) $v : $default;
        $bool = static fn (string $key): bool => filter_var(config($key), FILTER_VALIDATE_BOOLEAN);
        $int = static fn (string $key): int => is_numeric($v = config($key)) ? (int) $v : 0;
        /** @var array<int, mixed> $stack */
        $stack = (array) config('logging.channels.stack.channels', []);

        return [
            'app_env' => $str('app.env'),
            'app_debug' => $bool('app.debug'),
            'app_url' => $str('app.url'),
            'laravel' => app()->version(),
            'php' => PHP_VERSION,
            'timezone' => $str('app.timezone', 'UTC'),
            'locale' => $str('app.locale'),
            'db_driver' => $connection->getDriverName(),
            'db_version' => $dbVersion,
            'tables' => $tables,
            'queue' => $str('queue.default'),
            'cache' => $str('cache.default'),
            'session' => $str('session.driver'),
            'session_lifetime' => $int('session.lifetime'),
            'session_secure' => $bool('session.secure'),
            'mail' => $str('mail.default'),
            'mail_host' => $str('mail.mailers.smtp.host'),
            'log_stack' => implode(',', array_map(static fn ($c): string => is_string($c) ? $c : '', $stack)),
            'log_days' => $int('logging.channels.daily.days'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'upload_max' => (string) ini_get('upload_max_filesize'),
            'post_max' => (string) ini_get('post_max_size'),
            'max_execution' => (string) ini_get('max_execution_time'),
            'opcache' => (bool) $opcache,
            'configured_upload_mb' => $int('meldeplattform.max_upload_mb'),
            'saml_configured' => trim($str('saml2.idp.x509cert')) !== '',
            'otrs_configured' => trim($str('meldeplattform.otrs.base_url')) !== '',
            'otrs_inbound' => $bool('meldeplattform.otrs.inbound_enabled'),
            'webhook_signed' => trim($str('meldeplattform.webhook_secret')) !== '',
            'dev_login' => $bool('meldeplattform.dev_login_enabled'),
            'app_key_set' => trim($str('app.key')) !== '',
            'scheduler_heartbeat' => self::schedulerHeartbeat(),
        ];
    }

    /**
     * The scheduler's last heartbeat (routes/console.php touches the key every
     * five minutes). Null until it has run at least once.
     */
    public static function schedulerHeartbeat(): ?Carbon
    {
        $raw = Cache::get('scheduler.heartbeat');

        return is_string($raw) && $raw !== '' ? Carbon::parse($raw) : null;
    }

    /**
     * Bytes as a human size ("12,3 MB"), locale-aware decimal separator.
     */
    public static function bytes(?int $bytes, string $lang = 'en'): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        $decimals = $i === 0 ? 0 : 1;

        return $lang === 'de'
            ? number_format($value, $decimals, ',', '.').' '.$units[$i]
            : number_format($value, $decimals, '.', ',').' '.$units[$i];
    }

    /**
     * Convert a php.ini shorthand size ("128M", "2G") to bytes; null when it
     * is unlimited (-1) or unparseable.
     */
    public static function iniBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }
        $unit = strtoupper(substr($value, -1));
        $number = (float) $value;
        $factor = match ($unit) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        return (int) ($number * $factor);
    }

    /** @param list<float> $values */
    public static function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
