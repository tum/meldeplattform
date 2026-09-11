<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every TUM member can sign in (topics may require login to report), so a
 * signed-in session must not by itself open any admin surface. These tests
 * pin the boundary between a regular user and an administrator.
 */
class RegularUserAccessTest extends TestCase
{
    use RefreshDatabase;

    private function topic(): Topic
    {
        return Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);
    }

    public function test_is_administrator_distinguishes_roles(): void
    {
        $topic = $this->topic();
        $regular = User::create(['uid' => 'regular', 'name' => 'R', 'email' => 'r@x']);
        $topicAdmin = User::create(['uid' => 'ta', 'name' => 'T', 'email' => 't@x']);
        Admin::create(['user_id' => 'ta'])->topics()->attach($topic);
        // An admin row with no topics left is not an administrator either.
        $stale = User::create(['uid' => 'stale', 'name' => 'S', 'email' => 's@x']);
        Admin::create(['user_id' => 'stale']);
        $dbGlobal = User::create(['uid' => 'dbglobal', 'name' => 'G', 'email' => 'g@x', 'is_global_admin' => true]);

        $this->assertFalse($regular->isAdministrator());
        $this->assertFalse($stale->isAdministrator());
        $this->assertTrue($topicAdmin->isAdministrator());
        $this->assertTrue($dbGlobal->isAdministrator());
        $this->assertTrue(User::create(['uid' => 'globaladmin', 'name' => 'E', 'email' => 'e@x'])->isAdministrator());
    }

    public function test_regular_user_is_refused_on_every_admin_route(): void
    {
        $this->topic();
        $this->actingAsUser('regular');

        $this->get('/dashboard')->assertStatus(403);
        $this->get('/dashboard/export')->assertStatus(403);
        $this->get('/topics')->assertStatus(403);
        $this->post('/api/topics/bulk-status', ['action' => 'deactivate', 'ids' => [1]])->assertStatus(403);
        $this->postJson('/api/topic/summary-preview', ['summary' => '**x**'])->assertStatus(403);
        $this->getJson('/api/otrs/queues')->assertStatus(403);
        $this->get('/newTopic')->assertStatus(403);
        $this->get('/users')->assertStatus(403);
        $this->get('/audit')->assertStatus(403);
    }

    public function test_regular_user_still_reaches_the_public_pages(): void
    {
        $topic = $this->topic();
        $this->actingAsUser('regular');

        $this->get('/')->assertOk();
        $this->get('/form/'.$topic->id)->assertOk();
        $this->get('/track')->assertOk();
    }

    public function test_topic_admin_keeps_access_to_the_admin_surface(): void
    {
        $topic = $this->topic();
        Admin::create(['user_id' => 'ta'])->topics()->attach($topic);
        $this->actingAsUser('ta');

        $this->get('/dashboard')->assertOk();
        $this->get('/topics')->assertOk();
        $this->postJson('/api/topic/summary-preview', ['summary' => '**x**'])->assertOk();
    }

    public function test_header_offers_no_admin_links_to_a_regular_user(): void
    {
        $this->topic();

        $this->actingAsUser('regular')->get('/')
            ->assertOk()
            ->assertDontSee('href="'.route('dashboard').'"', false)
            ->assertDontSee('href="'.route('topics.index').'"', false)
            // Still signed in: logout is offered.
            ->assertSee('topbar-logout', false);

        Admin::create(['user_id' => 'ta'])->topics()->attach(Topic::first());
        $this->actingAsUser('ta')->get('/')
            ->assertOk()
            ->assertSee('href="'.route('dashboard').'"', false)
            ->assertSee('href="'.route('topics.index').'"', false)
            ->assertDontSee('href="'.route('users.index').'"', false);
    }
}
