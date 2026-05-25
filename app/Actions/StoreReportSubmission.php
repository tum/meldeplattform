<?php

namespace App\Actions;

use App\Models\File;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Services\MessengerDispatcher;
use Illuminate\Support\Facades\DB;

class StoreReportSubmission
{
    public function __construct(private readonly MessengerDispatcher $messengers) {}

    /**
     * Persist a new Report + first Message in a single transaction, attach
     * any uploads, then notify the topic's configured messengers about it.
     *
     * @param list<File> $files
     */
    public function execute(Topic $topic, string $messageBody, ?string $email, array $files): Report
    {
        $report = DB::transaction(function () use ($topic, $messageBody, $email, $files): Report {
            $report = Report::create([
                'topic_id' => $topic->id,
                'creator' => $email,
            ]);
            $message = Message::create([
                'report_id' => $report->id,
                'content' => $messageBody,
                'is_admin' => false,
            ]);
            if ($files !== []) {
                $message->files()->sync(array_map(static fn (File $f): int => $f->id, $files));
            }
            $report->setRelation('messages', collect([$message]));

            return $report;
        });

        $firstMessage = $report->messages->first();
        if ($firstMessage instanceof Message) {
            $this->messengers->dispatch(
                $topic,
                sprintf('[%s]: report #%d opened', $topic->name('en'), $report->id),
                $firstMessage,
                route('report.show', ['administratorToken' => $report->administrator_token]),
            );
        }

        return $report;
    }
}
