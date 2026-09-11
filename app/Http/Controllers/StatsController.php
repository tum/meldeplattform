<?php

namespace App\Http\Controllers;

use App\Services\SystemStats;
use Illuminate\View\View;

/**
 * Operational overview for global admins: case-handling figures, people and
 * access, retention status and the technical health of the installation.
 * Gated by `can:manage,User` on the route, like /users and /audit. Shows
 * counts and configuration only — never report content or reporter data.
 */
class StatsController
{
    public function index(SystemStats $stats): View
    {
        return view('pages.stats', [
            'reports' => $stats->reports(),
            'communication' => $stats->communication(),
            'people' => $stats->people(),
            'topics' => $stats->topics(),
            'audit' => $stats->audit(),
            'retention' => $stats->retention(),
            'storage' => $stats->storage(),
            'tech' => $stats->technical(),
            'generatedAt' => now(),
        ]);
    }
}
