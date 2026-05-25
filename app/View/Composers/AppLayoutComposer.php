<?php

namespace App\View\Composers;

use App\Models\Topic;
use Illuminate\View\View;

/**
 * Loads the topic list for the home page only. Cheaper per-request data
 * (locale-derived strings) is registered via View::share in AppServiceProvider
 * so it is also available inside @section('title', ...) expressions, which
 * evaluate before the parent layout (and so before this composer fires).
 */
class AppLayoutComposer
{
    public function compose(View $view): void
    {
        $view->with('topicsAll', Topic::with('admins')->get());
    }
}
