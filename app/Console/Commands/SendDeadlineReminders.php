<?php

namespace App\Console\Commands;

use App\Mail\DeadlineReminder;
use App\Models\Report;
use App\Models\Topic;
use App\Services\MessengerDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email each topic's case handlers a digest of reports approaching or past a
 * statutory deadline (EU Directive Art. 9 / HinSchG §17: 7-day acknowledgement,
 * 3-month feedback). Run daily from the scheduler.
 *
 * Without this, an overdue report is only visible as a passive badge on the
 * dashboard — a handler who does not log in never learns a deadline is about to
 * lapse. The digest is content-free (report IDs + deadlines only) and is sent
 * to the same mailbox that receives "report opened" notifications.
 */
class SendDeadlineReminders extends Command
{
    protected $signature = 'reports:remind {--dry-run : List what would be sent without sending anything}';

    protected $description = 'Email case handlers about reports approaching or past an acknowledgement/feedback deadline';

    public function handle(MessengerDispatcher $dispatcher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ackLead = Config::integer('meldeplattform.reminder_ack_lead_days', 2);
        $feedbackLead = Config::integer('meldeplattform.reminder_feedback_lead_days', 14);
        $dashboardUrl = route('dashboard');
        $dispatched = 0;

        foreach (Topic::query()->lazy() as $topic) {
            $target = $dispatcher->emailTarget($topic);
            if ($target === null) {
                continue; // No mailbox configured — nothing to remind.
            }

            /** @var list<array{id: int, type: string, due: string, overdue: bool}> $items */
            $items = [];

            Report::query()
                ->where('topic_id', $topic->id)
                ->active()
                ->orderBy('created_at')
                ->each(function (Report $report) use (&$items, $ackLead, $feedbackLead): void {
                    $needsAck = $report->needsAcknowledgementReminder($ackLead);
                    $needsFeedback = $report->needsFeedbackReminder($feedbackLead);

                    if ($needsAck) {
                        $items[] = [
                            'id' => $report->id,
                            'type' => 'acknowledgement',
                            'due' => $report->acknowledgementDueAt()?->format('d.m.Y') ?? '',
                            'overdue' => $report->isAcknowledgementOverdue(),
                        ];
                    } elseif ($needsFeedback) {
                        // Only emit the feedback deadline when no ack reminder was already
                        // added for this report, so each report appears at most once in the
                        // digest. Acknowledgement is the more urgent obligation and takes
                        // precedence.
                        $items[] = [
                            'id' => $report->id,
                            'type' => 'feedback',
                            'due' => $report->feedbackDueAt()?->format('d.m.Y') ?? '',
                            'overdue' => $report->isFeedbackOverdue(),
                        ];
                    }
                });

            if ($items === []) {
                continue;
            }

            $subject = sprintf('[%s]: %d report(s) approaching a deadline', $topic->name('en'), count($items));

            if ($dryRun) {
                $this->line(sprintf('would remind %s about %d item(s) for topic "%s"', $target, count($items), $topic->name('en')));
                $dispatched++;
            } else {
                try {
                    Mail::to($target)->send(new DeadlineReminder($subject, $topic->name('en'), $dashboardUrl, $items));
                    $dispatched++;
                } catch (\Throwable $e) {
                    Log::error('Deadline reminder delivery failed', [
                        'topic_id' => $topic->id,
                        'target' => $target,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }
            }
        }

        $verb = $dryRun ? 'Would send' : 'Sent';
        $this->info(sprintf('%s %d reminder digest(s).', $verb, $dispatched));

        return self::SUCCESS;
    }
}
