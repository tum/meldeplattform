<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopicLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_topic_is_hidden_from_public_home_and_form(): void
    {
        $active = Topic::factory()->create(['name_de' => 'ActiveTopicXZ', 'name_en' => 'ActiveTopicXZ']);
        $off = Topic::factory()->create(['name_de' => 'OfflineTopicQW', 'name_en' => 'OfflineTopicQW']);
        $off->deactivate();

        $this->get('/')
            ->assertOk()
            ->assertSee('ActiveTopicXZ')
            ->assertDontSee('OfflineTopicQW');

        // The public form is gone for a deactivated topic; an active one is fine.
        $this->get("/form/{$off->id}")->assertNotFound();
        $this->get("/form/{$active->id}")->assertOk();
    }

    public function test_deactivate_then_activate_toggles_state_and_audits(): void
    {
        $this->actingAsGlobalAdmin();
        $topic = Topic::factory()->create();

        $this->post("/api/topic/{$topic->id}/deactivate")->assertRedirect();
        $this->assertNotNull($topic->refresh()->deactivated_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'topic.deactivated',
            'subject_type' => 'topic',
            'subject_id' => $topic->id,
        ]);

        $this->post("/api/topic/{$topic->id}/activate")->assertRedirect();
        $this->assertNull($topic->refresh()->deactivated_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'topic.reactivated',
            'subject_id' => $topic->id,
        ]);
    }

    public function test_existing_reports_remain_accessible_after_deactivation(): void
    {
        $this->actingAsGlobalAdmin();
        $topic = Topic::factory()->create();
        $report = Report::factory()->for($topic)->create();

        $topic->deactivate();

        // Deactivation blocks only NEW reports; existing threads stay open to admins.
        $this->get("/reports/{$topic->id}/{$report->id}")->assertOk();
        $this->get("/reports/{$topic->id}")->assertOk();
    }

    public function test_delete_is_blocked_while_topic_has_reports(): void
    {
        $this->actingAsGlobalAdmin();
        $topic = Topic::factory()->create();
        Report::factory()->for($topic)->create();

        $this->delete("/api/topic/{$topic->id}")
            ->assertRedirect()
            ->assertSessionHas('flash.error');

        $this->assertDatabaseHas('topics', ['id' => $topic->id]);
    }

    public function test_delete_removes_empty_topic_and_prunes_orphan_admins(): void
    {
        $this->actingAsGlobalAdmin();
        $topic = Topic::factory()->create();
        $admin = Admin::create(['user_id' => 'lonely']);
        $admin->topics()->attach($topic);

        $this->delete("/api/topic/{$topic->id}")
            ->assertRedirect('/topics')
            ->assertSessionHas('flash.success');

        $this->assertDatabaseMissing('topics', ['id' => $topic->id]);
        // The admin held only this topic, so it is cleaned up with it.
        $this->assertDatabaseMissing('admins', ['user_id' => 'lonely']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'topic.deleted']);
    }

    public function test_topic_admin_can_deactivate_but_not_delete(): void
    {
        $topic = Topic::factory()->create();
        $admin = Admin::create(['user_id' => 'topicadmin']);
        $admin->topics()->attach($topic);
        $this->actingAsUser('topicadmin');

        $this->post("/api/topic/{$topic->id}/deactivate")->assertRedirect();
        $this->assertNotNull($topic->refresh()->deactivated_at);

        // Delete is reserved for global admins.
        $this->delete("/api/topic/{$topic->id}")->assertStatus(403);
        $this->assertDatabaseHas('topics', ['id' => $topic->id]);
    }
}
