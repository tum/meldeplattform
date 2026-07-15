<?php

namespace Tests\Feature;

use App\Actions\UpsertTopic;
use App\Models\Admin;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saving a topic must not strand `admins` rows. An admin row that survives with
 * no topics shows up in /users as a "Topic admin" with no topics and is never
 * cleaned up by users:prune, which keeps every UID present in `admins`.
 *
 * The regression: the cleanup filtered on the UIDs in the payload, but sync()
 * had just attached every one of them — so `doesntHave('topics')` always matched
 * zero rows, while the admins sync() *detached* were never considered.
 */
class TopicAdminOrphanCleanupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param list<string> $adminUids
     */
    private function save(?Topic $topic, array $adminUids): Topic
    {
        return app(UpsertTopic::class)->execute($topic, [
            'ID' => $topic->id ?? 0,
            'Name' => ['de' => 'T', 'en' => 'T'],
            'Summary' => ['de' => 'S', 'en' => 'S'],
            'Admins' => array_map(static fn (string $uid): array => ['UserID' => $uid], $adminUids),
            'Fields' => [],
        ]);
    }

    public function test_removing_the_last_admin_deletes_the_orphaned_row(): void
    {
        $topic = $this->save(null, ['ge42tum']);
        $this->assertSame(1, Admin::count());

        $this->save($topic->fresh(), []);

        $this->assertSame(0, Admin::doesntHave('topics')->count(), 'orphaned admin row survived');
        $this->assertSame(0, Admin::count());
    }

    public function test_replacing_an_admin_deletes_the_replaced_one(): void
    {
        $topic = $this->save(null, ['old_admin']);

        $this->save($topic->fresh(), ['new_admin']);

        $this->assertNull(Admin::where('user_id', 'old_admin')->first(), 'replaced admin was stranded');
        $this->assertNotNull(Admin::where('user_id', 'new_admin')->first());
        $this->assertSame(0, Admin::doesntHave('topics')->count());
    }

    public function test_an_admin_who_still_administers_another_topic_is_kept(): void
    {
        $shared = $this->save(null, ['multi']);
        $other = $this->save(null, ['multi']);

        // Dropped from one topic, but still attached to the other.
        $this->save($shared->fresh(), []);

        $admin = Admin::where('user_id', 'multi')->first();
        $this->assertNotNull($admin, 'admin was deleted despite still holding a topic');
        $this->assertTrue($admin->topics->contains($other->id));
    }

    public function test_admins_kept_across_a_save_are_untouched(): void
    {
        $topic = $this->save(null, ['keep_me', 'drop_me']);
        $keptId = Admin::where('user_id', 'keep_me')->value('id');

        $this->save($topic->fresh(), ['keep_me']);

        $this->assertSame($keptId, Admin::where('user_id', 'keep_me')->value('id'));
        $this->assertNull(Admin::where('user_id', 'drop_me')->first());
    }
}
