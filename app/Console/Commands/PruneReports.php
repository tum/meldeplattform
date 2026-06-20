<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Delete reports (and their messages + uploaded files) whose procedure was
 * concluded longer ago than their topic's retention window. This enforces the
 * statutory deletion duty (HinSchG §11(5): delete documentation three years
 * after the procedure is concluded) and GDPR data minimisation.
 *
 * The window is measured from `reports.closed_at` (set when a report is moved
 * to Done/Spam), so only concluded cases are ever pruned — a report whose
 * procedure is still open or in progress is never deleted, no matter how long
 * it has been dormant.
 */
class PruneReports extends Command
{
    protected $signature = 'reports:prune {--dry-run : List what would be deleted without deleting anything}';

    protected $description = 'Delete reports past their topic\'s data-retention window';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        foreach (Topic::query()->lazy() as $topic) {
            $days = $topic->effectiveRetentionDays();
            if ($days === null) {
                continue;
            }

            $cutoff = now()->subDays($days);

            Report::query()
                ->where('topic_id', $topic->id)
                ->whereNotNull('closed_at')
                ->where('closed_at', '<', $cutoff)
                ->with('messages.files')
                ->each(function (Report $report) use ($dryRun, &$total): void {
                    $total++;
                    if ($dryRun) {
                        $this->line(sprintf(
                            'would prune report #%d (concluded %s)',
                            $report->id,
                            $report->closed_at?->toDateTimeString() ?? 'unknown',
                        ));

                        return;
                    }
                    $this->pruneReport($report);
                });
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->info(sprintf('%s %d report(s).', $verb, $total));

        return self::SUCCESS;
    }

    /**
     * Delete one report and any uploads that become orphaned by doing so.
     * Report deletion cascades to messages and the message_files pivot at the
     * DB level, but File rows + the physical blobs are independent and must be
     * removed explicitly.
     */
    private function pruneReport(Report $report): void
    {
        /** @var array<int, File> $files */
        $files = $report->messages
            ->flatMap(fn (Message $message): iterable => $message->files)
            ->unique('id')
            ->all();

        $report->delete();

        foreach ($files as $file) {
            // Keep a file only if it is still attached to some surviving
            // message (defensive — uploads are normally tied to one report).
            if ($file->messages()->exists()) {
                continue;
            }

            try {
                Storage::disk($file->disk)->delete($file->path);
            } finally {
                // Always remove the DB row even if the storage call throws,
                // preventing permanently orphaned File records.
                $file->delete();
            }
        }
    }
}
