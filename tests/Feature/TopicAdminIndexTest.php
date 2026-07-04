<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicAdminIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_topics_for_global_admin(): void
    {
        $this->actingAsGlobalAdmin();
        Topic::factory()->create(['name_de' => 'IndexThemaAA', 'name_en' => 'IndexThemaAA']);

        $this->get('/topics')->assertOk()->assertSee('IndexThemaAA');
    }

    public function test_search_filters_by_name(): void
    {
        $this->actingAsGlobalAdmin();
        Topic::factory()->create(['name_de' => 'FindMePlease', 'name_en' => 'FindMePlease']);
        Topic::factory()->create(['name_de' => 'HideMeAway', 'name_en' => 'HideMeAway']);

        $this->get('/topics?q=FindMe')
            ->assertOk()
            ->assertSee('FindMePlease')
            ->assertDontSee('HideMeAway');
    }

    public function test_status_filter_shows_only_deactivated(): void
    {
        $this->actingAsGlobalAdmin();
        Topic::factory()->create(['name_de' => 'OnTopicZZ', 'name_en' => 'OnTopicZZ']);
        $off = Topic::factory()->create(['name_de' => 'OffTopicYY', 'name_en' => 'OffTopicYY']);
        $off->deactivate();

        $this->get('/topics?status=deactivated')
            ->assertOk()
            ->assertSee('OffTopicYY')
            ->assertDontSee('OnTopicZZ');
    }

    public function test_topic_admin_sees_only_their_topics(): void
    {
        $mine = Topic::factory()->create(['name_de' => 'MineTopicMM', 'name_en' => 'MineTopicMM']);
        Topic::factory()->create(['name_de' => 'OtherTopicOO', 'name_en' => 'OtherTopicOO']);
        $admin = Admin::create(['user_id' => 'ta']);
        $admin->topics()->attach($mine);
        $this->actingAsUser('ta');

        $this->get('/topics')
            ->assertOk()
            ->assertSee('MineTopicMM')
            ->assertDontSee('OtherTopicOO');
    }

    public function test_bulk_deactivate_is_scoped_to_manageable_topics(): void
    {
        $mine = Topic::factory()->create();
        $other = Topic::factory()->create();
        $admin = Admin::create(['user_id' => 'ta']);
        $admin->topics()->attach($mine);
        $this->actingAsUser('ta');

        $this->post('/api/topics/bulk-status', [
            'action' => 'deactivate',
            'ids' => [$mine->id, $other->id],
        ])->assertRedirect();

        $this->assertNotNull($mine->refresh()->deactivated_at);
        // A topic the actor cannot manage is silently left untouched.
        $this->assertNull($other->refresh()->deactivated_at);
    }
}
