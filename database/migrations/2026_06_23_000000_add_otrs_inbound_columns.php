<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // High-water mark for the inbound OTRS poll: the OTRS ArticleID of
            // the most recent agent answer already mirrored back into this
            // report. The poll only imports articles with a larger id, so the
            // at-least-once `otrs:poll-replies` run never duplicates an answer.
            // Null until the first answer is imported.
            $table->string('otrs_last_article_id')->nullable()->after('otrs_ticket_number');
        });

        Schema::table('messages', function (Blueprint $table) {
            // Origin of the message. Null = created in the platform (reporter or
            // admin). 'otrs' = mirrored in from an OTRS agent answer by the
            // inbound poll. The OTRS messenger skips 'otrs' messages so an
            // imported answer is never pushed back into the ticket it came from.
            $table->string('source')->nullable()->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('otrs_last_article_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
