<?php

use App\Enums\ReportState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a report's procedure was concluded (moved to Done/Spam).
 *
 * HinSchG §11(5) requires documentation to be deleted three years *after the
 * procedure is concluded* — not after the last activity. Anchoring retention
 * on this column (instead of `updated_at`) makes the prune job match the
 * statutory clock and prevents auto-deleting reports whose procedure is still
 * open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('acknowledged_at');
        });

        // Backfill already-concluded reports so existing closed cases keep a
        // retention anchor. We have no historical conclusion timestamp, so
        // `updated_at` (the last activity, which for a closed report is the
        // close itself in the common case) is the best available proxy.
        DB::table('reports')
            ->whereIn('state', [ReportState::Done->value, ReportState::Spam->value])
            ->whereNull('closed_at')
            ->update(['closed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn('closed_at');
        });
    }
};
