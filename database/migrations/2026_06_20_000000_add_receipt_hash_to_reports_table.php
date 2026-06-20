<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            // Keyed HMAC of the reporter's receipt code. Only the hash is
            // stored so a database leak can't be turned back into the codes
            // reporters use to re-enter their reports.
            $table->string('receipt_hash', 64)->nullable()->unique()->after('administrator_token');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropUnique(['receipt_hash']);
            $table->dropColumn('receipt_hash');
        });
    }
};
