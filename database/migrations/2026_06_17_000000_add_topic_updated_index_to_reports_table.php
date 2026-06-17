<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The home-page unread badge joins reports on topic_id and compares
        // updated_at against topic_views.last_seen_at, and the dashboard
        // sorts reports by updated_at. A composite (topic_id, updated_at)
        // index serves both the filter and the sort without a table scan.
        Schema::table('reports', function (Blueprint $table): void {
            $table->index(['topic_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->dropIndex(['topic_id', 'updated_at']);
        });
    }
};
