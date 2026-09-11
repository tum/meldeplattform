<?php

namespace App\Support;

use App\Enums\ReportState;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * The retention rules as query builders — one definition shared by the
 * scheduled prune commands (which delete) and the statistics page (which
 * counts what the next run will delete). Every window is a config value in
 * days, null meaning "off"; callers turn that into a cutoff instant.
 */
final class Retention
{
    /** A configured day window, or null when the feature is off. */
    public static function window(string $key): ?int
    {
        $days = config("meldeplattform.{$key}");

        return is_int($days) && $days > 0 ? $days : null;
    }

    /**
     * Spam window that applies to a topic: the configured spam window, never
     * longer than the topic's own — spam must not outlive genuine reports.
     */
    public static function spamWindowFor(?int $topicDays): ?int
    {
        $spamDays = self::window('spam_retention_days');
        if ($spamDays === null) {
            return null;
        }

        return $topicDays === null ? $spamDays : min($spamDays, $topicDays);
    }

    /**
     * Concluded reports of $topic whose `closed_at` predates $cutoff, narrowed
     * to spam only / everything but spam so the two windows never overlap.
     *
     * @return Builder<Report>
     */
    public static function reportsDue(Topic $topic, Carbon $cutoff, bool $spamOnly, bool $excludeSpam): Builder
    {
        $query = Report::query()
            ->where('topic_id', $topic->id)
            ->whereNotNull('closed_at')
            ->where('closed_at', '<', $cutoff);

        if ($spamOnly) {
            $query->where('state', ReportState::Spam->value);
        } elseif ($excludeSpam) {
            $query->where('state', '!=', ReportState::Spam->value);
        }

        return $query;
    }

    /**
     * Role-less users whose last login (or account creation, for rows predating
     * the last_login_at column) is older than the cutoff.
     *
     * @return Builder<User>
     */
    public static function inactiveUsers(Carbon $cutoff): Builder
    {
        // UIDs that carry a role are loaded up front — the `admins` table holds
        // only actual admins, so this set stays small regardless of how many
        // plain users have accumulated.
        /** @var list<string> $adminUids */
        $adminUids = Admin::query()->pluck('user_id')->all();
        $keepUids = array_values(array_unique(array_merge(self::envAdmins(), $adminUids)));

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

    /**
     * Users holding topic assignments or the DB global-admin flag whose last
     * login (or account creation, when they never logged in since the column
     * exists) predates the cutoff. Env admins are exempt.
     *
     * @return Builder<User>
     */
    public static function dormantAdministrators(Carbon $cutoff): Builder
    {
        /** @var list<string> $topicAdminUids */
        $topicAdminUids = Admin::query()->pluck('user_id')->all();

        return User::query()
            ->whereNotIn('uid', self::envAdmins())
            ->where(function (Builder $q) use ($topicAdminUids): void {
                $q->where('is_global_admin', true)->orWhereIn('uid', $topicAdminUids);
            })
            ->where(function (Builder $q) use ($cutoff): void {
                $q->where('last_login_at', '<', $cutoff)
                    ->orWhere(function (Builder $inner) use ($cutoff): void {
                        $inner->whereNull('last_login_at')->where('created_at', '<', $cutoff);
                    });
            });
    }

    /**
     * Pre-assigned admins (an `admins` row without a matching `users` row)
     * older than the cutoff: the person never showed up to use the access.
     *
     * @return Builder<Admin>
     */
    public static function expiredPreAssignments(Carbon $cutoff): Builder
    {
        return Admin::query()
            ->whereNotIn('user_id', self::envAdmins())
            ->whereDoesntHave('user')
            ->where('created_at', '<', $cutoff);
    }

    /**
     * Audit entries older than the cutoff — except entries about a report that
     * still exists, whose access trail is kept as long as the case file.
     *
     * @return Builder<AuditLog>
     */
    public static function auditEntriesDue(Carbon $cutoff): Builder
    {
        return AuditLog::query()
            ->where('created_at', '<', $cutoff)
            ->where(function (Builder $q): void {
                $q->where('subject_type', '!=', 'report')
                    ->orWhereNull('subject_type')
                    ->orWhereNotExists(function (QueryBuilder $sub): void {
                        $sub->selectRaw('1')
                            ->from((new Report)->getTable())
                            ->whereColumn('reports.id', 'audit_logs.subject_id');
                    });
            });
    }

    /** @return list<string> */
    public static function envAdmins(): array
    {
        return array_values(array_filter((array) config('meldeplattform.admin_users', []), 'is_string'));
    }
}
