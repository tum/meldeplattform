<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_blocks_non_global_admin(): void
    {
        $this->actingAsUser('regular');
        $this->get('/users')->assertStatus(403);
    }

    public function test_index_renders_for_global_admin(): void
    {
        $this->actingAsGlobalAdmin();
        $this->get('/users')->assertOk();
    }

    public function test_store_creates_admin_for_uid_with_no_user_row(): void
    {
        $this->actingAsGlobalAdmin();
        $topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);

        $this->post('/users', [
            'uid' => 'newadmin',
            'is_global_admin' => '0',
            'topic_ids' => [$topic->id],
        ])->assertRedirect('/users');

        $this->assertDatabaseHas('admins', ['user_id' => 'newadmin']);
        $admin = Admin::where('user_id', 'newadmin')->firstOrFail();
        $this->assertEqualsCanonicalizing([$topic->id], $admin->topics->pluck('id')->all());
    }

    public function test_store_rejects_non_alphanumeric_uid(): void
    {
        $this->actingAsGlobalAdmin();
        $this->from('/users')
            ->post('/users', ['uid' => 'evil/uid', 'topic_ids' => []])
            ->assertRedirect('/users')
            ->assertSessionHasErrors(['uid']);
    }

    public function test_update_toggles_global_admin_and_topic_assignment(): void
    {
        $this->actingAsGlobalAdmin();
        $user = User::create(['uid' => 'target', 'name' => 'T', 'email' => 't@x']);
        $topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);

        $this->post('/users/target', [
            'is_global_admin' => '1',
            'topic_ids' => [$topic->id],
        ])->assertRedirect('/users');

        $this->assertTrue($user->fresh()?->is_global_admin);
        $this->assertDatabaseHas('topic_admins', ['topic_id' => $topic->id]);
    }

    public function test_update_with_no_topics_removes_admin_row(): void
    {
        $this->actingAsGlobalAdmin();
        $admin = Admin::create(['user_id' => 'orphan']);
        $topic = Topic::create(['name_de' => 'T', 'name_en' => 'T', 'summary_de' => '', 'summary_en' => '']);
        $admin->topics()->attach($topic);

        $this->post('/users/orphan', ['topic_ids' => []])
            ->assertRedirect('/users');

        $this->assertDatabaseMissing('admins', ['user_id' => 'orphan']);
    }

    public function test_cannot_self_demote_global_admin(): void
    {
        $u = User::updateOrCreate(['uid' => 'globaladmin'], ['name' => 'GA', 'email' => 'ga@x']);
        $u->is_global_admin = true;
        $u->save();
        $this->actingAs($u);

        $this->from('/users/globaladmin/edit')
            ->post('/users/globaladmin', ['is_global_admin' => '0', 'topic_ids' => []])
            ->assertRedirect('/users/globaladmin/edit')
            ->assertSessionHasErrors(['is_global_admin']);

        $this->assertTrue($u->fresh()?->is_global_admin);
    }

    public function test_destroy_revokes_admin_and_global_flag(): void
    {
        $this->actingAsGlobalAdmin();
        $target = User::create(['uid' => 'target', 'name' => 'T', 'email' => 't@x', 'is_global_admin' => true]);
        Admin::create(['user_id' => 'target']);

        $this->delete('/users/target')->assertRedirect('/users');

        $this->assertDatabaseMissing('admins', ['user_id' => 'target']);
        $this->assertFalse($target->fresh()?->is_global_admin);
    }

    public function test_cannot_self_revoke(): void
    {
        $u = User::updateOrCreate(['uid' => 'globaladmin'], ['name' => 'GA', 'email' => 'ga@x']);
        $this->actingAs($u);

        $this->delete('/users/globaladmin')
            ->assertRedirect('/users')
            ->assertSessionHasErrors(['uid']);
    }

    public function test_db_promoted_user_becomes_global_admin(): void
    {
        $u = User::create(['uid' => 'promoted', 'name' => 'P', 'email' => 'p@x', 'is_global_admin' => true]);
        $this->assertTrue($u->isGlobalAdmin());

        // Refresh from DB to ensure the cast survives a round-trip.
        $this->assertTrue($u->fresh()?->isGlobalAdmin());
    }
}
