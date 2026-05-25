<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // UI-driven promotions live in this column; `MELDE_ADMIN_USERS` env
        // remains the bootstrap source of truth so a misconfigured DB can't
        // lock the platform owner out of the admin UI.
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_global_admin')->default(false)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_global_admin');
        });
    }
};
