<?php

namespace App\View\Composers;

use App\Models\Report;
use App\Models\Topic;
use App\Models\TopicView;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
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
     * @param list<int> $topicIds
     * @return array<int, int>
     */
    private function unreadCountsFor(User $user, array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }

        $lastSeen = TopicView::where('user_id', $user->id)
            ->whereIn('topic_id', $topicIds)
            ->pluck('last_seen_at', 'topic_id');

        $counts = [];
        foreach ($topicIds as $topicId) {
            $cutoff = $lastSeen->get($topicId);
            $query = Report::where('topic_id', $topicId);
            if ($cutoff !== null) {
                $query->where('updated_at', '>', $cutoff);
            }
            $counts[$topicId] = $query->count();
        }

        return $counts;
    }
}
