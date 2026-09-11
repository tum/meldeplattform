<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\View\View;

/**
 * Read-only viewer for the append-only audit log. Gated to global admins
 * only via the `can:manage,User` middleware on the route (see routes/web.php),
 * the same gate that protects the /users management UI.
 */
class AuditController
{
    public function index(): View
    {
        $entries = AuditLog::latest()->paginate(50);

        // Actor is stored as the bare UID (audit rows outlive users); look
        // up the display names for this page's actors in one query.
        /** @var array<string, string> $actorNames */
        $actorNames = User::query()
            ->whereIn('uid', $entries->getCollection()->pluck('actor')->filter()->unique())
            ->whereNotNull('name')
            ->pluck('name', 'uid')
            ->all();

        return view('pages.audit', [
            'entries' => $entries,
            'actorNames' => $actorNames,
        ]);
    }
}
