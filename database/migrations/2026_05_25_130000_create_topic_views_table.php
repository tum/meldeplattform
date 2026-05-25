<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tracks "user X last opened topic Y at time Z" so we can render
        // an unread badge for admins on the home page topic cards.
        Schema::create('topic_views', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->primary(['user_id', 'topic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_views');
    }
};
