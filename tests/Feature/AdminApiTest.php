<?php

namespace Tests\Feature;

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

    private function asGlobalAdmin(): self
    {
        return $this->withSession([
            'saml_user' => ['uid' => 'globaladmin', 'name' => 'Admin', 'email' => 'a@x'],
        ]);
    }

    private function asUser(string $uid): self
    {
        return $this->withSession([
            'saml_user' => ['uid' => $uid, 'name' => $uid, 'email' => $uid.'@x'],
        ]);
    }

    public function test_newtopic_requires_auth(): void
    {
        $this->get('/newTopic/0')->assertStatus(401);
    }

    public function test_newtopic_requires_global_admin_for_new(): void
    {
        $this->asUser('not-admin')->get('/newTopic/0')->assertStatus(403);
    }

    public function test_global_admin_can_open_newtopic_0(): void
    {
        $this->asGlobalAdmin()->get('/newTopic/0')->assertOk();
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
        $this->asGlobalAdmin()->postJson('/api/topic/0', [
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
        $newTopicId = (int) Topic::where('name_en', 'New')->value('id');
        $this->assertSame(1, Field::where('topic_id', $newTopicId)->count());
        $this->assertDatabaseHas('admins', ['user_id' => 'ge42tum']);
    }

    public function test_upsert_topic_requires_fields(): void
    {
        // FormRequest validation rejects empty Fields with 422.
        $this->asGlobalAdmin()->postJson('/api/topic/0', [
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
        $this->assertSame('done', Report::findOrFail($r->id)->state);

        $this->asGlobalAdmin()->postJson("/api/topic/{$t->id}/report/{$r->id}/status", ['s' => 'spam'])
            ->assertOk();
        $this->assertSame('spam', Report::findOrFail($r->id)->state);

        $this->asGlobalAdmin()->postJson("/api/topic/{$t->id}/report/{$r->id}/status", ['s' => 'invalid'])
            ->assertStatus(400);
    }

    public function test_reports_list_respects_topic_admin(): void
    {
        $t = Topic::create(['name_de' => 't', 'name_en' => 't', 'summary_de' => '', 'summary_en' => '']);
        $this->asGlobalAdmin()->get("/reports/{$t->id}")->assertOk();
        $this->asUser('nobody')->get("/reports/{$t->id}")->assertStatus(403);
    }

    public function test_get_topic_returns_skeleton_for_zero(): void
    {
        $this->asGlobalAdmin()->getJson('/api/topic/0')
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
