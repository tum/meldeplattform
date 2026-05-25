<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * The production topic list ("IT Sicherheit", "Compliance") is seeded
     * by the dedicated `2026_04_18_120000_seed_default_topics` migration —
     * which is idempotent and already shipped in production. New tooling
     * should register seeders here.
     */
    public function run(): void {}
}
