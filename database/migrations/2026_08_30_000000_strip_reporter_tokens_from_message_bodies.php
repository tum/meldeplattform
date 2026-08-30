<?php

use App\Support\AttachmentLinks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: remove the reporter access token from attachment links already
 * stored in `messages.content`.
 *
 * Report bodies used to embed `?…&token=<reporter_token>` in every upload link.
 * The body is not a reporter-only artefact — it is rendered to every case
 * handler on the admin report page, and for topics routing to OTRS it is pushed
 * verbatim into the ticket — so those copies handed the reporter's own access
 * credential to everyone who could read them. That token is enough to open the
 * report and to post messages *as the reporter*.
 *
 * New bodies are written without it (StoreReportSubmission) and the token is
 * re-attached at render time for the reporter only (AttachmentLinks). This
 * migration cleans up the rows written before that change; the links keep
 * working, because they are rebuilt from the File row rather than read from the
 * body.
 *
 * Irreversible by design: down() would have to re-introduce the leak.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('messages')
            ->where('content', 'like', '%token=%')
            ->orderBy('id')
            ->chunkById(200, function (iterable $messages): void {
                foreach ($messages as $message) {
                    $content = is_string($message->content) ? $message->content : '';
                    $stripped = AttachmentLinks::stripReporterTokens($content);

                    if ($stripped !== $content) {
                        // Raw update: `messages` touches its parent report, and
                        // reports.updated_at drives the admin unread badge — a
                        // data cleanup must not mark every report unread again.
                        DB::table('messages')
                            ->where('id', $message->id)
                            ->update(['content' => $stripped]);
                    }
                }
            });
    }

    public function down(): void
    {
        // No down path: restoring the tokens would restore the leak.
    }
};
