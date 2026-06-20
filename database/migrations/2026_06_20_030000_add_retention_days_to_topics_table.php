<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table): void {
            // Per-topic data-retention window in days. Null = fall back to the
            // global MELDE_DEFAULT_RETENTION_DAYS (which itself may be null =
            // keep forever). Reports with no activity for longer than this are
            // pruned by the reports:prune command (GDPR data minimisation).
            $table->unsignedInteger('retention_days')->nullable()->after('require_login');
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table): void {
            $table->dropColumn('retention_days');
        });
    }
};
