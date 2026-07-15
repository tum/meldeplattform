<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            // Drop the brittle DB-level enum in favour of a plain string. A
            // native enum requires a column ALTER every time a new state is
            // added (painful and locking on MySQL); a string lets new
            // ReportState cases ship with no further schema change — the enum
            // contract now lives entirely in app code (App\Enums\ReportState).
            $table->string('state', 32)->default('open')->change();

            // EU Whistleblowing Directive (2019/1937) acknowledgement: when
            // the report was first acknowledged by an administrator. Null
            // until acknowledged.
            $table->timestamp('acknowledged_at')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        // `in_progress` shipped after this migration and is written by
        // setStatus()/bulkSetStatus() today, so restoring the original
        // three-value enum would fail on data truncation under strict mode and
        // leave the schema half-rolled-back — precisely during the incident that
        // prompted the rollback. Fold those rows back to a value the old enum
        // accepts first; `open` is the honest choice, since in_progress means
        // acknowledged-but-not-concluded and acknowledged_at (which is about to
        // be dropped) is what distinguished them.
        DB::table('reports')->where('state', 'in_progress')->update(['state' => 'open']);

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('acknowledged_at');
            // Restore the original three-value enum.
            $table->enum('state', ['open', 'done', 'spam'])->default('open')->change();
        });
    }
};
