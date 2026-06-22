<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Admin;
use App\Models\Field;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_newtopic_requires_auth(): void
    {
        // Guests are redirected to the login flow by the `auth` middleware:
        // `/saml/out` in prod, `/dev/login` when the dev bypass is enabled
        // (which it is in the test environment).
        $this->get('/newTopic')->assertRedirect('/dev/login');
    }

    public function test_newtopic_requires_global_admin_for_new(): void
    {
        $this->actingAsUser('not-admin')->get('/newTopic')->assertStatus(403);
    }

    public function test_summary_preview_requires_auth(): void
    {
        $this->postJson('/api/topic/summary-preview', ['text' => 'x'])->assertUnauthorized();
    }

    public function test_summary_preview_renders_markdown_and_colors(): void
    {
        $res = $this->actingAsGlobalAdmin()
            ->postJson('/api/topic/summary-preview', ['text' => '{green}done{/green} **bold**']);

        $res->assertOk();
        $html = (string) $res->json('html');
        $this->assertStringContainsString('<span class="t-color-green">done</span>', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function test_summary_preview_rejects_overlong_text(): void
    {
        $this->actingAsGlobalAdmin()
            ->postJson('/api/topic/summary-preview', ['text' => str_repeat('a', 20001)])
            ->assertStatus(422);
    }

    public function test_global_admin_can_open_create_form(): void
    {
        $this->actingAsGlobalAdmin()->get('/newTopic')->assertOk();
    }

    public function test_topic_admin_may_edit_own_topic(): void
    {
        $t = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $admin = Admin::create(['user_id' => 'topicadmin']);
        $t->admins()->attach($admin);

        $this->actingAsUser('topicadmin')->get("/newTopic/{$t->id}")->assertOk();
    }

    public function test_non_admin_cannot_edit_topic(): void
    {
        $t = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $this->actingAsUser('someone-else')->get("/newTopic/{$t->id}")->assertStatus(403);
    }

    public function test_upsert_topic_creates_from_scratch(): void
    {
        $this->actingAsGlobalAdmin()->postJson('/api/topic', [
            'ID' => 0,
            'Name' => ['de' => 'Neues', 'en' => 'New'],
            'Summary' => ['de' => 'S-de', 'en' => 'S-en'],
            'Email' => 'it@tum.de',
            'Fields' => [[
                'ID' => 0,
                'Name' => ['de' => 'N-de', 'en' => 'N-en'],
                'Description' => ['de' => '', 'en' => ''],
                'Type' => 'textarea',
                'Required' => true,
                'Choices' => [],
            ]],
            'Admins' => [['UserID' => 'ge42tum']],
        ])->assertOk()->assertJson(['saved' => true]);

        $this->assertDatabaseHas('topics', ['name_en' => 'New']);
        $newTopic = Topic::where('name_en', 'New')->firstOrFail();
        $this->assertSame(1, Field::where('topic_id', $newTopic->id)->count());
        $this->assertDatabaseHas('admins', ['user_id' => 'ge42tum']);
    }

    public function test_upsert_topic_persists_and_clears_retention_days(): void
    {
        $field = [
            'ID' => 0,
            'Name' => ['de' => 'F', 'en' => 'F'],
            'Description' => ['de' => '', 'en' => ''],
            'Type' => 'textarea',
            'Required' => true,
            'Choices' => [],
        ];

        $this->actingAsGlobalAdmin()->postJson('/api/topic', [
            'ID' => 0,
            'Name' => ['de' => 'R', 'en' => 'R'],
            'RetentionDays' => 90,
            'Fields' => [$field],
            'Admins' => [],
        ])->assertOk();

        $topic = Topic::where('name_en', 'R')->firstOrFail();
        $this->assertSame(90, $topic->retention_days);

        // Exposed back through the editor resource.
        $this->actingAsGlobalAdmin()->getJson("/api/topic/{$topic->id}")
            ->assertJson(['RetentionDays' => 90]);

        // Clearing it (empty = keep forever) is allowed.
        $this->actingAsGlobalAdmin()->postJson("/api/topic/{$topic->id}", [
            'ID' => $topic->id,
            'Name' => ['de' => 'R', 'en' => 'R'],
            'RetentionDays' => null,
            'Fields' => [$field],
            'Admins' => [],
        ])->assertOk();

        $this->assertNull($topic->fresh()?->retention_days);
    }

    public function test_upsert_topic_requires_fields(): void
    {
        // FormRequest validation rejects empty Fields with 422.
        $this->actingAsGlobalAdmin()->postJson('/api/topic', [
            'ID' => 0,
            'Name' => ['de' => 'x', 'en' => 'x'],
            'Fields' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['Fields']);
    }

    public function test_upsert_rejects_topic_with_no_name_in_any_language(): void
    {
        // Both name keys present but blank (the shape the editor posts) must
        // still be rejected — required_without can't catch present-but-empty.
        $this->actingAsGlobalAdmin()->postJson('/api/topic', [
            'ID' => 0,
            'Name' => ['de' => '', 'en' => ''],
            'Fields' => [[
                'Name' => ['de' => 'N', 'en' => 'N'],
                'Type' => 'text',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors(['Name.en']);
    }

    public function test_upsert_accepts_topic_named_in_one_language(): void
    {
        $this->actingAsGlobalAdmin()->postJson('/api/topic', [
            'ID' => 0,
            'Name' => ['de' => '', 'en' => 'English only'],
            'Fields' => [[
                'Name' => ['de' => 'N', 'en' => 'N'],
                'Type' => 'text',
            ]],
        ])->assertOk()->assertJson(['saved' => true]);
    }

    public function test_upsert_rejects_field_with_no_name_in_any_language(): void
    {
        $this->actingAsGlobalAdmin()->postJson('/api/topic', [
            'ID' => 0,
            'Name' => ['de' => 'x', 'en' => 'x'],
            'Fields' => [[
                'Name' => ['de' => '', 'en' => ''],
                'Type' => 'text',
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors(['Fields.0.Name.en']);
    }

    public function test_set_status_does_not_bump_updated_at(): void
    {
        $t = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $r = Report::create(['topic_id' => $t->id]);
        Message::create(['report_id' => $r->id, 'content' => 'init', 'is_admin' => false]);
        $before = $r->fresh()?->updated_at;
        $this->assertNotNull($before);

        $this->travel(5)->seconds();
        $this->actingAsGlobalAdmin()->postJson("/api/topic/{$t->id}/report/{$r->id}/status", ['s' => 'close'])
            ->assertOk();

        $after = Report::findOrFail($r->id);
        $this->assertSame(ReportState::Done, $after->state);
        $this->assertNotNull($after->updated_at);
        $this->assertTrue($after->updated_at->equalTo($before), 'status change must not touch updated_at');
    }

    public function test_bulk_set_status_does_not_bump_updated_at(): void
    {
        $t = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $r = Report::create(['topic_id' => $t->id]);
        Message::create(['report_id' => $r->id, 'content' => 'init', 'is_admin' => false]);
        $before = $r->fresh()?->updated_at;
        $this->assertNotNull($before);

        $this->travel(5)->seconds();
        $this->actingAsGlobalAdmin()
            ->postJson("/api/topic/{$t->id}/reports/status", ['ids' => [$r->id], 's' => 'spam'])
            ->assertOk()->assertJson(['updated' => 1]);

        $after = Report::findOrFail($r->id);
        $this->assertSame(ReportState::Spam, $after->state);
        $this->assertNotNull($after->updated_at);
        $this->assertTrue($after->updated_at->equalTo($before), 'bulk status change must not touch updated_at');
    }

    public function test_set_status_transitions(): void
    {
        $t = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $r = Report::create(['topic_id' => $t->id]);
        Message::create(['report_id' => $r->id, 'content' => 'init', 'is_admin' => false]);

        $this->actingAsGlobalAdmin()->postJson("/api/topic/{$t->id}/report/{$r->id}/status", ['s' => 'close'])
            ->assertOk();
        $this->assertSame(ReportState::Done, Report::findOrFail($r->id)->state);

        $this->actingAsGlobalAdmin()->postJson("/api/topic/{$t->id}/report/{$r->id}/status", ['s' => 'spam'])
            ->assertOk();
        $this->assertSame(ReportState::Spam, Report::findOrFail($r->id)->state);

        $this->actingAsGlobalAdmin()->postJson("/api/topic/{$t->id}/report/{$r->id}/status", ['s' => 'invalid'])
            ->assertStatus(400);
    }

    public function test_bulk_set_status_updates_only_reports_in_the_topic(): void
    {
        $t1 = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);
        $t2 = Topic::create(['name_de' => 'B', 'name_en' => 'B', 'summary_de' => '', 'summary_en' => '']);
        $r1 = Report::create(['topic_id' => $t1->id]);
        $r2 = Report::create(['topic_id' => $t1->id]);
        $foreign = Report::create(['topic_id' => $t2->id]);

        $this->actingAsGlobalAdmin()
            ->postJson("/api/topic/{$t1->id}/reports/status", [
                'ids' => [$r1->id, $r2->id, $foreign->id],
                's' => 'close',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'updated' => 2]);

        $this->assertSame(ReportState::Done, Report::findOrFail($r1->id)->state);
        $this->assertSame(ReportState::Done, Report::findOrFail($r2->id)->state);
        $this->assertSame(ReportState::Open, Report::findOrFail($foreign->id)->state);
    }

    public function test_bulk_set_status_rejects_invalid_status(): void
    {
        $t = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);

        $this->actingAsGlobalAdmin()
            ->postJson("/api/topic/{$t->id}/reports/status", ['ids' => [], 's' => 'invalid'])
            ->assertStatus(400);
    }

    public function test_reports_list_respects_topic_admin(): void
    {
        $t = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $this->actingAsGlobalAdmin()->get("/reports/{$t->id}")->assertOk();
        $this->actingAsUser('nobody')->get("/reports/{$t->id}")->assertStatus(403);
    }

    public function test_get_topic_returns_skeleton_for_new(): void
    {
        $this->actingAsGlobalAdmin()->getJson('/api/topic/new')
            ->assertOk()
            ->assertJsonStructure(['ID', 'Name', 'Summary', 'Fields', 'Admins', 'Email']);
    }

    public function test_get_topic_returns_existing(): void
    {
        $t = Topic::create(['name_de' => 'DE', 'name_en' => 'EN', 'summary_de' => '', 'summary_en' => '']);
        Field::create([
            'topic_id' => $t->id,
            'name_de' => 'F', 'name_en' => 'F',
            'type' => 'text', 'required' => false, 'position' => 0,
        ]);

        $this->actingAsGlobalAdmin()->getJson("/api/topic/{$t->id}")
            ->assertOk()
            ->assertJson(['ID' => $t->id, 'Name' => ['de' => 'DE', 'en' => 'EN']])
            ->assertJsonCount(1, 'Fields');
    }
}
