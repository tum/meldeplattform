<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
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

        return view('pages.audit', [
            'entries' => $entries,
        ]);
    }
}
