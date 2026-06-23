<?php

namespace App\Console\Commands;

use App\Mail\ReportNotification;
use App\Models\AuditLog;
use App\Models\Message;
use App\Models\Report;
use App\Services\MessengerDispatcher;
use App\Services\OtrsReplyImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Pull case-handler answers back out of OTRS and mirror them into reports so a
 * reporter sees them in the platform (and is emailed, when they left a contact
 * address) — the inbound half of the OTRS integration. Run from the scheduler.
 *
 * Each customer-visible agent article becomes an `is_admin` message tagged
 * `source = 'otrs'`. That tag makes OtrsMessenger skip it on the resulting
 * notification fan-out, so an imported answer is never pushed back into its own
 * ticket. Importing an answer also acknowledges the report — an agent reply is
 * acknowledgement, exactly as an in-platform admin reply is.
 *
 * A no-op unless MELDE_OTRS_INBOUND_ENABLED is set and the connection is
 * configured; scoped to still-open reports that have an OTRS ticket.
 */
class PollOtrsReplies extends Command
{
    protected $signature = 'otrs:poll-replies {--dry-run : List answers that would be imported without writing anything}';

    protected $description = 'Import OTRS agent answers back into reports so reporters see them in the platform';

    public function handle(MessengerDispatcher $dispatcher): int
    {
        $importer = OtrsReplyImporter::fromConfig();
        if ($importer === null) {
            $this->info('OTRS inbound is disabled or not configured; nothing to poll.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $imported = 0;

        // Only reports that have a ticket and can still receive a reply — a
        // Done/Spam report's conversation is over, so we stop polling it.
        Report::query()
            ->active()
            ->whereNotNull('otrs_ticket_id')
            ->with('topic')
            ->each(function (Report $report) use ($importer, $dispatcher, $dryRun, &$imported): void {
                try {
                    $answers = $importer->newAnswers($report);
                } catch (\Throwable $e) {
                    // One unreachable/erroring ticket must not abort the batch.
                    Log::error('PollOtrsReplies: failed to fetch answers', [
                        'report_id' => $report->id,
                        'error' => $e->getMessage(),
                    ]);

                    return;
                }

                foreach ($answers as $answer) {
                    if ($dryRun) {
                        $this->line(sprintf('would import OTRS article %s into report #%d', $answer['id'], $report->id));

                        continue;
                    }

                    $this->importAnswer($report, $answer, $dispatcher);
                    $imported++;
                }
            });

        $this->info($dryRun ? 'Dry run complete.' : "Imported {$imported} OTRS answer(s).");

        return self::SUCCESS;
    }

    /**
     * Persist one imported answer and run the same follow-up an in-platform
     * admin reply does. The high-water mark is advanced as the first write so a
     * later failure in the (best-effort) notifications cannot cause a re-import.
     *
     * @param array{id: string, body: string} $answer
     */
    private function importAnswer(Report $report, array $answer, MessengerDispatcher $dispatcher): void
    {
        $topic = $report->topic;

        $message = Message::create([
            'report_id' => $report->id,
            'content' => $answer['body'],
            'is_admin' => true,
            'source' => 'otrs',
        ]);

        $report->forceFill(['otrs_last_article_id' => $answer['id']])->save();
        $report->acknowledge();
        AuditLog::record('report.replied', $report, ['source' => 'otrs']);

        $subject = sprintf('[%s]: report #%d updated', $topic->name('en'), $report->id);
        $adminUrl = route('admin.report.show', ['topic' => $topic->id, 'report' => $report->id]);

        // Fan out to the topic's other channels (email/webhook). The OTRS
        // channel self-skips this `source = 'otrs'` message, so no echo.
        $dispatcher->dispatch($topic, $subject, $message, $adminUrl);

        // Notify the reporter exactly as TopicAdminController::replyToReport
        // does — only when they chose to leave a contact email.
        if ($report->creator !== null && filter_var($report->creator, FILTER_VALIDATE_EMAIL) !== false) {
            try {
                Mail::to($report->creator)->send(new ReportNotification(
                    subjectLine: $subject,
                    heading: sprintf('Update zu Meldung #%d', $report->id),
                    linkUrl: route('report.show', ['reporterToken' => $report->reporter_token]),
                ));
            } catch (\Throwable $e) {
                Log::error('PollOtrsReplies: failed to notify reporter', ['error' => $e->getMessage()]);
            }
        }
    }
}
