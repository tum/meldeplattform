<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('acknowledged_at');
            // Restore the original three-value enum.
            $table->enum('state', ['open', 'done', 'spam'])->default('open')->change();
        });
    }
};
