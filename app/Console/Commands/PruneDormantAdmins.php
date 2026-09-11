<?php

namespace App\Console\Commands;

use App\Mail\DormantAdminsRevoked;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Revoke administrator access that has gone unused for longer than
 * `meldeplattform.dormant_admin_days`. Unused privileged access is the
 * classic leaver risk — a case handler changes role or leaves the university
 * and keeps a way into whistleblower reports. Access-rights hygiene of this
 * kind is required by BSI IT-Grundschutz ORP.4, ISO 27001 A.5.18 and NIST
 * SP 800-53 AC-2(3), and it serves GDPR data minimisation.
 *
 * Two kinds of rows are handled:
 *  - administrators (topic assignments and/or the DB global-admin flag) whose
 *    last login is older than the window: the assignments and the flag are
 *    removed, the user row stays (users:prune takes it later);
 *  - pre-assigned admins that never logged in (an `admins` row with no `users`
 *    row) created longer ago than the window: the row is dropped.
 *
 * Admins named in MELDE_ADMIN_USERS are never touched — that list is the
 * bootstrap path and is managed in the environment. Every revocation is
 * audit-logged, and the global admins are e-mailed a summary so the change
 * is seen by a human; re-granting in /users is one click if someone returns.
 */
class PruneDormantAdmins extends Command
{
    protected $signature = 'admins:prune {--dry-run : List what would be revoked without changing anything}';

    protected $description = 'Revoke administrator access unused past the configured window';

    public function handle(): int
    {
        $days = config('meldeplattform.dormant_admin_days');
        if (! is_int($days) || $days <= 0) {
            $this->info('Dormant-admin cleanup is disabled (MELDE_DORMANT_ADMIN_DAYS).');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        /** @var list<string> $envAdmins */
        $envAdmins = array_values(array_filter((array) config('meldeplattform.admin_users', []), 'is_string'));

        /** @var list<array{uid: string, last_login: string|null, kind: string}> $revoked */
        $revoked = [];

        foreach ($this->dormantAdministrators($cutoff, $envAdmins) as $user) {
            $lastLogin = $user->last_login_at?->toDateTimeString();
            $revoked[] = ['uid' => $user->uid, 'last_login' => $lastLogin, 'kind' => 'dormant'];
            $this->line(sprintf('%s admin access of %s (last login %s)', $dryRun ? 'would revoke' : 'revoked', $user->uid, $lastLogin ?? 'never'));
            if (! $dryRun) {
                $this->revoke($user->uid, ['reason' => 'dormant', 'last_login_at' => $lastLogin, 'window_days' => $days]);
            }
        }

        foreach ($this->expiredPreAssignments($cutoff, $envAdmins) as $admin) {
            $revoked[] = ['uid' => $admin->user_id, 'last_login' => null, 'kind' => 'never_logged_in'];
            $this->line(sprintf('%s pre-assigned admin %s (assigned %s, never logged in)', $dryRun ? 'would drop' : 'dropped', $admin->user_id, $admin->created_at?->toDateString() ?? 'unknown'));
            if (! $dryRun) {
                $this->revoke($admin->user_id, ['reason' => 'never_logged_in', 'assigned_at' => $admin->created_at?->toDateTimeString(), 'window_days' => $days]);
            }
        }

        $this->info(sprintf('%s %d administrator(s).', $dryRun ? 'Would revoke' : 'Revoked', count($revoked)));

        if (! $dryRun && $revoked !== []) {
            $this->notifyGlobalAdmins($revoked, $days);
        }

        return self::SUCCESS;
    }

    /**
     * Users holding topic assignments or the DB global-admin flag whose last
     * login (or account creation, when they never logged in since the column
     * exists) predates the cutoff.
     *
     * @param list<string> $envAdmins
     * @return iterable<int, User>
     */
    private function dormantAdministrators(Carbon $cutoff, array $envAdmins): iterable
    {
        /** @var list<string> $topicAdminUids */
        $topicAdminUids = Admin::query()->pluck('user_id')->all();

        return User::query()
            ->whereNotIn('uid', $envAdmins)
            ->where(function ($q) use ($topicAdminUids): void {
                $q->where('is_global_admin', true)->orWhereIn('uid', $topicAdminUids);
            })
            ->where(function ($q) use ($cutoff): void {
                $q->where('last_login_at', '<', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff): void {
                        $inner->whereNull('last_login_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->orderBy('uid')
            ->lazyById();
    }

    /**
     * Pre-assigned admins (an `admins` row without a matching `users` row)
     * older than the cutoff: the person never showed up to use the access.
     *
     * @param list<string> $envAdmins
     * @return iterable<int, Admin>
     */
    private function expiredPreAssignments(Carbon $cutoff, array $envAdmins): iterable
    {
        return Admin::query()
            ->whereNotIn('user_id', $envAdmins)
            ->whereDoesntHave('user')
            ->where('created_at', '<', $cutoff)
            ->orderBy('user_id')
            ->lazyById();
    }

    /**
     * Same effect as revoking in /users: drop the topic assignments (the
     * pivot cascades) and clear the DB global-admin flag. The user row itself
     * is left for users:prune — it is a login record, not a privilege.
     *
     * @param array<string, mixed> $metadata
     */
    private function revoke(string $uid, array $metadata): void
    {
        DB::transaction(function () use ($uid): void {
            Admin::where('user_id', $uid)->delete();
            User::where('uid', $uid)->update(['is_global_admin' => false]);
        });

        AuditLog::record('admin.revoked', null, ['target_uid' => $uid] + $metadata);
    }

    /**
     * Tell every global admin with an e-mail address what was revoked. One
     * message per run; a failure is logged, never fatal — the audit log already
     * holds the record of truth.
     *
     * @param list<array{uid: string, last_login: string|null, kind: string}> $revoked
     */
    private function notifyGlobalAdmins(array $revoked, int $days): void
    {
        /** @var list<string> $envAdmins */
        $envAdmins = array_values(array_filter((array) config('meldeplattform.admin_users', []), 'is_string'));

        /** @var list<string> $recipients */
        $recipients = User::query()
            ->where(function ($q) use ($envAdmins): void {
                $q->where('is_global_admin', true)->orWhereIn('uid', $envAdmins);
            })
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email')
            ->unique()
            ->values()
            ->all();

        if ($recipients === []) {
            Log::warning('PruneDormantAdmins: no global admin has an e-mail address; revocations are only in the audit log', [
                'revoked' => array_column($revoked, 'uid'),
            ]);

            return;
        }

        try {
            Mail::to($recipients)->send(new DormantAdminsRevoked($revoked, $days, route('users.index')));
        } catch (\Throwable $e) {
            Log::error('PruneDormantAdmins: notification delivery failed', [
                'recipients' => count($recipients),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
