<?php

namespace Tests\Feature;

use App\Enums\ReportState;
use App\Models\Admin;
use App\Models\Field;
use App\Models\Message;
use App\Models\Report;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private function asGlobalAdmin(): self
    {
        $user = User::updateOrCreate(['uid' => 'globaladmin'], ['name' => 'Admin', 'email' => 'a@x']);

        return $this->actingAs($user);
    }

    private function asUser(string $uid): self
    {
        $user = User::updateOrCreate(['uid' => $uid], ['name' => $uid, 'email' => $uid.'@x']);

        return $this->actingAs($user);
    }

    public function test_newtopic_requires_auth(): void
    {
        // Guests are redirected to the login flow by the `auth` middleware:
        // `/saml/out` in prod, `/dev/login` when the dev bypass is enabled
        // (which it is in the test environment).
        $this->get('/newTopic')->assertRedirect('/dev/login');
    }

    public function test_newtopic_requires_global_admin_for_new(): void
    {
        $this->asUser('not-admin')->get('/newTopic')->assertStatus(403);
    }

    public function test_global_admin_can_open_create_form(): void
    {
        $this->asGlobalAdmin()->get('/newTopic')->assertOk();
    }

    public function test_topic_admin_may_edit_own_topic(): void
    {
        $t = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $admin = Admin::create(['user_id' => 'topicadmin']);
        $t->admins()->attach($admin);

        $this->asUser('topicadmin')->get("/newTopic/{$t->id}")->assertOk();
    }

    public function test_non_admin_cannot_edit_topic(): void
    {
        $t = Topic::create([
            'name_de' => 'T', 'name_en' => 'T', 'summary_de' => 's', 'summary_en' => 's',
        ]);
        $this->asUser('someone-else')->get("/newTopic/{$t->id}")->assertStatus(403);
    }

    public function test_upsert_topic_creates_from_scratch(): void
    {
        $this->asGlobalAdmin()->postJson('/api/topic', [
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

    public function test_upsert_topic_requires_fields(): void
    {
        // FormRequest validation rejects empty Fields with 422.
        $this->asGlobalAdmin()->postJson('/api/topic', [
            'ID' => 0,
            'Name' => ['de' => 'x', 'en' => 'x'],
            'Fields' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['Fields']);
    }

    public function test_set_status_transitions(): void
    {
        $t = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $r = Report::create(['topic_id' => $t->id]);
        Message::create(['report_id' => $r->id, 'content' => 'init', 'is_admin' => false]);

        $this->asGlobalAdmin()->postJson("/api/topic/{$t->id}/report/{$r->id}/status", ['s' => 'close'])
            ->assertOk();
        $this->assertSame(ReportState::Done, Report::findOrFail($r->id)->state);

        $this->asGlobalAdmin()->postJson("/api/topic/{$t->id}/report/{$r->id}/status", ['s' => 'spam'])
            ->assertOk();
        $this->assertSame(ReportState::Spam, Report::findOrFail($r->id)->state);

        $this->asGlobalAdmin()->postJson("/api/topic/{$t->id}/report/{$r->id}/status", ['s' => 'invalid'])
            ->assertStatus(400);
    }

    public function test_bulk_set_status_updates_only_reports_in_the_topic(): void
    {
        $t1 = Topic::create(['name_de' => 'A', 'name_en' => 'A', 'summary_de' => '', 'summary_en' => '']);
        $t2 = Topic::create(['name_de' => 'B', 'name_en' => 'B', 'summary_de' => '', 'summary_en' => '']);
        $r1 = Report::create(['topic_id' => $t1->id]);
        $r2 = Report::create(['topic_id' => $t1->id]);
        $foreign = Report::create(['topic_id' => $t2->id]);

        $this->asGlobalAdmin()
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

        $this->asGlobalAdmin()
            ->postJson("/api/topic/{$t->id}/reports/status", ['ids' => [], 's' => 'invalid'])
            ->assertStatus(400);
    }

    public function test_reports_list_respects_topic_admin(): void
    {
        $t = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $this->asGlobalAdmin()->get("/reports/{$t->id}")->assertOk();
        $this->asUser('nobody')->get("/reports/{$t->id}")->assertStatus(403);
    }

    public function test_get_topic_returns_skeleton_for_new(): void
    {
        $this->asGlobalAdmin()->getJson('/api/topic/new')
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

        $this->asGlobalAdmin()->getJson("/api/topic/{$t->id}")
            ->assertOk()
            ->assertJson(['ID' => $t->id, 'Name' => ['de' => 'DE', 'en' => 'EN']])
            ->assertJsonCount(1, 'Fields');
    }
}
