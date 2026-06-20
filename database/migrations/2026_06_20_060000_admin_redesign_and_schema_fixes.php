<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove administrator_token — admin access now requires a login session.
        Schema::table('reports', function (Blueprint $table) {
            $table->dropUnique(['administrator_token']);
            $table->dropColumn('administrator_token');
        });

        // Backfill closed_at for any concluded reports that still have NULL
        // (can happen when updated_at was null on historical rows during the
        // earlier backfill migration).
        DB::statement("
            UPDATE reports
            SET closed_at = created_at
            WHERE state IN ('done','spam')
              AND closed_at IS NULL
        ");

        // Make audit_logs.created_at non-nullable — the event timestamp must
        // always be recorded for an append-only audit trail to be useful.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable(false)->default(DB::raw('CURRENT_TIMESTAMP'))->change();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->change();
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->string('administrator_token', 64)->nullable()->unique()->after('reporter_token');
        });
    }
};
