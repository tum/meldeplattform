<?php

namespace App\Console\Commands;

use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
        /** @var list<array{report_id: int, ticket_id: string, ticket_number: string|null}> $orphanedTickets */
        $orphanedTickets = [];

        foreach (Topic::query()->lazy() as $topic) {
            $days = $topic->effectiveRetentionDays();
            if ($days === null) {
                continue;
            }

            $cutoff = now()->subDays($days);

            // lazyById() pages by keyset (`where id > last`), not OFFSET. each()
            // /chunk() page with OFFSET, and deleting the rows being paged
            // shifts every later row left by one page — so each page after the
            // first skipped exactly the rows that moved into it, silently
            // leaving reports past their statutory deletion date in place and
            // over-reporting the count. Keyset paging is unaffected by deletes.
            $due = Report::query()
                ->where('topic_id', $topic->id)
                ->whereNotNull('closed_at')
                ->where('closed_at', '<', $cutoff)
                ->with('messages.files')
                ->lazyById();

            foreach ($due as $report) {
                $total++;
                if ($dryRun) {
                    $this->line(sprintf(
                        'would prune report #%d (concluded %s)',
                        $report->id,
                        $report->closed_at?->toDateTimeString() ?? 'unknown',
                    ));

                    continue;
                }

                $ticket = $this->pruneReport($report);
                if ($ticket !== null) {
                    $orphanedTickets[] = $ticket;
                }
            }
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->info(sprintf('%s %d report(s).', $verb, $total));

        $this->reportOrphanedOtrsTickets($orphanedTickets, $dryRun);

        return self::SUCCESS;
    }

    /**
     * OTRS holds a full copy of the report body, and this command cannot delete
     * it: the standard GenericTicketConnectorREST webservice this integration
     * speaks exposes TicketCreate/TicketUpdate/TicketGet/TicketSearch, but no
     * TicketDelete. Once the report row is gone its `otrs_ticket_id` goes with
     * it, so the ticket is not even discoverable afterwards.
     *
     * Rather than let the statutory deletion duty fail silently, name every
     * affected ticket so an operator can discharge it OTRS-side.
     *
     * @param list<array{report_id: int, ticket_id: string, ticket_number: string|null}> $tickets
     */
    private function reportOrphanedOtrsTickets(array $tickets, bool $dryRun): void
    {
        if ($tickets === []) {
            return;
        }

        $this->newLine();
        $this->warn(sprintf(
            '%d pruned report(s) have an OTRS ticket holding a full copy of the report body.',
            count($tickets),
        ));
        $this->warn('This command cannot delete them — delete them in OTRS to complete the retention duty:');

        foreach ($tickets as $ticket) {
            $this->line(sprintf(
                '  report #%d -> OTRS ticket %s%s',
                $ticket['report_id'],
                $ticket['ticket_number'] ?? $ticket['ticket_id'],
                $ticket['ticket_number'] !== null ? ' (id '.$ticket['ticket_id'].')' : '',
            ));
        }

        if ($dryRun) {
            return;
        }

        // Also log it: the console output of a cron run is usually unread, and
        // after this run the ticket reference no longer exists anywhere else.
        Log::warning('PruneReports: OTRS tickets survive their pruned reports and must be deleted manually', [
            'tickets' => $tickets,
        ]);
    }

    /**
     * Delete one report and any uploads that become orphaned by doing so.
     * Report deletion cascades to messages and the message_files pivot at the
     * DB level, but File rows + the physical blobs are independent and must be
     * removed explicitly.
     *
     * @return array{report_id: int, ticket_id: string, ticket_number: string|null}|null
     *                                                                                   the OTRS ticket this report leaves behind, if any
     */
    private function pruneReport(Report $report): ?array
    {
        /** @var array<int, File> $files */
        $files = $report->messages
            ->flatMap(fn (Message $message): iterable => $message->files)
            ->unique('id')
            ->all();

        // Capture before the delete: afterwards the reference is gone for good.
        $ticketId = is_string($report->otrs_ticket_id) ? trim($report->otrs_ticket_id) : '';
        $ticket = $ticketId === '' ? null : [
            'report_id' => $report->id,
            'ticket_id' => $ticketId,
            'ticket_number' => is_string($report->otrs_ticket_number) && $report->otrs_ticket_number !== ''
                ? $report->otrs_ticket_number
                : null,
        ];

        $report->delete();

        foreach ($files as $file) {
            // Keep a file only if it is still attached to some surviving
            // message (defensive — uploads are normally tied to one report).
            if ($file->messages()->exists()) {
                continue;
            }

            try {
                Storage::disk($file->disk)->delete($file->path);
            } catch (\Throwable $e) {
                // One unreadable blob must not abort the run: every remaining
                // report in this and every later topic is also past its
                // deletion date, and without a catch the `finally` below still
                // re-threw. Name the path — the File row is about to go, so
                // this log line is the only remaining way to find the orphan.
                Log::warning('PruneReports: could not delete stored file; blob may be orphaned on disk', [
                    'disk' => $file->disk,
                    'path' => $file->path,
                    'error' => $e->getMessage(),
                ]);
            } finally {
                // Always remove the DB row even if the storage call throws,
                // preventing permanently orphaned File records.
                $file->delete();
            }
        }

        return $ticket;
    }
}
