<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            // Store the disk-relative key, not an absolute filesystem path.
            // Lets Storage::disk()->download() enforce the disk-root boundary
            // and keeps rows portable if the upload disk ever moves to S3.
            $table->renameColumn('location', 'path');
            $table->string('disk', 64)->default('uploads')->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropColumn('disk');
            $table->renameColumn('path', 'location');
        });
    }
};
