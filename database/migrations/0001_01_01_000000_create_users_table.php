<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The app authenticates exclusively via SAML; the `users` table is kept
 * so future Laravel-auth-based features (e.g. Gate-based Policy checks
 * tied to an Eloquent User) have somewhere to land. It is not populated
 * at runtime today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('uid')->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
