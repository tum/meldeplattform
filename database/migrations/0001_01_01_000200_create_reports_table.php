<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->string('reporter_token', 64)->unique();
            $table->string('administrator_token', 64)->unique();
            $table->enum('state', ['open', 'done', 'spam'])->default('open');
            $table->string('creator', 512)->nullable();
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->longText('content');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });

        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('location');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('message_files', function (Blueprint $table) {
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->primary(['message_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_files');
        Schema::dropIfExists('files');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('reports');
    }
};
