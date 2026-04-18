<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->string('name_de');
            $table->string('name_en');
            $table->text('summary_de')->nullable();
            $table->text('summary_en')->nullable();
            $table->string('email')->nullable();
            $table->json('contacts')->nullable();
            $table->timestamps();
        });

        Schema::create('fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->string('name_de');
            $table->string('name_en');
            $table->text('description_de')->nullable();
            $table->text('description_en')->nullable();
            $table->string('type', 32)->default('text');
            $table->boolean('required')->default(false);
            $table->json('choices')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index();
            $table->timestamps();
        });

        Schema::create('topic_admins', function (Blueprint $table) {
            $table->foreignId('topic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
            $table->primary(['topic_id', 'admin_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topic_admins');
        Schema::dropIfExists('admins');
        Schema::dropIfExists('fields');
        Schema::dropIfExists('topics');
    }
};
