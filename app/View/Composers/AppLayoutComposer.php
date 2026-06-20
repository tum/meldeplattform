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

        // The unread badge only renders for topics the user can manage
        // (see pages/index.blade.php `@can('update', $t)`), so scope the
        // count query to exactly those — both to match the UI and to avoid
        // aggregating reports the user can't see.
        $unreadCounts = $user instanceof User
            ? $this->unreadCountsFor($user, $this->managedTopicIds($user))
            : [];

        $view->with([
            'topicsAll' => $topics,
            'unreadByTopic' => $unreadCounts,
        ]);
    }

    /**
     * The subset of topic IDs the user is allowed to manage (global admins
     * see all; topic-admins only their own). Filtering happens in SQL via
     * Topic::manageableBy so the cost scales with the user's topics, not the
     * total topic count.
     *
     * @return list<int>
     */
    private function managedTopicIds(User $user): array
    {
        /** @var list<int> $ids */
        $ids = Topic::query()
            ->manageableBy($user)
            ->pluck('id')
            ->all();

        return $ids;
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
