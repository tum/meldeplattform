<?php

namespace App\View\Composers;

use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
        $topics = Topic::with('admins')->get();
        $user = Auth::user();

        /** @var list<int> $topicIds */
        $topicIds = $topics->pluck('id')->values()->all();

        $unreadCounts = $user instanceof User
            ? $this->unreadCountsFor($user, $topicIds)
            : [];

        $view->with([
            'topicsAll' => $topics,
            'unreadByTopic' => $unreadCounts,
        ]);
    }

    /**
     * Return [topic_id => unread_count] for topics the user manages.
     * "Unread" = a report in that topic whose `updated_at` (touched on
     * every new Message) is newer than this user's last visit to the
     * topic's reports page.
     *
     * Implemented as a single GROUP BY join so a home page with N topics
     * costs one query instead of N.
     *
     * @param list<int> $topicIds
     * @return array<int, int>
     */
    private function unreadCountsFor(User $user, array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }

        /** @var array<int, int> $rows */
        $rows = DB::table('reports as r')
            ->leftJoin('topic_views as tv', function ($join) use ($user): void {
                $join->on('tv.topic_id', '=', 'r.topic_id')
                    ->where('tv.user_id', '=', $user->id);
            })
            ->whereIn('r.topic_id', $topicIds)
            ->where(function ($q): void {
                $q->whereNull('tv.last_seen_at')
                    ->orWhereColumn('r.updated_at', '>', 'tv.last_seen_at');
            })
            ->groupBy('r.topic_id')
            ->selectRaw('r.topic_id, count(*) as cnt')
            ->pluck('cnt', 'r.topic_id')
            ->map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0)
            ->all();

        // Fill topics with no matching rows so callers can read $counts[$id]
        // without an isset() guard.
        return array_replace(array_fill_keys($topicIds, 0), $rows);
    }
}
