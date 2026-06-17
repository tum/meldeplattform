<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Models\TopicView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnreadIndicatorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Topic, 1: User}
     */
    private function topicWithAdmin(string $uid): array
    {
        $topic = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '',
        ]);
        $admin = Admin::create(['user_id' => $uid]);
        $topic->admins()->attach($admin);
        $user = User::updateOrCreate(['uid' => $uid], ['name' => $uid, 'email' => "{$uid}@x"]);

        return [$topic, $user];
    }

    public function test_visiting_reports_marks_topic_seen(): void
    {
        [$topic, $user] = $this->topicWithAdmin('seenadmin');

        $this->actingAs($user)->get("/reports/{$topic->id}")->assertOk();

        $this->assertDatabaseHas('topic_views', [
            'user_id' => $user->id,
            'topic_id' => $topic->id,
        ]);
    }

    public function test_new_messages_touch_report_updated_at(): void
    {
        $topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);
        $report = Report::create(['topic_id' => $topic->id]);
        $original = $report->updated_at;

        // Travel time forward so the touch produces a distinguishable timestamp.
        $this->travel(2)->seconds();
        Message::create(['report_id' => $report->id, 'content' => 'hi', 'is_admin' => false]);

        $fresh = $report->fresh();
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->updated_at);
        $this->assertNotNull($original);
        $this->assertTrue($fresh->updated_at->greaterThan($original));
    }

    public function test_unread_count_drops_to_zero_after_visit(): void
    {
        [$topic, $user] = $this->topicWithAdmin('countadmin');
        Report::create(['topic_id' => $topic->id]);

        $this->actingAs($user)->get('/')->assertOk()->assertSee('unread-badge', escape: false);

        // Mark seen and re-render: badge should no longer render.
        TopicView::markSeen($user->id, $topic->id);
        $this->travel(1)->seconds();

        $this->actingAs($user)->get('/')->assertOk()->assertDontSee('unread-badge', escape: false);
    }

    public function test_unread_counts_are_scoped_to_managed_topics(): void
    {
        // The user administers `mine` but not `other`; both have reports.
        [$mine, $user] = $this->topicWithAdmin('scopedadmin');
        $other = Topic::create(['name_de' => 'O', 'name_en' => 'O', 'summary_de' => '', 'summary_en' => '']);
        Report::create(['topic_id' => $mine->id]);
        Report::create(['topic_id' => $other->id]);

        $response = $this->actingAs($user)->get('/')->assertOk();

        /** @var array<int, int> $unread */
        $unread = $response->viewData('unreadByTopic');
        $this->assertArrayHasKey($mine->id, $unread);
        $this->assertArrayNotHasKey($other->id, $unread, 'unread must not be computed for unmanaged topics');
    }
}
