<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // The OTRS/Znuny ticket this report was pushed into. Lets later
            // messages append to the SAME ticket (TicketUpdate) instead of
            // opening a new one each time. Null until the report is first sent
            // to a topic that routes to OTRS. `otrs_ticket_number` is the
            // human-facing number, kept only for display/cross-reference.
            $table->string('otrs_ticket_id')->nullable()->after('creator');
            $table->string('otrs_ticket_number')->nullable()->after('otrs_ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn(['otrs_ticket_id', 'otrs_ticket_number']);
        });
    }
};
