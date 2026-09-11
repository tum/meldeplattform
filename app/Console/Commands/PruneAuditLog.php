<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Report;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * Delete audit entries older than `meldeplattform.audit_retention_days`.
 *
 * The audit log names the acting administrator on every row, so it is
 * personal data with a purpose that expires: once the case it documents is
 * gone, nothing is left to hold anyone accountable for. Two rules follow:
 *  - entries about a report that STILL exists are kept regardless of age —
 *    the access trail must outlive nothing but the case file itself;
 *  - everything else (topic/admin actions, exports, entries whose report was
 *    pruned) goes after the window.
 *
 * The AuditLog model is append-only and throws on delete(); this command is
 * the one sanctioned deletion path and uses a mass query, which bypasses
 * model events by design. The run itself is recorded as `audit.pruned`.
 */
class PruneAuditLog extends Command
{
    protected $signature = 'audit:prune {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Delete audit entries past the retention window (keeping those of still-existing reports)';

    public function handle(): int
    {
        $days = config('meldeplattform.audit_retention_days');
        if (! is_int($days) || $days <= 0) {
            $this->info('Audit-log retention is disabled (MELDE_AUDIT_RETENTION_DAYS).');

            return self::SUCCESS;
        }

        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $query = AuditLog::query()
            ->where('created_at', '<', $cutoff)
            ->where(function ($q): void {
                $q->where('subject_type', '!=', 'report')
                    ->orWhereNull('subject_type')
                    ->orWhereNotExists(function (QueryBuilder $sub): void {
                        $sub->selectRaw('1')
                            ->from((new Report)->getTable())
                            ->whereColumn('reports.id', 'audit_logs.subject_id');
                    });
            });

        if ($dryRun) {
            $count = $query->count();
            $this->info(sprintf('Would prune %d audit entr%s older than %s.', $count, $count === 1 ? 'y' : 'ies', $cutoff->toDateString()));

            return self::SUCCESS;
        }

        // Mass delete: no models are hydrated, so the model's append-only
        // guard (a `deleting` event) does not fire.
        $deleted = $query->delete();
        $count = is_numeric($deleted) ? (int) $deleted : 0;

        if ($count > 0) {
            AuditLog::record('audit.pruned', null, ['count' => $count, 'older_than' => $cutoff->toDateString(), 'window_days' => $days]);
        }

        $this->info(sprintf('Pruned %d audit entr%s older than %s.', $count, $count === 1 ? 'y' : 'ies', $cutoff->toDateString()));

        return self::SUCCESS;
    }
}
