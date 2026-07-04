<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A deactivated topic stops accepting new reports and disappears from
        // the public topic list, while its existing reports stay fully
        // manageable. NULL = active; a timestamp records when it was taken
        // offline. Mirrors the timestamp-as-state idiom already used for
        // reports (closed_at / acknowledged_at). Deletion remains a real
        // delete, only ever allowed for a topic that has no reports.
        Schema::table('topics', function (Blueprint $table): void {
            $table->timestamp('deactivated_at')->nullable()->after('retention_days');
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table): void {
            $table->dropColumn('deactivated_at');
        });
    }
};
