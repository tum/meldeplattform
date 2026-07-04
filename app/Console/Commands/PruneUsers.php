<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Delete role-less user accounts that have been dormant past the configured
 * window (`meldeplattform.inactive_user_days`). Every login creates a `users`
 * row, so without this the table grows unbounded; a pruned account is just a
 * stale login record and is recreated automatically on the person's next login.
 *
 * An account is kept — never pruned — when it carries any role: a DB global
 * admin (`is_global_admin`), an env global admin (`MELDE_ADMIN_USERS`), or a
 * topic admin / pre-assigned admin (a row in `admins`). Reports reference their
 * reporter by a plain string, not a foreign key, so they are never affected.
 */
class PruneUsers extends Command
{
    protected $signature = 'users:prune {--dry-run : List what would be deleted without deleting anything}';

    protected $description = 'Delete role-less user accounts inactive past the configured window';

    public function handle(): int
    {
        $days = config('meldeplattform.inactive_user_days');
        if (! is_int($days) || $days <= 0) {
            $this->info('Inactive-user cleanup is disabled (MELDE_INACTIVE_USER_DAYS).');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = $this->pruneableQuery($cutoff);

        $total = 0;
        if ($dryRun) {
            $query->orderBy('uid')->get()->each(function (User $user) use (&$total): void {
                $total++;
                $this->line(sprintf(
                    'would prune user %s (last login %s)',
                    $user->uid,
                    $user->last_login_at?->toDateTimeString() ?? 'never',
                ));
            });
        } else {
            // A single mass delete; topic_views cascade at the DB level via the
            // foreign key, so no per-row model events are needed. delete()
            // returns mixed on the Eloquent builder — normalise for the counter.
            $deleted = $query->delete();
            $total = is_numeric($deleted) ? (int) $deleted : 0;
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->info(sprintf('%s %d inactive user(s).', $verb, $total));

        return self::SUCCESS;
    }

    /**
     * Role-less users whose last login (or account creation, for rows predating
     * the last_login_at column) is older than the cutoff.
     *
     * @return Builder<User>
     */
    private function pruneableQuery(Carbon $cutoff): Builder
    {
        // UIDs that carry a role are loaded up front — the `admins` table holds
        // only actual admins, so this set stays small regardless of how many
        // plain users have accumulated.
        /** @var list<string> $envAdmins */
        $envAdmins = array_values(array_filter(
            (array) config('meldeplattform.admin_users', []),
            'is_string',
        ));
        /** @var list<string> $adminUids */
        $adminUids = Admin::query()->pluck('user_id')->all();
        $keepUids = array_values(array_unique(array_merge($envAdmins, $adminUids)));

        return User::query()
            ->where('is_global_admin', false)
            ->whereNotIn('uid', $keepUids)
            ->where(function (Builder $q) use ($cutoff): void {
                $q->where('last_login_at', '<', $cutoff)
                    ->orWhere(function (Builder $inner) use ($cutoff): void {
                        $inner->whereNull('last_login_at')->where('created_at', '<', $cutoff);
                    });
            });
    }
}
