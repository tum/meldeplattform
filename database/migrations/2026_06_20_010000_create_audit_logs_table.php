<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // The acting admin's uid, or 'system' when no auth user is bound.
            $table->string('actor')->nullable();
            $table->string('action')->index();
            // Lightweight polymorphic reference: a short type string
            // ('report'/'topic'/'user') plus the subject's id.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            // Small structured context. NEVER reporter PII / report content.
            $table->json('metadata')->nullable();
            // Append-only: created_at only, rows are never updated.
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
